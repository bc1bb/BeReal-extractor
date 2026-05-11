<?php
require __DIR__ . '/_lib.php';

$friends = friends();
usort($friends, fn($a, $b) => strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? '')));

// Count @-mentions of each username inside the export owner's comments.
$mentions = [];
foreach (comments() as $c) {
    $content = (string)($c['content'] ?? '');
    if (preg_match_all('/@([A-Za-z0-9._-]+)/', $content, $m)) {
        foreach ($m[1] as $u) {
            $u = strtolower($u);
            $mentions[$u] = ($mentions[$u] ?? 0) + 1;
        }
    }
}

render_header('/friends.php', 'Friends');
?>
<h1>Friends</h1>
<p class="muted"><?= nice_num(count($friends)) ?> friends · oldest first</p>

<?php if (!$friends): ?>
<div class="card"><span class="muted">No friend records found in this export.</span></div>
<?php else: ?>
<div class="card">
  <table class="t">
    <tr><th>#</th><th>Username</th><th>Display name</th><th>Added</th><th>You @mentioned</th></tr>
    <?php foreach ($friends as $i => $f):
        $u = (string)($f['friendUsername'] ?? '');
        $m = $mentions[strtolower($u)] ?? 0; ?>
      <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td><code>@<?= h($u) ?></code></td>
        <td><?= h((string)($f['friendFullname'] ?? '')) ?></td>
        <td><?= h(fmt_date($f['createdAt'] ?? '', 'Y-m-d')) ?></td>
        <td><?= $m > 0 ? '<strong>' . nice_num($m) . '</strong>' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php $bl = blocked(); if ($bl): ?>
  <h2>Blocked users</h2>
  <div class="card">
    <table class="t">
      <tr><th>User ID</th><th>Blocked at</th></tr>
      <?php foreach ($bl as $b): ?>
        <tr>
          <td><code><?= h((string)($b['userId'] ?? '')) ?></code></td>
          <td><?= h(fmt_date($b['blockedAt'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
