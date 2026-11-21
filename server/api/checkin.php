<?php
declare(strict_types=1);
/* Called by the HMS desktop app on start-up and every ~10 minutes.
   Registers the install on first contact and returns a signed policy. */
require __DIR__ . '/../lib/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('{"error":"method not allowed"}'); }

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
$installId = is_array($in) && is_string($in['installId'] ?? null) ? strtolower($in['installId']) : '';
$nonce = is_array($in) && is_string($in['nonce'] ?? null) ? $in['nonce'] : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $installId) || !preg_match('/^[0-9a-f]{16,64}$/', $nonce)) {
    http_response_code(400);
    exit('{"error":"bad request"}');
}

$pdo = db();
$now = gmdate('Y-m-d H:i:s');
$ip = clip($_SERVER['REMOTE_ADDR'] ?? '', 45);

$q = $pdo->prepare('SELECT * FROM installs WHERE install_id = ?');
$q->execute([$installId]);
$inst = $q->fetch();

if (!$inst && settings()['require_code'] === '1') { // join with an access code first (api/enroll.php); nothing is registered yet
    echo json_encode(sign_envelope(build_unenrolled_policy($installId, $nonce)), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$inst) { // first contact: register, applying the "new installs expire after N days" setting
    $days = (int)settings()['default_expiry_days'];
    $exp = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
    $pdo->prepare('INSERT IGNORE INTO installs (install_id, first_seen, last_seen, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$installId, $now, $now, $exp]);
}

$pdo->prepare('UPDATE installs SET device = ?, platform = ?, os_release = ?, app_version = ?, ip = ?, last_seen = ?, checkins = checkins + 1 WHERE install_id = ?')
    ->execute([clip($in['device'] ?? '', 120), clip($in['platform'] ?? '', 20), clip($in['osRelease'] ?? '', 60), clip($in['appVersion'] ?? '', 20), $ip, $now, $installId]);

$q->execute([$installId]);
$inst = $q->fetch();

store_error_reports($inst, is_array($in) ? ($in['errors'] ?? null) : null);
cleanup_stale_installs();
$arch = is_array($in) && is_string($in['arch'] ?? null) ? clip($in['arch'], 12) : '';
echo json_encode(sign_envelope(build_policy($inst, $nonce, $arch)), JSON_UNESCAPED_SLASHES);
