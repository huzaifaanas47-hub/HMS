<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
send_security_headers();
session_boot();

$error = '';
$ip = clip($_SERVER['REMOTE_ADDR'] ?? '', 45);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 3600)]);
    $c = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?');
    $c->execute([$ip, gmdate('Y-m-d H:i:s', time() - 900)]);
    if ((int)$c->fetchColumn() >= 8) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $u = $pdo->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $u->execute([clip($_POST['username'] ?? '', 64)]);
        $row = $u->fetch();
        $ok = password_verify((string)($_POST['password'] ?? ''), $row['password_hash'] ?? HMS_DUMMY_HASH) && $row;
        if ($ok) {
            $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION = ['admin_id' => (int)$row['id'], 'last' => time()];
            redirect('index.php');
        }
        $pdo->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([$ip, gmdate('Y-m-d H:i:s')]);
        $error = 'Wrong username or password.';
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>HMS Admin - Login</title>
<style>body{font:14px Segoe UI,-apple-system,BlinkMacSystemFont,Inter,Arial,sans-serif;background:radial-gradient(900px 480px at 50% -10%,#17223d 0%,#0f1420 62%);display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;-webkit-font-smoothing:antialiased}
form{background:#fff;padding:32px 30px 28px;border-radius:16px;width:360px;box-shadow:0 24px 60px rgba(0,0,0,.4)}h1{font-size:21px;font-weight:700;margin:0 0 6px;color:#18202f}
.sub{color:#66728a;font-size:13px;margin:0 0 10px}label{display:block;font-size:12px;font-weight:600;color:#5b667a;margin:14px 0 5px}
input{width:100%;padding:11px 12px;border:1px solid #cfd6e3;border-radius:9px;font:inherit;outline:none}input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}button{margin-top:20px;width:100%;background:#3b82f6;color:#fff;border:0;border-radius:9px;padding:12px;font:inherit;font-weight:600;cursor:pointer}button:hover{background:#2f6fdb}
.err{background:#fde6e6;color:#b42323;padding:9px 12px;border-radius:8px;margin:10px 0 0}</style></head><body>
<form method="post" autocomplete="off"><h1>HMS Admin</h1><p class="sub">Sign in to manage installs and access.</p>
<?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
<?= csrf_field() ?>
<label>Username</label><input name="username" required autofocus>
<label>Password</label><input name="password" type="password" required>
<button>Sign in</button></form></body></html>
