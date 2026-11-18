<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = clinic_get($id);
if (!$c) { http_response_code(404); exit('Clinic not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? 'save');
    $pdo = db();
    if ($act === 'delete') {
        $pdo->prepare('UPDATE installs SET clinic_id = NULL WHERE clinic_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM clinics WHERE id = ?')->execute([$id]);
        flash('Clinic deleted. Its PCs are now unassigned and use the global settings.');
        redirect('clinics.php');
    }
    if ($act === 'extend') {
        $days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
        $from = max(time(), utc_ts($c['expires_at']) ?? 0);
        $pdo->prepare('UPDATE clinics SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $from + $days * 86400), $id]);
        flash("Every PC in this clinic extended by $days days (PCs with their own earlier date are not affected: see \"Own settings\").");
    } elseif ($act === 'expire_now') {
        $pdo->prepare('UPDATE clinics SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $id]);
        flash('Clinic access ended. Its PCs lock at their next check-in (within ~10 minutes).');
    } elseif ($act === 'no_expiry') {
        $pdo->prepare('UPDATE clinics SET expires_at = NULL WHERE id = ?')->execute([$id]);
        flash('Expiry removed for this clinic.');
    } elseif ($act === 'clear_overrides') {
        $n = $pdo->prepare('UPDATE installs SET expires_at = NULL, url_override = NULL WHERE clinic_id = ?');
        $n->execute([$id]);
        flash('Removed the individual expiry and URL of every PC here. They now follow the clinic.');
    } elseif ($act === 'add') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['pc'] ?? []))));
        if (!$ids) flash('Tick at least one PC to add.', 'err');
        else {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("UPDATE installs SET clinic_id = ?, expires_at = NULL, url_override = NULL WHERE id IN ($in)");
            $st->execute(array_merge([$id], $ids));
            flash(count($ids) . ' PC(s) added. Their own expiry/URL was cleared so the clinic settings apply.');
        }
    } elseif ($act === 'remove') {
        $pc = (int)($_POST['pc'] ?? 0);
        $pdo->prepare('UPDATE installs SET clinic_id = NULL WHERE id = ? AND clinic_id = ?')->execute([$pc, $id]);
        flash('PC removed from the clinic.');
    } else {
        $name = clip($_POST['name'] ?? '', 120);
        $url = normalize_url($_POST['url'] ?? '');
        $exp = parse_local_input($_POST['expires_at'] ?? '');
        if ($name === '') { flash('Enter a clinic name.', 'err'); redirect('clinic.php?id=' . $id); }
        if ($url === null) { flash('That site URL is not valid.', 'err'); redirect('clinic.php?id=' . $id); }
        if (trim((string)($_POST['expires_at'] ?? '')) !== '' && $exp === null) { flash('That expiry date is not valid.', 'err'); redirect('clinic.php?id=' . $id); }
        try {
            $pdo->prepare('UPDATE clinics SET name = ?, status = ?, expires_at = ?, url = ?, message = ?, notes = ? WHERE id = ?')->execute([
                $name,
                ($_POST['status'] ?? '') === 'blocked' ? 'blocked' : 'active',
                $exp,
                $url === '' ? null : $url,
                clip($_POST['message'] ?? '', 500) ?: null,
                utf8_cut((string)($_POST['notes'] ?? ''), 2000) ?: null,
                $id,
            ]);
            flash('Saved. All PCs in this clinic pick it up at their next check-in (within ~10 minutes, or on restart).');
        } catch (PDOException $e) {
            flash('Another clinic already has that name.', 'err');
        }
    }
    redirect('clinic.php?id=' . $id);
}

$now = time();
$members = db()->prepare('SELECT * FROM installs WHERE clinic_id = ? ORDER BY name, device');
$members->execute([$id]);
$members = $members->fetchAll();
$free = db()->query('SELECT * FROM installs WHERE clinic_id IS NULL ORDER BY last_seen DESC LIMIT 500')->fetchAll();
$own = 0;
foreach ($members as $m) if (!empty($m['expires_at']) || !empty($m['url_override'])) $own++;

page_header('Clinic: ' . $c['name'], 'clinics.php');
?>
<h1><?= h($c['name']) ?></h1>
<p class="muted"><a href="clinics.php">&larr; All clinics</a> · <?= count($members) ?> PC(s)</p>

