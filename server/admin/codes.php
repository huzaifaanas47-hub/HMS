<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    $pdo = db();
    if ($act === 'create') {
        $cid = (int)($_POST['clinic_id'] ?? 0);
        $max = max(1, min(100000, (int)($_POST['max_uses'] ?? 1)));
        $exp = parse_local_input($_POST['expires_at'] ?? '');
        if (!clinic_get($cid)) flash('Choose a clinic first. Create one under Clinics if you have none.', 'err');
        elseif (trim((string)($_POST['expires_at'] ?? '')) !== '' && $exp === null) flash('That expiry date is not valid.', 'err');
        else {
            $done = false;
            for ($i = 0; $i < 5 && !$done; $i++) {
                try {
                    $code = code_generate();
                    $pdo->prepare('INSERT INTO enroll_codes (code, clinic_id, note, max_uses, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$code, $cid, clip($_POST['note'] ?? '', 120) ?: null, $max, $exp, gmdate('Y-m-d H:i:s')]);
                    flash('Code ' . code_pretty($code) . ' created. Give it to the users together with the download link.');
                    log_activity('Code created', code_pretty($code) . ' for ' . clinic_get($cid)['name'] . ', up to ' . $max . ' devices');
                    $done = true;
                } catch (PDOException $e) {
                    if ((string)$e->getCode() !== '23000') { flash('The code could not be saved: ' . $e->getMessage(), 'err'); $done = true; } // only a duplicate code is retried
                }
            }
            if (!$done) flash('Could not make a unique code. Try again.', 'err');
        }
        redirect('codes.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    $row = code_get($id);
    if (!$row) { flash('Code not found.', 'err'); redirect('codes.php'); }
    if ($act === 'delete') {
        $pdo->prepare('UPDATE installs SET code_id = NULL WHERE code_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM enroll_codes WHERE id = ?')->execute([$id]);
        log_activity('Code deleted', code_pretty($row['code']));
        flash('Code deleted. Devices that used it keep working under their clinic, but lose the code\'s expiry date.');
    } elseif ($act === 'extend') {
        $days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
        $cur = utc_ts($row['expires_at']);
        if ($cur === null) flash('This code has no end date, so there is nothing to extend. Use Edit to set an end date if you want one.', 'err');
        else {
            $pdo->prepare('UPDATE enroll_codes SET expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', max(time(), $cur) + $days * 86400), $id]);
            log_activity('Code extended', code_pretty($row['code']) . ': +' . $days . ' days');
            flash("Code extended by $days days. Every device that joined with it gets the new date at its next check-in.");
        }
    } else { // save
        $exp = parse_local_input($_POST['expires_at'] ?? '');
        if (trim((string)($_POST['expires_at'] ?? '')) !== '' && $exp === null) { flash('That expiry date is not valid.', 'err'); redirect('codes.php'); }
        $max = max(1, min(100000, (int)($_POST['max_uses'] ?? 1)));
        $joined = $pdo->prepare('SELECT COUNT(*) FROM installs WHERE code_id = ?');
        $joined->execute([$id]);
        if ($max < (int)$joined->fetchColumn()) { flash('The limit cannot be lower than the ' . (int)$joined->fetchColumn() . ' devices that already joined. To stop new devices, switch off "Accept new devices" instead.', 'err'); redirect('codes.php'); }
        $pdo->prepare('UPDATE enroll_codes SET max_uses = ?, expires_at = ?, is_open = ?, note = ? WHERE id = ?')->execute([
            $max, $exp, isset($_POST['is_open']) ? 1 : 0, clip($_POST['note'] ?? '', 120) ?: null, $id]);
        log_activity('Code changed', code_pretty($row['code']) . ': limit ' . $max . ', ' . (isset($_POST['is_open']) ? 'open' : 'closed') . ($exp ? ', until ' . $exp . ' UTC' : ', no end date'));
        flash('Saved. Devices that already joined follow the new date at their next check-in.');
    }
    redirect('codes.php');
}

$now = time();
$used = [];
foreach (db()->query('SELECT code_id, COUNT(*) n FROM installs WHERE code_id IS NOT NULL GROUP BY code_id') as $r) $used[(int)$r['code_id']] = (int)$r['n'];
$codes = db()->query('SELECT * FROM enroll_codes ORDER BY id DESC')->fetchAll();
$pre = (int)($_GET['clinic'] ?? 0);
$dl = base_dir_url() . '/download.php';
$req = settings()['require_code'] === '1';

page_header('Access codes', 'codes.php');
?>
<h1>Access codes</h1>
<p class="muted">A code puts a new device straight into a clinic: it gets the clinic's site URL and access date, with nothing for you to assign by hand. Works for Windows, Mac and Android.
You choose how many devices may join and until when. Both can be changed later.</p>
<?php if (!$req): ?><div class="msg" style="background:#fff1d6;color:#92600a">Codes are optional right now: apps can still join without one. Turn on <b>Require an access code</b> in <a href="settings.php">Settings</a> once your users have the latest app.</div><?php endif; ?>

