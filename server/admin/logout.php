<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
send_security_headers();
session_boot();
$_SESSION = [];
session_destroy();
redirect('login.php');
