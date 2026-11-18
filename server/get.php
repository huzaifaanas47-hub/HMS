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

$size = (int)filesize($file);
$start = 0; $end = $size - 1; $status = 200;
// Resumable downloads: a phone that loses its connection continues where it stopped instead of starting over.
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim((string)$_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
    $status = 206;
}
if ($start === 0) db()->prepare('UPDATE releases SET downloads = downloads + 1 WHERE id = ?')->execute([$r['id']]); // count a download once, not every resume
@set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();
http_response_code($status);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $r['filename']) . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) header("Content-Range: bytes $start-$end/$size");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$fh = fopen($file, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $buf = fread($fh, (int)min(1048576, $left));
    if ($buf === false || $buf === '') break;
    echo $buf; flush();
    $left -= strlen($buf);
}
fclose($fh);
