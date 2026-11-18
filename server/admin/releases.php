<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

$limit = min(ini_bytes('upload_max_filesize') ?: PHP_INT_MAX, ini_bytes('post_max_size') ?: PHP_INT_MAX);
$dir = releases_dir();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('The file is larger than this server allows (' . fmt_size($limit) . '). See "Server upload limit" below.', 'err');
        redirect('releases.php');
    }
    csrf_check();
    $act = (string)($_POST['action'] ?? 'upload');
    if ($act === 'delete') {
        $st = db()->prepare('SELECT * FROM releases WHERE id = ?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        if ($r = $st->fetch()) {
            @unlink($dir . '/' . basename($r['stored']));
            db()->prepare('DELETE FROM releases WHERE id = ?')->execute([$r['id']]);
            flash('Removed ' . $r['filename'] . '.');
        }
        redirect('releases.php');
    }
    $platform = (string)($_POST['platform'] ?? '');
    $version = clip($_POST['version'] ?? '', 20);
    $f = $_FILES['file'] ?? null;
    if (!isset(HMS_PLATFORMS[$platform])) flash('Choose which computer this file is for.', 'err');
    elseif (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,19}$/', $version)) flash('Enter the version, for example 2.0.0.', 'err');
    elseif (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        flash(in_array($e, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is larger than this server allows (' . fmt_size($limit) . '). See "Server upload limit" below.' : ($e === UPLOAD_ERR_NO_FILE ? 'Choose a file to upload.' : 'Upload failed (code ' . (int)$e . '). Try again.'), 'err');
    } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== HMS_PLATFORMS[$platform]['ext']) {
        flash(HMS_PLATFORMS[$platform]['label'] . ' needs a .' . HMS_PLATFORMS[$platform]['ext'] . ' file.', 'err');
    } elseif (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        flash('Cannot create lib/releases/ on the server. Create it and make it writable by the web server.', 'err');
    } elseif (!is_writable($dir)) {
        flash('lib/releases/ is not writable by the web server.', 'err');
    } else {
        $stored = bin2hex(random_bytes(16)) . '.bin';
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) flash('Could not save the file on the server.', 'err');
        else {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$f['name']));
            db()->prepare('INSERT INTO releases (platform, version, stored, filename, size, sha256, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$platform, $version, $stored, $name, filesize($dir . '/' . $stored), hash_file('sha256', $dir . '/' . $stored), clip($_POST['notes'] ?? '', 500) ?: null, gmdate('Y-m-d H:i:s')]);
            flash('Uploaded ' . $name . '. It is now the download for ' . HMS_PLATFORMS[$platform]['label'] . '.');
        }
    }
    redirect('releases.php');
}

$rows = db()->query('SELECT * FROM releases ORDER BY id DESC LIMIT 200')->fetchAll();
$latest = [];
foreach (HMS_PLATFORMS as $k => $_) $latest[$k] = release_latest($k);
$link = base_dir_url() . '/download.php';

page_header('Downloads', 'releases.php');
?>
<h1>Downloads</h1>
<div class="box">
  <h2>Link to give your users</h2>
  <div class="mono"><a href="<?= h($link) ?>" target="_blank" rel="noopener"><?= h($link) ?></a></div>
  <p class="muted">The page picks the right installer for the visitor's computer. The newest upload for each type is what people get. Direct links: 
  <?php foreach (HMS_PLATFORMS as $k => $p): ?><span class="mono"><?= h(base_dir_url() . '/get.php?p=' . $k) ?></span> <?php endforeach; ?></p>
</div>

<form method="post" enctype="multipart/form-data" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <h2>Upload a new version</h2>
  <div class="grid">
    <div><label>For which computer</label><select name="platform">
      <?php foreach (HMS_PLATFORMS as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p['label']) ?> (.<?= h($p['ext']) ?>)</option><?php endforeach; ?></select></div>
    <div><label>Version</label><input type="text" name="version" placeholder="2.0.0" maxlength="20" required></div>
  </div>
  <label>Installer file (.exe for Windows, .dmg for Mac: <b>arm64</b> = Apple chip, <b>x64</b> = Intel, .apk for Android)</label><input type="file" name="file" required>
  <label>What changed (optional, shown to users)</label><input type="text" name="notes" maxlength="500">
  <div class="row"><button class="btn">Upload</button></div>
  <p class="muted">Server upload limit: <b><?= $limit === PHP_INT_MAX ? 'none' : h(fmt_size($limit)) ?></b>. An installer is about 80 to 120 MB. If this is too small, set <code>upload_max_filesize</code> and <code>post_max_size</code> to 300M in php.ini, and on nginx add <code>client_max_body_size 300m;</code>, then restart PHP and the web server.</p>
</form>

<table>
<tr><th>File</th><th>For</th><th>Version</th><th>Uploaded</th><th>Downloads</th><th></th></tr>
<?php foreach ($rows as $r): $cur = isset($latest[$r['platform']]) && (int)$latest[$r['platform']]['id'] === (int)$r['id']; ?>
<tr>
  <td><b><?= h($r['filename']) ?></b> <?php if ($cur): ?><span class="tag ok">current</span><?php endif; ?><br><span class="muted"><?= h(fmt_size($r['size'])) ?> · SHA-256 <span class="mono"><?= h(substr($r['sha256'], 0, 16)) ?>…</span></span></td>
  <td><?= h(HMS_PLATFORMS[$r['platform']]['label'] ?? $r['platform']) ?></td>
  <td><?= h($r['version']) ?></td>
  <td><?= h(fmt_local($r['created_at'])) ?></td>
  <td><?= (int)$r['downloads'] ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn red" name="id" value="<?= (int)$r['id'] ?>" data-confirm="Delete this file from the server?">Delete</button></form></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">Nothing uploaded yet.</td></tr><?php endif; ?>
</table>
<?php page_footer();
