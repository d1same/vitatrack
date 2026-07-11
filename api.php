<?php
// JSON API — all endpoints. Called as api.php?action=<name> with a JSON body.
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/calc.php';
require __DIR__ . '/includes/ai.php';
require __DIR__ . '/includes/off.php';
require __DIR__ . '/includes/push.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

function out(array $data): never { echo json_encode($data); exit; }
function fail(string $msg, int $code = 400): never { http_response_code($code); out(['ok' => false, 'error' => $msg]); }
function today(): string { return $_GET['tzdate'] ?? date('Y-m-d'); }

function get_profile(int $uid): array {
    $p = db()->prepare("SELECT * FROM profiles WHERE user_id=?");
    $p->execute([$uid]);
    return $p->fetch() ?: [];
}
function get_settings(int $uid): array {
    $st = db()->prepare("SELECT key,value FROM settings WHERE user_id=?");
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['key']] = $r['value'];
    unset($out['anthropic_key']); // never send the key back to the client
    return $out;
}
function public_user(array $u): array { return ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email']]; }

try {
switch ($action) {

// ── Auth ─────────────────────────────────────────────────────────────
case 'register': {
    $name = trim((string)($in['name'] ?? ''));
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pass = (string)($in['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid name and email');
    if (strlen($pass) < 6) fail('Password must be at least 6 characters');
    $st = db()->prepare("SELECT id FROM users WHERE email=?");
    $st->execute([$email]);
    if ($st->fetch()) fail('An account with this email already exists');
    db()->prepare("INSERT INTO users (name,email,pass_hash) VALUES (?,?,?)")
        ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
    $uid = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO profiles (user_id) VALUES (?)")->execute([$uid]);
    login_user($uid);
    out(['ok' => true, 'user' => ['id' => $uid, 'name' => $name, 'email' => $email], 'profile' => get_profile($uid), 'settings' => []]);
}

case 'login': {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $st = db()->prepare("SELECT * FROM users WHERE email=?");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['pass_hash'])) {
        fail('Wrong email or password', 401);
    }
    login_user((int)$u['id']);
    out(['ok' => true, 'user' => public_user($u), 'profile' => get_profile((int)$u['id']), 'settings' => get_settings((int)$u['id'])]);
}

case 'logout': logout_user(); out(['ok' => true]);

case 'change_password': {
    $uid = require_user();
    $st = db()->prepare("SELECT pass_hash FROM users WHERE id=?");
    $st->execute([$uid]);
    if (!password_verify((string)($in['current'] ?? ''), $st->fetch()['pass_hash'] ?? '')) {
        fail('Current password is wrong');
    }
    if (strlen((string)($in['new'] ?? '')) < 6) fail('New password must be at least 6 characters');
    db()->prepare("UPDATE users SET pass_hash=? WHERE id=?")
      ->execute([password_hash((string)$in['new'], PASSWORD_DEFAULT), $uid]);
    out(['ok' => true]);
}

case 'request_reset': {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $generic = 'If that email is registered, a reset link is on its way. Check spam too.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(['ok' => true, 'message' => $generic]);
    $st = db()->prepare("SELECT id, reset_requested FROM users WHERE email=?");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) out(['ok' => true, 'message' => $generic]);
    if ((int)($u['reset_requested'] ?? 0) > time() - 120) out(['ok' => true, 'message' => $generic]); // throttle
    $token = bin2hex(random_bytes(32));
    db()->prepare("UPDATE users SET reset_token=?, reset_expires=?, reset_requested=? WHERE id=?")
      ->execute([hash('sha256', $token), time() + 3600, time(), $u['id']]);
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/index.php?reset=' . $token;
    $sent = @mail(
        $email,
        'VitaTrack password reset',
        "Someone requested a password reset for your VitaTrack account.\n\n"
        . "Reset link (valid for 1 hour):\n$link\n\n"
        . "If this wasn't you, you can ignore this email.",
        "From: VitaTrack <noreply@$host>\r\nContent-Type: text/plain; charset=UTF-8"
    );
    if (!$sent) fail('This server could not send email — ask the site owner to reset your password.');
    out(['ok' => true, 'message' => $generic]);
}

case 'reset_password': {
    $token = (string)($in['token'] ?? '');
    $pass = (string)($in['password'] ?? '');
    if (strlen($pass) < 6) fail('Password must be at least 6 characters');
    if (strlen($token) !== 64 || !ctype_xdigit($token)) fail('Invalid or expired reset link — request a new one');
    $st = db()->prepare("SELECT id FROM users WHERE reset_token=? AND reset_expires > ?");
    $st->execute([hash('sha256', $token), time()]);
    $u = $st->fetch();
    if (!$u) fail('Invalid or expired reset link — request a new one');
    db()->prepare("UPDATE users SET pass_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
      ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    out(['ok' => true]);
}

case 'me': {
    $uid = current_user_id();
    if (!$uid) out(['ok' => true, 'user' => null]);
    $st = db()->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) { logout_user(); out(['ok' => true, 'user' => null]); }
    out(['ok' => true, 'user' => public_user($u), 'profile' => get_profile($uid), 'settings' => get_settings($uid)]);
}

// ── Profile & plan ───────────────────────────────────────────────────
case 'save_profile': {
    $uid = require_user();
    $p = get_profile($uid);
    foreach (['sex','diet','fasting_plan','units'] as $k) if (isset($in[$k])) $p[$k] = substr((string)$in[$k], 0, 30);
    foreach (['birth_year'] as $k) if (isset($in[$k])) $p[$k] = (int)$in[$k];
    foreach (['height_cm','activity','start_weight_kg','goal_weight_kg','body_fat','weekly_rate'] as $k) {
        if (isset($in[$k])) $p[$k] = (float)$in[$k];
    }
    if (isset($in['health_issues']) && is_array($in['health_issues'])) {
        $p['health_issues'] = json_encode(array_slice(array_map('strval', $in['health_issues']), 0, 20));
    }
    $t = compute_targets($p);
    db()->prepare("UPDATE profiles SET sex=?,birth_year=?,height_cm=?,activity=?,start_weight_kg=?,goal_weight_kg=?,
        body_fat=?,diet=?,fasting_plan=?,health_issues=?,units=?,weekly_rate=?,
        kcal_target=?,protein_g=?,carbs_g=?,fat_g=?,water_ml=?,onboarded=1 WHERE user_id=?")
      ->execute([$p['sex'],$p['birth_year'],$p['height_cm'],$p['activity'],$p['start_weight_kg'],$p['goal_weight_kg'],
        $p['body_fat'],$p['diet'],$p['fasting_plan'],$p['health_issues'],$p['units'],$p['weekly_rate'],
        $t['kcal_target'],$t['protein_g'],$t['carbs_g'],$t['fat_g'],$t['water_ml'],$uid]);
    // Log starting weight if provided and no entry today
    if (!empty($p['start_weight_kg'])) {
        db()->prepare("INSERT INTO weights (user_id,date,weight_kg,body_fat) VALUES (?,?,?,?)
            ON CONFLICT(user_id,date) DO UPDATE SET weight_kg=excluded.weight_kg, body_fat=excluded.body_fat")
          ->execute([$uid, today(), $p['start_weight_kg'], $p['body_fat'] ?: null]);
    }
    out(['ok' => true, 'profile' => get_profile($uid), 'targets' => $t]);
}

// ── Weight ───────────────────────────────────────────────────────────
case 'log_weight': {
    $uid = require_user();
    $w = (float)($in['weight_kg'] ?? 0);
    if ($w < 20 || $w > 400) fail('Please enter a valid weight');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
    $bf = isset($in['body_fat']) && $in['body_fat'] !== '' ? (float)$in['body_fat'] : null;
    db()->prepare("INSERT INTO weights (user_id,date,weight_kg,body_fat) VALUES (?,?,?,?)
        ON CONFLICT(user_id,date) DO UPDATE SET weight_kg=excluded.weight_kg, body_fat=excluded.body_fat")
      ->execute([$uid, $date, $w, $bf]);
    out(['ok' => true]);
}

case 'weights': {
    $uid = require_user();
    $st = db()->prepare("SELECT id, date, weight_kg, body_fat FROM weights WHERE user_id=? ORDER BY date");
    $st->execute([$uid]);
    out(['ok' => true, 'weights' => $st->fetchAll()]);
}

case 'del_weight': {
    $uid = require_user();
    db()->prepare("DELETE FROM weights WHERE id=? AND user_id=?")->execute([(int)($in['id'] ?? 0), $uid]);
    out(['ok' => true]);
}

// ── Day summary (dashboard) ─────────────────────────────────────────
case 'day': {
    $uid = require_user();
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
    $st = db()->prepare("SELECT * FROM diary WHERE user_id=? AND date=? ORDER BY id");
    $st->execute([$uid, $date]);
    $entries = $st->fetchAll();
    $st = db()->prepare("SELECT ml FROM water WHERE user_id=? AND date=?");
    $st->execute([$uid, $date]);
    $water = (int)($st->fetch()['ml'] ?? 0);
    $st = db()->prepare("SELECT * FROM workouts WHERE user_id=? AND date=? ORDER BY id");
    $st->execute([$uid, $date]);
    $workouts = $st->fetchAll();
    $st = db()->prepare("SELECT * FROM fasts WHERE user_id=? AND end_ts IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    $activeFast = $st->fetch() ?: null;
    $st = db()->prepare("SELECT weight_kg FROM weights WHERE user_id=? ORDER BY date DESC LIMIT 1");
    $st->execute([$uid]);
    $lastWeight = $st->fetch()['weight_kg'] ?? null;
    out(['ok' => true, 'date' => $date, 'entries' => $entries, 'water' => $water,
         'workouts' => $workouts, 'active_fast' => $activeFast, 'last_weight' => $lastWeight]);
}

// ── Food & diary ─────────────────────────────────────────────────────
case 'foods': {
    $uid = require_user();
    $q = trim((string)($in['q'] ?? ''));
    if ($q === '') {
        $st = db()->prepare("SELECT * FROM foods WHERE user_id IS NULL OR user_id=? ORDER BY user_id DESC, name LIMIT 60");
        $st->execute([$uid]);
    } else {
        $st = db()->prepare("SELECT * FROM foods WHERE (user_id IS NULL OR user_id=?) AND name LIKE ? ORDER BY user_id DESC, name LIMIT 60");
        $st->execute([$uid, '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%']);
    }
    out(['ok' => true, 'foods' => $st->fetchAll()]);
}

case 'add_food': {
    $uid = require_user();
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Food name is required');
    $keto = ((float)($in['carbs'] ?? 0) - (float)($in['fiber'] ?? 0)) <= 8 ? 1 : 0;
    $st = db()->prepare("SELECT id FROM foods WHERE user_id=? AND name=?");
    $st->execute([$uid, substr($name,0,80)]);
    if ($ex = $st->fetch()) {
        db()->prepare("UPDATE foods SET kcal=?,protein=?,carbs=?,fat=?,fiber=?,keto=? WHERE id=?")
          ->execute([(float)($in['kcal'] ?? 0), (float)($in['protein'] ?? 0), (float)($in['carbs'] ?? 0),
                     (float)($in['fat'] ?? 0), (float)($in['fiber'] ?? 0), $keto, $ex['id']]);
        out(['ok' => true, 'id' => (int)$ex['id']]);
    }
    db()->prepare("INSERT INTO foods (user_id,name,kcal,protein,carbs,fat,fiber,keto) VALUES (?,?,?,?,?,?,?,?)")
      ->execute([$uid, substr($name,0,80), (float)($in['kcal'] ?? 0), (float)($in['protein'] ?? 0),
                 (float)($in['carbs'] ?? 0), (float)($in['fat'] ?? 0), (float)($in['fiber'] ?? 0), $keto]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

case 'recent_foods': {
    $uid = require_user();
    // most recently logged distinct foods (values from the latest entry of each)
    $st = db()->prepare("SELECT name, grams, kcal, protein, carbs, fat, fiber, sugar, sodium, satfat, MAX(id) mid
        FROM diary WHERE user_id=? GROUP BY name ORDER BY mid DESC LIMIT 10");
    $st->execute([$uid]);
    $recent = $st->fetchAll();
    // most frequently logged
    $st = db()->prepare("SELECT name, grams, kcal, protein, carbs, fat, fiber, sugar, sodium, satfat, COUNT(*) c, MAX(id) mid
        FROM diary WHERE user_id=? GROUP BY name HAVING c >= 2 ORDER BY c DESC LIMIT 10");
    $st->execute([$uid]);
    $frequent = $st->fetchAll();
    out(['ok' => true, 'recent' => $recent, 'frequent' => $frequent]);
}

case 'barcode': {
    require_user();
    $code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
    if (strlen($code) < 6 || strlen($code) > 14) fail('That doesn\'t look like a product barcode');
    $f = off_lookup($code);
    if (!$f) fail('Product not found in the Open Food Facts database — try Photo scan or add it as Custom');
    out(['ok' => true, 'food' => $f]);
}

case 'off_search': {
    require_user();
    $q = trim((string)($in['q'] ?? ''));
    if (strlen($q) < 2) fail('Type at least 2 characters');
    out(['ok' => true, 'foods' => off_search($q)]);
}

case 'log_food': {
    $uid = require_user();
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
    $meal = in_array($in['meal'] ?? '', ['breakfast','lunch','dinner','snacks']) ? $in['meal'] : 'snacks';
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Missing food name');
    db()->prepare("INSERT INTO diary (user_id,date,meal,name,grams,kcal,protein,carbs,fat,fiber,sugar,sodium,satfat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$uid, $date, $meal, substr($name,0,100), (float)($in['grams'] ?? 100),
                 round((float)($in['kcal'] ?? 0),1), round((float)($in['protein'] ?? 0),1),
                 round((float)($in['carbs'] ?? 0),1), round((float)($in['fat'] ?? 0),1), round((float)($in['fiber'] ?? 0),1),
                 round((float)($in['sugar'] ?? 0),1), round((float)($in['sodium'] ?? 0)), round((float)($in['satfat'] ?? 0),1)]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

// ── Saved meals (log your usual meals in one tap) ────────────────────
case 'save_meal': {
    $uid = require_user();
    $name = trim((string)($in['name'] ?? ''));
    $items = $in['items'] ?? [];
    if ($name === '' || !is_array($items) || !count($items)) fail('Meal needs a name and at least one food');
    $clean = [];
    foreach (array_slice($items, 0, 30) as $it) {
        $clean[] = [
            'name' => substr((string)($it['name'] ?? 'Item'), 0, 100),
            'grams' => (float)($it['grams'] ?? 100),
            'kcal' => (float)($it['kcal'] ?? 0), 'protein' => (float)($it['protein'] ?? 0),
            'carbs' => (float)($it['carbs'] ?? 0), 'fat' => (float)($it['fat'] ?? 0),
            'fiber' => (float)($it['fiber'] ?? 0), 'sugar' => (float)($it['sugar'] ?? 0),
            'sodium' => (float)($it['sodium'] ?? 0), 'satfat' => (float)($it['satfat'] ?? 0),
        ];
    }
    db()->prepare("INSERT INTO meals (user_id,name,items) VALUES (?,?,?)")
      ->execute([$uid, substr($name,0,60), json_encode($clean)]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

case 'meals': {
    $uid = require_user();
    $st = db()->prepare("SELECT * FROM meals WHERE user_id=? ORDER BY name");
    $st->execute([$uid]);
    $rows = array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name'], 'items' => json_decode($m['items'], true)], $st->fetchAll());
    out(['ok' => true, 'meals' => $rows]);
}

case 'del_meal': {
    $uid = require_user();
    db()->prepare("DELETE FROM meals WHERE id=? AND user_id=?")->execute([(int)($in['id'] ?? 0), $uid]);
    out(['ok' => true]);
}

// ── Biometrics (blood pressure, glucose, ketones, sleep, steps…) ────
case 'log_bio': {
    $uid = require_user();
    $types = ['bp','glucose','ketones','rhr','sleep','steps'];
    $type = in_array($in['type'] ?? '', $types, true) ? $in['type'] : null;
    if (!$type) fail('Unknown metric type');
    $v1 = (float)($in['v1'] ?? 0);
    if ($v1 <= 0) fail('Enter a value');
    $v2 = isset($in['v2']) && $in['v2'] !== '' ? (float)$in['v2'] : null;
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
    db()->prepare("INSERT INTO biometrics (user_id,date,type,v1,v2) VALUES (?,?,?,?,?)")
      ->execute([$uid, $date, $type, $v1, $v2]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

case 'bios': {
    $uid = require_user();
    $st = db()->prepare("SELECT * FROM biometrics WHERE user_id=? ORDER BY date, id");
    $st->execute([$uid]);
    out(['ok' => true, 'bios' => $st->fetchAll()]);
}

case 'del_bio': {
    $uid = require_user();
    db()->prepare("DELETE FROM biometrics WHERE id=? AND user_id=?")->execute([(int)($in['id'] ?? 0), $uid]);
    out(['ok' => true]);
}

// ── Daily lessons (drip course) ──────────────────────────────────────
case 'lessons': {
    $uid = require_user();
    $lessons = db()->query("SELECT id,ord,emoji,category,title,body FROM lessons ORDER BY ord")->fetchAll();
    $st = db()->prepare("SELECT lesson_id, date FROM lesson_reads WHERE user_id=?");
    $st->execute([$uid]);
    $reads = [];
    foreach ($st->fetchAll() as $r) $reads[$r['lesson_id']] = $r['date'];
    out(['ok' => true, 'lessons' => $lessons, 'reads' => $reads, 'today' => today()]);
}

case 'lesson_read': {
    $uid = require_user();
    $id = (int)($in['id'] ?? 0);
    // Drip rule: only one NEW lesson per day
    $st = db()->prepare("SELECT COUNT(*) c FROM lesson_reads WHERE user_id=? AND date=?");
    $st->execute([$uid, today()]);
    $alreadyToday = (int)$st->fetch()['c'] > 0;
    $st = db()->prepare("SELECT COUNT(*) c FROM lesson_reads WHERE user_id=? AND lesson_id=?");
    $st->execute([$uid, $id]);
    if ((int)$st->fetch()['c'] === 0) {
        if ($alreadyToday) fail('One new lesson per day — come back tomorrow! 🌱');
        db()->prepare("INSERT INTO lesson_reads (user_id,lesson_id,date) VALUES (?,?,?)")->execute([$uid, $id, today()]);
    }
    out(['ok' => true]);
}

case 'del_food_entry': {
    $uid = require_user();
    db()->prepare("DELETE FROM diary WHERE id=? AND user_id=?")->execute([(int)($in['id'] ?? 0), $uid]);
    out(['ok' => true]);
}

// ── Water ────────────────────────────────────────────────────────────
case 'water': {
    $uid = require_user();
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
    $delta = (int)($in['delta'] ?? 0);
    db()->prepare("INSERT INTO water (user_id,date,ml) VALUES (?,?,MAX(0,?))
        ON CONFLICT(user_id,date) DO UPDATE SET ml=MAX(0, ml + ?)")
      ->execute([$uid, $date, $delta, $delta]);
    $st = db()->prepare("SELECT ml FROM water WHERE user_id=? AND date=?");
    $st->execute([$uid, $date]);
    out(['ok' => true, 'ml' => (int)$st->fetch()['ml']]);
}

// ── Fasting ──────────────────────────────────────────────────────────
case 'fast_start': {
    $uid = require_user();
    db()->prepare("UPDATE fasts SET end_ts=datetime('now') WHERE user_id=? AND end_ts IS NULL")->execute([$uid]);
    $hours = max(8, min(72, (float)($in['target_hours'] ?? 16)));
    db()->prepare("INSERT INTO fasts (user_id,start_ts,target_hours) VALUES (?,datetime('now'),?)")->execute([$uid, $hours]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

case 'fast_end': {
    $uid = require_user();
    db()->prepare("UPDATE fasts SET end_ts=datetime('now') WHERE user_id=? AND end_ts IS NULL")->execute([$uid]);
    out(['ok' => true]);
}

case 'fasts': {
    $uid = require_user();
    $st = db()->prepare("SELECT * FROM fasts WHERE user_id=? ORDER BY id DESC LIMIT 30");
    $st->execute([$uid]);
    out(['ok' => true, 'fasts' => $st->fetchAll(), 'server_now' => gmdate('Y-m-d H:i:s')]);
}

// ── Recipes & workouts ──────────────────────────────────────────────
case 'recipes': {
    require_user();
    out(['ok' => true, 'recipes' => db()->query("SELECT * FROM recipes ORDER BY tag, name")->fetchAll()]);
}

case 'exercises': {
    require_user();
    out(['ok' => true, 'exercises' => db()->query("SELECT * FROM exercises ORDER BY category, name")->fetchAll()]);
}

case 'log_workout': {
    $uid = require_user();
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Missing workout name');
    db()->prepare("INSERT INTO workouts (user_id,date,name,minutes,kcal) VALUES (?,?,?,?,?)")
      ->execute([$uid, $date, substr($name,0,80), (float)($in['minutes'] ?? 30), round((float)($in['kcal'] ?? 0))]);
    out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

case 'del_workout': {
    $uid = require_user();
    db()->prepare("DELETE FROM workouts WHERE id=? AND user_id=?")->execute([(int)($in['id'] ?? 0), $uid]);
    out(['ok' => true]);
}

// ── Progress ─────────────────────────────────────────────────────────
case 'progress': {
    $uid = require_user();
    $st = db()->prepare("SELECT id, date, weight_kg, body_fat FROM weights WHERE user_id=? ORDER BY date");
    $st->execute([$uid]);
    $weights = $st->fetchAll();
    $days = max(7, min(3650, (int)($in['days'] ?? 30)));
    $since = date('Y-m-d', strtotime("-$days days"));
    $st = db()->prepare("SELECT date, ROUND(SUM(kcal)) kcal, ROUND(SUM(protein),1) protein,
        ROUND(SUM(carbs-fiber),1) netcarbs FROM diary WHERE user_id=? AND date>=? GROUP BY date ORDER BY date");
    $st->execute([$uid, $since]);
    $daily = $st->fetchAll();
    $st = db()->prepare("SELECT date, ml FROM water WHERE user_id=? AND date>=? ORDER BY date");
    $st->execute([$uid, $since]);
    $water = $st->fetchAll();
    $st = db()->prepare("SELECT COUNT(DISTINCT date) c FROM diary WHERE user_id=?");
    $st->execute([$uid]);
    $daysLogged = (int)$st->fetch()['c'];
    // Logging streak: consecutive days with diary entries ending today/yesterday
    $st = db()->prepare("SELECT DISTINCT date FROM diary WHERE user_id=? ORDER BY date DESC LIMIT 120");
    $st->execute([$uid]);
    $dates = array_column($st->fetchAll(), 'date');
    $streak = 0;
    $cursor = today();
    if ($dates && $dates[0] !== $cursor) $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
    foreach ($dates as $d) {
        if ($d === $cursor) { $streak++; $cursor = date('Y-m-d', strtotime($cursor . ' -1 day')); }
        else break;
    }
    out(['ok' => true, 'weights' => $weights, 'daily' => $daily, 'water' => $water,
         'days_logged' => $daysLogged, 'streak' => $streak]);
}

// ── Settings ─────────────────────────────────────────────────────────
case 'save_settings': {
    $uid = require_user();
    $allowed = ['anthropic_key','reminders_water','reminders_meals','reminders_weight','theme','water_glass_ml'];
    $st = db()->prepare("INSERT INTO settings (user_id,key,value) VALUES (?,?,?)
        ON CONFLICT(user_id,key) DO UPDATE SET value=excluded.value");
    foreach ($in as $k => $v) {
        if (in_array($k, $allowed, true)) $st->execute([$uid, $k, substr((string)$v, 0, 300)]);
    }
    out(['ok' => true, 'settings' => get_settings($uid)]);
}

case 'has_api_key': {
    $uid = require_user();
    $st = db()->prepare("SELECT value FROM settings WHERE user_id=? AND key='anthropic_key'");
    $st->execute([$uid]);
    $v = $st->fetch()['value'] ?? '';
    out(['ok' => true, 'has_key' => $v !== '']);
}

// ── Web Push (background notifications) ─────────────────────────────
case 'vapid_key': {
    require_user();
    out(['ok' => true, 'key' => vapid_public_key()]);
}

case 'push_subscribe': {
    $uid = require_user();
    $ep = (string)($in['endpoint'] ?? '');
    if (!preg_match('#^https://#', $ep) || strlen($ep) > 600) fail('Bad subscription endpoint');
    db()->prepare("INSERT INTO push_subs (user_id, endpoint, tz_offset) VALUES (?,?,?)
        ON CONFLICT(endpoint) DO UPDATE SET user_id=excluded.user_id, tz_offset=excluded.tz_offset")
      ->execute([$uid, $ep, max(-840, min(840, (int)($in['tz_offset'] ?? 0)))]);
    out(['ok' => true]);
}

case 'push_unsubscribe': {
    $uid = require_user();
    db()->prepare("DELETE FROM push_subs WHERE endpoint=? AND user_id=?")
      ->execute([(string)($in['endpoint'] ?? ''), $uid]);
    out(['ok' => true]);
}

// Called by the service worker when a push wakes it: returns what to show.
case 'due_reminder': {
    $uid = require_user();
    $st = db()->prepare("SELECT value FROM settings WHERE user_id=? AND key='pending_push'");
    $st->execute([$uid]);
    $v = $st->fetch()['value'] ?? null;
    if ($v) {
        db()->prepare("DELETE FROM settings WHERE user_id=? AND key='pending_push'")->execute([$uid]);
        $d = json_decode($v, true);
        if (is_array($d) && !empty($d['title'])) out(['ok' => true, 'title' => $d['title'], 'body' => $d['body'] ?? '']);
    }
    out(['ok' => true, 'title' => 'VitaTrack', 'body' => 'Time for a healthy habit — log your day.']);
}

// ── AI photo scan ────────────────────────────────────────────────────
case 'analyze_photo': {
    $uid = require_user();
    $st = db()->prepare("SELECT value FROM settings WHERE user_id=? AND key='anthropic_key'");
    $st->execute([$uid]);
    $key = trim((string)($st->fetch()['value'] ?? ''));
    if ($key === '') fail('Add an API key in Settings first (Settings → AI Photo Scan) — Ollama Cloud (free), Anthropic, or OpenAI');
    $result = analyze_food_photo($key, (string)($in['image'] ?? ''));
    out($result['ok'] ? ['ok' => true, 'items' => $result['items'], 'notes' => $result['notes']] : ['ok' => false, 'error' => $result['error']]);
}

default: fail('Unknown action', 404);
}
} catch (Throwable $e) {
    http_response_code(500);
    out(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
