<?php
declare(strict_types=1);
/* A new app install joins a clinic with an access code. Answers with a signed confirmation;
   the app then checks in normally and receives the clinic's URL and expiry. */
require __DIR__ . '/../lib/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function enroll_fail(int $status, string $msg): void { http_response_code($status); echo json_encode(['error' => $msg]); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') enroll_fail(405, 'Method not allowed.');

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
$installId = is_array($in) && is_string($in['installId'] ?? null) ? strtolower($in['installId']) : '';
$nonce = is_array($in) && is_string($in['nonce'] ?? null) ? $in['nonce'] : '';
$code = code_normalize(is_array($in) ? ($in['code'] ?? '') : '');
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $installId) || !preg_match('/^[0-9a-f]{16,64}$/', $nonce)) enroll_fail(400, 'Bad request.');
if (!preg_match('/^[A-Z0-9]{8}$/', $code)) enroll_fail(422, 'Enter the 8-character code from your administrator.');

$pdo = db();
$ip = clip($_SERVER['REMOTE_ADDR'] ?? '', 45);
$now = gmdate('Y-m-d H:i:s');

$tries = $pdo->prepare('SELECT COUNT(*) FROM enroll_attempts WHERE ip = ? AND at > ?');
$tries->execute([$ip, gmdate('Y-m-d H:i:s', time() - 900)]);
if ((int)$tries->fetchColumn() >= 10) enroll_fail(429, 'Too many wrong codes. Try again in 15 minutes.');
$pdo->exec("DELETE FROM enroll_attempts WHERE at < '" . gmdate('Y-m-d H:i:s', time() - 86400) . "'");

$bad = function (int $status, string $msg) use ($pdo, $ip, $now) {
    $pdo->prepare('INSERT INTO enroll_attempts (ip, at) VALUES (?, ?)')->execute([$ip, $now]);
    enroll_fail($status, $msg);
};

$pdo->beginTransaction();
try {
    $q = $pdo->prepare('SELECT * FROM enroll_codes WHERE code = ? FOR UPDATE');
    $q->execute([$code]);
    $row = $q->fetch();
    $clinic = $row ? clinic_get($row['clinic_id']) : null;
    if (!$row || !$clinic) { $pdo->rollBack(); $bad(404, 'That code is not valid. Check it and try again.'); }
    if ($row['expires_at'] !== null && utc_ts($row['expires_at']) <= time()) { $pdo->rollBack(); $bad(410, 'This code has expired. Ask your administrator for a new one.'); }

    $e = $pdo->prepare('SELECT id, code_id FROM installs WHERE install_id = ?');
    $e->execute([$installId]);
    $existing = $e->fetch();
    $already = $existing && (int)$existing['code_id'] === (int)$row['id'];

    if (!$already) {
        if (!(int)$row['is_open']) { $pdo->rollBack(); $bad(403, 'This code is no longer accepting new devices.'); }
        $n = $pdo->prepare('SELECT COUNT(*) FROM installs WHERE code_id = ?');
        $n->execute([$row['id']]);
        if ((int)$n->fetchColumn() >= (int)$row['max_uses']) { $pdo->rollBack(); $bad(403, 'This code has reached its limit of ' . (int)$row['max_uses'] . ' devices. Ask your administrator.'); }
    }

    $dev = [clip($in['device'] ?? '', 120), clip($in['platform'] ?? '', 20), clip($in['osRelease'] ?? '', 60), clip($in['appVersion'] ?? '', 20)];
    if ($existing) {
        $pdo->prepare('UPDATE installs SET clinic_id = ?, code_id = ?, expires_at = NULL, url_override = NULL, device = ?, platform = ?, os_release = ?, app_version = ?, ip = ?, last_seen = ? WHERE id = ?')
            ->execute(array_merge([$row['clinic_id'], $row['id']], $dev, [$ip, $now, $existing['id']]));
    } else {
        $pdo->prepare('INSERT INTO installs (install_id, device, platform, os_release, app_version, ip, first_seen, last_seen, clinic_id, code_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge([$installId], $dev, [$ip, $now, $now, $row['clinic_id'], $row['id']]));
    }
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    enroll_fail(500, 'Something went wrong on the server. Try again.');
}

echo json_encode(sign_envelope(['v' => 1, 'installId' => $installId, 'nonce' => $nonce, 'enrolled' => true, 'clinic' => $clinic['name'], 'serverTime' => time() * 1000]), JSON_UNESCAPED_SLASHES);
