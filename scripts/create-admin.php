<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$email = getenv('ADMIN_EMAIL') ?: '';
$password = getenv('ADMIN_PASSWORD') ?: '';
$name = getenv('ADMIN_NAME') ?: 'Administrator';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Set ADMIN_EMAIL and ADMIN_PASSWORD (12–72 bytes); ADMIN_NAME is optional.\n"); exit(1);
}
if (one('SELECT id FROM users WHERE email=?', [$email])) { fwrite(STDERR,"Email already exists; existing account preserved.\n"); exit(1); }
insert_row('users', ['name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'admin']);
echo "Administrator created.\n";
