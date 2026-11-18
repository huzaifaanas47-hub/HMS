<?php
declare(strict_types=1);
/* One-time installer for the HMS admin panel. It deletes itself when it succeeds. */
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
$cfgFile = __DIR__ . '/lib/config.php';
if (is_file($cfgFile)) { http_response_code(403); exit('Already installed. Delete setup.php from the server.'); }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$err = ''; $done = null;
$f = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'admin_user' => 'admin', 'timezone' => 'Asia/Karachi'];
if (!function_exists('sodium_crypto_sign_keypair')) $err = 'The PHP "sodium" extension is required (apt install php-sodium / enable ext-sodium).';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));
    $dbpass = (string)($_POST['db_pass'] ?? '');
    $apass = (string)($_POST['admin_pass'] ?? '');
    try {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $f['admin_user'])) throw new Exception('Admin username: 3-64 letters, digits, _ . -');
        if (strlen($apass) < 10) throw new Exception('Admin password must be at least 10 characters.');
        if (!in_array($f['timezone'], DateTimeZone::listIdentifiers(), true)) throw new Exception('Unknown timezone.');
        $pdo = new PDO("mysql:host={$f['db_host']};dbname={$f['db_name']};charset=utf8mb4", $f['db_user'], $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT NULL) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS installs (
            id INT AUTO_INCREMENT PRIMARY KEY, install_id CHAR(36) NOT NULL UNIQUE, name VARCHAR(120) NULL, device VARCHAR(120) NULL,
            platform VARCHAR(20) NULL, os_release VARCHAR(60) NULL, app_version VARCHAR(20) NULL, ip VARCHAR(45) NULL,
            first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, checkins INT NOT NULL DEFAULT 1,
            status ENUM('active','blocked') NOT NULL DEFAULT 'active', expires_at DATETIME NULL, url_override VARCHAR(2048) NULL,
            message VARCHAR(500) NULL, notes TEXT NULL, KEY idx_last_seen (last_seen)) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, at DATETIME NOT NULL, KEY idx_ip (ip, at)) $opts");
        $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$f['admin_user'], password_hash($apass, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s')]);

        $kp = sodium_crypto_sign_keypair();
        $config = "<?php\nreturn " . var_export([
            'db_host' => $f['db_host'], 'db_name' => $f['db_name'], 'db_user' => $f['db_user'], 'db_pass' => $dbpass,
            'timezone' => $f['timezone'],
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($kp)),
        ], true) . ";\n";
        if (@file_put_contents($cfgFile, $config, LOCK_EX) === false) {
            $done = ['manual' => $config];
        } else {
            @chmod($cfgFile, 0600);
            $done = ['manual' => null];
            @unlink(__FILE__);
        }
        $done['pub'] = base64_encode(sodium_crypto_sign_publickey($kp));
    } catch (Throwable $ex) {
        $err = $ex instanceof PDOException ? 'Database error: ' . $ex->getMessage() : $ex->getMessage();
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>HMS Admin setup</title>
<style>body{font:14px -apple-system,Segoe UI,Arial,sans-serif;background:#f3f5f9;margin:0;padding:30px}.box{max-width:560px;margin:auto;background:#fff;border:1px solid #e0e5ee;border-radius:10px;padding:24px}
h1{font-size:20px;margin:0 0 6px}label{display:block;font-size:12px;color:#5b667a;margin:12px 0 4px}input{width:100%;padding:9px 10px;border:1px solid #cfd6e3;border-radius:6px;font:inherit}
button{margin-top:18px;background:#2e6bf2;color:#fff;border:0;border-radius:6px;padding:10px 18px;font:inherit;cursor:pointer}.err{background:#fde6e6;color:#b42323;padding:9px 12px;border-radius:6px;margin:12px 0}
code,pre{font-family:Consolas,monospace;font-size:12px;background:#eef1f6;padding:2px 5px;border-radius:4px;word-break:break-all;white-space:pre-wrap}.muted{color:#6b7587;font-size:12px}</style></head><body><div class="box">
<?php if ($done): ?>
  <h1>Installed</h1>
  <?php if ($done['manual']): ?>
    <div class="err">Could not write lib/config.php. Create that file with this exact content, then delete setup.php:</div><pre><?= e($done['manual']) ?></pre>
  <?php else: ?><p>setup.php has deleted itself. Sign in at <a href="admin/login.php">admin/login.php</a>.</p><?php endif; ?>
  <p>Public key for the desktop app's <code>app-config.json</code> (also shown later in Settings):</p><p><code><?= e($done['pub']) ?></code></p>
<?php else: ?>
  <h1>HMS Admin setup</h1><p class="muted">Create an empty MySQL/MariaDB database first, then fill this in once.</p>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <label>Database host</label><input name="db_host" value="<?= e($f['db_host']) ?>" required>
    <label>Database name</label><input name="db_name" value="<?= e($f['db_name']) ?>" required>
    <label>Database user</label><input name="db_user" value="<?= e($f['db_user']) ?>" required>
    <label>Database password</label><input name="db_pass" type="password">
    <label>Admin username</label><input name="admin_user" value="<?= e($f['admin_user']) ?>" required>
    <label>Admin password (10+ characters)</label><input name="admin_pass" type="password" required>
    <label>Your timezone (for showing and entering dates)</label><input name="timezone" value="<?= e($f['timezone']) ?>" required>
    <button>Install</button>
  </form>
<?php endif; ?>
</div></body></html>
