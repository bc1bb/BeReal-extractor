<?php
require __DIR__ . '/_lib.php';

$stream = unified_stream();
$cache  = cache_data();

// Build a flat list of "image + the face boxes that live in it".
$faceItems = [];
foreach ($stream as $p) {
    foreach (['primary', 'secondary'] as $side) {
        $path = $p[$side]['path'] ?? null;
        if (!$path) continue;
        $rel = resolve_photo_path($path);
        $m = $cache[$rel] ?? null;
        if (!$m || empty($m['faces'])) continue;
        $faceItems[] = [
            'path'    => $path,
            'side'    => $side,
            'takenAt' => $p['takenAt'],
            'faces'   => $m['faces'],
            'w'       => $m['w'] ?? 1500,
            'h'       => $m['h'] ?? 2000,
        ];
    }
}

$sort = $_GET['sort'] ?? 'newest';
if ($sort === 'newest')          usort($faceItems, fn($a, $b) => strcmp((string)$b['takenAt'], (string)$a['takenAt']));
elseif ($sort === 'oldest')      usort($faceItems, fn($a, $b) => strcmp((string)$a['takenAt'], (string)$b['takenAt']));
elseif ($sort === 'most-faces')  usort($faceItems, fn($a, $b) => count($b['faces']) - count($a['faces']));

$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 48;
$total = count($faceItems);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$slice = array_slice($faceItems, ($page - 1) * $per, $per);

function pager_url(int $p): string {
    $q = $_GET; $q['page'] = $p;
    return '?' . http_build_query($q);
}

render_header('/faces.php', 'Faces');
?>
<h1>All faces</h1>
<p class="muted"><?= nice_num($total) ?> images with at least one detected face · page <?= $page ?>/<?= $pages ?></p>

<?php if ($total === 0): ?>
<div class="card">
  <strong>No face data yet.</strong>
  <span class="muted">Run <code>python3 analyze.py</code> from this folder to detect faces, then refresh.</span>
</div>
<?php else: ?>

<form class="controls" method="get">
  <label>Order
    <select name="sort">
      <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest first</option>
      <option value="oldest"     <?= $sort === 'oldest'     ? 'selected' : '' ?>>Oldest first</option>
      <option value="most-faces" <?= $sort === 'most-faces' ? 'selected' : '' ?>>Most faces</option>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<div class="thumbs">
<?php foreach ($slice as $item):
    $url = photo_url($item['path']);
    $w = max(1, (int)$item['w']);
    $h = max(1, (int)$item['h']);
?>
  <div class="thumb" style="aspect-ratio: <?= $w ?>/<?= $h ?>;">
    <a href="<?= h($url) ?>" target="_blank" style="display:block; width:100%; height:100%;">
      <img loading="lazy" src="<?= h($url) ?>" alt="">
      <?php foreach ($item['faces'] as $f):
          if (count($f) < 4) continue;
          [$x, $y, $fw, $fh] = $f;
          $l = ($x  / $w) * 100; $t = ($y  / $h) * 100;
          $bw = ($fw / $w) * 100; $bh = ($fh / $h) * 100; ?>
        <span class="face-box" style="left: <?= $l ?>%; top: <?= $t ?>%; width: <?= $bw ?>%; height: <?= $bh ?>%;"></span>
      <?php endforeach; ?>
      <div class="meta">
        <span class="tag"><?= h(fmt_date($item['takenAt'])) ?></span>
        <span class="tag ok"><?= count($item['faces']) ?> face<?= count($item['faces']) === 1 ? '' : 's' ?></span>
      </div>
    </a>
  </div>
<?php endforeach; ?>
</div>

<div class="pager">
  <?php if ($page > 1):  ?><a href="<?= h(pager_url($page - 1)) ?>">‹ prev</a><?php endif; ?>
  <span class="cur"><?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h(pager_url($page + 1)) ?>">next ›</a><?php endif; ?>
</div>

<?php endif; ?>
<?php render_footer(); ?>
