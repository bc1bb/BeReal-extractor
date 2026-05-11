<?php
require __DIR__ . '/_lib.php';
require __DIR__ . '/_stats.php';

$u = user();
$cacheLoaded = !empty(cache_data());
$friendCount = count(friends());
$commentCount = count(comments());

render_header('/index.php');

if (!unified_stream()) {
    echo '<h1>Dashboard</h1><p class="muted">No posts found in this export.</p>';
    render_footer();
    exit;
}
$s = compute_stats();
?>
<h1>Dashboard</h1>
<p class="muted">
  <?= h((string)($u['fullname'] ?? '')) ?>
  <?= !empty($u['username']) ? ' (@' . h((string)$u['username']) . ')' : '' ?>
  · joined <?= !empty($u['createdAt']) ? h(fmt_date($u['createdAt'], 'Y-m-d')) : '—' ?>
  · <?= h((string)($u['countryCode'] ?? '')) ?>
  · <?= h(display_tz()->getName()) ?>
</p>

<?php if (!$cacheLoaded): ?>
<div class="card" style="border-color: var(--accent);">
  <strong>cache.json not built yet.</strong>
  <span class="muted">Run <code>python3 analyze.py</code> from this folder to enable dark-image filtering and face stats.</span>
</div>
<?php endif; ?>

<div class="grid">
  <div class="stat"><div class="k">Total posts</div><div class="v"><?= nice_num($s['total']) ?></div><div class="sub"><?= h(fmt_date($s['first'], 'Y-m-d')) ?> → <?= h(fmt_date($s['last'], 'Y-m-d')) ?></div></div>
  <div class="stat"><div class="k">Active days</div><div class="v"><?= nice_num($s['activeDays']) ?> / <?= nice_num($s['spanDays']) ?></div><div class="sub"><?= pct($s['activeDays'] / $s['spanDays'] * 100) ?> coverage</div></div>
  <div class="stat"><div class="k">On time</div><div class="v"><?= nice_num($s['onTime']) ?></div><div class="sub"><?= ($s['onTime'] + $s['late']) > 0 ? pct($s['onTime'] / ($s['onTime'] + $s['late']) * 100) : '—' ?></div></div>
  <div class="stat"><div class="k">Late</div><div class="v"><?= nice_num($s['late']) ?></div><div class="sub"><?= ($s['onTime'] + $s['late']) > 0 ? pct($s['late'] / ($s['onTime'] + $s['late']) * 100) : '—' ?></div></div>
  <div class="stat"><div class="k">Median post delay</div><div class="v"><?= h(gmdate('H:i:s', $s['medianDelaySec'])) ?></div><div class="sub">after the daily BeReal</div></div>
  <div class="stat"><div class="k">Friends</div><div class="v"><?= nice_num($friendCount) ?></div><div class="sub"><?= nice_num($commentCount) ?> comments authored</div></div>
  <?php if ($cacheLoaded): $pc = $s['photoCache']; ?>
  <div class="stat"><div class="k">Faces detected</div><div class="v"><?= nice_num($pc['totalFaces']) ?></div><div class="sub"><?= nice_num($pc['withFace']) ?> photos with ≥1 face</div></div>
  <div class="stat"><div class="k">Dark photos filtered</div><div class="v"><?= nice_num($pc['dark']) ?></div><div class="sub">of <?= nice_num($pc['post']) ?> post images</div></div>
  <?php endif; ?>
</div>

<h2>Posts per month</h2>
<div class="card">
  <?php
  $max = $s['byMonth'] ? max($s['byMonth']) : 1;
  foreach ($s['byMonth'] as $month => $count):
      $w = $max ? ($count / $max) * 100 : 0;
  ?>
    <div class="bar-row">
      <span class="lbl"><?= h((string)$month) ?></span>
      <span class="bar"><span style="width: <?= $w ?>%"></span></span>
      <span class="val"><?= nice_num((int)$count) ?></span>
    </div>
  <?php endforeach; ?>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 8px;">
  <div>
    <h2>By hour of day (<?= h(display_tz()->getName()) ?>)</h2>
    <div class="card">
      <?php
      $maxH = max($s['byHour']) ?: 1;
      for ($i = 0; $i < 24; $i++):
          $c = $s['byHour'][$i];
          $w = ($c / $maxH) * 100; ?>
        <div class="bar-row">
          <span class="lbl"><?= sprintf('%02d:00', $i) ?></span>
          <span class="bar"><span style="width: <?= $w ?>%"></span></span>
          <span class="val"><?= nice_num($c) ?></span>
        </div>
      <?php endfor; ?>
    </div>
  </div>
  <div>
    <h2>By weekday</h2>
    <div class="card">
      <?php
      $names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
      $maxD = max($s['byDow']) ?: 1;
      foreach ($names as $i => $name):
          $c = $s['byDow'][$i]; $w = ($c / $maxD) * 100; ?>
        <div class="bar-row">
          <span class="lbl"><?= $name ?></span>
          <span class="bar"><span style="width: <?= $w ?>%"></span></span>
          <span class="val"><?= nice_num($c) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <h2>Retakes</h2>
    <div class="card">
      <?php
      ksort($s['retakes']);
      $maxR = $s['retakes'] ? max($s['retakes']) : 1;
      foreach ($s['retakes'] as $r => $c):
          $w = $maxR ? ($c / $maxR) * 100 : 0; ?>
        <div class="bar-row">
          <span class="lbl"><?= (int)$r ?> retake<?= (int)$r === 1 ? '' : 's' ?></span>
          <span class="bar"><span style="width: <?= $w ?>%"></span></span>
          <span class="val"><?= nice_num((int)$c) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($s['topLocations']): ?>
<h2>Top locations</h2>
<div class="card">
  <table class="t">
    <tr><th>Lat / Lng (≈1 km grid)</th><th>Posts</th><th></th></tr>
    <?php foreach ($s['topLocations'] as $k => $c):
      [$lat, $lng] = explode(',', (string)$k); ?>
      <tr>
        <td><code><?= h((string)$k) ?></code></td>
        <td><?= nice_num((int)$c) ?></td>
        <td><a href="https://www.openstreetmap.org/?mlat=<?= h($lat) ?>&amp;mlon=<?= h($lng) ?>&amp;zoom=13" target="_blank" rel="noopener noreferrer">view</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
