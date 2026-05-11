# CLAUDE.md

Notes for future Claude (or any engineer) editing this project. Captures the
architecture and the decisions that aren't obvious from the code alone, so you
can make changes confidently without re-deriving the design.

The README.md in this folder is the *user-facing* doc. This file is the
*engineering* doc.

## What this is, in one sentence

A two-language local app — Python for offline image analysis, PHP for the
web UI — that turns a BeReal data export into a browsable, statful archive
without any code or data leaving the user's machine.

## High-level architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│                  BeReal export root (the "data root")                │
│                                                                      │
│   user.json   posts.json   memories.json   friends.json   ...        │
│   Photos/{post,profile,realmoji}/*.webp                              │
│                                                                      │
│   cache.json           ← analyze.py writes this                      │
│   faces_raw.npz        ← cluster_faces.py: embeddings cache          │
│   faces.json           ← cluster_faces.py: cluster summary           │
│   people_labels.json   ← people.php writes this when user labels     │
│                                                                      │
│   ┌──────────────────────────────────────────────────────────────┐   │
│   │ bereal-archive/  (this folder — the only thing users share)   │   │
│   │                                                              │   │
│   │   analyze.py          (CV2 + Haar)                           │   │
│   │   cluster_faces.py    (InsightFace + DBSCAN)                 │   │
│   │   *.php               (web UI)                               │   │
│   │   thumb-menu.js style.css                                    │   │
│   │   run.sh                                                     │   │
│   └──────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────┘
```

**Key invariant:** all generated artefacts (`cache.json`, `faces.json`,
`faces_raw.npz`, `people_labels.json`) are written to the *data root*, not
into `bereal-archive/`. This means the scripts folder stays clean and safe to
share publicly, while caches sit next to the data they index.

## The data-root discovery pattern

Both Python and PHP walk upward from the script location looking for a
directory that contains **both** `Photos/` and `user.json`. Up to 5 levels.

- PHP: `find_data_root()` in `_lib.php`. Cached in a static var.
- Python: `find_data_root(start: Path)` in `analyze.py` and `cluster_faces.py`.
- Bash: `find_root()` in `run.sh`.

Two conditions are checked together so a generic `Photos/` folder elsewhere
on disk can't false-positive.

**Why this pattern:** lets users drop `bereal-archive/` anywhere inside their
export (or beside it) without configuration. The previous design hard-coded
`__DIR__` as the data root, which forced the scripts to sit at the export
root and polluted that directory.

If you ever need to support a non-nested layout (scripts somewhere completely
outside the export), add a `--root` CLI arg (already exists for the Python
scripts) and an env var read for PHP.

## The posts.json ↔ memories.json join (the non-obvious one)

The BeReal export has two related files describing the same posts:

- `posts.json` — array of `{primary, secondary, retakeCounter, location, ...}`,
  sorted **oldest first**. `primary` is the **back camera** (environment),
  `secondary` is the **selfie**.
- `memories.json` — array of `{frontImage, backImage, isLate, takenTime,
  berealMoment, ...}`, sorted **newest first**. `frontImage` is the
  **selfie**, `backImage` is the back camera.

So the mapping is:

| `posts.json`     | `memories.json` |
| ---------------- | --------------- |
| `primary`        | `backImage`     |
| `secondary`      | `frontImage`    |

If you join the two by `posts.primary.path == memories.frontImage.path`, you
get 0 matches and silent failure (this happened on the first build — late/
on-time stats were all zero). The implementation in
`unified_stream()` (`_lib.php`) indexes memories by **both** front and back
paths and joins on whichever the post matches, which is robust to either
ordering.

Whenever you read a post and need `isLate` / `berealMoment`, go through
`unified_stream()` — don't re-derive the join.

## Photo path resolution (the other non-obvious one)

JSON paths look like `/Photos/{userId}/post/abc.webp`. The actual files on
disk are at `Photos/post/abc.webp` — the user-ID segment is *not* in the
filesystem. `resolve_photo_path()` strips it. Always go through this
helper; never use a raw JSON path as a filesystem path.

## Why PHP + Python instead of one stack

Constraints when this was built:

1. Image analysis (OpenCV, InsightFace, DBSCAN) is meaningfully easier in
   Python — the libraries are mature there, and shelling out from PHP would
   be uglier than just running Python.
2. The web UI needs ~zero install ceremony for the average user. `php -S`
   ships with every PHP install and runs anywhere. A Flask/FastAPI app
   would force users to manage virtualenvs *just to view their data*.

So: Python for the heavy lifting (cold path, runs once), PHP for the UI
(hot path, runs every time you want to browse). The two communicate by file
— Python writes `cache.json` / `faces.json`, PHP reads them.

If you ever rewrite this in one stack, the Python side is the one to keep;
the PHP can be replaced by a static-site generator or a Flask app cheaply.

## File-by-file tour

### `_lib.php`

Shared library. Imported by every page. Contents:

- `find_data_root()` / `data_root_ok()` — auto-discovery + an `is-ok?` flag
  so pages can render a clear error banner if the user mis-placed the folder.
- `load_json(name)` — returns `null` on missing/invalid JSON. Pages must
  handle null gracefully (`?? []` is the idiom used everywhere).
- `cache_data()` — loads `cache.json` once per request, cached in a static.
- `resolve_photo_path()` — strips the user-ID segment from JSON paths.
- `photo_url()`, `photo_meta()`, `is_dark_photo()`, `face_count()` — thin
  helpers on top of `resolve_photo_path()` + the cache.
- `unified_stream()` — the post-stream join described above. Cached.
- `display_tz()` — returns `DateTimeZone` from `user.json` after validating
  against PHP's tz list. Defaults to UTC.
- `fmt_date()`, `nice_num()`, `pct()`, `h()` — string helpers.
- `render_header()`, `render_footer()` — the shell every page uses. The
  header also emits the "export not found" banner when
  `data_root_ok()` is false.

`h()` is the only function that escapes — all template output **must** go
through it. If you ever add a `printf`-style helper, escape inside it.

### `_stats.php`

`compute_stats()` is a single big aggregator that returns a record consumed
by `index.php`. Kept separate so the dashboard template is mostly
presentation. Pure function over `unified_stream()` + `cache_data()`.

When adding a stat: add it to the returned array and reference it from
`index.php`. Don't pre-compute presentation strings here — the template
formats.

### `img.php`

Sandboxed image server. Three layers of defense:

1. Regex guard:
   `^Photos/(post|profile|realmoji)/[A-Za-z0-9_\-]+\.webp$`
2. `realpath()` resolution of both the request and `Photos/`, with a
   prefix check that the resolved path starts with the resolved `Photos/`.
3. `is_file()` check to confirm it's a regular file.

Returns 400 on bad input, 404 on missing files, 200 with cache-immutable
headers on success. If you ever need to serve other file types, *extend the
regex, don't loosen it*.

### `index.php`

Dashboard. Renders bar charts as plain `<div class="bar-row">` markup with
percentage widths — no JS, no charting library. Bar widths are normalized
against the row maximum to keep the layout self-contained. Good enough for
the data volumes BeReal exports produce.

### `gallery.php`

Filter / sort / paginate over `unified_stream()`. Each card renders the
BeReal stacked pair: back camera as the full background (`<a class="main-link">`),
selfie as a small absolutely-positioned inset (`<a class="inset">`).

Each card carries `data-*` attributes describing both URLs and their dark
flags. `thumb-menu.js` reads those attributes to power the right-click menu
without server help.

The two `<a>` elements are intentionally siblings (not nested) — nesting
anchors is invalid HTML and Chrome will hoist the outer one, breaking the
inner click.

The `no-dark` filter only excludes posts where **both** sides are dark.
That choice matters: many night-time BeReals have a black back camera but a
clear selfie thanks to the front flash; dropping those would lose real
moments.

### `thumb-menu.js`

Self-contained IIFE. One global menu element, event-delegated `contextmenu`
listener on `document`, no framework. It reads `data-*` from the nearest
`.thumb` ancestor — the same code works for any future page that uses the
same card markup.

`setMain(thumb, side)` swaps URLs and `src` attributes between the
`.main-link` and `.inset`, plus their `title`s. Reading the current side is
done by comparing `mainLink.href` (the resolved absolute URL) to the
data attributes — using `getAttribute('href')` instead of `.href` would
return the raw attribute and the comparison would be consistent.
The current implementation compares `getAttribute('href')` to `dataset.front`
which is the resolved path-only form.

### `faces.php`

Renders per-image face bounding boxes as absolutely-positioned
`<span class="face-box">` overlays, sized as percentages of the image's
declared `aspect-ratio`. Skips entries with malformed `faces` arrays
defensively.

This page is the "raw" face view — `people.php` is the more useful one.
Keep it for debugging the detector.

### `people.php`

Two views in one file: the cluster index (when `?cluster=` is absent) and
the per-cluster detail. Hands `POST` a label, persists to
`people_labels.json` in the data root, and redirects (PRG pattern).

**Face thumbnails are rendered via SVG**, not CSS-transformed `<img>`s. Why:
the bounding box from InsightFace is in pixel coordinates; SVG's `viewBox`
lets you say "show this rectangle of pixels" directly, with `<image>`
preserving aspect ratio. The CSS-transform approach (which the first build
used) is fragile: it depends on the rendered element size, transform
origins, and `object-position`, and breaks at different thumbnail sizes.

The crop is padded ~35–45 % beyond the face box so the result includes hair
and shoulders, then clamped to the image bounds so cropping near edges
doesn't render dead space.

When labelling clusters, the input is `maxlength=80` and trimmed; empty
strings *delete* the label rather than storing empty.

### `map.php`

Leaflet + marker-cluster, loaded from UNPKG (the only third-party network
fetch in the app, and only on this page). Coordinates emitted via
`json_encode` with `JSON_THROW_ON_ERROR` so malformed lat/lng surfaces
loudly rather than silently breaking the page.

If you want to remove the UNPKG dependency for total offline use, vendor
`leaflet.css` and `leaflet.js` next to `style.css` and update the
`<link>` / `<script>` URLs.

### `friends.php`, `comments.php`

Straightforward table/stat pages. Comments parses `@username` mentions with
a regex and cross-references them against `friends.json` so the friends
page can show "you mentioned this person N times".

### `analyze.py`

OpenCV-based dark-image detection and Haar-cascade face detection. Two
classifiers (frontal + profile). The profile pass only runs when the
frontal pass found nothing, on the theory that side-lit selfies often miss
the frontal cascade.

Dark detection uses two thresholds: low mean **and** low standard
deviation. A pitch-black photo has both; a dark-but-textured photo (e.g.
night sky with stars) has low mean but high std, and we keep it.

Cache is incremental: every 100 images we flush partial state to disk so
ctrl-C / kill -9 doesn't lose work.

When extending: stick to lightweight CV. The heavyweight neural work
belongs in `cluster_faces.py`. This script needs to stay installable from
two packages (opencv-python-headless, numpy).

### `cluster_faces.py`

Two-phase: embed + cluster. Phases are independently runnable
(`--cluster-only`).

- **Embed phase**: InsightFace's `buffalo_l` bundle gives detection +
  landmarks + 512-d recognition embeddings + age + gender from a single
  pass. We discard sub-24-pixel faces and faces below detection
  confidence 0.55. Embeddings persist to `faces_raw.npz` (numpy savez
  with `dtype=object` to allow ragged lists), saved every 50 images for
  ctrl-C safety.
- **Cluster phase**: DBSCAN over cosine distance, parameters
  `eps=0.42 / min_samples=4`. These defaults are tuned for the `buffalo_l`
  embedding space and prioritize precision over recall — better to leave
  a friend in DBSCAN noise than to merge two people. The user can
  re-cluster with looser parameters via `--eps` / `--min-samples`.

The output keeps the top-40 highest-confidence members per cluster
(`TOP_MEMBERS_KEPT`) so `faces.json` stays bounded for users with very
large archives.

### `run.sh`

Bash launcher. Validates that PHP exists and that an export root is
discoverable *before* starting the server, so failures are clear and
loud rather than mysterious 500s. Uses `exec` for clean signal handling
(ctrl-C kills PHP directly).

### `.gitignore`

Belt-and-suspenders blocklist. Three layers:

1. BeReal export artefacts the tool *consumes* (`/Photos/`, `/conversations/`,
   `/*.json`, `/*.zip`) — covers the case where someone runs `git init`
   one level above this folder instead of inside it.
2. Generated caches (`cache.json`, `faces.json`, `faces_raw.npz`,
   `people_labels.json`) — these *should* live in the data root, not in
   here, but if someone manually copies one in, it stays out of git.
3. Standard noise (`__pycache__/`, `.venv/`, OS files, editor folders).

Verified by `git check-ignore` during the build.

## Conventions

**HTML escaping.** All template output goes through `h()` (defined in
`_lib.php`). Never emit a variable into HTML without it. The only exception
is JSON for JS, which uses `json_encode` with `JSON_THROW_ON_ERROR`.

**No JS framework.** All JS is plain ES2017. Each page that needs JS has a
single `<script src="/name.js" defer>` reference. If a page needs more JS
than fits in one file, add another `.js` file rather than inlining.

**No build step.** The user runs PHP directly against the files. Don't
introduce a transpiler, bundler, or `npm install` unless absolutely
required.

**Generated files in the data root.** Never write into `bereal-archive/`
from a script (apart from someone manually placing a config file). The
folder must stay clean for sharing.

**Dates and times.** Always go through `fmt_date()` so the user's timezone
from `user.json` is applied consistently. Direct `DateTime::format` calls
will show UTC and surprise users.

**Personal data.** No hardcoded names, usernames, phone numbers, ID
fragments, locations, or timezones. Anything that looks personal must come
from a JSON file at runtime. If you find yourself adding "Europe/Paris" or
similar to source, you're doing it wrong — pull it from `user.json` and
validate.

## How to add a new page

1. Create `newpage.php` in this folder.
2. Start with `<?php require __DIR__ . '/_lib.php';` and at least call
   `render_header('/newpage.php')` / `render_footer()`.
3. Add the route to the nav in `_lib.php` (`render_header()`'s nav block).
4. Use `unified_stream()` for post data, `cache_data()` for image
   analysis, `load_json('foo.json')` for raw JSON files.
5. Escape with `h()`, format dates with `fmt_date()`, render numbers
   with `nice_num()`.

## How to add a new stat

1. Open `_stats.php`. Compute it inside `compute_stats()`'s loop over
   `$stream`, or in a second pass after the loop.
2. Add it to the returned array.
3. Render it in `index.php` using the existing `.stat` card or
   `.bar-row` markup.

If the stat needs image data, also `require_once __DIR__ . '/_lib.php';`
isn't necessary — `_stats.php` already does it — and you can call
`cache_data()` to get the analysis cache.

## How to add a new image analysis pass

Two options:

- **Cheap** (no model): add to `analyze.py`. Returns extra fields in each
  cache entry. Update `_lib.php` if PHP needs to read them.
- **Heavy** (model required): create a new script (e.g.
  `pose_estimate.py`) following the same shape as `cluster_faces.py`:
  resumable, writes output to data root, has `--root` / `--limit` /
  `--force` flags.

Update `requirements.txt` if you add a dependency. Keep the
`opencv-python-headless` / `numpy` baseline thin so users who only want
the dark-image filter aren't forced to install heavy deps.

## Things to be careful with

**Don't cache the post stream in a global file.** It's user-specific and
must be rebuilt per request. The in-process static cache inside
`unified_stream()` is fine because it's per-request.

**Don't add `set_time_limit(0)` to PHP.** The dev server is short-lived;
if a page takes seconds, the page is doing too much. Push work into a
Python preprocessor and read the result.

**Don't trust JSON shape.** BeReal has changed the export format before.
Use `?? null` / `?? []` and `is_array()` checks; never assume a key
exists. Pages should degrade to a "no data" banner rather than crash.

**Watch out for invalid HTML in cards.** The right-click menu and the
gallery card rely on `.thumb > .main-link` and `.thumb > .inset` being
siblings. If you nest anchors, browsers reorder the DOM and the JS
breaks silently.

**Path security in `img.php`.** If you ever extend the regex,
re-run path-traversal probes (`../`, `Photos/../`, `Photos/post/../../`).
The current test in the user-visible report has all three returning 400.

## Known limitations

- Comments don't link back to their posts. `comments.json` only stores
  `postId`, but the post objects don't carry IDs in the export. We
  surface the IDs and counts but can't resolve them to images.
- Conversation data (`conversations/*/`) isn't surfaced anywhere. There
  are folders of conversation thumbnails but no metadata file telling us
  who's in each conversation; making this useful would require user input
  ("which folder is which friend?").
- The face clustering can't separate identical twins, very young children
  from their adult selves in old posts (same archive, years apart), or
  someone who wears very different makeup styles. This is an InsightFace
  limit, not ours.
- No mobile-optimized layout. The grid and tables work on phones but
  aren't pretty.

## When something feels weird

- "Numbers don't match." Almost always a memory-vs-post join issue.
  Re-read the section above on `posts ↔ memories`.
- "Images broken in the gallery." PHP started in the wrong folder, or
  the path-resolution regex matched something it shouldn't have. Check
  the browser network tab — 400 means the regex rejected it, 404 means
  the file isn't there.
- "People page is empty." `faces.json` is missing or empty; either
  `cluster_faces.py` hasn't run, or it ran but produced zero clusters
  (try lowering `--min-samples`).
- "Dashboard says cache.json not built." `analyze.py` hasn't run.
  Everything else still works.