<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save">
  <h2>Settings for every PC in this clinic</h2>
  <div class="grid">
    <div><label>Clinic name</label><input type="text" name="name" value="<?= h($c['name']) ?>" maxlength="120" required></div>
    <div><label>Status</label><select name="status"><option value="active"<?= $c['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="blocked"<?= $c['status'] === 'blocked' ? ' selected' : '' ?>>Blocked (locks all its PCs)</option></select></div>
    <div><label>Access expires (<?= h(cfg()['timezone'] ?? 'UTC') ?>) - empty = never</label><input type="datetime-local" name="expires_at" value="<?= h(input_local($c['expires_at'])) ?>"></div>
    <div><label>Site URL - empty = global default</label><input type="text" name="url" value="<?= h($c['url']) ?>" placeholder="https://..."></div>
  </div>
  <label>Message shown on the lock screen</label><input type="text" name="message" value="<?= h($c['message']) ?>" maxlength="500">
  <label>Private notes</label><textarea name="notes"><?= h($c['notes']) ?></textarea>
  <div class="row"><button class="btn">Save clinic</button></div>
</form>

<div class="box"><h2>Quick actions</h2>
  <form method="post" class="row" style="margin-top:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="extend">
    <button class="btn alt" name="days" value="7">+7 days</button><button class="btn alt" name="days" value="30">+30 days</button><button class="btn alt" name="days" value="90">+90 days</button><button class="btn alt" name="days" value="365">+1 year</button></form>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn alt" name="action" value="no_expiry">Remove expiry</button>
    <button class="btn red" name="action" value="expire_now" data-confirm="Lock every PC in this clinic now?">Expire now</button>
    <button class="btn red" name="action" value="delete" data-confirm="Delete this clinic? Its PCs become unassigned.">Delete clinic</button></form>
</div>

<h2>PCs in this clinic</h2>
<?php if ($own): ?>
<form method="post" class="msg" style="background:#fff1d6;color:#92600a"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="clear_overrides">
  <?= $own ?> PC(s) have their own expiry or URL that overrides the clinic (the earliest expiry always wins).
  <button class="btn alt" style="margin-left:8px" data-confirm="Clear the individual expiry and URL of every PC in this clinic?">Make them all follow the clinic</button></form>
<?php endif; ?>
<table>
<tr><th>PC</th><th>Last seen</th><th>Access until</th><th>Site URL pushed</th><th></th></tr>
<?php foreach ($members as $m):
    $online = ($t = utc_ts($m['last_seen'])) && $now - $t < HMS_ONLINE_MINUTES * 60;
    $u = effective_url($m);
?>
<tr>
  <td><span class="dot<?= $online ? ' on' : '' ?>"></span><b><?= h($m['name'] ?: ($m['device'] ?: 'Unnamed')) ?></b>
    <?php if ($m['status'] === 'blocked'): ?><span class="tag bad">blocked</span><?php endif; ?><?php if (!empty($m['expires_at']) || !empty($m['url_override'])): ?> <span class="tag warn">own settings</span><?php endif; ?>
    <br><span class="muted"><?= h($m['device']) ?> · <?= h($m['ip']) ?></span></td>
  <td><?= h(ago($m['last_seen'])) ?></td>
  <td><?= exp_tag(effective_expiry($m), $now) ?></td>
  <td class="muted"><?= h($u ?: 'installer default') ?></td>
  <td><a class="btn alt" href="install.php?id=<?= (int)$m['id'] ?>">Edit</a>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="remove"><button class="btn alt" name="pc" value="<?= (int)$m['id'] ?>">Remove</button></form></td>
</tr>
<?php endforeach; if (!$members): ?><tr><td colspan="5" class="muted">No PCs yet. Add them below once they have installed the app and checked in.</td></tr><?php endif; ?>
</table>

<form method="post" class="box" style="margin-top:18px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="add">
  <h2>Add PCs that are not in any clinic</h2>
  <?php if (!$free): ?><p class="muted">Nothing to add. New installs appear here after their first check-in.</p><?php else: ?>
  <table>
  <?php foreach ($free as $f): ?>
    <tr><td style="width:30px"><input type="checkbox" name="pc[]" value="<?= (int)$f['id'] ?>"></td>
    <td><b><?= h($f['name'] ?: ($f['device'] ?: 'Unnamed')) ?></b> <span class="muted">· <?= h($f['device']) ?> · <?= h($f['ip']) ?> · first seen <?= h(fmt_local($f['first_seen'])) ?></span></td></tr>
  <?php endforeach; ?></table>
  <div class="row"><button class="btn">Add ticked PCs to <?= h($c['name']) ?></button></div>
  <?php endif; ?>
</form>
<?php page_footer();
