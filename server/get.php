<?php
declare(strict_types=1);
/* Public file download. ?p=windows|mac-arm64|mac-x64 gives the newest build of that platform; ?id=N a specific one. */
require __DIR__ . '/lib/common.php';

$r = null;
if (isset($_GET['id'])) {
    $st = db()->prepare('SELECT * FROM releases WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $r = $st->fetch() ?: null;
} elseif (isset($_GET['p']) && is_string($_GET['p']) && isset(HMS_PLATFORMS[$_GET['p']])) {
    $r = release_latest($_GET['p']);
}
$file = $r ? releases_dir() . '/' . basename($r['stored']) : '';
if (!$r || !is_file($file)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('This download is not available.'); }

db()->prepare('UPDATE releases SET downloads = downloads + 1 WHERE id = ?')->execute([$r['id']]);
@set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $r['filename']) . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$fh = fopen($file, 'rb');
while (!feof($fh)) { echo fread($fh, 1048576); flush(); }
fclose($fh);
