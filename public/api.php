<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/places.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
ini_set('session.use_strict_mode', '1');
session_name('offbeat_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

try {
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action)) fail('Unknown action.', 404);
    $reads = ['session','places','place','services','my_places','saved','plans','admin_dashboard'];
    $mutations = ['register','login','logout','profile','save_place','delete_place','save_toggle','plan_generate','plan_save','plan_delete','report','advertise','moderate_place','user_status','save_service','delete_service','resolve_report','advertising_status','record_payment','cancel_payment'];
    if (!in_array($action, [...$reads, ...$mutations], true)) fail('Unknown action.', 404);
    $method = in_array($action, $reads, true) ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $method) { header('Allow: ' . $method); fail('Method not allowed.', 405); }
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Your session expired. Refresh the page and try again.', 403);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 28 * 1024 * 1024) fail('Request is too large.', 413);
        $d = input();
    } else $d = $_GET;
    switch ($action) {
        case 'session': respond(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
        case 'register':
            rate_limit('register', 8, 3600);
            $name = string_field($d, 'name', 100, true, 2); $email = strtolower(string_field($d, 'email', 190, true));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.', 422, ['email' => 'Invalid email address.']);
            $password = password_field($d, 'password', 10);
            if (one('SELECT id FROM users WHERE email=?', [$email])) fail('That email is already registered. Please sign in.');
            $id = insert_row('users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true); $_SESSION['user_id'] = $id;
            respond(['user' => current_user(), 'csrf' => $_SESSION['csrf']], 201);
        case 'login':
            rate_limit('login', 20, 900);
            $email = strtolower(string_field($d, 'email', 190, true)); $password = password_field($d, 'password');
            $u = one('SELECT * FROM users WHERE email=?', [$email]);
            $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (!password_verify($password, $u['password_hash'] ?? $dummy) || !$u) fail('Email or password is incorrect.', 401);
            if ($u['status'] !== 'active') fail('This account is suspended. Contact the administrator.', 403);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) update_row('users', (int)$u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true); $_SESSION['user_id'] = $u['id'];
            respond(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
        case 'logout':
            $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); respond(['user' => null, 'csrf' => $_SESSION['csrf']]);
        case 'profile':
            rate_limit('profile', 30, 900);
            $u = require_user(); $name = string_field($d, 'name', 100, true, 2); $email = strtolower(string_field($d, 'email', 190, true));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
            if (one('SELECT id FROM users WHERE email=? AND id<>?', [$email, $u['id']])) fail('That email is already in use.');
            $data = ['name' => $name, 'email' => $email];
            if ($email !== $u['email'] || !empty($d['new_password'])) {
                $current = password_field($d, 'current_password');
                $hash = one('SELECT password_hash FROM users WHERE id=?', [$u['id']])['password_hash'];
                if (!password_verify($current, $hash)) fail('Current password is incorrect.');
            }
            if (!empty($d['new_password'])) $data['password_hash'] = password_hash(password_field($d, 'new_password', 10), PASSWORD_DEFAULT);
            update_row('users', (int)$u['id'], $data); respond(['user' => current_user()]);
        case 'places':
            $where = ["p.status='approved'"]; $values = [];
            $q = string_field($d, 'q', 120); if ($q !== '') { $where[] = '(p.title LIKE ? OR p.description LIKE ? OR p.region LIKE ?)'; array_push($values, "%$q%", "%$q%", "%$q%"); }
            foreach (['category','region'] as $field) { $v = string_field($d, $field, 100); if ($v !== '' && strtolower($v) !== 'all') { $where[] = "p.$field=?"; $values[] = $v; } }
            $sort = enum_field($d, 'sort', ['newest','name','nearest'], 'newest');
            $page = int_field($d, 'page', 1, 10000, 1); $perPage = 24;
            $hasCoords = isset($d['lat']) && $d['lat'] !== '' && isset($d['lng']) && $d['lng'] !== '';
            if ($hasCoords) {
                $lat = coordinate($d, 'lat', -90, 90); $lng = coordinate($d, 'lng', -180, 180);
                $radius = isset($d['radius']) && $d['radius'] !== '' ? coordinate($d, 'radius', 0.1, 20000) : null;
                // Database-side distance filtering keeps memory and output bounded.
                $distance = '6371*2*ASIN(SQRT(LEAST(1,POW(SIN(RADIANS(p.latitude-?)/2),2)+COS(RADIANS(?))*COS(RADIANS(p.latitude))*POW(SIN(RADIANS(p.longitude-?)/2),2))))';
                if ($radius !== null) { $where[] = "$distance<=?"; array_push($values, $lat, $lat, $lng, $radius); }
                $order = $sort === 'nearest' ? $distance : ($sort === 'name' ? 'p.title ASC' : 'p.created_at DESC,p.id DESC');
            } else {
                if ($sort === 'nearest' || (!empty($d['radius']))) fail('Choose a starting map location to filter or sort by distance.');
                $order = $sort === 'name' ? 'p.title ASC' : 'p.created_at DESC,p.id DESC';
            }
            $filter = implode(' AND ', $where);
            $total = (int)query("SELECT COUNT(*) FROM places p WHERE $filter", $values)->fetchColumn();
            if ($hasCoords && $sort === 'nearest') array_push($values, $lat, $lat, $lng);
            $rows = all(place_select() . " WHERE $filter ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $values);
            if ($hasCoords) foreach ($rows as &$p) $p['distance_km'] = round(distance_km($lat, $lng, (float)$p['latitude'], (float)$p['longitude']), 1);
            respond(['items' => decorate_places($rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)), 'categories' => array_column(all("SELECT DISTINCT category FROM places WHERE status='approved' ORDER BY category"), 'category')]);
        case 'place': respond(get_place(int_field($d, 'id')));
        case 'services':
            $where = []; $values = [];
            if (!empty($d['place_id'])) { $id = int_field($d, 'place_id'); get_place($id, true); $where[] = 's.place_id=?'; $values[] = $id; }
            if (!empty($d['type'])) { $where[] = 's.type=?'; $values[] = string_field($d, 'type', 40); }
            if (!empty($d['q'])) { $where[] = '(s.name LIKE ? OR s.description LIKE ?)'; $q = '%' . string_field($d, 'q', 120) . '%'; array_push($values, $q, $q); }
            respond(service_rows($where ? implode(' AND ', $where) : '1=1', $values));
        case 'my_places':
            $u = require_user(); respond(decorate_places(all(place_select() . ' WHERE p.user_id=? ORDER BY p.updated_at DESC LIMIT 500', [$u['id']])));
        case 'save_place': respond(save_place($d));
        case 'delete_place':
            $u = require_user(); $id = int_field($d, 'id'); $p = get_place($id);
            if ($u['role'] !== 'admin' && $p['user_id'] != $u['id']) fail('You can only delete your own places.', 403);
            query('DELETE FROM places WHERE id=?', [$id]); foreach ($p['images'] as $img) delete_local_image($img['path']); respond(['deleted' => true]);
        case 'save_toggle':
            $u = require_user(); $id = int_field($d, 'place_id'); get_place($id, true);
            $exists = one('SELECT user_id FROM saved_places WHERE user_id=? AND place_id=?', [$u['id'], $id]);
            if ($exists) query('DELETE FROM saved_places WHERE user_id=? AND place_id=?', [$u['id'], $id]);
            else query('INSERT IGNORE INTO saved_places(user_id,place_id) VALUES(?,?)', [$u['id'], $id]);
            respond(['is_saved' => !$exists]);
        case 'saved':
            $u = require_user(); respond(decorate_places(all(place_select() . " JOIN saved_places sp ON sp.place_id=p.id WHERE sp.user_id=? AND p.status='approved' ORDER BY sp.created_at DESC LIMIT 500", [$u['id']])));
        case 'plan_generate': rate_limit('plan_generate', 100, 900); respond(generate_plan($d));
        case 'plan_save':
            $u = require_user(); $data = plan_data($d); $generated = generate_plan($data);
            $name = string_field($d, 'name', 120, true);
            if ((int)query('SELECT COUNT(*) FROM plans WHERE user_id=?', [$u['id']])->fetchColumn() >= 100) fail('You can save up to 100 plans. Delete an older plan first.');
            $id = insert_row('plans', ['user_id' => $u['id'], 'name' => $name, ...$data, 'place_ids' => json_encode($data['place_ids'])]);
            respond(['id' => $id, 'name' => $name, ...$data, ...$generated], 201);
        case 'plans':
            $u = require_user(); $rows = all('SELECT * FROM plans WHERE user_id=? ORDER BY created_at DESC LIMIT 100', [$u['id']]);
            foreach ($rows as &$r) $r['place_ids'] = json_decode($r['place_ids'], true); respond($rows);
        case 'plan_delete':
            $u = require_user(); $id = int_field($d, 'id');
            if (!query('DELETE FROM plans WHERE id=? AND user_id=?', [$id, $u['id']])->rowCount()) fail('Plan not found.', 404); respond(['deleted' => true]);
        case 'report':
            rate_limit('report', 10, 3600); $id = int_field($d, 'place_id'); get_place($id, true);
            insert_row('reports', ['place_id' => $id, 'user_id' => current_user()['id'] ?? null, 'reason' => string_field($d, 'reason', 3000, true, 10)]); respond(['submitted' => true], 201);
        case 'advertise':
            rate_limit('advertise', 5, 3600); $data = [];
            foreach (['business_name' => 160,'contact_name' => 100,'email' => 190,'phone' => 60,'message' => 3000] as $key => $max) $data[$key] = string_field($d, $key, $max, true);
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
            $data['place_id'] = !empty($d['place_id']) ? int_field($d, 'place_id') : null; if ($data['place_id']) get_place($data['place_id'], true);
            insert_row('advertising_requests', $data); respond(['submitted' => true, 'message' => 'Your request was sent to the administrator. Payment is arranged offline; submitting does not activate a promotion.'], 201);
        case 'admin_dashboard':
            require_user(true);
            $stats = ['users' => (int)query('SELECT COUNT(*) FROM users')->fetchColumn(), 'approved' => (int)query("SELECT COUNT(*) FROM places WHERE status='approved'")->fetchColumn(), 'pending' => (int)query("SELECT COUNT(*) FROM places WHERE status='pending'")->fetchColumn(), 'services' => (int)query('SELECT COUNT(*) FROM services')->fetchColumn(), 'reports' => (int)query("SELECT COUNT(*) FROM reports WHERE status='new'")->fetchColumn(), 'advertising' => (int)query("SELECT COUNT(*) FROM advertising_requests WHERE status<>'closed'")->fetchColumn()];
            respond(['stats' => $stats, 'places' => decorate_places(all(place_select() . " ORDER BY (p.status='pending') DESC,p.updated_at DESC LIMIT 500")), 'users' => all('SELECT id,name,email,role,status,created_at FROM users ORDER BY created_at DESC LIMIT 500'), 'services' => service_rows('1=1', [], false), 'reports' => all('SELECT r.*,p.title AS place_title,u.name AS reporter_name FROM reports r JOIN places p ON p.id=r.place_id LEFT JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC LIMIT 500'), 'advertising' => all('SELECT a.*,p.title AS place_title FROM advertising_requests a LEFT JOIN places p ON p.id=a.place_id ORDER BY a.created_at DESC LIMIT 500'), 'payments' => all('SELECT pay.*,COALESCE(s.name,pay.service_name) AS service_name FROM payments pay LEFT JOIN services s ON s.id=pay.service_id ORDER BY pay.created_at DESC LIMIT 500')]);
        case 'moderate_place':
            require_user(true); $id = int_field($d, 'id'); get_place($id);
            $status = enum_field($d, 'status', ['approved','rejected','pending']); $note = string_field($d, 'moderation_note', 3000, $status === 'rejected');
            update_row('places', $id, ['status' => $status, 'moderation_note' => $note]); respond(get_place($id));
        case 'user_status':
            $u = require_user(true); $id = int_field($d, 'id'); $target = one('SELECT id,role FROM users WHERE id=?', [$id]);
            if (!$target) fail('User not found.', 404);
            if ($id == $u['id'] || $target['role'] === 'admin') fail('Administrator accounts cannot be suspended here.', 403);
            update_row('users', $id, ['status' => enum_field($d, 'status', ['active','suspended'])]); respond(['updated' => true]);
        case 'save_service':
            $u = require_user(true); $data = service_data($d); $id = !empty($d['id']) ? int_field($d, 'id') : null;
            $data['place_id'] = !empty($d['place_id']) ? int_field($d, 'place_id') : null; if ($data['place_id']) get_place($data['place_id']);
            $data['submitted_by'] = $u['id'];
            if ($id) { if (!one('SELECT id FROM services WHERE id=?', [$id])) fail('Service not found.', 404); update_row('services', $id, $data); }
            else $id = insert_row('services', $data);
            respond(service_rows('s.id=?', [$id], false)[0]);
        case 'delete_service':
            require_user(true); $id = int_field($d, 'id'); if (!query('DELETE FROM services WHERE id=?', [$id])->rowCount()) fail('Service not found.', 404); respond(['deleted' => true]);
        case 'resolve_report':
            require_user(true); $id = int_field($d, 'id'); if (!one('SELECT id FROM reports WHERE id=?', [$id])) fail('Report not found.', 404);
            update_row('reports', $id, ['status' => enum_field($d, 'status', ['resolved','dismissed'])]); respond(['updated' => true]);
        case 'advertising_status':
            require_user(true); $id = int_field($d, 'id'); if (!one('SELECT id FROM advertising_requests WHERE id=?', [$id])) fail('Request not found.', 404);
            update_row('advertising_requests', $id, ['status' => enum_field($d, 'status', ['new','contacted','closed'])]); respond(['updated' => true]);
        case 'record_payment':
            $u = require_user(true); $id = int_field($d, 'service_id'); $service = one('SELECT id,name,type FROM services WHERE id=?', [$id]);
            if (!$service) fail('Service not found.', 404);
            if (in_array($service['type'], ['hospital','police','bus_station'])) fail('Essential public services cannot be sponsored.');
            $amount = $d['amount'] ?? null;
            if (!is_scalar($amount) || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', (string)$amount) || (float)$amount <= 0) fail('Enter a positive payment amount with at most 2 decimal places.');
            $data = ['service_id' => $id, 'service_name' => $service['name'], 'recorded_by' => $u['id'], 'amount' => $amount, 'reference' => string_field($d, 'reference', 190, true), 'notes' => string_field($d, 'notes', 3000)];
            foreach (['paid_on','starts_on','ends_on'] as $key) {
                $value = string_field($d, $key, 10, true); $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) fail('Enter valid payment and sponsorship dates.'); $data[$key] = $value;
            }
            if ($data['paid_on'] > date('Y-m-d')) fail('Payment date cannot be in the future.');
            if ($data['ends_on'] < $data['starts_on']) fail('Sponsorship end must be on or after its start.');
            $paymentId = insert_row('payments', $data); respond(one('SELECT * FROM payments WHERE id=?', [$paymentId]), 201);
        case 'cancel_payment':
            require_user(true); $id = int_field($d, 'id'); if (!one('SELECT id FROM payments WHERE id=?', [$id])) fail('Payment record not found.', 404);
            update_row('payments', $id, ['status' => 'cancelled']); respond(['cancelled' => true]);
    }
} catch (ApiError $e) {
    http_response_code($e->status); echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'errors' => (object)$e->errors], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (isset($e->errorInfo) && ($e->errorInfo[1] ?? null) === 1062) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'This record already exists. Please refresh and try again.']); }
    else { error_log('Offbeat API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'The server could not complete this request. Please try again shortly.']); }
}
