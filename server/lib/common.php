<?php
declare(strict_types=1);
/* HMS admin panel - shared library: config, DB, settings, time, policy signing, auth, layout. */

const HMS_ONLINE_MINUTES = 15;
const HMS_DUMMY_HASH = '$2y$10$usesomesillystringforsaleeOqfXqk3dZP4wqkZb7yG2m0Nn6F6vS'; // equalises login timing

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) { http_response_code(503); exit('HMS admin is not installed yet. Open setup.php first.'); }
        $c = require $f;
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        $pdo = new PDO(
            'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
        ensure_schema($pdo);
    }
    return $pdo;
}

/* Upgrades an existing database in place (v2: clinics, v3: downloads, v4: access codes). Runs once, then costs one tiny query per request. */
function ensure_schema(PDO $pdo): void {
    try { $v = $pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn(); }
    catch (Throwable $e) { return; } // not installed yet
    if ($v === '5') return;
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS clinics (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, url VARCHAR(2048) NULL,
        status ENUM('active','blocked') NOT NULL DEFAULT 'active', expires_at DATETIME NULL,
        message VARCHAR(500) NULL, notes TEXT NULL, created_at DATETIME NOT NULL
    ) $opts");
    $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'installs' AND COLUMN_NAME = 'clinic_id'")->fetchColumn();
    if (!$has) $pdo->exec('ALTER TABLE installs ADD COLUMN clinic_id INT NULL, ADD KEY idx_clinic (clinic_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS releases (
        id INT AUTO_INCREMENT PRIMARY KEY, platform VARCHAR(20) NOT NULL, version VARCHAR(20) NOT NULL,
        stored VARCHAR(80) NOT NULL, filename VARCHAR(160) NOT NULL, size BIGINT NOT NULL, sha256 CHAR(64) NOT NULL,
        notes VARCHAR(500) NULL, downloads INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY idx_platform (platform, id)
    ) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enroll_codes (
        id INT AUTO_INCREMENT PRIMARY KEY, code CHAR(8) NOT NULL UNIQUE, clinic_id INT NOT NULL, note VARCHAR(120) NULL,
        max_uses INT NOT NULL DEFAULT 1, expires_at DATETIME NULL, is_open TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, KEY idx_clinic (clinic_id)
    ) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enroll_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, at DATETIME NOT NULL, KEY idx_ip (ip, at)) $opts");
    $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'installs' AND COLUMN_NAME = 'code_id'")->fetchColumn();
    if (!$has) $pdo->exec('ALTER TABLE installs ADD COLUMN code_id INT NULL, ADD KEY idx_code (code_id)');
    // v5: access codes are required by default. Devices already listed keep working; only unknown devices must enter a code.
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('require_code', '1') ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute();
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', '5') ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute();
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function utf8_cut(string $s, int $max): string { // no mbstring dependency
    return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : substr($s, 0, $max);
}

function clip($v, int $max): string {
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    return utf8_cut(trim($v), $max);
}

/* ---------------------------------------------------------------- settings */

function settings(): array {
    static $s = null;
    if ($s === null) {
        $s = ['default_url' => '', 'default_expiry_days' => '0', 'global_expires_at' => '', 'global_message' => '', 'offline_grace_days' => '14', 'require_code' => '1', 'stale_delete_days' => '0', 'last_cleanup' => '0'];
        foreach (db()->query('SELECT k, v FROM settings') as $r) $s[$r['k']] = (string)$r['v'];
    }
    return $s;
}

function setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/* -------------------------------------------------------------------- time */
/* Everything is stored in UTC; the admin sees / types times in config 'timezone'. */

function admin_tz(): DateTimeZone { return new DateTimeZone(cfg()['timezone'] ?? 'UTC'); }

function utc_ts(?string $s): ?int {
    if (!$s) return null;
    $t = strtotime($s . ' UTC');
    return $t === false ? null : $t;
}

function fmt_local(?string $utc, string $f = 'Y-m-d H:i'): string {
    $t = utc_ts($utc);
    return $t ? (new DateTimeImmutable('@' . $t))->setTimezone(admin_tz())->format($f) : '—';
}

function input_local(?string $utc): string { return fmt_local($utc, 'Y-m-d\TH:i') === '—' ? '' : fmt_local($utc, 'Y-m-d\TH:i'); }

function parse_local_input($v): ?string {
    if (!is_string($v) || trim($v) === '') return null;
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($v), admin_tz());
    return $d ? $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
}

