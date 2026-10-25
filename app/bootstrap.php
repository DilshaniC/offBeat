<?php
declare(strict_types=1);

// Optional local configuration. Environment variables always take precedence.
$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) && getenv($key) === false) putenv($key . '=' . trim(trim($value), "\"'"));
    }
}
date_default_timezone_set('Asia/Colombo');
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'offbeat') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, getenv('DB_USERNAME') ?: 'offbeat', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}
function query(string $sql, array $values = []): PDOStatement { $s = db()->prepare($sql); $s->execute($values); return $s; }
function one(string $sql, array $values = []): ?array { return query($sql, $values)->fetch() ?: null; }
function all(string $sql, array $values = []): array { return query($sql, $values)->fetchAll(); }
function insert_row(string $table, array $data): int {
    query('INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($data)) . '`) VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
    return (int)db()->lastInsertId();
}
function update_row(string $table, int $id, array $data): void {
    query('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($k) => '`' . $k . '`=?', array_keys($data))) . ' WHERE id=?', [...array_values($data), $id]);
}
class ApiError extends RuntimeException { public function __construct(string $message, public int $status = 422, public array $errors = []) { parent::__construct($message); } }
function fail(string $message, int $status = 422, array $errors = []): never { throw new ApiError($message, $status, $errors); }
function respond(mixed $data, int $status = 200): never { http_response_code($status); echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function input(): array {
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
        if (strlen($raw) > 1024 * 1024) fail('Request is too large.', 413);
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data) && $data !== []) fail('Invalid JSON request.', 400);
        return $data;
    }
    return $_POST;
}
function string_field(array $data, string $key, int $max = 200, bool $required = false, int $min = 1): string {
    $raw = $data[$key] ?? '';
    if (!is_scalar($raw) && $raw !== null) fail('Invalid ' . str_replace('_', ' ', $key) . '.', 422, [$key => 'Must be text.']);
    $value = trim((string)$raw);
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if (($required && $length < $min) || $length > $max) fail('Please check ' . str_replace('_', ' ', $key) . '.', 422, [$key => $required && $length < $min ? "Enter at least $min characters." : "Maximum $max characters."]);
    return $value;
}
function password_field(array $data, string $key, int $min = 1): string {
    $value = $data[$key] ?? null;
    if (!is_string($value) || strlen($value) < $min || strlen($value) > 72 || str_contains($value, "\0")) fail('Please check ' . str_replace('_', ' ', $key) . '.', 422, [$key => "Use between $min and 72 bytes, without null characters."]);
    return $value;
}
function int_field(array $data, string $key, int $min = 1, int $max = PHP_INT_MAX, mixed $default = null): int {
    $raw = $data[$key] ?? $default;
    if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int)$raw < $min || (int)$raw > $max) fail('Invalid ' . str_replace('_', ' ', $key) . '.', 422, [$key => "Enter a whole number from $min to $max."]);
    return (int)$raw;
}
function coordinate(array $data, string $key, float $min, float $max, bool $optional = false): ?float {
    $raw = $data[$key] ?? null;
    if ($optional && ($raw === null || $raw === '')) return null;
    if (!is_scalar($raw) || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < $min || (float)$raw > $max) fail('Invalid map coordinates.', 422, [$key => "Must be between $min and $max."]);
    return (float)$raw;
}
function enum_field(array $data, string $key, array $allowed, ?string $default = null): string {
    $value = $data[$key] ?? $default;
    if (!is_string($value) || !in_array($value, $allowed, true)) fail('Invalid ' . str_replace('_', ' ', $key) . '.', 422);
    return $value;
}
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $user = one('SELECT id,name,email,role,status FROM users WHERE id=?', [$_SESSION['user_id']]);
    if (!$user || $user['status'] !== 'active') { unset($_SESSION['user_id']); return null; }
    return $user;
}
function require_user(bool $admin = false): array {
    $u = current_user(); if (!$u) fail('Please sign in to continue.', 401);
    if ($admin && $u['role'] !== 'admin') fail('Administrator access required.', 403);
    return $u;
}
function rate_limit(string $action, int $limit, int $seconds): void {
    $bucket = $action . ':' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
    $now = time();
    query('INSERT INTO rate_limits(bucket,hits,resets_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=IF(resets_at<=?,1,hits+1),resets_at=IF(resets_at<=?,VALUES(resets_at),resets_at)', [$bucket, $now + $seconds, $now, $now]);
    $row = one('SELECT hits,resets_at FROM rate_limits WHERE bucket=?', [$bucket]);
    if ($row['hits'] > $limit) { header('Retry-After: ' . max(1, $row['resets_at'] - $now)); fail('Too many attempts. Please try again later.', 429); }
    if (random_int(1, 100) === 1) query('DELETE FROM rate_limits WHERE resets_at<? LIMIT 1000', [$now]);
}
function place_select(): string {
    return "SELECT p.*,u.name AS author_name,(SELECT path FROM place_images WHERE place_id=p.id ORDER BY id LIMIT 1) AS cover_image FROM places p JOIN users u ON u.id=p.user_id";
}
function decorate_places(array $rows): array {
    $saved = [];
    $viewer = current_user();
    if (!empty($_SESSION['user_id'])) $saved = array_column(all('SELECT place_id FROM saved_places WHERE user_id=?', [$_SESSION['user_id']]), 'place_id');
    foreach ($rows as &$row) {
        $row['is_saved'] = in_array($row['id'], $saved);
        $row['is_demo'] = (bool)$row['is_demo'];
        // Moderation feedback is restricted to the owner and administrator.
        if (($row['user_id'] ?? null) != ($_SESSION['user_id'] ?? null) && ($viewer['role'] ?? '') !== 'admin') unset($row['moderation_note']);
    }
    return $rows;
}
function get_place(int $id, bool $publicOnly = false): array {
    $p = one(place_select() . ' WHERE p.id=?', [$id]);
    if (!$p) fail('Place not found.', 404);
    $u = current_user();
    if ($p['status'] !== 'approved' && ($publicOnly || !$u || ($u['role'] !== 'admin' && $u['id'] != $p['user_id']))) fail('Place not found.', 404);
    $p = decorate_places([$p])[0];
    $p['images'] = all('SELECT id,path,caption FROM place_images WHERE place_id=? ORDER BY id', [$id]);
    $p['services'] = service_rows('s.place_id=?', [$id], false);
    return $p;
}
function service_rows(string $where = '1=1', array $values = [], bool $public = true): array {
    $visibility = $public ? " AND (s.place_id IS NULL OR p.status='approved')" : '';
    $rows = all("SELECT s.*,p.title AS place_title,(SELECT MAX(ends_on) FROM payments pay WHERE pay.service_id=s.id AND pay.status='active' AND pay.starts_on<=CURRENT_DATE AND pay.ends_on>=CURRENT_DATE AND s.type NOT IN ('hospital','police','bus_station')) AS paid_until FROM services s LEFT JOIN places p ON p.id=s.place_id WHERE $where $visibility ORDER BY (paid_until IS NOT NULL) DESC,s.name LIMIT 500", $values);
    foreach ($rows as &$r) $r['is_sponsored'] = $r['paid_until'] !== null;
    return $rows;
}
function service_data(array $d): array {
    $out = ['name' => string_field($d, 'name', 160, true), 'type' => enum_field($d, 'type', ['hospital','police','bus_station','shop','vehicle_rental','tuk_tuk','guide','other']), 'description' => string_field($d, 'description', 3000), 'phone' => string_field($d, 'phone', 60), 'address' => string_field($d, 'address', 255), 'latitude' => coordinate($d, 'latitude', -90, 90, true), 'longitude' => coordinate($d, 'longitude', -180, 180, true), 'website' => string_field($d, 'website', 500)];
    if (($out['latitude'] === null) !== ($out['longitude'] === null)) fail('Provide both service coordinates or leave both empty.');
    if ($out['website'] && (!filter_var($out['website'], FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($out['website'], PHP_URL_SCHEME) ?? ''), ['https','http']))) fail('Website must be a valid http or https URL.');
    return $out;
}
function distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
    return 6371 * 2 * asin(sqrt(min(1, max(0, $a))));
}
function plan_data(array $d): array {
    $ids = $d['place_ids'] ?? [];
    if (!is_array($ids) || count($ids) < 1 || count($ids) > 12) fail('Choose between 1 and 12 places.');
    $ids = array_values(array_unique(array_map(fn($id) => int_field(['id' => $id], 'id'), $ids)));
    $startLat = coordinate(['start_lat' => $d['start_lat'] ?? 6.9271], 'start_lat', -90, 90);
    $startLng = coordinate(['start_lng' => $d['start_lng'] ?? 79.8612], 'start_lng', -180, 180);
    $time = string_field(['start_time' => $d['start_time'] ?? '07:00'], 'start_time', 5, true);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) fail('Start time must use HH:MM.');
    return ['place_ids' => $ids, 'start_lat' => $startLat, 'start_lng' => $startLng, 'start_time' => $time];
}
function generate_plan(array $d): array {
    $d = plan_data($d);
    $places = all(place_select() . " WHERE p.status='approved' AND p.id IN (" . implode(',', array_fill(0, count($d['place_ids']), '?')) . ')', $d['place_ids']);
    if (count($places) !== count($d['place_ids'])) fail('One or more selected places are no longer published. Remove them and try again.');
    $lat = $d['start_lat']; $lng = $d['start_lng']; $start = (int)substr($d['start_time'], 0, 2) * 60 + (int)substr($d['start_time'], 3, 2); $minutes = $start; $km = 0; $stops = [];
    $formatTime = fn(int $n) => sprintf('%02d:%02d', intdiv($n, 60) % 24, $n % 60) . ($n >= 1440 ? ' (+' . intdiv($n, 1440) . ' day)' : '');
    while ($places) {
        $best = null; $leg = INF;
        foreach ($places as $i => $p) { $dist = distance_km($lat, $lng, (float)$p['latitude'], (float)$p['longitude']); if ($dist < $leg) { $leg = $dist; $best = $i; } }
        $p = $places[$best]; unset($places[$best]);
        // Straight-line distance inflated by 1.35; assumed average 30 km/h.
        $leg *= 1.35; $minutes += (int)ceil($leg / 30 * 60); $km += $leg;
        $p['leg_km'] = round($leg, 1); $p['arrival_time'] = $formatTime($minutes);
        $minutes += (int)$p['visit_minutes']; $p['departure_time'] = $formatTime($minutes); $stops[] = $p;
        $lat = (float)$p['latitude']; $lng = (float)$p['longitude'];
    }
    $warning = 'Approximate guidance only. Does not account for road access, traffic, weather, opening hours or breaks. Return journey is excluded; verify local conditions before travelling.';
    if ($minutes - $start > 720 || $minutes >= 1140) $warning .= ' This plan may be too long for a safe day trip or finish after dark. Choose fewer stops or stay overnight.';
    return ['stops' => decorate_places($stops), 'total_km' => round($km, 1), 'total_minutes' => $minutes - $start, 'warning' => $warning, 'method' => 'Nearest-neighbour ordering; straight-line kilometres × 1.35 at an assumed 30 km/h. Not live navigation.'];
}
