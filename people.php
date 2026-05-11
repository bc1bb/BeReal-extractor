<?php
require __DIR__ . '/_lib.php';

$facesFile  = data_root() . '/faces.json';
$labelsFile = data_root() . '/people_labels.json';

$data   = is_file($facesFile)  ? (json_decode((string)file_get_contents($facesFile),  true) ?? null) : null;
$labels = is_file($labelsFile) ? (json_decode((string)file_get_contents($labelsFile), true) ?? []) : [];
if (!is_array($labels)) $labels = [];

// Persist a label submitted via POST (label-this-person form).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cluster'])) {
    $c    = (string)(int)$_POST['cluster'];
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        unset($labels[$c]);
    } else {
        $labels[$c] = mb_substr($name, 0, 80);
    }
    file_put_contents($labelsFile, json_encode($labels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header('Location: /people.php' . (isset($_GET['cluster']) ? '?cluster=' . (int)$_GET['cluster'] : ''));
    exit;
}

$cid = isset($_GET['cluster']) ? (int)$_GET['cluster'] : null;

render_header('/people.php', 'People');

if (!$data || empty($data['clusters'])) {
    ?>
    <h1>People</h1>
    <div class="card" style="border-color: var(--accent);">
      <strong>No identity data yet.</strong>
      <p class="muted" style="margin: 6px 0 0;">
        Run <code>python3 cluster_faces.py</code> from this folder. The script downloads the
        InsightFace <em>buffalo_l</em> model (~280 MB) on first run and produces
        <code>faces.json</code> with per-identity clusters.
      </p>
    </div>
    <?php
    render_footer();
    exit;
}

$clusters = $data['clusters'];
$byImage  = $data['by_image'] ?? [];
$cache    = cache_data();

$dim = function (string $rel) use ($cache): array {
    $m = $cache[$rel] ?? null;
    return [(int)($m['w'] ?? 1500), (int)($m['h'] ?? 2000)];
};

// Build "image-path -> takenAt" so cluster detail can show timestamps.
$takenByRel = [];
foreach (unified_stream() as $p) {
    foreach (['primary', 'secondary'] as $side) {
        $r = resolve_photo_path($p[$side]['path'] ?? null);
        if ($r) $takenByRel[$r] = $p['takenAt'] ?? null;
    }
}

if ($cid === null):
    // Overview: tile every cluster with its representative face crop.
?>
    <h1>People</h1>
    <p class="muted">
      <?= nice_num(count($clusters)) ?> identities found across <?= nice_num(count($byImage)) ?> images.
      Click a face to browse all photos of that person.
    </p>

    <div class="thumbs" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
    <?php foreach ($clusters as $c):
        $name = $labels[(string)$c['id']] ?? null;
        if (!isset($c['rep_box']) || count($c['rep_box']) < 4) continue;
        [$x, $y, $w, $h] = $c['rep_box'];
        [$iw, $ih] = $dim((string)$c['rep_image']);
        $repUrl = '/img.php?p=' . rawurlencode((string)$c['rep_image']);

        // Pad the crop a touch so we see hair / chin / shoulders, then clamp
        // the viewBox so it stays inside the image bounds.
        $pad = 0.35;
        $cx = $x + $w / 2; $cy = $y + $h / 2;
        $side = max($w, $h) * (1 + $pad);
        $vbX = max(0.0, min($iw - $side, $cx - $side / 2));
        $vbY = max(0.0, min($ih - $side, $cy - $side / 2));
        $glyph = ($c['male'] ?? 0) > ($c['female'] ?? 0) ? '♂' : (($c['female'] ?? 0) > ($c['male'] ?? 0) ? '♀' : '·');
    ?>
      <a class="thumb" style="aspect-ratio: 1 / 1;" href="?cluster=<?= (int)$c['id'] ?>">
        <svg viewBox="<?= $vbX ?> <?= $vbY ?> <?= $side ?> <?= $side ?>"
             preserveAspectRatio="xMidYMid slice"
             style="width:100%;height:100%;display:block;background:#111;">
          <image href="<?= h($repUrl) ?>" width="<?= $iw ?>" height="<?= $ih ?>" preserveAspectRatio="xMidYMid slice"/>
        </svg>
        <div class="meta">
          <span class="tag"><?= h($name ?? 'person #' . (int)$c['id']) ?></span>
          <span class="tag ok"><?= nice_num((int)$c['count']) ?> photos</span>
          <span class="tag"><?= $glyph ?><?= !empty($c['avg_age']) ? ' ' . (int)$c['avg_age'] : '' ?></span>
        </div>
      </a>
    <?php endforeach; ?>
    </div>
<?php

else:
    // Detail page for a single identity.
    $cluster = null;
    foreach ($clusters as $c) {
        if ((int)$c['id'] === $cid) { $cluster = $c; break; }
    }
    if (!$cluster) {
        echo '<h1>People</h1><p>Unknown cluster.</p>';
        render_footer();
        exit;
    }
    $name = $labels[(string)$cid] ?? null;
?>
    <h1>
      <?= h($name ?? 'Person #' . $cid) ?>
      <span class="muted" style="font-size: 14px;">(<?= nice_num((int)$cluster['count']) ?> appearances)</span>
    </h1>
    <p><a href="/people.php">← all people</a></p>

    <form class="controls" method="post" style="margin-bottom: 18px;">
      <input type="hidden" name="cluster" value="<?= $cid ?>">
      <label>Label this person
        <input type="text" name="name" value="<?= h($name ?? '') ?>" maxlength="80"
               placeholder="e.g. Alex, mom, me, the dog">
      </label>
      <button class="btn" type="submit">Save</button>
      <span class="muted">
        avg age ≈ <?= isset($cluster['avg_age']) ? h((string)$cluster['avg_age']) : '—' ?>
        · <?= (int)($cluster['male'] ?? 0) ?> male / <?= (int)($cluster['female'] ?? 0) ?> female votes
      </span>
    </form>

    <div class="thumbs" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">
    <?php foreach (($cluster['members'] ?? []) as $m):
        if (!isset($m['box']) || count($m['box']) < 4) continue;
        $rel = (string)$m['rel'];
        [$x, $y, $w, $h] = $m['box'];
        [$iw, $ih] = $dim($rel);
        $url = '/img.php?p=' . rawurlencode($rel);
        $ts  = $takenByRel[$rel] ?? null;

        $pad  = 0.45;
        $cx   = $x + $w / 2; $cy = $y + $h / 2;
        $side = max($w, $h) * (1 + $pad);
        $vbX  = max(0.0, min($iw - $side, $cx - $side / 2));
        $vbY  = max(0.0, min($ih - $side, $cy - $side / 2));
    ?>
      <a class="thumb" style="aspect-ratio: 1 / 1;" href="<?= h($url) ?>" target="_blank">
        <svg viewBox="<?= $vbX ?> <?= $vbY ?> <?= $side ?> <?= $side ?>"
             preserveAspectRatio="xMidYMid slice"
             style="width:100%;height:100%;display:block;background:#111;">
          <image href="<?= h($url) ?>" width="<?= $iw ?>" height="<?= $ih ?>" preserveAspectRatio="xMidYMid slice"/>
        </svg>
        <div class="meta">
          <?php if ($ts): ?><span class="tag"><?= h(fmt_date($ts, 'Y-m-d')) ?></span><?php endif; ?>
          <span class="tag ok"><?= number_format((float)($m['score'] ?? 0), 2) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
