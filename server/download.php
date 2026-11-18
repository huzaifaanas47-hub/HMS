<?php
declare(strict_types=1);
/* Public page for your users: the right HMS installer for their computer. No sign-in needed. */
require __DIR__ . '/lib/common.php';
send_security_headers();

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$os = preg_match('/Android/i', $ua) ? 'android' : (preg_match('/iPhone|iPad/i', $ua) ? 'mobile' : (preg_match('/Windows/i', $ua) ? 'windows' : (preg_match('/Macintosh|Mac OS X/i', $ua) ? 'mac' : 'other')));
if (isset($_GET['os']) && in_array($_GET['os'], ['windows', 'mac', 'android'], true)) $os = $_GET['os'];

$rel = [];
foreach (HMS_PLATFORMS as $k => $_) $rel[$k] = release_latest($k);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

function dl_button(?array $r, string $key, string $label): string {
    global $e;
    if (!$r) return '<span class="btn off">' . $e($label) . ' is not published yet</span>';
    return '<a class="btn" href="get.php?p=' . $e($key) . '">Download for ' . $e($label) . '<small>Version ' . $e($r['version']) . ' · ' . $e(fmt_size($r['size'])) . '</small></a>';
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Download HMS</title>
<style>
:root{--bg:#eef3f1;--panel:#fff;--ink:#16211f;--mute:#5b6d69;--line:#d3ddda;--brand:#0e6b63;--brand-ink:#fff}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 "Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif;font-variant-numeric:tabular-nums}
main{max-width:640px;margin:0 auto;padding:56px 16px 72px}
h1{font-size:clamp(34px,7vw,52px);line-height:1.04;letter-spacing:-.025em;margin:0 0 10px;font-weight:700}
.lead{color:var(--mute);margin:0 0 30px;max-width:46ch}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:24px}
.btn{display:block;background:var(--brand);color:var(--brand-ink);text-decoration:none;font-size:20px;font-weight:650;padding:18px 22px;border-radius:8px;margin:0 0 12px;line-height:1.25}
.btn:last-child{margin-bottom:0}
.btn small{display:block;font-size:14px;font-weight:400;opacity:.85;margin-top:2px}
.btn:hover{background:#0b574f}
.btn:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid #f2b705;outline-offset:2px}
.btn.off{background:#e4eae8;color:var(--mute);font-size:16px;font-weight:500}
.hint{color:var(--mute);font-size:14px;margin:14px 0 0}
h2{font-size:18px;margin:34px 0 12px;letter-spacing:-.01em}
ol{margin:0;padding-left:22px}li{margin:0 0 8px;padding-left:4px}li::marker{color:var(--brand);font-weight:700}
.others{margin-top:34px;border-top:1px solid var(--line);padding-top:18px;font-size:15px}
.others a{color:var(--brand);font-weight:600}
.others ul{list-style:none;margin:8px 0 0;padding:0}.others li{padding:0;margin:0 0 4px}
details{margin-top:18px;font-size:13px;color:var(--mute)}summary{cursor:pointer}
code{font:12px Consolas,Menlo,monospace;word-break:break-all;background:#e8efed;padding:1px 5px;border-radius:4px}
.notes{color:var(--mute);font-size:14px;margin:12px 0 0}
</style></head><body><main>
<h1>Download HMS</h1>
<?php if ($os === 'mobile'): ?>
  <p class="lead">HMS is not available for iPhone or iPad. Open this page on a Windows PC, a Mac or an Android phone.</p>
<?php elseif ($os === 'android'): ?>
  <p class="lead">You are on Android. Download the app, then open it and enter the access code from your administrator.</p>
  <div class="panel"><?= dl_button($rel['android'], 'android', 'Android') ?></div>
  <h2>Install on your phone</h2>
  <ol>
    <li>Open the downloaded file. If Android blocks it, tap Settings and allow your browser to install apps, then open the file again.</li>
    <li>Tap Install, then Open.</li>
    <li>Allow camera, microphone and location when HMS asks. The pages you use need them.</li>
    <li>Type your access code. You only do this once.</li>
  </ol>
<?php elseif ($os === 'mac'): ?>
  <p class="lead">You are on a Mac. Pick the file that matches your Mac's chip.</p>
  <div class="panel">
    <?= dl_button($rel['mac-arm64'], 'mac-arm64', 'Mac with Apple chip') ?>
    <?= dl_button($rel['mac-x64'], 'mac-x64', 'Mac with Intel chip') ?>
    <p class="hint">Not sure? Click the Apple menu, then About This Mac. If it says Chip: Apple M1, M2, M3 or M4, choose Apple chip. If it says Processor: Intel, choose Intel.</p>
  </div>
  <h2>Install on your Mac</h2>
  <ol>
    <li>Open the downloaded file and drag HMS onto Applications.</li>
    <li>Open Applications, right-click HMS, choose Open, then click Open again. You only do this the first time.</li>
    <li>Allow camera and microphone when your Mac asks. The pages you use need them.</li>
    <li>Type your access code. You only do this once.</li>
  </ol>
<?php elseif ($os === 'windows'): ?>
  <p class="lead">You are on Windows. This installs HMS for the PC you are using now.</p>
  <div class="panel"><?= dl_button($rel['windows'], 'windows', 'Windows') ?></div>
  <h2>Install on your PC</h2>
  <ol>
    <li>Open the downloaded file. If Windows says "Windows protected your PC", click More info, then Run anyway.</li>
    <li>Choose the install folder and click Next.</li>
    <li>Keep the server address shown, or type the one your administrator gave you, then finish.</li>
    <li>Open HMS from the desktop shortcut and type your access code. You only do this once.</li>
  </ol>
<?php else: ?>
  <p class="lead">Choose the download for your computer.</p>
  <div class="panel">
    <?= dl_button($rel['windows'], 'windows', 'Windows') ?>
    <?= dl_button($rel['mac-arm64'], 'mac-arm64', 'Mac with Apple chip') ?>
    <?= dl_button($rel['mac-x64'], 'mac-x64', 'Mac with Intel chip') ?>
    <?= dl_button($rel['android'], 'android', 'Android') ?>
  </div>
<?php endif; ?>

<?php if (in_array($os, ['windows', 'mac', 'android'], true)): $names = ['windows' => 'Windows', 'mac' => 'Mac', 'android' => 'Android']; ?>
<div class="others">Using a different device?
  <?php foreach ($names as $k => $n): if ($k !== $os): ?><a href="?os=<?= $k ?>"><?= $e($n) ?></a>&nbsp; <?php endif; endforeach; ?>
</div>
<?php endif; ?>

<?php $notes = array_filter(array_map(fn($r) => $r && $r['notes'] ? $r : null, $rel)); if ($notes): ?>
  <?php foreach (array_slice($notes, 0, 1) as $r): ?><p class="notes">What's new in <?= $e($r['version']) ?>: <?= $e($r['notes']) ?></p><?php endforeach; ?>
<?php endif; ?>

<details><summary>Check the file (for IT staff)</summary>
  <?php foreach (HMS_PLATFORMS as $k => $p): if ($rel[$k]): ?><p><?= $e($p['label']) ?>, <?= $e($rel[$k]['filename']) ?><br>SHA-256 <code><?= $e($rel[$k]['sha256']) ?></code></p><?php endif; endforeach; ?>
</details>
</main></body></html>
