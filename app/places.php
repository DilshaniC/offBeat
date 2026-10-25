<?php
declare(strict_types=1);

function uploaded_images(): array {
    $f = $_FILES['images'] ?? null;
    if (!$f) return [];
    if (!is_array($f) || !isset($f['name'], $f['error'], $f['size'], $f['tmp_name']) || !is_array($f['error']) || !is_array($f['size']) || !is_array($f['tmp_name'])) fail('Invalid image upload.');
    if (!is_array($f['name'])) fail('Use images[] for image uploads.');
    if (count($f['name']) > 8) fail('Upload no more than 8 images.');
    $validated = []; $total = 0;
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($f['name'] as $i => $name) {
        if (!is_string($name) || !isset($f['error'][$i], $f['size'][$i], $f['tmp_name'][$i]) || !is_int($f['error'][$i]) || !is_int($f['size'][$i]) || !is_string($f['tmp_name'][$i])) fail('Invalid image upload.');
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($f['error'][$i] !== UPLOAD_ERR_OK) fail('An image upload failed. Each image must be at most 5 MB.');
        if ($f['size'][$i] > 5 * 1024 * 1024) fail('Each image must be at most 5 MB.');
        $total += $f['size'][$i]; if ($total > 24 * 1024 * 1024) fail('Total image upload must be at most 24 MB.');
        $tmp = $f['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) fail('Invalid image upload.');
        $mime = $finfo->file($tmp); $size = @getimagesize($tmp);
        if (!isset($types[$mime]) || !$size || $size[0] * $size[1] > 25000000 || $size[0] < 32 || $size[1] < 32) fail('Use a valid JPEG, PNG or WebP image between 32 pixels and 25 megapixels.');
        $validated[] = ['tmp' => $tmp, 'extension' => $types[$mime]];
    }
    return $validated;
}
function delete_local_image(string $path): void {
    if (preg_match('~^uploads/[a-f0-9]{40}\.(jpg|png|webp)$~', $path)) @unlink(dirname(__DIR__) . '/public/' . $path);
}
function save_place(array $d): array {
    $u = require_user();
    $id = !empty($d['id']) ? int_field($d, 'id') : null;
    $existing = null;
    if ($id) {
        $existing = one('SELECT * FROM places WHERE id=?', [$id]);
        if (!$existing) fail('Place not found.', 404);
        if ($u['role'] !== 'admin' && $existing['user_id'] != $u['id']) fail('You can only edit your own places.', 403);
    }
    $data = [];
    foreach (['title' => 160, 'category' => 60, 'region' => 100, 'description' => 12000, 'route_from_colombo' => 6000, 'conservation_notes' => 4000, 'safety_notes' => 4000] as $field => $max) $data[$field] = string_field($d, $field, $max, true);
    foreach (['accessibility' => 3000, 'entry_fee' => 190, 'opening_hours' => 190, 'contact' => 190, 'best_time' => 190, 'difficulty' => 40] as $field => $max) $data[$field] = string_field($d, $field, $max);
    $data['latitude'] = coordinate($d, 'latitude', -90, 90); $data['longitude'] = coordinate($d, 'longitude', -180, 180);
    $data['visit_minutes'] = int_field($d, 'visit_minutes', 15, 720, 60);
    $data['status'] = $u['role'] === 'admin' ? ($existing['status'] ?? 'approved') : 'pending';
    $data['moderation_note'] = null;
    $images = uploaded_images();
    $remove = $d['remove_image_ids'] ?? [];
    if (is_string($remove)) $remove = json_decode($remove, true);
    if (!is_array($remove) || count($remove) > 8) fail('Invalid image removal list.');
    $remove = array_values(array_unique(array_map(fn($v) => int_field(['id' => $v], 'id'), $remove)));
    $oldImages = $id ? all('SELECT id,path FROM place_images WHERE place_id=?', [$id]) : [];
    foreach ($remove as $removeId) if (!in_array($removeId, array_column($oldImages, 'id'))) fail('Image does not belong to this place.', 403);
    $count = count($oldImages) - count($remove) + count($images);
    if ($count < 1 || $count > 8) fail('Every place needs between 1 and 8 images.');
    $services = null;
    if (array_key_exists('services_json', $d)) {
        $services = is_string($d['services_json']) ? json_decode($d['services_json'], true) : $d['services_json'];
        if (!is_array($services) || count($services) > 30) fail('Provide no more than 30 nearby services.');
        $services = array_map(function ($s) { if (!is_array($s)) fail('Invalid nearby service.'); return ['id' => isset($s['id']) ? int_field($s, 'id') : null, 'data' => service_data($s)]; }, $services);
    }
    $moved = [];
    db()->beginTransaction();
    try {
        if ($id) update_row('places', $id, $data); else $id = insert_row('places', ['user_id' => $u['id'], ...$data]);
        foreach ($images as $img) {
            $path = 'uploads/' . bin2hex(random_bytes(20)) . '.' . $img['extension'];
            $dest = dirname(__DIR__) . '/public/' . $path;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) throw new RuntimeException('Upload directory unavailable.');
            if (!move_uploaded_file($img['tmp'], $dest)) throw new RuntimeException('Could not save image.');
            chmod($dest, 0644); $moved[] = $path;
            insert_row('place_images', ['place_id' => $id, 'path' => $path, 'caption' => $data['title']]);
        }
        foreach ($remove as $rid) query('DELETE FROM place_images WHERE id=? AND place_id=?', [$rid, $id]);
        if ($services !== null) {
            $kept = [];
            foreach ($services as $s) {
                if ($s['id']) {
                    $old = one('SELECT * FROM services WHERE id=? AND place_id=?', [$s['id'], $id]);
                    if (!$old) fail('Service does not belong to this place.', 403);
                    $kept[] = $s['id'];
                    // Admin-maintained/sponsored listings cannot be changed indirectly by a traveller.
                    $hasPayment = one('SELECT id FROM payments WHERE service_id=? LIMIT 1', [$s['id']]);
                    if ($u['role'] === 'admin' || ($old['submitted_by'] == $u['id'] && !$hasPayment)) update_row('services', $s['id'], $s['data']);
                } else $kept[] = insert_row('services', ['place_id' => $id, 'submitted_by' => $u['id'], ...$s['data']]);
            }
            $oldServices = all('SELECT id,submitted_by FROM services WHERE place_id=?', [$id]);
            foreach ($oldServices as $old) {
                if (in_array($old['id'], $kept)) continue;
                if ($u['role'] === 'admin' || ($old['submitted_by'] == $u['id'] && !one('SELECT id FROM payments WHERE service_id=? LIMIT 1', [$old['id']]))) query('DELETE FROM services WHERE id=?', [$old['id']]);
            }
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); foreach ($moved as $path) delete_local_image($path); throw $e; }
    foreach ($oldImages as $old) if (in_array($old['id'], $remove)) delete_local_image($old['path']);
    return get_place($id);
}
