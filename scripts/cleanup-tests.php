<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/bootstrap.php';
if ((getenv('APP_ENV') ?: '') !== 'local' || !in_array('--confirm-test-cleanup', $argv, true)) {
    fwrite(STDERR, "Local test cleanup only. Requires APP_ENV=local and --confirm-test-cleanup.\n"); exit(1);
}
$users = all("SELECT id FROM users WHERE role='traveller' AND name IN ('Smoke Traveller','Other Traveller') AND email REGEXP '^(other-)?smoke-[0-9]+@example[.]test$'");
db()->beginTransaction();
foreach ($users as $u) {
    $images=all('SELECT i.path FROM place_images i JOIN places p ON p.id=i.place_id WHERE p.user_id=?',[$u['id']]);
    query('DELETE FROM places WHERE user_id=?', [$u['id']]);
    query('DELETE FROM users WHERE id=?', [$u['id']]);
    foreach ($images as $image) if (preg_match('~^uploads/[a-f0-9]{40}\.(jpg|png|webp)$~',$image['path'])) @unlink(dirname(__DIR__).'/public/'.$image['path']);
}
query("DELETE FROM payments WHERE reference REGEXP '^SMOKE-[0-9]+$'");
query("DELETE FROM advertising_requests WHERE email REGEXP '^smoke-[0-9]+@example[.]test$' AND business_name='Smoke store'");
// The loopback-only development stack shares one client IP for repeatable tests.
query('DELETE FROM rate_limits');
db()->commit();
echo "Removed marked local smoke fixtures and reset local rate limits.\n";
