<?php
require __DIR__ . '/_lib.php';

$comments = comments();
$q = trim((string)($_GET['q'] ?? ''));

if ($q !== '') {
    $needle = mb_strtolower($q);
    $comments = array_values(array_filter($comments, function ($c) use ($needle) {
        return str_contains(mb_strtolower((string)($c['content'] ?? '')), $needle)
            || str_contains(mb_strtolower((string)($c['postId']  ?? '')), $needle);
    }));
}

$totalChars   = 0;
$mentionCount = 0;
$exclam       = 0;
$questions    = 0;
$longest      = ['content' => '', 'len' => 0];

foreach ($comments as $c) {
    $t = (string)($c['content'] ?? '');
    $totalChars += mb_strlen($t);
    if (preg_match_all('/@[A-Za-z0-9._-]+/u', $t, $m)) $mentionCount += count($m[0]);
    if (str_contains($t, '!')) $exclam++;
    if (str_contains($t, '?')) $questions++;
    if (mb_strlen($t) > $longest['len']) {
        $longest = ['content' => $t, 'len' => mb_strlen($t)];
    }
}
$avg = $comments ? $totalChars / count($comments) : 0;

// Top-10 posts by comment volume (postId is opaque but unique).
$byPost = [];
foreach ($comments as $c) {
    $pid = (string)($c['postId'] ?? '?');
    $byPost[$pid] = ($byPost[$pid] ?? 0) + 1;
}
arsort($byPost);
$topPosts = array_slice($byPost, 0, 10, true);

render_header('/comments.php', 'Comments');
?>
<h1>Comments</h1>
<p class="muted">
  <?= nice_num(count($comments)) ?> comments authored
  <?= $q !== '' ? ' matching “' . h($q) . '”' : '' ?>
</p>

<form class="controls" method="get">
  <label>Search<input type="text" name="q" value="<?= h($q) ?>" placeholder="word, @mention or postId"></label>
  <button class="btn" type="submit">Filter</button>
  <?php if ($q !== ''): ?><a class="btn" href="?">clear</a><?php endif; ?>
</form>

<div class="grid">
  <div class="stat"><div class="k">Comments</div><div class="v"><?= nice_num(count($comments)) ?></div></div>
  <div class="stat"><div class="k">@-mentions</div><div class="v"><?= nice_num($mentionCount) ?></div></div>
  <div class="stat"><div class="k">Avg length</div><div class="v"><?= number_format($avg, 1) ?> ch</div></div>
  <div class="stat">
    <div class="k">Longest</div>
    <div class="v"><?= nice_num($longest['len']) ?> ch</div>
    <div class="sub" title="<?= h($longest['content']) ?>"><?= h(mb_substr($longest['content'], 0, 60)) ?><?= mb_strlen($longest['content']) > 60 ? '…' : '' ?></div>
  </div>
  <div class="stat"><div class="k">Excited (!)</div><div class="v"><?= nice_num($exclam) ?></div></div>
  <div class="stat"><div class="k">Questions (?)</div><div class="v"><?= nice_num($questions) ?></div></div>
</div>

<?php if ($topPosts): ?>
<h2>Most-commented posts (by you)</h2>
<div class="card">
  <table class="t">
    <tr><th>postId</th><th>Comments authored</th></tr>
    <?php foreach ($topPosts as $pid => $c): ?>
      <tr><td><code><?= h((string)$pid) ?></code></td><td><?= nice_num((int)$c) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<h2>Recent comments</h2>
<div class="card">
  <table class="t">
    <tr><th>postId</th><th>Content</th></tr>
    <?php foreach (array_slice(array_reverse($comments), 0, 200) as $c):
        $pid = (string)($c['postId'] ?? ''); ?>
      <tr>
        <td><code style="font-size: 11px;"><?= h(mb_substr($pid, 0, 12)) ?><?= mb_strlen($pid) > 12 ? '…' : '' ?></code></td>
        <td><?= h((string)($c['content'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php render_footer(); ?>