function ago(?string $utc): string {
    $t = utc_ts($utc);
    if (!$t) return 'never';
    $d = time() - $t;
    if ($d < 90) return 'just now';
    if ($d < 3600) return intdiv($d, 60) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' h ago';
    return intdiv($d, 86400) . ' d ago';
}

function normalize_url($v): ?string { // '' => '' (cleared); invalid => null
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    if (strlen($v) > 2048 || !filter_var($v, FILTER_VALIDATE_URL)) return null;
    $p = parse_url($v);
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
    return $v;
}

/* ------------------------------------------------------------------ clinics */

function clinics_all(bool $fresh = false): array { // id => row
    static $c = null;
    if ($c === null || $fresh) {
        $c = [];
        foreach (db()->query('SELECT * FROM clinics ORDER BY name') as $r) $c[(int)$r['id']] = $r;
    }
    return $c;
}

function clinic_get($id): ?array { return $id ? (clinics_all()[(int)$id] ?? null) : null; }

/* -------------------------------------------------------------- access codes */

const HMS_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no I, L, O, 0, 1: easy to read out loud

function code_generate(): string {
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= HMS_CODE_ALPHABET[random_int(0, strlen(HMS_CODE_ALPHABET) - 1)];
    return $c;
}

function code_normalize($v): string { return is_string($v) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?? '') : ''; }
function code_pretty(string $c): string { return substr($c, 0, 4) . '-' . substr($c, 4); }

function code_get($id): ?array {
    if (!$id) return null;
    static $cache = [];
    if (!array_key_exists((int)$id, $cache)) {
        $st = db()->prepare('SELECT * FROM enroll_codes WHERE id = ?');
        $st->execute([(int)$id]);
        $cache[(int)$id] = $st->fetch() ?: null;
    }
    return $cache[(int)$id];
}

/* ------------------------------------------------------------------ policy */
/* Precedence for a PC that belongs to a clinic:
   site URL : the PC's own override > the clinic's URL > the global default
   expiry   : the EARLIEST of the PC's own date, the access code's date, the clinic's date and the global date
   blocked  : if the PC or its clinic is blocked
   message  : the PC's > the clinic's > the global one */

function effective_expiry(array $inst): ?int {
    $c = [];
    if (!empty($inst['expires_at'])) $c[] = utc_ts($inst['expires_at']);
    if ($cl = clinic_get($inst['clinic_id'] ?? null)) { if (!empty($cl['expires_at'])) $c[] = utc_ts($cl['expires_at']); }
    if ($co = code_get($inst['code_id'] ?? null)) { if (!empty($co['expires_at'])) $c[] = utc_ts($co['expires_at']); }
    $g = settings()['global_expires_at'];
    if ($g !== '') $c[] = utc_ts($g);
    $c = array_filter($c, fn($x) => $x !== null);
    return $c ? min($c) : null;
}

function effective_url(array $inst): string {
    if (!empty($inst['url_override'])) return $inst['url_override'];
    $cl = clinic_get($inst['clinic_id'] ?? null);
    if ($cl && !empty($cl['url'])) return $cl['url'];
    return settings()['default_url'];
}

function effective_status(array $inst): string {
    $cl = clinic_get($inst['clinic_id'] ?? null);
    return ($inst['status'] === 'blocked' || ($cl && $cl['status'] === 'blocked')) ? 'blocked' : 'active';
}

/* Which uploaded build suits this device? Desktop apps report process.platform ('win32', 'darwin'); Android reports 'android'.
   Only a build NEWER than the version the device reports is offered, so the notice disappears by itself after updating. */
function update_info(array $inst, string $arch = ''): ?array {
    $plat = strtolower((string)($inst['platform'] ?? ''));
    if ($plat === 'android') { $key = 'android'; $os = 'android'; }
    elseif ($plat === 'win32') { $key = 'windows'; $os = 'windows'; }
    elseif ($plat === 'darwin') { $key = $arch === 'x64' ? 'mac-x64' : 'mac-arm64'; $os = 'mac'; }
    else return null;
    $cur = trim((string)($inst['app_version'] ?? ''));
    $rel = release_latest($key);
    if ($cur === '' || !$rel || version_compare((string)$rel['version'], $cur, '<=')) return null;
    return ['version' => (string)$rel['version'], 'url' => base_dir_url() . '/download.php?os=' . $os, 'notes' => $rel['notes'] ?: null];
}

/* Devices that have not checked in for N days are removed, which frees their place on the access code (an uninstalled
   app can never tell the server, so this is how its slot comes back). Off when the setting is 0. Runs at most once an hour. */
