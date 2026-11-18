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
<style>body{font:14px -apple-system,Segoe UI,Arial,sans-serif;background:#161b24;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
form{background:#fff;padding:26px;border-radius:10px;width:320px}h1{font-size:19px;margin:0 0 14px}label{display:block;font-size:12px;color:#5b667a;margin:12px 0 4px}
input{width:100%;padding:9px 10px;border:1px solid #cfd6e3;border-radius:6px;font:inherit}button{margin-top:16px;width:100%;background:#2e6bf2;color:#fff;border:0;border-radius:6px;padding:10px;font:inherit;cursor:pointer}
.err{background:#fde6e6;color:#b42323;padding:8px 10px;border-radius:6px;margin-bottom:8px}</style></head><body>
<form method="post" autocomplete="off"><h1>HMS Admin</h1>
<?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
<?= csrf_field() ?>
<label>Username</label><input name="username" required autofocus>
<label>Password</label><input name="password" type="password" required>
<button>Sign in</button></form></body></html>
