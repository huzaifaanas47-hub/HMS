<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM installs WHERE id = ?');
$st->execute([$id]);
$inst = $st->fetch();
if (!$inst) { http_response_code(404); exit('Install not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? 'save');
    $pdo = db();
    if ($act === 'delete') {
        $pdo->prepare('DELETE FROM installs WHERE id = ?')->execute([$id]);
        flash('Install removed. If the app is still installed it will register again on its next check-in.');
        redirect('index.php');
    }
    if ($act === 'extend') {
        $days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
        $cur = utc_ts($inst['expires_at']);
        if ($cur === null) flash('This install has no end date of its own, so there is nothing to extend. Set a date in "This PC\'s own expiry" if you want one.', 'err');
        else {
            $pdo->prepare('UPDATE installs SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', max(time(), $cur) + $days * 86400), $id]);
            flash("Access extended by $days days.");
        }
    } elseif ($act === 'expire_now') {
        $pdo->prepare('UPDATE installs SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $id]);
        flash('Access ended. The app locks at its next check-in (within ~10 minutes).');
    } elseif ($act === 'no_expiry') {
        $pdo->prepare('UPDATE installs SET expires_at = NULL WHERE id = ?')->execute([$id]);
        flash('Expiry removed for this install.');
    } else {
        $url = normalize_url($_POST['url_override'] ?? '');
        $exp = parse_local_input($_POST['expires_at'] ?? '');
        if ($url === null) { flash('That site URL is not valid.', 'err'); redirect('install.php?id=' . $id); }
        if (trim((string)($_POST['expires_at'] ?? '')) !== '' && $exp === null) { flash('That expiry date is not valid.', 'err'); redirect('install.php?id=' . $id); }
        $newClinic = (int)($_POST['clinic_id'] ?? 0);
        if ($newClinic && !clinic_get($newClinic)) $newClinic = 0;
        $joined = $newClinic && $newClinic !== (int)$inst['clinic_id'];
        if ($joined) { $exp = null; $url = ''; } // joining a clinic: the clinic's expiry and URL take over
        $pdo->prepare('UPDATE installs SET name = ?, status = ?, expires_at = ?, url_override = ?, message = ?, notes = ?, clinic_id = ? WHERE id = ?')->execute([
            clip($_POST['name'] ?? '', 120) ?: null,
            ($_POST['status'] ?? '') === 'blocked' ? 'blocked' : 'active',
            $exp,
            $url === '' ? null : $url,
            clip($_POST['message'] ?? '', 500) ?: null,
            utf8_cut((string)($_POST['notes'] ?? ''), 2000) ?: null,
            $newClinic ?: null,
            $id,
        ]);
        // The old access code only belongs to a device while the device stays in that code's clinic; otherwise the code's date and place no longer apply.
        $pdo->prepare('UPDATE installs SET code_id = NULL WHERE id = ? AND code_id IS NOT NULL AND code_id NOT IN (SELECT id FROM enroll_codes WHERE clinic_id = ?)')->execute([$id, $newClinic]);
        flash(($joined ? 'Moved into the clinic; its expiry and URL now apply. ' : '') . 'Saved. The app picks this up at its next check-in (within ~10 minutes, or on restart).');
    }
    redirect('install.php?id=' . $id);
}

page_header('Manage install', 'index.php');
$e = effective_expiry($inst);
?>
<h1><?= h($inst['name'] ?: ($inst['device'] ?: 'Unnamed install')) ?></h1>
<p class="muted"><a href="index.php">&larr; All installs</a> · Install ID <span class="mono"><?= h($inst['install_id']) ?></span></p>

<?php if ($co = code_get($inst['code_id'] ?? null)): ?><p class="muted">Joined with access code <span class="mono"><?= h(code_pretty($co['code'])) ?></span>. Deleting this device frees its place on that code.</p><?php endif; ?>
<div class="box"><div class="grid">
  <div><b>Device</b><br><?= h($inst['device'] ?: '—') ?> (<?= h($inst['platform']) ?> <?= h($inst['os_release']) ?>)</div>
  <div><b>App version</b><br><?= h($inst['app_version'] ?: '—') ?></div>
  <div style="margin-top:10px"><b>First seen</b><br><?= h(fmt_local($inst['first_seen'])) ?> (<?= (int)$inst['checkins'] ?> check-ins)</div>
  <div style="margin-top:10px"><b>Last seen</b><br><?= h(fmt_local($inst['last_seen'])) ?> · <?= h(ago($inst['last_seen'])) ?> · <?= h($inst['ip']) ?></div>
</div></div>

<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save">
  <h2>Control</h2>
  <div class="grid">
    <div><label>Label (your own name for this PC / clinic)</label><input type="text" name="name" value="<?= h($inst['name']) ?>" maxlength="120"></div>
    <div><label>Clinic</label><select name="clinic_id"><option value="0">- none (global settings) -</option><?php foreach (clinics_all() as $cl): ?><option value="<?= (int)$cl['id'] ?>"<?= (int)$inst['clinic_id'] === (int)$cl['id'] ? ' selected' : '' ?>><?= h($cl['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Status</label><select name="status"><option value="active"<?= $inst['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="blocked"<?= $inst['status'] === 'blocked' ? ' selected' : '' ?>>Blocked (locks the app)</option></select></div>
    <div><label>This PC's own expiry (<?= h(cfg()['timezone'] ?? 'UTC') ?>) - empty = follow the clinic</label><input type="datetime-local" name="expires_at" value="<?= h(input_local($inst['expires_at'])) ?>"></div>
    <div><label>This PC's own site URL - empty = follow the clinic / global default</label><input type="text" name="url_override" value="<?= h($inst['url_override']) ?>" placeholder="https://..."></div>
  </div>
  <label>Message shown to the user (on the lock screen)</label><input type="text" name="message" value="<?= h($inst['message']) ?>" maxlength="500">
  <label>Private notes</label><textarea name="notes"><?= h($inst['notes']) ?></textarea>
  <?php if ($e !== null && ($inst['expires_at'] === null || $e < (utc_ts($inst['expires_at']) ?? PHP_INT_MAX))): ?>
    <p class="muted">The global expiry (<?= h(fmt_local(gmdate('Y-m-d H:i:s', $e))) ?>) is earlier than this install's own date and applies first.</p>
  <?php endif; ?>
  <div class="row"><button class="btn">Save changes</button></div>
</form>

<div class="box"><h2>Quick actions</h2>
  <form method="post" class="row" style="margin-top:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="extend">
    <button class="btn alt" name="days" value="7">+7 days</button><button class="btn alt" name="days" value="30">+30 days</button><button class="btn alt" name="days" value="90">+90 days</button><button class="btn alt" name="days" value="365">+1 year</button></form>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn alt" name="action" value="no_expiry">Remove expiry</button>
    <button class="btn red" name="action" value="expire_now" data-confirm="Lock this install now?">Expire now</button>
    <button class="btn red" name="action" value="delete" data-confirm="Remove this install record?">Delete record</button></form>
</div>
<?php page_footer();
