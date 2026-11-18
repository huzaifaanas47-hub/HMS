<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

// After a POST: normal form -> redirect; background upload (progress bar) -> small JSON answer, the message shows on the next page load.
function finish(): void {
    if (isset($_POST['ajax'])) { header('Content-Type: application/json'); echo '{"done":true}'; exit; }
    redirect('releases.php');
}

$limit = min(ini_bytes('upload_max_filesize') ?: PHP_INT_MAX, ini_bytes('post_max_size') ?: PHP_INT_MAX);
$dir = releases_dir();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('The file is larger than this server allows (' . fmt_size($limit) . '). See "Server upload limit" below.', 'err');
        finish();
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
        finish();
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
    finish();
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

<form method="post" enctype="multipart/form-data" class="box" id="upload-form"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <h2>Upload a new version</h2>
  <div class="grid">
    <div><label>For which computer</label><select name="platform">
      <?php foreach (HMS_PLATFORMS as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p['label']) ?> (.<?= h($p['ext']) ?>)</option><?php endforeach; ?></select></div>
    <div><label>Version</label><input type="text" name="version" placeholder="2.0.0" maxlength="20" required></div>
  </div>
  <label>Installer file (.exe for Windows, .dmg for Mac: <b>arm64</b> = Apple chip, <b>x64</b> = Intel, .apk for Android)</label><input type="file" name="file" required>
  <label>What changed (optional, shown to users)</label><input type="text" name="notes" maxlength="500">
  <div class="row"><button class="btn" id="up-btn">Upload</button> <button type="button" class="btn alt" id="up-cancel" style="display:none">Cancel</button></div>
  <div id="up-box" style="display:none;margin-top:12px">
    <div style="background:#e4eae8;border-radius:8px;height:18px;overflow:hidden"><div id="up-bar" style="background:#2e6bf2;height:100%;width:0;transition:width .15s"></div></div>
    <p id="up-text" class="muted" style="margin:6px 0 0"></p>
  </div>
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
<script nonce="<?= csp_nonce() ?>">
(function () {
  var form = document.getElementById('upload-form'), btn = document.getElementById('up-btn'), cancel = document.getElementById('up-cancel');
  var box = document.getElementById('up-box'), bar = document.getElementById('up-bar'), text = document.getElementById('up-text');
  var xhr = null;
  function mb(n) { return (n / 1048576).toFixed(1) + ' MB'; }
  function reset(msg) { btn.disabled = false; btn.textContent = 'Upload'; cancel.style.display = 'none'; text.textContent = msg || ''; if (msg) text.style.color = '#b42318'; }
  form.addEventListener('submit', function (e) {
    var f = form.elements.file.files[0];
    if (!f || !window.XMLHttpRequest || !window.FormData) return; // fall back to a normal upload
    e.preventDefault();
    box.style.display = 'block'; bar.style.width = '0'; text.style.color = ''; text.textContent = 'Starting...';
    btn.disabled = true; btn.textContent = 'Uploading...'; cancel.style.display = '';
    var t0 = Date.now();
    xhr = new XMLHttpRequest();
    xhr.open('POST', form.getAttribute('action') || location.href);
    xhr.upload.onprogress = function (ev) {
      if (!ev.lengthComputable) return;
      var pct = Math.min(100, Math.round(ev.loaded / ev.total * 100)), secs = Math.max(1, (Date.now() - t0) / 1000);
      bar.style.width = pct + '%';
      text.textContent = pct + '%  (' + mb(ev.loaded) + ' of ' + mb(ev.total) + ', ' + mb(ev.loaded / secs) + '/s)' + (pct === 100 ? ' - saving on the server...' : '');
    };
    xhr.onload = function () {
      if (xhr.status === 413) { reset('The server refused the file because it is too big. Raise client_max_body_size (nginx) and the PHP limits, see below.'); return; }
      if (xhr.status >= 400) { reset('Upload failed (HTTP ' + xhr.status + ').'); return; }
      bar.style.width = '100%';
      location.href = 'releases.php'; // the result message is shown there
    };
    xhr.onerror = function () { reset('The upload was interrupted. Check your connection and try again.'); };
    xhr.onabort = function () { bar.style.width = '0'; reset('Upload cancelled.'); text.style.color = ''; };
    var fd = new FormData(form); fd.append('ajax', '1'); xhr.send(fd);
  });
  cancel.addEventListener('click', function () { if (xhr) xhr.abort(); });
})();
</script>
<?php page_footer();