function cleanup_stale_installs(): void {
    $s = settings();
    $days = (int)($s['stale_delete_days'] ?? 0);
    if ($days < 1 || time() - (int)($s['last_cleanup'] ?? 0) < 3600) return;
    setting_set('last_cleanup', (string)time());
    db()->prepare('DELETE FROM installs WHERE last_seen IS NOT NULL AND last_seen < ?')->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
}

function build_policy(array $inst, string $nonce, string $arch = ''): array {
    $s = settings();
    $cl = clinic_get($inst['clinic_id'] ?? null);
    $exp = effective_expiry($inst);
    $base = effective_url($inst);
    $msg = !empty($inst['message']) ? $inst['message'] : (!empty($cl['message']) ? $cl['message'] : ($s['global_message'] !== '' ? $s['global_message'] : null));
    return [
        'v' => 1,
        'installId' => $inst['install_id'],
        'nonce' => $nonce,
        'status' => effective_status($inst),
        'expiresAt' => $exp !== null ? $exp * 1000 : null,
        'baseUrl' => $base !== '' ? $base : null,
        'message' => $msg,
        'offlineGraceDays' => (int)$s['offline_grace_days'],
        'update' => update_info($inst, $arch),
        'serverTime' => time() * 1000,
    ];
}

/* Sent to a device that has not joined with an access code yet (only when "require access code" is on).
   Old app versions only understand status 'blocked', so they get locked with a readable message. */
function build_unenrolled_policy(string $installId, string $nonce): array {
    return [
        'v' => 1, 'installId' => $installId, 'nonce' => $nonce, 'status' => 'blocked', 'enrollRequired' => true,
        'expiresAt' => null, 'baseUrl' => null,
        'message' => 'An access code is required. If this app does not ask for one, install the latest HMS.',
        'offlineGraceDays' => (int)settings()['offline_grace_days'], 'serverTime' => time() * 1000,
    ];
}

function exp_tag(?int $e, int $now): string { // small HTML badge for an expiry timestamp
    if ($e === null) return '<span class="tag ok">no expiry</span>';
    $t = h(fmt_local(gmdate('Y-m-d H:i:s', $e)));
    if ($e <= $now) return '<span class="tag bad">expired ' . $t . '</span>';
    return '<span class="tag' . ($e <= $now + 7 * 86400 ? ' warn' : '') . '">' . $t . '</span>';
}

function secret_key(): string {
    $k = base64_decode(cfg()['secret_key'], true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Bad secret_key in config');
    return $k;
}

function public_key_b64(): string { return base64_encode(sodium_crypto_sign_publickey_from_secretkey(secret_key())); }

/* The app verifies the signature over this exact JSON string, so nothing may re-encode it. */
function sign_envelope(array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ['payload' => $json, 'sig' => base64_encode(sodium_crypto_sign_detached($json, secret_key()))];
}

function policy_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))), '/');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'your-domain') . $dir . '/api/checkin.php';
}

/* -------------------------------------------------------------------- auth */

function csp_nonce(): string { static $n = null; return $n ??= bin2hex(random_bytes(16)); }

function send_security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
}

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('hmsadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function require_admin(): void {
    send_security_headers();
    session_boot();
    if (empty($_SESSION['admin_id']) || (time() - ($_SESSION['last'] ?? 0)) > 7200) {
        $_SESSION = [];
        header('Location: login.php');
        exit;
    }
    $_SESSION['last'] = time();
}

