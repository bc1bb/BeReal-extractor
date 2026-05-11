<?php
require __DIR__ . '/_lib.php';

$stream = unified_stream();

$filter = $_GET['filter'] ?? 'no-dark';   // all | no-dark | dark | with-faces
$sort   = $_GET['sort']   ?? 'newest';    // newest | oldest
$year   = (string)($_GET['year'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 60;

$years = [];
foreach ($stream as $p) {
    if ($p['takenAt']) $years[substr((string)$p['takenAt'], 0, 4)] = true;
}
$years = array_keys($years);
sort($years);

$rows = [];
foreach ($stream as $p) {
    $pPath = $p['primary']['path']   ?? null;
    $sPath = $p['secondary']['path'] ?? null;
    $pDark = is_dark_photo($pPath);
    $sDark = is_dark_photo($sPath);
    $faces = face_count($pPath) + face_count($sPath);

    if ($year !== '' && substr((string)$p['takenAt'], 0, 4) !== $year) continue;
    if ($filter === 'no-dark'    && $pDark && $sDark)  continue;
    if ($filter === 'dark'       && !($pDark || $sDark)) continue;
    if ($filter === 'with-faces' && $faces === 0)        continue;

    $rows[] = $p + [
        '_pDark' => $pDark,
        '_sDark' => $sDark,
        '_faces' => $faces,
    ];
}

if ($sort === 'newest') $rows = array_reverse($rows);

$total = count($rows);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$slice = array_slice($rows, ($page - 1) * $per, $per);

function pager_url(int $p): string {
    $q = $_GET; $q['page'] = $p;
    return '?' . http_build_query($q);
}

render_header('/gallery.php', 'Gallery');
?>
<h1>Gallery</h1>
<p class="muted"><?= nice_num($total) ?> posts after filter · page <?= $page ?>/<?= $pages ?></p>

<form class="controls" method="get">
  <label>Filter
    <select name="filter">
      <option value="all"        <?= $filter === 'all'        ? 'selected' : '' ?>>All photos</option>
      <option value="no-dark"    <?= $filter === 'no-dark'    ? 'selected' : '' ?>>Skip dark/black</option>
      <option value="dark"       <?= $filter === 'dark'       ? 'selected' : '' ?>>Dark only</option>
      <option value="with-faces" <?= $filter === 'with-faces' ? 'selected' : '' ?>>With faces</option>
    </select>
  </label>
  <label>Year
    <select name="year">
      <option value="">All</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Order
    <select name="sort">
      <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
      <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <span class="muted" style="margin-left:auto;">Right-click any photo for options</span>
</form>

<div class="thumbs">
<?php foreach ($slice as $p):
    // primary = back camera, secondary = front camera (selfie).
    $backUrl  = photo_url($p['primary']['path']   ?? null);
    $frontUrl = photo_url($p['secondary']['path'] ?? null);
    $taken    = $p['takenAt'] ? fmt_date($p['takenAt']) : '';
    $bothDark = $p['_pDark'] && $p['_sDark'];
?>
  <div class="thumb"
       data-back="<?= h($backUrl) ?>"
       data-front="<?= h($frontUrl) ?>"
       data-back-dark="<?= $p['_pDark'] ? '1' : '0' ?>"
       data-front-dark="<?= $p['_sDark'] ? '1' : '0' ?>"
       data-taken="<?= h($taken) ?>">
    <?php if ($bothDark): ?>
      <div class="dark-fallback">DARK</div>
    <?php elseif (!$p['_pDark']): ?>
      <a class="main-link" href="<?= h($backUrl) ?>" target="_blank" title="Back camera — click to open">
        <img loading="lazy" src="<?= h($backUrl) ?>" alt="back camera">
      </a>
    <?php else: ?>
      <a class="main-link" href="<?= h($frontUrl) ?>" target="_blank" title="Selfie — back camera is dark">
        <img loading="lazy" src="<?= h($frontUrl) ?>" alt="selfie (back is dark)">
      </a>
    <?php endif; ?>

    <?php if ($frontUrl && !$p['_sDark'] && !$p['_pDark']): ?>
      <a class="inset" href="<?= h($frontUrl) ?>" target="_blank" title="Open selfie">
        <img loading="lazy" src="<?= h($frontUrl) ?>" alt="selfie">
      </a>
    <?php endif; ?>

    <div class="meta">
      <span class="tag"><?= h($taken) ?></span>
      <?php if ($p['isLate'] === true): ?><span class="tag late">late</span><?php endif; ?>
      <?php if ($p['_faces'] > 0): ?><span class="tag ok"><?= $p['_faces'] ?> face<?= $p['_faces'] === 1 ? '' : 's' ?></span><?php endif; ?>
      <?php if ($p['_pDark'] && !$p['_sDark']): ?><span class="tag dark">back dark</span><?php endif; ?>
      <?php if ($p['_sDark'] && !$p['_pDark']): ?><span class="tag dark">selfie dark</span><?php endif; ?>
      <?php if (($p['retakes'] ?? 0) > 0): ?><span class="tag">↻ <?= (int)$p['retakes'] ?></span><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="pager">
  <?php if ($page > 1):  ?><a href="<?= h(pager_url($page - 1)) ?>">‹ prev</a><?php endif; ?>
  <span class="cur"><?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h(pager_url($page + 1)) ?>">next ›</a><?php endif; ?>
</div>

<script src="/thumb-menu.js" defer></script>
<?php render_footer(); ?>
