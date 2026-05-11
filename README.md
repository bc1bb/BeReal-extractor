# See updates at https://git.bc1bb.foo/bc1bb/BeReal-extractor

# bereal-archive

A local, private web viewer for your BeReal data export. Drop it next to the
ZIP you got from BeReal Support and you get a real app: a dashboard with stats
about your posting habits, a BeReal-style photo gallery, a map of every
geotagged moment, and — if you want it — a *people* page that automatically
groups every photo by the faces inside it.

Everything runs on your computer. **No data ever leaves your machine.**

## Why you might want this

You have three years of BeReals locked inside `posts.json` and a sea of
`.webp` files. BeReal's official viewer is the app itself, and the app shows
you basically nothing about your archive as a whole. This little tool turns
the export into something you can actually browse, search, and reminisce
over.

A few things it can tell you:

- How many BeReals you've taken, what fraction were late, the median delay
  between the daily moment and when you actually posted
- A heatmap of which hours of the day and which weekdays you tend to post
- Which 8 places you've BeRealed from the most
- A timeline of every face that appears more than ~4 times across your
  archive, grouped by person (you can label them: "Mom", "Alex", etc.)

## What you need

- **PHP 8.0+** with the GD extension (most macOS/Linux installs have this
  by default). On macOS: `brew install php`. On Ubuntu: `sudo apt install php-cli php-gd`.
- **Python 3.9+** with `pip`
- About **300 MB of free disk** if you want the face-recognition feature
  (the model downloads on first run)

## Get started in five minutes

#### 1. Get your BeReal export

Go to BeReal Settings → *Privacy and Permissions* → *Request your data*.
A few hours later you'll get an email with a ZIP. Unzip it somewhere. You
should see files like `user.json`, `posts.json`, `memories.json`, and a
`Photos/` folder full of `.webp` images.

#### 2. Drop this folder inside

Copy the whole `bereal-archive/` folder anywhere inside the unzipped
export. A typical layout looks like this:

```
my-bereal-export/
├── user.json
├── posts.json
├── memories.json
├── friends.json
├── Photos/
│   ├── post/
│   ├── profile/
│   └── realmoji/
└── bereal-archive/        ← this folder, anywhere inside
    ├── run.sh
    ├── analyze.py
    └── ...
```

The scripts find the export root automatically by walking up the
filesystem looking for a folder that contains both `Photos/` and
`user.json`.

#### 3. Start the viewer

```bash
cd bereal-archive
./run.sh
```

Open <http://127.0.0.1:8123> in your browser. You should see your
dashboard. The gallery, map, friends, and comments pages all work right
away — no Python needed.

#### 4. (Optional) Dark-image filter + face overlays

If you want the gallery to skip near-black photos and show how many faces
are in each photo, install the lightweight Python deps and run the
analyzer once:

```bash
pip install opencv-python-headless numpy
python3 analyze.py
```

This walks every `.webp`, measures brightness, runs a fast Haar-cascade
face detector, and writes `cache.json` to your export root. It's
resumable — ctrl-C and rerun anytime.

On an M1 MacBook Pro, ~2 500 photos take about 3 minutes.

#### 5. (Optional, the fun one) Group photos by person

If you want the *People* page — where every face in your archive is
clustered automatically — install the heavier Python deps and run the
clustering pipeline:

```bash
pip install insightface onnxruntime scikit-learn
python3 cluster_faces.py
```

On first run it downloads the InsightFace `buffalo_l` model (~280 MB).
Then it computes a 512-dimensional embedding for every detected face and
clusters them with DBSCAN under cosine distance. About 20 minutes for
2 500 photos on a laptop.

Output: `faces.json` (cluster summaries) and `faces_raw.npz` (embeddings,
resumable cache).

Now the **People** page in the web UI works. Click a face, see every
photo of that person, type a name to label them.

If the algorithm groups too few people, relax the parameters:

```bash
python3 cluster_faces.py --cluster-only --eps 0.46 --min-samples 3
```

`--cluster-only` reuses the cached embeddings, so re-clustering is
instant. Tweak until you like the result.

