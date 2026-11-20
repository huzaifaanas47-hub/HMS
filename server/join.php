<?php
declare(strict_types=1);
/* Public page opened when someone scans the QR code of an access code (or taps the join link).
   It opens the HMS app with the code already filled in, or offers the download if the app is not installed yet. */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$raw = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['code'] ?? '')));
$code = substr($raw, 0, 8);
$valid = strlen($code) === 8;
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/join.php')), '/');
$dl = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/download.php?os=android';
$pretty = substr($code, 0, 4) . '-' . substr($code, 4);
$open = 'intent://join?code=' . $code . '#Intent;scheme=hms;package=com.talha.hms;S.browser_fallback_url=' . rawurlencode($dl) . ';end';
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Join HMS</title>
<style>
body{margin:0;font:16px/1.5 Segoe UI,-apple-system,Roboto,Arial,sans-serif;background:#0f1420;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center}
.c{max-width:420px;padding:28px}h1{font-size:24px;margin:0 0 8px}p{color:#b7c0d0}
.code{font:700 34px/1.2 Consolas,monospace;letter-spacing:3px;background:#182033;border-radius:12px;padding:16px;margin:18px 0}
a.b{display:block;margin:10px 0;padding:14px;border-radius:12px;text-decoration:none;font-weight:700;background:#3b82f6;color:#fff}a.b.alt{background:#263049}
</style></head><body><div class="c">
<?php if ($valid): ?>
<h1>Join HMS</h1>
<p>Your access code:</p>
<div class="code"><?= e($pretty) ?></div>
<a class="b" href="<?= e($open) ?>">Open the HMS app</a>
<a class="b alt" href="<?= e($dl) ?>">Download HMS for Android</a>
<p>If HMS is already installed, tap the first button: the code is filled in for you. Otherwise download and install HMS first, then come back to this page, or type the code above when the app asks for it.</p>
<?php else: ?>
<h1>Join HMS</h1>
<p>This link is not complete. Ask your administrator for a new code or QR.</p>
<?php endif; ?>
</div></body></html>