<table>
<tr><th>Code</th><th>Clinic</th><th>Devices</th><th>Access until</th><th>New devices</th><th></th></tr>
<?php foreach ($codes as $c):
    $cl = clinic_get($c['clinic_id']); $n = $used[(int)$c['id']] ?? 0;
    $e = $c['expires_at'] ? utc_ts($c['expires_at']) : null;
?>
<tr>
  <td><span class="mono" style="font-size:15px;font-weight:700"><?= h(code_pretty($c['code'])) ?></span><br><span class="muted"><?= h($c['note']) ?></span></td>
  <td><?= $cl ? '<a href="clinic.php?id=' . (int)$cl['id'] . '">' . h($cl['name']) . '</a>' : '<span class="tag bad">clinic deleted</span>' ?></td>
  <td><a href="index.php?code=<?= (int)$c['id'] ?>"><?= $n ?> of <?= (int)$c['max_uses'] ?></a><?= $n >= (int)$c['max_uses'] ? ' <span class="tag warn">full</span>' : '' ?></td>
  <td><?= exp_tag($e, $now) ?></td>
  <td><?= (int)$c['is_open'] ? '<span class="tag ok">open</span>' : '<span class="tag">closed</span>' ?></td>
  <td><details><summary class="btn alt" style="display:inline-block;cursor:pointer">Edit</summary>
    <form method="post" class="box" style="margin:8px 0 0;min-width:280px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <label>Most devices that may join</label><input type="number" name="max_uses" min="1" max="100000" value="<?= (int)$c['max_uses'] ?>">
      <label>Access until (<?= h(cfg()['timezone'] ?? 'UTC') ?>) - empty = no end</label><input type="datetime-local" name="expires_at" value="<?= h(input_local($c['expires_at'])) ?>">
      <label>Note</label><input type="text" name="note" maxlength="120" value="<?= h($c['note']) ?>">
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_open" value="1"<?= (int)$c['is_open'] ? ' checked' : '' ?>> Accept new devices</label>
      <div class="row"><button class="btn" name="action" value="save">Save</button>
        <button class="btn red" name="action" value="delete" data-confirm="Delete this code? Its devices keep working under the clinic.">Delete</button></div>
    </form>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="extend">
      <button class="btn alt" name="days" value="30">+30 days</button><button class="btn alt" name="days" value="90">+90 days</button></form></details>
    <details style="margin-top:6px"><summary class="btn alt" style="display:inline-block;cursor:pointer">QR</summary>
      <div class="box" style="margin:8px 0 0;text-align:center"><div data-qr="<?= h(base_dir_url() . '/join.php?code=' . $c['code']) ?>" style="width:190px;margin:auto"></div><p class="muted">Show this to the user. They scan it with the phone camera and tap the link: HMS opens with the code filled in.</p></div></details></td>
</tr>
<?php endforeach; if (!$codes): ?><tr><td colspan="6" class="muted">No codes yet. Create one below.</td></tr><?php endif; ?>
</table>

<form method="post" class="box" style="margin-top:18px"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2>New code</h2>
  <div class="grid">
    <div><label>Clinic or hospital</label><select name="clinic_id" required><option value="">Choose...</option>
      <?php foreach (clinics_all() as $cl): ?><option value="<?= (int)$cl['id'] ?>"<?= $pre === (int)$cl['id'] ? ' selected' : '' ?>><?= h($cl['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>How many devices may join</label><input type="number" name="max_uses" min="1" max="100000" value="10" required></div>
    <div><label>Access until (<?= h(cfg()['timezone'] ?? 'UTC') ?>) - empty = no end</label><input type="datetime-local" name="expires_at"></div>
    <div><label>Note for yourself</label><input type="text" name="note" maxlength="120" placeholder="e.g. Front desk and pharmacy"></div>
  </div>
  <div class="row"><button class="btn">Create code</button></div>
</form>

<div class="box"><h2>What to send to your users</h2>
  <p>1. The download link: <a href="<?= h($dl) ?>"><span class="mono"><?= h($dl) ?></span></a><br>2. Their access code. They type it the first time they open HMS.</p>
  <p class="muted">The clinic's own expiry also applies; the earliest date always wins. A code that has expired stops new devices from joining. Deleting a device under <a href="index.php">PCs</a> frees its place on the code (and, with "Require an access code" on, locks that device until it joins again).</p>
</div>
<?php $qrLib = @file_get_contents(__DIR__ . '/../lib/qrcode.js'); if ($qrLib): // draws the QR codes in the browser (MIT library, no outside request) ?>
<script nonce="<?= csp_nonce() ?>"><?= $qrLib ?></script>
<script nonce="<?= csp_nonce() ?>">document.querySelectorAll('[data-qr]').forEach(function(el){try{var q=qrcode(0,'M');q.addData(el.getAttribute('data-qr'));q.make();el.innerHTML=q.createSvgTag(5,2);}catch(e){}});</script>
<?php endif; ?>
<?php page_footer();