## What you'll see

| Page | What it does |
| --- | --- |
| **Dashboard** | Totals (posts, active days, on-time vs. late), median post delay, monthly bar chart, hour/weekday heatmap, retake distribution, top 8 locations |
| **Gallery** | Every post in BeReal layout (back camera big, selfie inset). Filters: skip dark, dark-only, with-faces, by year. Right-click any photo for a context menu: open back/selfie, swap which is the main image, copy URLs |
| **People** | One tile per identity, labeled with the size of the cluster. Click to see every photo of that person; type a name to label them |
| **All faces** | Every photo containing a face, with bounding-box overlays |
| **Map** | Every geotagged post on a Leaflet map with cluster markers. Click a marker for a thumbnail |
| **Friends** | Your friends in the order you added them, plus a count of how often you @-mentioned each one in comments |
| **Comments** | Search and stats over the comments you authored |

## Privacy, plainly

- **The web server is bound to `127.0.0.1`**, not your network. Only your
  own machine can reach it.
- **No analytics, no telemetry, no third-party requests** at all — except:
  - Map tiles from OpenStreetMap (only when you open `/map.php`)
  - Leaflet JS/CSS from UNPKG (also only on the map page)
  - The InsightFace model download from GitHub Releases (only the first
    time you run `cluster_faces.py`)
- **Everything else is local-only**: image analysis, face detection,
  clustering, the web UI. None of your photos, comments, friends, or
  locations are sent anywhere.
- The generated cache files (`cache.json`, `faces.json`,
  `faces_raw.npz`, `people_labels.json`) live in the export root, *next
  to your data*, not inside this folder. So when you share this folder
  publicly, none of your personal data tags along.
- The included `.gitignore` blocks every BeReal export file and every
  generated cache from ever being committed by mistake.

## Common questions

**Q: Do I have to run the Python parts?**
**No**. The dashboard, gallery, map, friends, and comments pages work with
the JSON files alone. Skipping `analyze.py` just means you don't get the
dark-frame filter or face counts. Skipping `cluster_faces.py` just means
the *People* page stays empty.

**Q: Why doesn't a photo open when I click it?**
The PHP server has to be running (`./run.sh` in this folder). If the
page loads but images are broken, you probably launched PHP from the
wrong folder — run it from inside `bereal-archive/` so the docroot is
correct.

**Q: The face clustering grouped me, then put one friend across three
separate clusters. What went wrong?**
The default settings are tuned for precision over recall. Try
`--cluster-only --eps 0.46 --min-samples 3` to allow looser groupings.
If you find a setting you like, you can also rerun on a single person to
sanity-check.

**Q: It says "BeReal export not found".**
Either you ran PHP from a folder that doesn't have a BeReal export
above it, or your export is missing `Photos/` or `user.json`. Make sure
this folder is somewhere *inside* the unzipped BeReal export.

**Q: My export uses different timezones — what's shown in the
dashboard?**
The timezone declared in your `user.json` is used everywhere times are
shown. Falls back to UTC if the field is missing or invalid.

**Q: Can I move the bereal-archive folder somewhere else and point it
at the export?**
Not built in. Today it expects to live somewhere inside the export
folder. Patches welcome.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `error: 'php' is not installed` | Install PHP first: `brew install php` (macOS) or `sudo apt install php-cli php-gd` |
| Pages load but images are broken | PHP started from the wrong folder. Run `./run.sh` from inside `bereal-archive/` |
| `BeReal export not found` notice on every page | `bereal-archive/` isn't inside the unzipped export, or the export is incomplete |
| `analyze.py` fails with "Could not load OpenCV Haar cascades" | `pip install opencv-python-headless` (not the bare `opencv-python`) |
| `cluster_faces.py` fails to download the model | Check your internet connection on first run only; the model is cached after the first download |
| Port 8123 already in use | Run `./run.sh 9000` (or any free port) |

## License

MIT. Use it, fork it, share it. If you ship a fork, please keep the
privacy posture intact — no telemetry, no remote logging.
