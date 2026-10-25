<?php
declare(strict_types=1);
// Offline password reset. The application deliberately has no self-service email reset, so this
// CLI script is the recovery path when any account - including the last administrator - is locked out.
// Requires shell access to the server, which is the intended trust boundary.
//
//   List accounts:  php scripts/reset-password.php
//   Reset one:      RESET_EMAIL=you@example.com RESET_PASSWORD='new-secret-here' php scripts/reset-password.php
//   Also reactivate a suspended account:  add RESET_ACTIVATE=1
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim(getenv('RESET_EMAIL') ?: ''));
$password = getenv('RESET_PASSWORD') ?: '';

if ($email === '' && $password === '') {
    $users = all('SELECT id,name,email,role,status FROM users ORDER BY role DESC, id');
    if (!$users) { echo "No accounts exist yet. Create one with scripts/create-admin.php\n"; exit; }
    printf("%-4s %-34s %-11s %-10s %s\n", 'ID', 'EMAIL', 'ROLE', 'STATUS', 'NAME');
    foreach ($users as $u) printf("%-4s %-34s %-11s %-10s %s\n", $u['id'], $u['email'], $u['role'], $u['status'], $u['name']);
    echo "\nReset one with:\n  RESET_EMAIL=<email> RESET_PASSWORD='<new password>' php scripts/reset-password.php\n";
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Set RESET_EMAIL to a valid email address. Run without arguments to list accounts.\n"); exit(1); }
$length = strlen($password);
if ($length < 12 || $length > 72) { fwrite(STDERR, "Set RESET_PASSWORD to 12-72 bytes. Received {$length}.\n"); exit(1); }

$user = one('SELECT id,name,email,role,status FROM users WHERE email=?', [$email]);
if (!$user) { fwrite(STDERR, "No account uses {$email}. Run without arguments to list accounts.\n"); exit(1); }

$data = ['password_hash' => password_hash($password, PASSWORD_DEFAULT)];
if ($user['status'] !== 'active') {
    if (getenv('RESET_ACTIVATE') === '1') { $data['status'] = 'active'; }
    else { fwrite(STDERR, "Warning: {$email} is {$user['status']} and still cannot sign in. Re-run with RESET_ACTIVATE=1 to reactivate it.\n"); }
}
update_row('users', (int)$user['id'], $data);
$status = $data['status'] ?? $user['status'];
echo "Password reset for {$user['email']} ({$user['role']}, account is {$status}).\n";
