<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['pc'] ?? []))));
    $cid = (int)($_POST['clinic_id'] ?? 0);
    if (!$ids) flash('Tick at least one PC.', 'err');
    elseif ($cid && !clinic_get($cid)) flash('Unknown clinic.', 'err');
    else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($cid) { db()->prepare("UPDATE installs SET clinic_id = ?, expires_at = NULL, url_override = NULL WHERE id IN ($in)")->execute(array_merge([$cid], $ids)); flash(count($ids) . ' PC(s) moved into ' . clinic_get($cid)['name'] . '. The clinic expiry and URL now apply to them.'); }
        else { db()->prepare("UPDATE installs SET clinic_id = NULL WHERE id IN ($in)")->execute($ids); flash(count($ids) . ' PC(s) removed from their clinic.'); }
        // A device keeps its access code only while it stays in that code's clinic; moved devices stop counting on, and following the dates of, their old code.
        db()->prepare("UPDATE installs SET code_id = NULL WHERE id IN ($in) AND code_id IS NOT NULL AND code_id NOT IN (SELECT id FROM enroll_codes WHERE clinic_id = ?)")->execute(array_merge($ids, [$cid]));
    }
    $keep = array_filter(['q' => (string)($_GET['q'] ?? ''), 'clinic' => $_GET['clinic'] ?? '', 'code' => (string)($_GET['code'] ?? '')], fn($v) => $v !== '');
    redirect('index.php' . ($keep ? '?' . http_build_query($keep) : ''));
}

$s = settings();
$q = clip($_GET['q'] ?? '', 80);
$sql = 'SELECT * FROM installs';
$args = [];
$where = [];
$fc = isset($_GET['clinic']) && $_GET['clinic'] !== '' ? (int)$_GET['clinic'] : null; // 0 = unassigned
$fcode = (int)($_GET['code'] ?? 0);
if ($fcode) { $where[] = 'code_id = ?'; $args[] = $fcode; }
if ($fc === 0) $where[] = 'clinic_id IS NULL';
elseif ($fc !== null) { $where[] = 'clinic_id = ?'; $args[] = $fc; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(name LIKE ? OR device LIKE ? OR install_id LIKE ? OR ip LIKE ?)';
    array_push($args, $like, $like, $like, $like);
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$st = db()->prepare($sql . ' ORDER BY last_seen DESC LIMIT 1000');
$st->execute($args);
$rows = $st->fetchAll();

$now = time();
$stats = ['total' => 0, 'online' => 0, 'blocked' => 0, 'expired' => 0, 'soon' => 0];
foreach (db()->query('SELECT status, clinic_id, code_id, expires_at, last_seen FROM installs') as $r) {
    $stats['total']++;
    if (($t = utc_ts($r['last_seen'])) && $now - $t < HMS_ONLINE_MINUTES * 60) $stats['online']++;
    if (effective_status($r) === 'blocked') $stats['blocked']++;
    $e = effective_expiry($r);
    if ($e !== null && $e <= $now) $stats['expired']++;
    elseif ($e !== null && $e <= $now + 7 * 86400) $stats['soon']++;
}

page_header('Devices', 'index.php');
?>
<h1>All devices<?= $fcode && ($cc = code_get($fcode)) ? ' that joined with ' . h(code_pretty($cc['code'])) : '' ?></h1>
<div class="cards">
  <div class="card"><b><?= $stats['total'] ?></b><span>Total installs</span></div>
  <div class="card"><b><?= $stats['online'] ?></b><span>Online now</span></div>
  <div class="card"><b><?= $stats['soon'] ?></b><span>Expire within 7 days</span></div>
  <div class="card"><b><?= $stats['expired'] ?></b><span>Expired</span></div>
  <div class="card"><b><?= $stats['blocked'] ?></b><span>Blocked</span></div>
</div>
<form method="get" class="row" style="margin:0 0 12px"><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, device, install ID or IP" style="max-width:340px">
  <select name="clinic" style="max-width:220px"><option value="">All clinics</option><option value="0"<?= $fc === 0 ? ' selected' : '' ?>>Not in a clinic</option><?php foreach (clinics_all() as $cl): ?><option value="<?= (int)$cl['id'] ?>"<?= $fc === (int)$cl['id'] ? ' selected' : '' ?>><?= h($cl['name']) ?></option><?php endforeach; ?></select>
  <button class="btn alt">Filter</button></form>
<form method="post" action="index.php<?= $_SERVER['QUERY_STRING'] !== '' ? '?' . h($_SERVER['QUERY_STRING']) : '' ?>"><?= csrf_field() ?>
<table>
<tr><th style="width:26px"></th><th>PC</th><th>Clinic</th><th>System</th><th>Last seen</th><th>Access until</th><th>Site URL pushed</th><th></th></tr>
<?php foreach ($rows as $r):
    $online = ($t = utc_ts($r['last_seen'])) && $now - $t < HMS_ONLINE_MINUTES * 60;
    $e = effective_expiry($r);
    $url = effective_url($r);
    $cl = clinic_get($r['clinic_id']);
?>
<tr>
  <td><input type="checkbox" name="pc[]" value="<?= (int)$r['id'] ?>"></td>
  <td><span class="dot<?= $online ? ' on' : '' ?>"></span><b><?= h($r['name'] ?: ($r['device'] ?: 'Unnamed')) ?></b>
      <?php if ($r['status'] === 'blocked'): ?><span class="tag bad">blocked</span><?php endif; ?><br><span class="muted mono"><?= h(substr($r['install_id'], 0, 8)) ?></span></td>
  <td><?= $cl ? '<a href="clinic.php?id=' . (int)$cl['id'] . '">' . h($cl['name']) . '</a>' : '<span class="muted">none</span>' ?></td>
  <td><?= h($r['platform']) ?> <?= h($r['os_release']) ?><br><span class="muted">v<?= h($r['app_version']) ?> · <?= h($r['ip']) ?></span></td>
  <td><?= h(ago($r['last_seen'])) ?><br><span class="muted"><?= h(fmt_local($r['last_seen'])) ?></span></td>
  <td><?php if ($e === null): ?><span class="tag ok">no expiry</span><?php elseif ($e <= $now): ?><span class="tag bad">expired <?= h(fmt_local(gmdate('Y-m-d H:i:s', $e))) ?></span><?php else: ?><span class="tag<?= $e <= $now + 7 * 86400 ? ' warn' : '' ?>"><?= h(fmt_local(gmdate('Y-m-d H:i:s', $e))) ?></span><?php endif; ?></td>
  <td class="muted"><?= $url !== '' ? h($url) : 'installer default' ?><?= $r['url_override'] ? ' <span class="tag">override</span>' : '' ?></td>
  <td><a class="btn alt" href="install.php?id=<?= (int)$r['id'] ?>">Manage</a></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No PCs yet. They appear here the first time the app checks in.</td></tr><?php endif; ?>
</table>
<div class="row"><select name="clinic_id" style="max-width:240px"><option value="0">- remove from clinic -</option><?php foreach (clinics_all() as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= h($cl['name']) ?></option><?php endforeach; ?></select>
  <button class="btn" data-confirm="Move the ticked PCs? Their own expiry date and URL are cleared, and an access code from another clinic is detached from them.">Move ticked PCs to this clinic</button></div>
</form>
<p class="muted">Times shown in <?= h(cfg()['timezone'] ?? 'UTC') ?>. "Online" means checked in within the last <?= HMS_ONLINE_MINUTES ?> minutes (the app checks in every 10).</p>
<?php page_footer();