function csrf_token(): string {
    session_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

function csrf_check(): void {
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    if (!$ok) { http_response_code(400); exit('Bad request (CSRF). Go back, refresh and try again.'); }
}

function flash(?string $msg = null, string $type = 'ok'): ?array {
    session_boot();
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): void { header('Location: ' . $to); exit; }

/* ------------------------------------------------------------------ layout */

function page_header(string $title, string $active = ''): void {
    $nav = ['clinics.php' => 'Clinics', 'index.php' => 'Devices', 'codes.php' => 'Codes', 'releases.php' => 'Downloads', 'settings.php' => 'Settings'];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' - HMS Admin</title><style>
*{box-sizing:border-box}body{margin:0;font:14px/1.5 Segoe UI,-apple-system,BlinkMacSystemFont,Inter,Roboto,Arial,sans-serif;background:#f1f4f9;color:#18202f;-webkit-font-smoothing:antialiased}
header{background:#0f1420;color:#fff;display:flex;align-items:center;gap:6px;padding:0 22px;height:56px;box-shadow:0 1px 0 #263049,0 4px 14px rgba(15,20,32,.12);position:sticky;top:0;z-index:5}
header b{font-size:16px;margin-right:18px;letter-spacing:.3px}header a{color:#9aa6bf;text-decoration:none;padding:7px 12px;border-radius:8px;font-weight:500;transition:background .12s,color .12s}header a:hover{color:#fff;background:rgba(255,255,255,.07)}header a.on{color:#fff;background:#3b82f6}header .sp{margin-left:auto}
main{max-width:1180px;margin:26px auto;padding:0 20px}h1{font-size:22px;font-weight:700;margin:0 0 16px}h2{font-size:15px;font-weight:700;margin:0 0 12px}
.cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}.card{background:#fff;border:1px solid #e2e8f2;border-radius:12px;padding:14px 18px;min-width:140px;box-shadow:0 1px 2px rgba(15,20,32,.04)}
.card b{display:block;font-size:24px;font-weight:700}.card span{color:#6b7587;font-size:12px}
.box{background:#fff;border:1px solid #e2e8f2;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(15,20,32,.04)}
table{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid #e2e8f2;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(15,20,32,.04)}tbody tr:hover td{background:#f8faff}
th,td{text-align:left;padding:9px 11px;border-bottom:1px solid #eef1f6;font-size:13px;vertical-align:top}th{background:#f7f9fc;color:#66728a;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#c3c9d6;margin-right:6px}.dot.on{background:#27b35a}
.tag{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;background:#eef1f6;color:#44506a}.tag.bad{background:#fde6e6;color:#b42323}.tag.warn{background:#fff1d6;color:#92600a}.tag.ok{background:#dff5e8;color:#17703a}
label{display:block;font-size:12px;color:#5b667a;margin:12px 0 4px}input[type=text],input[type=url],input[type=number],input[type=password],input[type=datetime-local],select,textarea{width:100%;padding:8px 10px;border:1px solid #cfd6e3;border-radius:8px;font:inherit;background:#fff;outline:none}input:focus,select:focus,textarea:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
textarea{min-height:70px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}@media(max-width:760px){.grid{grid-template-columns:1fr}}
.btn{display:inline-block;background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;transition:background .12s}.btn:hover{background:#2f6fdb}.btn.alt{background:#e8ecf4;color:#25324a}.btn.alt:hover{background:#dce2ee}.btn.red{background:#dc3f3f}.btn.red:hover{background:#c63232}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.msg{padding:11px 16px;border-radius:10px;margin-bottom:16px;font-weight:500}.msg.ok{background:#dff5e8;color:#17703a}.msg.err{background:#fde6e6;color:#b42323}
code,.mono{font-family:Consolas,monospace;font-size:12px;background:#eef1f6;padding:2px 5px;border-radius:4px;word-break:break-all}.muted{color:#6b7587;font-size:12px}
</style></head><body><header><b>HMS Admin</b>';
    foreach ($nav as $href => $label) echo '<a href="' . h($href) . '"' . ($active === $href ? ' class="on"' : '') . '>' . h($label) . '</a>';
    echo '<span class="sp"></span><a href="logout.php">Log out</a></header><main>';
    if ($f = flash()) echo '<div class="msg ' . ($f[1] === 'err' ? 'err' : 'ok') . '">' . h($f[0]) . '</div>';
}

function page_footer(): void {
    // Confirmation prompts: buttons with data-confirm ask first (inline onclick is blocked by the CSP).
    echo '</main><script nonce="' . csp_nonce() . '">document.addEventListener("click",function(e){var b=e.target.closest("[data-confirm]");if(b&&!confirm(b.getAttribute("data-confirm")))e.preventDefault();});</script></body></html>';
}


/* ---------------------------------------------------------------- downloads */

const HMS_PLATFORMS = [
    'windows'   => ['label' => 'Windows',            'ext' => 'exe'],
    'mac-arm64' => ['label' => 'Mac (Apple chip)',   'ext' => 'dmg'],
    'mac-x64'   => ['label' => 'Mac (Intel chip)',   'ext' => 'dmg'],
    'android'   => ['label' => 'Android',            'ext' => 'apk'],
];

function releases_dir(): string { return __DIR__ . '/releases'; }

function release_latest(string $platform): ?array {
    $st = db()->prepare('SELECT * FROM releases WHERE platform = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$platform]);
    return $st->fetch() ?: null;
}

function fmt_size($b): string {
    $b = (float)$b;
    if ($b >= 1073741824) return number_format($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576) return number_format($b / 1048576, 0) . ' MB';
    return number_format(max(1, $b / 1024), 0) . ' KB';
}

function ini_bytes(string $k): int {
    $v = trim((string)ini_get($k));
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n;
}

function base_dir_url(): string { // https://host/HMS-App  (folder that holds download.php)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))), '/');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'your-domain') . $dir;
}
