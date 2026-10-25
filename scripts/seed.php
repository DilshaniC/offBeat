<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

// Idempotent demonstration data only. Never resets existing users or content.
$adminEmail = getenv('SEED_ADMIN_EMAIL') ?: 'admin@offbeat.local';
$adminPassword = getenv('SEED_ADMIN_PASSWORD');
$existing = one('SELECT id,role FROM users WHERE email=?', [$adminEmail]);
if ($existing && $existing['role'] !== 'admin') { fwrite(STDERR, "That email belongs to a traveller. Choose another administrator email.\n"); exit(1); }
if (!$existing && (!$adminPassword || strlen($adminPassword) < 12)) { fwrite(STDERR, "Set SEED_ADMIN_PASSWORD to a unique password of at least 12 characters. Optional SEED_ADMIN_EMAIL sets the administrator email.\n"); exit(1); }
db()->beginTransaction();
try {
    $adminId = $existing ? (int)$existing['id'] : insert_row('users', ['name' => 'Offbeat Administrator', 'email' => $adminEmail, 'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT), 'role' => 'admin']);
    $demo = [
        ['The Quiet Canopy', 'Forest', 'Makandura', 6.1610, 80.5620, 'forest', 'A slower kind of escape beneath a green canopy. This fictional example shows how a community contributor can describe a shaded forest walk, its birdlife and the feeling of leaving the busy road behind.', 90, 'Easy'],
        ['Hidden Falls Trail', 'Waterfall', 'Mulatiyana', 6.1970, 80.5830, 'waterfall', 'An illustrative waterfall walk with a shaded approach and time to pause by the water. A real submission should describe the last-mile path, landowner permission and seasonal water conditions.', 120, 'Moderate'],
        ['Tea Country Morning', 'Tea estate', 'Makandura', 6.1790, 80.5450, 'tea', 'An example of a small tea-country discovery: early light, green slopes and a chance to learn how a rural community works. Estate and factory visits always need prior permission.', 90, 'Easy'],
        ['Riverbend Retreat', 'River', 'Akuressa', 6.1190, 80.4990, 'river', 'This fictional riverbank stop illustrates a quiet picnic place and an easy break on a rural day out. A published record should state whether public access is permitted and identify safe areas to sit.', 60, 'Easy'],
        ['Hilltop Golden Hour', 'Viewpoint', 'Mulatiyana', 6.2110, 80.5580, 'hills', 'A demonstration viewpoint for travellers who enjoy wide skies and a gentle climb. A real contributor should describe the gradient, exposed sections and how to return before dark.', 90, 'Moderate'],
        ['Village Temple Path', 'Culture', 'Makandura', 6.1530, 80.5390, 'forest', 'An illustrative cultural visit centred on quiet observation and respect for village life. Add verified temple history, visiting permission and local customs when replacing this example.', 45, 'Easy'],
        ['Bamboo Brook Walk', 'Nature', 'Deiyandara', 6.1590, 80.5940, 'river', 'A fictional short walk beside a shaded brook, designed as an example of a gentle nature stop. Real access notes should cover slippery ground, crossings and nearby community facilities.', 60, 'Easy'],
        ['The Green Ridge', 'Hiking', 'Mulatiyana', 6.2260, 80.6010, 'hills', 'An illustrative ridge walk for a longer escape into the countryside. Use this example to see how hiking records combine photographs, coordinates, realistic duration and essential preparation.', 150, 'Challenging'],
        ['Paddy Field Afternoons', 'Nature', 'Hakmana', 6.1050, 80.6140, 'tea', 'A demonstration rural landscape entry celebrating the quiet rhythm of agricultural life. Working fields are private livelihoods; actual visitor routes must stay on permitted public paths.', 45, 'Easy'],
        ['Old Village Footpath', 'Heritage', 'Kamburupitiya', 6.0820, 80.5590, 'forest', 'An example heritage walk connecting stories, landscape and community. Replace the sample with locally checked history and obtain consent before sharing stories or photographing residents.', 75, 'Easy'],
    ];
    $count = 0;
    foreach ($demo as [$title,$category,$region,$lat,$lng,$image,$description,$duration,$difficulty]) {
        if (one('SELECT id FROM places WHERE title=? AND is_demo=1', [$title])) continue;
        $id = insert_row('places', [
            'user_id'=>$adminId, 'title'=>$title, 'category'=>$category, 'region'=>$region,
            'description'=>$description . "\n\nDEMONSTRATION ENTRY: this is a fictional destination with an illustrative map pin and stock photography. It is not verified travel guidance.",
            'latitude'=>$lat, 'longitude'=>$lng,
            'route_from_colombo'=>'Example route format only: travel from Colombo toward Matara, then use the inland connection toward Makandura. A real listing must specify current bus connections or road names, the correct turn-off, parking and the final walking approach. This demonstration pin is not a navigable destination; confirm the complete route with the administrator before travel.',
            'conservation_notes'=>'Bring a reusable water bottle and carry every piece of waste home. Stay on permitted paths; leave plants, stones and wildlife undisturbed. Keep music off, respect local homes and ask before photographing people. Follow closures and landowner instructions.',
            'safety_notes'=>'Access, weather, water levels and mobile coverage have not been verified for this demonstration. Do not use these coordinates for an actual journey. For real visits, confirm local conditions, avoid water during heavy rain and tell someone your route.',
            'accessibility'=>'Not assessed. A real contributor must describe steps, surface, gradient and available assistance.',
            'entry_fee'=>'Not verified — ask before visiting', 'opening_hours'=>'Access hours not verified',
            'best_time'=>'Early morning, subject to verified weather and access', 'difficulty'=>$difficulty,
            'visit_minutes'=>$duration, 'status'=>'approved', 'is_demo'=>1,
        ]);
        insert_row('place_images', ['place_id'=>$id,'path'=>'assets/images/'.$image.'.jpg','caption'=>'Illustrative nature photograph; not an image of a verified destination.']);
        $count++;
    }
    db()->commit();
    echo "Administrator available. Added $count clearly labelled demonstration places. Existing data preserved.\n";
} catch (Throwable $e) { if(db()->inTransaction()) db()->rollBack(); fwrite(STDERR,"Seeding failed: " . $e->getMessage() . "\n"); exit(1); }

