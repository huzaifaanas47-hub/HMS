<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'password') {
        $st = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $new = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$st->fetchColumn())) flash('Current password is wrong.', 'err');
        elseif (strlen($new) < 10) flash('New password must be at least 10 characters.', 'err');
        elseif ($new !== (string)($_POST['new_password2'] ?? '')) flash('The two new passwords do not match.', 'err');
        else {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            flash('Password changed.');
        }
        redirect('settings.php');
    }
    $url = normalize_url($_POST['default_url'] ?? '');
    $gexp = parse_local_input($_POST['global_expires_at'] ?? '');
    if ($url === null) { flash('That default site URL is not valid.', 'err'); redirect('settings.php'); }
    if (trim((string)($_POST['global_expires_at'] ?? '')) !== '' && $gexp === null) { flash('That global expiry date is not valid.', 'err'); redirect('settings.php'); }
    setting_set('require_code', isset($_POST['require_code']) ? '1' : '0');
    setting_set('default_url', $url);
    setting_set('default_expiry_days', (string)max(0, min(3650, (int)($_POST['default_expiry_days'] ?? 0))));
    setting_set('global_expires_at', $gexp ?? '');
    setting_set('global_message', clip($_POST['global_message'] ?? '', 500));
    setting_set('offline_grace_days', (string)max(0, min(365, (int)($_POST['offline_grace_days'] ?? 14))));
    setting_set('stale_delete_days', (string)max(0, min(3650, (int)($_POST['stale_delete_days'] ?? 0))));
    flash('Settings saved. Every installed app picks them up at its next check-in (within ~10 minutes).');
    redirect('settings.php');
}

$s = settings();
page_header('Settings', 'settings.php');
?>
<h1>Settings</h1>
<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2>Applies to every install</h2>
  <label>Default site URL - pushed to all installed apps (empty = keep whatever each user typed in the installer)</label>
  <input type="text" name="default_url" value="<?= h($s['default_url']) ?>" placeholder="https://yourhospital.com">
  <label style="display:flex;gap:8px;align-items:flex-start;margin-top:14px"><input type="checkbox" name="require_code" value="1"<?= $s['require_code'] === '1' ? ' checked' : '' ?> style="margin-top:3px">
    <span><b>Require an access code to join.</b> New apps must enter a code from <a href="codes.php">Codes</a> before they work. PCs already listed keep working; deleting a PC from the list revokes it. Turn this on once your users have the latest app.</span></label>
  <div class="grid">
    <div><label>New installs expire after N days (0 = never)</label><input type="number" name="default_expiry_days" min="0" max="3650" value="<?= h($s['default_expiry_days']) ?>"></div>
    <div><label>Global expiry (<?= h(cfg()['timezone'] ?? 'UTC') ?>) - ends access for EVERYONE at this moment</label><input type="datetime-local" name="global_expires_at" value="<?= h(input_local($s['global_expires_at'])) ?>"></div>
    <div><label>Lock the app if it cannot reach this server for N days (0 = never)</label><input type="number" name="offline_grace_days" min="0" max="365" value="<?= h($s['offline_grace_days']) ?>"></div>
    <div><label>Remove devices not seen for N days (0 = never). Frees their place on the access code, e.g. after an uninstall</label><input type="number" name="stale_delete_days" min="0" max="3650" value="<?= h($s['stale_delete_days']) ?>"></div>
    <div><label>Message shown on the lock screen (unless an install has its own)</label><input type="text" name="global_message" value="<?= h($s['global_message']) ?>" maxlength="500"></div>
  </div>
  <div class="row"><button class="btn">Save settings</button></div>
</form>

<div class="box"><h2>Put these in the desktop app's <code>app-config.json</code></h2>
  <p class="muted">Then rebuild the installer. The public key lets the app verify that policies really come from this server.</p>
  <label>policyUrl</label><div class="mono"><?= h(policy_url()) ?></div>
  <label>publicKey</label><div class="mono"><?= h(public_key_b64()) ?></div>
</div>

<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="password">
  <h2>Change admin password</h2>
  <div class="grid"><div><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div><div></div>
  <div><label>New password (10+ characters)</label><input type="password" name="new_password" required autocomplete="new-password"></div>
  <div><label>Repeat new password</label><input type="password" name="new_password2" required autocomplete="new-password"></div></div>
  <div class="row"><button class="btn alt">Change password</button></div>
</form>
<?php page_footer();
