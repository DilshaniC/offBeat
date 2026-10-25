<?php
declare(strict_types=1);
// Fixed demonstration accounts documented in README.md. Local marking/testing only.
// Kept separate from the generated administrator in storage/local-access.json, which is never touched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
if ((getenv('APP_ENV') ?: 'local') === 'production' && getenv('DEMO_LOGINS_FORCE') !== '1') {
    fwrite(STDERR, "Refusing to create fixed demo logins while APP_ENV=production. Set DEMO_LOGINS_FORCE=1 only if you truly intend this.\n");
    exit(1);
}
$accounts = [
    ['name' => 'Demo Administrator', 'email' => 'demo.admin@offbeat.local', 'password' => 'OffbeatAdmin!2026', 'role' => 'admin'],
    ['name' => 'Demo Traveller', 'email' => 'demo.traveller@offbeat.local', 'password' => 'OffbeatDemo!2026', 'role' => 'traveller'],
];
foreach ($accounts as $account) {
    $fields = [
        'name' => $account['name'],
        'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT),
        'role' => $account['role'],
        'status' => 'active',
    ];
    $existing = one('SELECT id FROM users WHERE email=?', [$account['email']]);
    if ($existing) {
        // Reset so the documented password always works, even after someone changes it while testing.
        update_row('users', (int)$existing['id'], $fields);
        echo "Demo {$account['role']} reset: {$account['email']}\n";
    } else {
        insert_row('users', $fields + ['email' => $account['email']]);
        echo "Demo {$account['role']} created: {$account['email']}\n";
    }
}
