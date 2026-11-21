<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

// After a POST: normal form -> redirect; background upload (progress bar) -> small JSON answer, the message shows on the next page load.
function finish(): void {
    if (isset($_POST['ajax'])) { header('Content-Type: application/json'); echo '{"done":true}'; exit; }
    redirect('releases.php');
}

function jerr(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg] + $extra);
    exit;
}
function jok(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }

/* Resumable upload: the browser sends the file in small pieces. A piece that arrives twice (its answer got lost) is
   skipped, a piece that arrives out of order is answered with the size the server really has, and an unfinished
   upload stays on the server for 3 days so it can be resumed after any interruption. */
function resumable(string $act, string $dir): void {
    if (!isset($_POST['csrf'], $_SESSION['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) jerr(400, 'The page is out of date. Reload it and choose the file again.');
    $id = (string)($_POST['upload_id'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) jerr(400, 'Bad upload id.');
    $tmp = $dir . '/tmp';
    if (!is_dir($tmp) && !@mkdir($tmp, 0750, true)) jerr(500, 'Cannot create lib/releases/tmp/ on the server. Create it and make it writable by the web server.');
    if (!is_writable($tmp)) jerr(500, 'lib/releases/tmp/ is not writable by the web server.');
    foreach (glob($tmp . '/*.part') ?: [] as $old) if (@filemtime($old) < time() - 3 * 86400) @unlink($old);
    $part = "$tmp/$id.part";
    clearstatcache(true, $part);
    $size = is_file($part) ? (int)filesize($part) : 0;

    if ($act === 'status') jok(['size' => $size]);

    $total = (int)($_POST['total'] ?? 0);
    if ($total < 1 || $total > 2 * 1024 * 1024 * 1024) jerr(422, 'That file size is not allowed (limit 2 GB).');

    if ($act === 'chunk') {
        $offset = (int)($_POST['offset'] ?? -1);
        $f = $_FILES['chunk'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jerr((($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) ? 413 : 400, 'The piece did not arrive.');
        if ($offset < 0 || $offset > $size) jerr(409, 'Out of order.', ['size' => $size]);
        if ($offset < $size) jok(['size' => $size]); // already stored: the answer was lost on the way back
        $fh = fopen($part, 'ab');
        if (!$fh || !flock($fh, LOCK_EX)) jerr(500, 'Could not write the piece on the server.');
        clearstatcache(true, $part);
        $cur = (int)filesize($part);
        if ($cur !== $offset) { flock($fh, LOCK_UN); fclose($fh); jok(['size' => $cur]); }
        $in = fopen($f['tmp_name'], 'rb');
        $len = (int)filesize($f['tmp_name']);
        if ($cur + $len > $total) { flock($fh, LOCK_UN); fclose($fh); jerr(422, 'More data than the file size announced.'); }
        stream_copy_to_stream($in, $fh);
        fflush($fh); flock($fh, LOCK_UN); fclose($fh); fclose($in);
        jok(['size' => $cur + $len]);
    }

    // finish: check everything and turn the assembled file into a release
    $platform = (string)($_POST['platform'] ?? '');
    $version = clip($_POST['version'] ?? '', 20);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)($_POST['filename'] ?? '')));
    if (!isset(HMS_PLATFORMS[$platform])) jerr(422, 'Choose which computer this file is for.');
    if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,19}$/', $version)) jerr(422, 'Enter the version, for example 2.0.0.');
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== HMS_PLATFORMS[$platform]['ext']) jerr(422, HMS_PLATFORMS[$platform]['label'] . ' needs a .' . HMS_PLATFORMS[$platform]['ext'] . ' file.');
    if ($size !== $total) jerr(409, 'The file is not complete yet.', ['size' => $size]);
    @set_time_limit(0); // hashing a big file can take a while
    $sha = hash_file('sha256', $part); // before the move: if this is cut short, the piece stays resumable and nothing is orphaned
    $stored = bin2hex(random_bytes(16)) . '.bin';
    if ($sha === false || !@rename($part, $dir . '/' . $stored)) jerr(500, 'Could not save the file on the server.');
    try {
        db()->prepare('INSERT INTO releases (platform, version, stored, filename, size, sha256, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$platform, $version, $stored, $name, $total, $sha, clip($_POST['notes'] ?? '', 500) ?: null, gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) { @unlink($dir . '/' . $stored); jerr(500, 'Could not record the file in the database.'); }
    log_activity('Download uploaded', $name . ' (' . $platform . ' ' . $version . ')');
    flash('Uploaded ' . $name . '. It is now the download for ' . HMS_PLATFORMS[$platform]['label'] . '.');
    jok(['done' => true]);
}

$limit = min(ini_bytes('upload_max_filesize') ?: PHP_INT_MAX, ini_bytes('post_max_size') ?: PHP_INT_MAX);
$dir = releases_dir();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('The file is larger than this server allows (' . fmt_size($limit) . '). See "Server upload limit" below.', 'err');
        finish();
    }
    if (in_array($_POST['action'] ?? '', ['status', 'chunk', 'finish'], true)) resumable((string)$_POST['action'], $dir);
    csrf_check();
    $act = (string)($_POST['action'] ?? 'upload');
    if ($act === 'delete') {
        $st = db()->prepare('SELECT * FROM releases WHERE id = ?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        if ($r = $st->fetch()) {
            @unlink($dir . '/' . basename($r['stored']));
            db()->prepare('DELETE FROM releases WHERE id = ?')->execute([$r['id']]);
            log_activity('Download deleted', $r['filename'] . ' (' . $r['platform'] . ' ' . $r['version'] . ')');
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
  <label>Installer file (.exe for Windows, .dmg for Mac: <b>arm64</b> = Apple chip, <b>x64</b> = Intel, .apk for Android, .AppImage for Linux: <b>x64</b> = Intel/AMD, <b>arm64</b> = ARM)</label><input type="file" name="file" required>
  <label>What changed (optional, shown to users)</label><input type="text" name="notes" maxlength="500">
  <div class="row"><button class="btn" id="up-btn">Upload</button> <button type="button" class="btn alt" id="up-cancel" style="display:none">Pause</button></div>
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
  var form = document.getElementById('upload-form');
  if (!form || typeof Promise === 'undefined' || !window.XMLHttpRequest || !window.FormData || !window.File) return; // plain form upload as fallback
  var btn = document.getElementById('up-btn'), pause = document.getElementById('up-cancel');
  var box = document.getElementById('up-box'), bar = document.getElementById('up-bar'), text = document.getElementById('up-text');
  var LIMIT = <?= $limit === PHP_INT_MAX ? 0 : (int)$limit ?>, EXT = <?= json_encode(array_map(fn($p) => $p['ext'], HMS_PLATFORMS)) ?>;
  var CHUNK = Math.max(262144, Math.min(4194304, LIMIT ? Math.floor(LIMIT * 0.9) : 4194304)), MAX_TRY = 12;
  var aborted = false, xhr = null;

  function mb(n) { return (n / 1048576).toFixed(1) + ' MB'; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function waitOnline() {
    if (navigator.onLine !== false) return Promise.resolve();
    text.style.color = '#b45309'; text.textContent = 'You are offline. The upload continues by itself when the connection is back...';
    return new Promise(function (r) { window.addEventListener('online', function f() { window.removeEventListener('online', f); r(); }); });
  }
  function rid() { var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }

  function post(fields, blob, onProg) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('csrf', form.elements.csrf.value);
      Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
      if (blob) fd.append('chunk', blob, 'piece');
      xhr = new XMLHttpRequest();
      xhr.open('POST', form.getAttribute('action') || location.href);
      xhr.timeout = 120000;
      if (onProg) xhr.upload.onprogress = onProg;
      xhr.onload = function () { var j = null; try { j = JSON.parse(xhr.responseText); } catch (e) { } resolve({ status: xhr.status, json: j }); };
      xhr.onerror = xhr.ontimeout = function () { reject({ network: true }); };
      xhr.onabort = function () { reject({ aborted: true }); };
      xhr.send(fd);
    });
  }

  // One request, retried with growing pauses on any network trouble; the upload itself is never lost.
  async function call(fields, blob, onProg) {
    var attempt = 0;
    for (;;) {
      if (aborted) throw { aborted: true };
      await waitOnline();
      try {
        var r = await post(fields, blob, onProg);
        if (r.status >= 500 || r.status === 408 || r.status === 429) throw { retry: true };
        if (!r.json) {
          if (r.status === 413) throw { fatal: true, msg: 'The server refused a piece because it is too big. Raise client_max_body_size and the PHP limits (see below).' };
          throw { fatal: true, msg: 'Your sign-in expired. Sign in again, then choose the same file: the upload resumes where it stopped.' };
        }
        return r;
      } catch (e) {
        if (e.aborted || e.fatal) throw e;
        attempt++;
        if (attempt > MAX_TRY) throw { fatal: true, msg: 'The connection kept failing. Choose the same file again to resume where it stopped.' };
        var wait = Math.min(30, Math.pow(2, attempt - 1));
        for (var s = wait; s > 0; s--) {
          text.style.color = '#b45309';
          text.textContent = 'Connection problem. Retrying in ' + s + 's (try ' + attempt + ' of ' + MAX_TRY + '). Your progress is kept.';
          await sleep(1000);
          if (aborted) throw { aborted: true };
        }
      }
    }
  }

  function ui(running) { btn.disabled = running; btn.textContent = running ? 'Uploading...' : 'Upload'; pause.style.display = running ? '' : 'none'; }

  form.addEventListener('submit', async function (e) {
    var f = form.elements.file.files[0];
    if (!f) return;
    e.preventDefault();
    var platform = form.elements.platform.value, version = form.elements.version.value.trim();
    var ext = (f.name.split('.').pop() || '').toLowerCase();
    box.style.display = 'block'; text.style.color = '#b42318';
    if (ext !== EXT[platform]) { bar.style.width = '0'; text.textContent = 'This computer type needs a .' + EXT[platform] + ' file.'; return; }
    if (!/^[0-9A-Za-z][0-9A-Za-z.+-]{0,19}$/.test(version)) { bar.style.width = '0'; text.textContent = 'Enter the version, for example 2.0.0.'; return; }

    var key = 'hmsup:' + f.name + ':' + f.size + ':' + f.lastModified, id = null;
    try { id = localStorage.getItem(key); } catch (x) { }
    if (!id) { id = rid(); try { localStorage.setItem(key, id); } catch (x) { } }
    aborted = false; text.style.color = ''; bar.style.width = '0'; text.textContent = 'Starting...'; ui(true);
    try {
      var total = f.size;
      var st = await call({ action: 'status', upload_id: id, total: total });
      var offset = (st.json && st.json.size) || 0;
      if (offset > total) { id = rid(); try { localStorage.setItem(key, id); } catch (x) { } offset = 0; }
      var start = offset, t0 = Date.now();
      function progress(loaded) {
        var pct = Math.min(100, Math.floor(loaded / total * 100)), secs = Math.max(1, (Date.now() - t0) / 1000);
        bar.style.width = pct + '%'; text.style.color = '';
        text.textContent = pct + '%  (' + mb(loaded) + ' of ' + mb(total) + ', ' + mb(Math.max(0, loaded - start) / secs) + '/s)';
      }
      if (offset > 0) { progress(offset); text.textContent = 'Resuming at ' + mb(offset) + ' of ' + mb(total) + '...'; }
      while (offset < total) {
        var base = offset, blob = f.slice(offset, Math.min(total, offset + CHUNK));
        var r = await call({ action: 'chunk', upload_id: id, offset: offset, total: total }, blob, function (ev) { if (ev.lengthComputable) progress(base + ev.loaded); });
        if (r.status === 409 && r.json && typeof r.json.size === 'number') { offset = r.json.size; continue; }
        if (r.status !== 200 || r.json.error) throw { fatal: true, msg: r.json.error || ('Upload failed (HTTP ' + r.status + ').') };
        offset = r.json.size;
        progress(offset);
      }
      text.textContent = '100%  - saving on the server...';
      var fin = await call({ action: 'finish', upload_id: id, total: total, platform: platform, version: version, notes: form.elements.notes.value, filename: f.name });
      if (fin.status !== 200 || fin.json.error) throw { fatal: true, msg: fin.json.error || ('Could not finish (HTTP ' + fin.status + ').') };
      try { localStorage.removeItem(key); } catch (x) { }
      location.href = 'releases.php'; // the result message is shown there
    } catch (err) {
      ui(false);
      if (err && err.aborted) { text.style.color = ''; text.textContent = 'Paused. Choose the same file and press Upload to continue where it stopped.'; return; }
      text.style.color = '#b42318'; text.textContent = (err && err.msg) || 'Something went wrong. Try again.';
    }
  });
  pause.addEventListener('click', function () { aborted = true; if (xhr) xhr.abort(); });
})();
</script>
<?php page_footer();
