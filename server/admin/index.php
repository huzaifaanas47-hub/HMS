<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

$s = settings();
$q = clip($_GET['q'] ?? '', 80);
$sql = 'SELECT * FROM installs';
$args = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' WHERE name LIKE ? OR device LIKE ? OR install_id LIKE ? OR ip LIKE ?';
    $args = [$like, $like, $like, $like];
}
$st = db()->prepare($sql . ' ORDER BY last_seen DESC LIMIT 1000');
$st->execute($args);
$rows = $st->fetchAll();

$now = time();
$stats = ['total' => 0, 'online' => 0, 'blocked' => 0, 'expired' => 0, 'soon' => 0];
foreach (db()->query('SELECT * FROM installs') as $r) {
    $stats['total']++;
    if (($t = utc_ts($r['last_seen'])) && $now - $t < HMS_ONLINE_MINUTES * 60) $stats['online']++;
    if ($r['status'] === 'blocked') $stats['blocked']++;
    $e = effective_expiry($r);
    if ($e !== null && $e <= $now) $stats['expired']++;
    elseif ($e !== null && $e <= $now + 7 * 86400) $stats['soon']++;
}

page_header('Installs', 'index.php');
?>
<h1>Installs</h1>
<div class="cards">
  <div class="card"><b><?= $stats['total'] ?></b><span>Total installs</span></div>
  <div class="card"><b><?= $stats['online'] ?></b><span>Online now</span></div>
  <div class="card"><b><?= $stats['soon'] ?></b><span>Expire within 7 days</span></div>
  <div class="card"><b><?= $stats['expired'] ?></b><span>Expired</span></div>
  <div class="card"><b><?= $stats['blocked'] ?></b><span>Blocked</span></div>
</div>
<form method="get" class="row" style="margin:0 0 12px"><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, device, install ID or IP" style="max-width:340px"><button class="btn alt">Search</button></form>
<table>
<tr><th>Install</th><th>System</th><th>Last seen</th><th>Access until</th><th>Site URL pushed</th><th></th></tr>
<?php foreach ($rows as $r):
    $online = ($t = utc_ts($r['last_seen'])) && $now - $t < HMS_ONLINE_MINUTES * 60;
    $e = effective_expiry($r);
    $url = $r['url_override'] !== null && $r['url_override'] !== '' ? $r['url_override'] : $s['default_url'];
?>
<tr>
  <td><span class="dot<?= $online ? ' on' : '' ?>"></span><b><?= h($r['name'] ?: ($r['device'] ?: 'Unnamed')) ?></b>
      <?php if ($r['status'] === 'blocked'): ?><span class="tag bad">blocked</span><?php endif; ?><br><span class="muted mono"><?= h(substr($r['install_id'], 0, 8)) ?></span></td>
  <td><?= h($r['platform']) ?> <?= h($r['os_release']) ?><br><span class="muted">v<?= h($r['app_version']) ?> · <?= h($r['ip']) ?></span></td>
  <td><?= h(ago($r['last_seen'])) ?><br><span class="muted"><?= h(fmt_local($r['last_seen'])) ?></span></td>
  <td><?php if ($e === null): ?><span class="tag ok">no expiry</span><?php elseif ($e <= $now): ?><span class="tag bad">expired <?= h(fmt_local(gmdate('Y-m-d H:i:s', $e))) ?></span><?php else: ?><span class="tag<?= $e <= $now + 7 * 86400 ? ' warn' : '' ?>"><?= h(fmt_local(gmdate('Y-m-d H:i:s', $e))) ?></span><?php endif; ?></td>
  <td class="muted"><?= $url !== '' ? h($url) : 'installer default' ?><?= $r['url_override'] ? ' <span class="tag">override</span>' : '' ?></td>
  <td><a class="btn alt" href="install.php?id=<?= (int)$r['id'] ?>">Manage</a></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">No installs yet. They appear here the first time the app checks in.</td></tr><?php endif; ?>
</table>
<p class="muted">Times shown in <?= h(cfg()['timezone'] ?? 'UTC') ?>. "Online" means checked in within the last <?= HMS_ONLINE_MINUTES ?> minutes (the app checks in every 10).</p>
<?php page_footer();
