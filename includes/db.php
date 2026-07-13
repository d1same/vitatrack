<?php
// Database layer — SQLite via PDO. Creates schema + seed data on first run,
// and upgrades older databases in place (ensure_cols).

// Polyfill for hosts still on PHP 7.x (str_starts_with is PHP 8.0+)
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $pdo = new PDO('sqlite:' . $dir . '/health.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);
    return $pdo;
}

function ensure_cols(PDO $pdo, string $table, array $cols): bool {
    $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $added = false;
    foreach ($cols as $name => $ddl) {
        if (!in_array($name, $have, true)) { $pdo->exec("ALTER TABLE $table ADD COLUMN $name $ddl"); $added = true; }
    }
    return $added;
}

const SCHEMA_VERSION = 19; // bump when schema or seed content changes

function init_schema(PDO $pdo): void {
    // Fast path: schema already current — skip all migration/seed checks
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() === SCHEMA_VERSION) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        pass_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS profiles (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        sex TEXT DEFAULT 'male',
        birth_year INTEGER,
        height_cm REAL,
        activity REAL DEFAULT 1.375,
        start_weight_kg REAL,
        goal_weight_kg REAL,
        body_fat REAL,
        diet TEXT DEFAULT 'keto',
        fasting_plan TEXT DEFAULT '16:8',
        health_issues TEXT DEFAULT '[]',
        units TEXT DEFAULT 'metric',
        weekly_rate REAL DEFAULT 0.5,
        kcal_target INTEGER,
        protein_g INTEGER,
        carbs_g INTEGER,
        fat_g INTEGER,
        water_ml INTEGER DEFAULT 2500,
        onboarded INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS weights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        weight_kg REAL NOT NULL,
        body_fat REAL,
        UNIQUE(user_id, date)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS foods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        kcal REAL NOT NULL, protein REAL DEFAULT 0, carbs REAL DEFAULT 0,
        fat REAL DEFAULT 0, fiber REAL DEFAULT 0,
        keto INTEGER DEFAULT 0,
        sugar REAL DEFAULT 0, sodium REAL DEFAULT 0, satfat REAL DEFAULT 0,
        serving_g REAL, serving_label TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS diary (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        meal TEXT NOT NULL,
        name TEXT NOT NULL,
        grams REAL DEFAULT 100,
        kcal REAL NOT NULL, protein REAL DEFAULT 0, carbs REAL DEFAULT 0,
        fat REAL DEFAULT 0, fiber REAL DEFAULT 0,
        sugar REAL DEFAULT 0, sodium REAL DEFAULT 0, satfat REAL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_diary ON diary(user_id, date)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS water (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        ml INTEGER DEFAULT 0,
        PRIMARY KEY(user_id, date)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fasts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        start_ts TEXT NOT NULL,
        end_ts TEXT,
        target_hours REAL DEFAULT 16
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS workouts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        name TEXT NOT NULL,
        minutes REAL DEFAULT 30,
        kcal REAL DEFAULT 0,
        source TEXT DEFAULT 'manual',
        ext_id TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        key TEXT NOT NULL,
        value TEXT,
        PRIMARY KEY(user_id, key)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, tag TEXT, minutes INTEGER,
        kcal REAL, protein REAL, carbs REAL, fat REAL, fiber REAL,
        ingredients TEXT, instructions TEXT, emoji TEXT,
        diet TEXT DEFAULT 'keto',
        heart INTEGER DEFAULT 0,      -- heart/cholesterol-friendly (low sat fat)
        lowsodium INTEGER DEFAULT 0,  -- blood-pressure-friendly
        diabetic INTEGER DEFAULT 0,   -- low sugar, moderate carbs
        cuisine TEXT DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS exercises (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, category TEXT, difficulty TEXT,
        back_safe INTEGER DEFAULT 1, low_impact INTEGER DEFAULT 1,
        kcal30 INTEGER DEFAULT 100, description TEXT, emoji TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS meals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        items TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS biometrics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        type TEXT NOT NULL,
        v1 REAL NOT NULL,
        v2 REAL,
        source TEXT DEFAULT 'manual'
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lessons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ord INTEGER, emoji TEXT, category TEXT, title TEXT, body TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipe_favs (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        PRIMARY KEY(user_id, name)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL UNIQUE,
        expires INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        endpoint TEXT NOT NULL UNIQUE,
        tz_offset INTEGER DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_reads (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
        date TEXT NOT NULL,
        PRIMARY KEY(user_id, lesson_id)
    )");

    // In-place upgrade of older databases
    $foodsUpgraded = ensure_cols($pdo, 'foods', ['sugar' => 'REAL DEFAULT 0', 'sodium' => 'REAL DEFAULT 0', 'satfat' => 'REAL DEFAULT 0']);
    ensure_cols($pdo, 'foods', ['serving_g' => 'REAL', 'serving_label' => 'TEXT']);
    ensure_cols($pdo, 'diary', ['sugar' => 'REAL DEFAULT 0', 'sodium' => 'REAL DEFAULT 0', 'satfat' => 'REAL DEFAULT 0']);
    $recipesUpgraded = ensure_cols($pdo, 'recipes', ['diet' => "TEXT DEFAULT 'keto'", 'heart' => 'INTEGER DEFAULT 0', 'lowsodium' => 'INTEGER DEFAULT 0', 'diabetic' => 'INTEGER DEFAULT 0', 'cuisine' => "TEXT DEFAULT ''"]);
    $foodsUpgraded = $foodsUpgraded || $pdo->query("SELECT COUNT(*) c FROM foods WHERE user_id IS NULL AND name LIKE 'Ghormeh%'")->fetch()['c'] == 0;
    $recipesUpgraded = $recipesUpgraded || $pdo->query("SELECT COUNT(*) c FROM recipes WHERE name='Protein Pancakes'")->fetch()['c'] == 0;
    ensure_cols($pdo, 'users', ['reset_token' => 'TEXT', 'reset_expires' => 'INTEGER', 'reset_requested' => 'INTEGER']);
    ensure_cols($pdo, 'biometrics', ['source' => "TEXT DEFAULT 'manual'"]);
    ensure_cols($pdo, 'workouts', ['source' => "TEXT DEFAULT 'manual'", 'ext_id' => 'TEXT']);

    seed($pdo, $foodsUpgraded, $recipesUpgraded);
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

function seed(PDO $pdo, bool $reseedFoods = false, bool $reseedRecipes = false): void {
    if ($reseedFoods) $pdo->exec("DELETE FROM foods WHERE user_id IS NULL"); // diary stores copies, so this is safe
    if ($reseedRecipes) $pdo->exec("DELETE FROM recipes"); // recipes are global content; logged meals are copies

    if ($pdo->query("SELECT COUNT(*) c FROM foods WHERE user_id IS NULL")->fetch()['c'] == 0) seed_foods($pdo);
    if ($pdo->query("SELECT COUNT(*) c FROM recipes")->fetch()['c'] == 0) seed_recipes($pdo);
    if ($pdo->query("SELECT COUNT(*) c FROM exercises")->fetch()['c'] == 0) seed_exercises($pdo);
    if ($pdo->query("SELECT COUNT(*) c FROM lessons")->fetch()['c'] == 0) seed_lessons($pdo);
    import_usda_foods($pdo);
}

// Bulk-import the bundled USDA FNDDS database (includes/food_db.csv.gz,
// ~5,300 generic foods and dishes with lab-analyzed nutrition, public domain).
function import_usda_foods(PDO $pdo): void {
    $file = __DIR__ . '/food_db.csv.gz';
    if (!is_file($file) || !function_exists('gzopen')) return;
    if ($pdo->query("SELECT COUNT(*) c FROM foods WHERE user_id IS NULL")->fetch()['c'] > 1000) return; // already imported
    $gz = gzopen($file, 'rb');
    if (!$gz) return;
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO foods
        (user_id,name,kcal,protein,carbs,fat,fiber,keto,sugar,sodium,satfat,serving_g,serving_label)
        VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?)");
    while (($line = gzgets($gz, 4096)) !== false) {
        $r = str_getcsv(trim($line));
        if (count($r) < 11 || $r[0] === '') continue;
        $keto = ((float)$r[3] - (float)$r[5]) <= 8 ? 1 : 0;
        $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $keto, $r[6], $r[7], $r[8],
                      $r[9] !== '' ? $r[9] : null, $r[10] !== '' ? $r[10] : null]);
    }
    gzclose($gz);
    $pdo->commit();
}

function seed_foods(PDO $pdo): void {
    // [name, kcal, protein, carbs, fat, fiber, keto, sugar, sodium(mg), satfat] — all per 100 g, typical values
    $foods = [
        ['Chicken breast (cooked)',165,31,0,3.6,0,1,0,74,1],['Chicken thigh (cooked)',209,26,0,11,0,1,0,84,3],
        ['Ground beef 85% (cooked)',250,26,0,15,0,1,0,72,6],['Ribeye steak (cooked)',291,24,0,21,0,1,0,54,9],
        ['Salmon (cooked)',208,20,0,13,0,1,0,59,3.1],['Tuna (canned in water)',116,26,0,1,0,1,0,247,0.3],
        ['Shrimp (cooked)',99,24,0.2,0.3,0,1,0,111,0.1],['Cod (cooked)',105,23,0,0.9,0,1,0,78,0.2],
        ['Pork chop (cooked)',231,25,0,14,0,1,0,62,4.5],['Bacon (cooked)',541,37,1.4,42,0,1,1.4,1717,14],
        ['Turkey breast (cooked)',147,30,0,2,0,1,0,99,0.6],['Lamb (cooked)',294,25,0,21,0,1,0,72,9],
        ['Egg (whole)',155,13,1.1,11,0,1,1.1,124,3.3],['Egg white',52,11,0.7,0.2,0,1,0.7,166,0],
        ['Cheddar cheese',403,25,1.3,33,0,1,0.5,621,21],['Mozzarella',280,28,3.1,17,0,1,1.2,627,10],
        ['Cream cheese',342,6,4.1,34,0,1,3.2,314,19],['Feta cheese',264,14,4.1,21,0,1,4.1,917,15],
        ['Parmesan',431,38,4.1,29,0,1,0.9,1529,19],['Cottage cheese',98,11,3.4,4.3,0,1,2.7,364,1.7],
        ['Greek yogurt (full fat)',97,9,3.6,5,0,1,3.6,35,3.5],['Greek yogurt (nonfat)',59,10,3.6,0.4,0,0,3.6,36,0.1],
        ['Heavy cream',340,2.8,2.8,36,0,1,2.8,27,23],['Butter',717,0.9,0.1,81,0,1,0.1,576,51],
        ['Olive oil',884,0,0,100,0,1,0,2,14],['Coconut oil',862,0,0,100,0,1,0,0,87],
        ['MCT oil',830,0,0,100,0,1,0,0,97],['Avocado oil',884,0,0,100,0,1,0,0,12],
        ['Mayonnaise',680,1,1,75,0,1,0.6,635,12],['Whole milk',61,3.2,4.8,3.3,0,0,4.8,40,1.9],
        ['Almond milk (unsweetened)',15,0.6,0.6,1.2,0.5,1,0.2,72,0.1],['Avocado',160,2,8.5,15,6.7,1,0.7,7,2.1],
        ['Almonds',579,21,22,50,12.5,1,4.4,1,3.8],['Walnuts',654,15,14,65,6.7,1,2.6,2,6.1],
        ['Pecans',691,9,14,72,9.6,1,4,0,6.2],['Macadamia nuts',718,7.9,14,76,8.6,1,4.6,5,12],
        ['Peanut butter',588,25,20,50,6,1,9,426,10],['Almond butter',614,21,19,56,10,1,4.4,7,4.2],
        ['Chia seeds',486,17,42,31,34,1,0,16,3.3],['Flax seeds',534,18,29,42,27,1,1.6,30,3.7],
        ['Pumpkin seeds',559,30,11,49,6,1,1.4,7,8.7],['Sunflower seeds',584,21,20,51,8.6,1,2.6,9,4.5],
        ['Spinach (raw)',23,2.9,3.6,0.4,2.2,1,0.4,79,0.1],['Kale (raw)',49,4.3,8.8,0.9,3.6,1,2.3,38,0.1],
        ['Broccoli (cooked)',35,2.4,7.2,0.4,3.3,1,1.4,41,0.1],['Cauliflower (cooked)',23,1.8,4.1,0.5,2.3,1,1.9,15,0.1],
        ['Zucchini (cooked)',15,1.1,2.7,0.4,1,1,1.7,3,0.1],['Asparagus (cooked)',22,2.4,4.1,0.2,2,1,1.3,14,0.1],
        ['Brussels sprouts (cooked)',36,2.6,7.1,0.5,2.6,1,2.2,21,0.1],['Cabbage (raw)',25,1.3,5.8,0.1,2.5,1,3.2,18,0],
        ['Lettuce (romaine)',17,1.2,3.3,0.3,2.1,1,1.2,8,0],['Cucumber',15,0.7,3.6,0.1,0.5,1,1.7,2,0],
        ['Bell pepper',31,1,6,0.3,2.1,1,4.2,4,0],['Tomato',18,0.9,3.9,0.2,1.2,1,2.6,5,0],
        ['Mushrooms (raw)',22,3.1,3.3,0.3,1,1,2,5,0],['Onion',40,1.1,9.3,0.1,1.7,0,4.2,4,0],
        ['Garlic',149,6.4,33,0.5,2.1,0,1,17,0.1],['Green beans (cooked)',35,1.9,7.9,0.3,3.2,1,3.6,1,0.1],
        ['Celery',14,0.7,3,0.2,1.6,1,1.3,80,0],['Eggplant (cooked)',35,0.8,8.7,0.2,2.5,1,3.2,1,0],
        ['Olives (green)',145,1,3.8,15,3.3,1,0.5,1556,2],['Pickles (dill)',11,0.3,2.3,0.2,1.2,1,1.1,1208,0],
        ['Carrot',41,0.9,9.6,0.2,2.8,0,4.7,69,0],['Sweet potato (baked)',90,2,21,0.2,3.3,0,6.5,36,0],
        ['White potato (baked)',93,2.5,21,0.1,2.2,0,1.2,10,0],['White rice (cooked)',130,2.7,28,0.3,0.4,0,0.1,1,0.1],
        ['Brown rice (cooked)',112,2.6,24,0.9,1.8,0,0.4,5,0.2],['Quinoa (cooked)',120,4.4,21,1.9,2.8,0,0.9,7,0.2],
        ['Oatmeal (cooked)',71,2.5,12,1.5,1.7,0,0.3,4,0.3],['White bread',265,9,49,3.2,2.7,0,5,490,0.7],
        ['Whole wheat bread',247,13,41,3.4,7,0,4.3,450,0.8],['Pasta (cooked)',131,5,25,1.1,1.8,0,0.6,1,0.2],
        ['Tortilla (flour)',306,8.2,49,7.7,3.5,0,1.8,640,2],['Corn',96,3.4,21,1.5,2.4,0,4.5,1,0.2],
        ['Black beans (cooked)',132,8.9,24,0.5,8.7,0,0.3,1,0.1],['Chickpeas (cooked)',164,8.9,27,2.6,7.6,0,4.8,7,0.3],
        ['Lentils (cooked)',116,9,20,0.4,7.9,0,1.8,2,0.1],['Blueberries',57,0.7,14,0.3,2.4,0,10,1,0],
        ['Strawberries',32,0.7,7.7,0.3,2,1,4.9,1,0],['Raspberries',52,1.2,12,0.7,6.5,1,4.4,1,0],
        ['Blackberries',43,1.4,9.6,0.5,5.3,1,4.9,1,0],['Apple',52,0.3,14,0.2,2.4,0,10.4,1,0],
        ['Banana',89,1.1,23,0.3,2.6,0,12.2,1,0.1],['Orange',47,0.9,12,0.1,2.4,0,9.4,0,0],
        ['Grapes',69,0.7,18,0.2,0.9,0,16,2,0.1],['Watermelon',30,0.6,7.6,0.2,0.4,0,6.2,1,0],
        ['Lemon',29,1.1,9.3,0.3,2.8,1,2.5,2,0],['Lime',30,0.7,11,0.2,2.8,1,1.7,2,0],
        ['Coconut (shredded, unsweetened)',660,6.9,24,64,16.3,1,7.4,37,57],['Dark chocolate 85%',592,9.7,46,46,11,0,14,20,28],
        ['Honey',304,0.3,82,0,0.2,0,82,4,0],['Sugar',387,0,100,0,0,0,100,0,0],
        ['Erythritol',0,0,100,0,0,1,0,0,0],['Stevia',0,0,0,0,0,1,0,0,0],
        ['Coffee (black)',1,0.1,0,0,0,1,0,2,0],['Green tea',1,0,0.2,0,0,1,0,1,0],
        ['Bone broth',17,4,0.4,0.2,0,1,0,300,0.1],['Protein powder (whey)',400,80,8,6,1,1,8,200,2],
        ['Protein bar (avg)',380,30,40,12,5,0,25,300,5],['Pizza (cheese slice)',266,11,33,10,2.3,0,3.6,598,4.5],
        ['Hamburger (fast food)',254,13,30,9,1.1,0,5,396,3.5],['French fries',312,3.4,41,15,3.8,0,0.5,210,2.3],
        ['Soda (cola)',42,0,10.6,0,0,0,10.6,4,0],['Orange juice',45,0.7,10.4,0.2,0.2,0,8.4,1,0],
        ['Beer',43,0.5,3.6,0,0,0,0,4,0],['Red wine',85,0.1,2.6,0,0,0,0.6,4,0],
        ['Vodka/spirits (40%)',231,0,0,0,0,1,0,1,0],['Ice cream (vanilla)',207,3.5,24,11,0.7,0,21,80,6.8],
        ['Croissant',406,8.2,46,21,2.6,0,11,470,12],['Donut (glazed)',452,4.9,51,25,1.4,0,23,380,11],
        ['Potato chips',536,7,53,35,4.4,0,0.3,525,3.1],['Popcorn (air-popped)',387,13,78,4.5,14.5,0,0.9,8,0.6],
        ['Hummus',166,7.9,14,9.6,6,0,0.3,379,1.4],['Salsa',36,1.5,7,0.2,1.9,1,4,430,0],
        ['Ketchup',101,1,27,0.1,0.3,0,22,907,0],['Mustard',66,4.4,5.8,3.3,4,1,0.9,1135,0.2],
        ['Soy sauce',53,8.1,4.9,0.6,0.8,1,0.4,5493,0.1],['Ranch dressing',430,1.3,5.9,45,0.4,1,2.6,901,7],
        ['Caesar dressing',542,2.2,3.6,58,0.5,1,2.4,1000,9],['Tofu (firm)',144,17,2.8,8.7,2.3,1,0.6,14,1.3],
        ['Tempeh',192,20,7.6,11,4.8,1,0,9,2.5],['Edamame (cooked)',122,11,8.9,5.2,5.2,0,2.2,6,0.6],
        ['Sausage (pork)',301,19,2.7,24,0,1,1,731,8.5],['Pepperoni',504,23,1.2,44,0,1,0,1582,16],
        ['Ham (sliced)',145,21,1.5,5.5,0,1,1.5,1203,1.8],['Salami',336,22,1.2,26,0,1,0.9,1740,9.3],
        ['Hot dog',290,10,4.2,26,0,1,1.6,1090,9.9],['Deli turkey (sliced)',104,17,3.5,2,0,1,2.1,1015,0.5],
        ['String cheese',330,24,2,25,0,1,1,700,15],['Pork rinds',544,61,0,31,0,1,0,1818,11],
        ['Beef jerky',410,33,11,26,1.8,1,9,1785,11],['Sardines (canned in oil)',208,25,0,11,0,1,0,505,1.5],
        ['Crab (cooked)',97,19,0,1.5,0,1,0,395,0.2],['Lobster (cooked)',89,19,0,0.9,0,1,0,486,0.2],
        ['Scallops (cooked)',111,21,3.2,0.8,0,1,0,667,0.2],['Duck (roasted)',337,19,0,28,0,1,0,59,9.7],
        // ── Persian dishes & staples (per 100g, typical homemade) ──
        ['Ghormeh Sabzi (herb stew)',120,8,6,7,2,1,1,350,2],['Khoresh Gheymeh',130,8,9,7,2,0,2,380,2.5],
        ['Fesenjan (walnut-pomegranate stew)',210,9,12,14,2,0,8,300,2],['Khoresh Bademjan (eggplant stew)',110,6,8,6,2.5,1,2,340,2],
        ['Kabab Koobideh',230,17,2,17,0.3,1,0.5,420,7],['Joojeh Kabab (saffron chicken)',165,25,1,7,0,1,0.5,350,1.8],
        ['Kabab Barg',190,26,0.5,9,0,1,0,330,3.5],['Chelo (Persian steamed rice)',160,2.5,32,2.5,0.5,0,0.1,120,0.5],
        ['Tahdig (crispy rice)',250,4,45,6,0.8,0,0.2,200,1],['Zereshk Polo (barberry rice w/ chicken)',180,10,25,5,1,0,4,300,1.2],
        ['Baghali Polo (dill & fava rice)',170,5,30,3.5,2,0,0.5,250,0.7],['Adas Polo (lentil rice)',180,6,33,3,3,0,4,220,0.5],
        ['Tahchin (saffron rice cake)',220,10,28,8,0.7,0,1,320,2.5],['Kashk-e Bademjan',150,5,10,10,3,1,3,450,3],
        ['Mirza Ghasemi',120,4,9,8,2.5,1,3,300,1.5],['Ash Reshteh (noodle-bean soup)',130,6,18,4,4,0,2,480,1],
        ['Abgoosht / Dizi',140,11,10,6,2,0,1,400,2.5],['Kotlet (Persian patty)',220,12,12,14,1,0,1,380,3.5],
        ['Kuku Sabzi (herb frittata)',155,8,6,11,2.5,1,1,320,2.5],['Dolmeh (stuffed grape leaves)',160,4,22,6,2,0,2,400,1],
        ['Salad Shirazi',35,1,6,1,1.5,1,3,200,0.1],['Mast-o-Khiar (yogurt cucumber)',60,3.5,5,3,0.5,1,3.5,180,1.8],
        ['Doogh (yogurt drink)',30,1.7,2.5,1.3,0,1,2.2,250,0.8],['Lavash bread',275,9,56,1,2,0,1.5,480,0.2],
        ['Sangak bread',260,9,52,1.5,3.5,0,1,420,0.3],['Barbari bread',280,9,55,2.5,2.5,0,1.2,450,0.4],
        ['Halim (wheat & turkey porridge)',130,8,18,3,2,0,3,250,1],['Sholeh Zard (saffron rice pudding)',150,2,33,1,0.3,0,18,20,0.3],
        ['Dates (khorma)',277,1.8,75,0.2,6.7,0,66,1,0],['Dried barberries (zereshk)',316,3.6,64,3.5,7,0,32,5,0.1],
        ['Pomegranate',83,1.7,19,1.2,4,0,14,3,0.1],
    ];
    $st = $pdo->prepare("INSERT INTO foods (user_id,name,kcal,protein,carbs,fat,fiber,keto,sugar,sodium,satfat) VALUES (NULL,?,?,?,?,?,?,?,?,?,?)");
    foreach ($foods as $f) $st->execute($f);
}

function seed_recipes(PDO $pdo): void {
    // Macros per serving. Trailing flags: diet ('keto'|'lowcarb'|'balanced'),
    // heart (low sat-fat), lowsodium (BP-friendly), diabetic (low sugar).
    $recipes = [
        // ── Keto ──
        ['Bacon & Eggs Skillet','breakfast',15,520,28,3,44,1,"3 eggs|3 strips bacon|1 tbsp butter|Salt, pepper, chives","Cook bacon in a skillet until crisp, set aside. Fry eggs in the bacon fat with butter. Season and top with chives and crumbled bacon.",'🍳','keto',0,0,1],
        ['Keto Avocado Egg Boats','breakfast',20,410,15,9,36,7,"1 avocado|2 small eggs|30g shredded cheddar|Paprika, salt","Halve the avocado, remove pit, scoop a little extra out. Crack an egg into each half, top with cheese. Bake at 200°C/400°F for 15 min.",'🥑','keto',0,1,1],
        ['Cream Cheese Pancakes','breakfast',15,340,15,5,29,1,"2 eggs|60g cream cheese|1 tsp erythritol|Cinnamon|Butter for frying","Blend eggs, cream cheese, sweetener and cinnamon until smooth. Fry small pancakes in butter 2 min per side. Serve with berries.",'🥞','keto',0,1,1],
        ['Chia Coconut Pudding','breakfast',10,290,8,12,24,10,"3 tbsp chia seeds|200ml coconut milk|1 tsp erythritol|Vanilla|Few raspberries","Whisk everything together, refrigerate overnight. Stir, top with raspberries and shredded coconut.",'🥥','keto',0,1,1],
        ['Grilled Chicken Caesar (no croutons)','lunch',20,480,42,6,32,2,"150g chicken breast|Romaine lettuce|2 tbsp Caesar dressing|20g parmesan|Olive oil","Season and grill the chicken 6-7 min per side. Slice over chopped romaine, toss with dressing and shaved parmesan.",'🥗','keto',0,0,1],
        ['Tuna Avocado Salad','lunch',10,420,32,8,29,6,"1 can tuna|1/2 avocado|2 tbsp mayo|Celery, red onion|Lemon juice","Mix drained tuna with mayo, diced celery and onion. Fold in diced avocado, squeeze lemon, season. Serve in lettuce cups.",'🐟','keto',1,1,1],
        ['Zucchini Noodle Alfredo','lunch',20,390,14,9,34,3,"2 zucchini (spiralized)|100ml heavy cream|30g parmesan|1 tbsp butter|Garlic","Sauté garlic in butter, add cream and simmer 3 min. Stir in parmesan. Toss zoodles in sauce 2 min — don't overcook.",'🍝','keto',0,0,1],
        ['Bunless Cheeseburger Bowl','lunch',20,560,35,7,43,3,"150g ground beef|30g cheddar|Lettuce, tomato, pickles|2 tbsp mayo-mustard sauce","Brown seasoned beef, melt cheese on top. Serve over shredded lettuce with toppings and sauce.",'🍔','keto',0,0,1],
        ['Egg Salad Lettuce Wraps','lunch',15,380,18,4,32,2,"3 boiled eggs|2 tbsp mayo|1 tsp mustard|Chives|Butter lettuce leaves","Chop eggs, mix with mayo, mustard, chives, salt and pepper. Spoon into lettuce leaves.",'🥬','keto',0,1,1],
        ['Butter-Basted Ribeye & Asparagus','dinner',25,680,45,5,52,2,"200g ribeye|150g asparagus|2 tbsp butter|Garlic, rosemary","Sear ribeye 3-4 min per side, basting with butter, garlic and rosemary. Rest 5 min. Sauté asparagus in the pan juices.",'🥩','keto',0,1,1],
        ['Garlic Butter Salmon','dinner',20,520,38,4,39,1,"180g salmon fillet|2 tbsp butter|Garlic, lemon, dill|150g broccoli","Pan-sear salmon skin-side down 4 min, flip 3 min. Add butter, garlic, lemon. Steam broccoli alongside.",'🐠','keto',0,1,1],
        ['Chicken Thighs w/ Creamy Spinach','dinner',30,590,40,6,44,2,"2 chicken thighs|100g spinach|80ml heavy cream|30g parmesan|Garlic","Sear thighs skin-side down until crisp, finish in oven. Deglaze pan with cream, add garlic, spinach and parmesan for the sauce.",'🍗','keto',0,1,1],
        ['Cauliflower Fried "Rice"','dinner',20,320,18,9,23,4,"200g riced cauliflower|1 egg|50g ham|Soy sauce|Sesame oil, scallions","Stir-fry cauliflower rice in sesame oil 4 min. Push aside, scramble egg. Add ham, soy sauce, scallions. Toss hot.",'🍚','keto',1,0,1],
        ['Keto Meatballs in Marinara','dinner',35,540,36,8,40,2,"200g ground beef|1 egg|30g parmesan|Low-sugar marinara|Italian herbs","Mix beef, egg, parmesan, herbs. Roll and bake at 200°C for 18 min. Simmer in marinara 5 min. Serve over zoodles.",'🧆','keto',0,0,1],
        ['Shrimp & Zucchini Scampi','dinner',20,380,30,7,26,2,"180g shrimp|2 tbsp butter|Garlic, chili flakes|1 zucchini, ribboned|Lemon","Sauté garlic in butter, add shrimp 2 min per side. Toss zucchini ribbons in, splash of lemon, chili flakes.",'🦐','keto',0,0,1],
        ['Pork Chops w/ Mushroom Cream','dinner',30,610,42,5,46,1,"200g pork chop|100g mushrooms|80ml heavy cream|Thyme, garlic","Sear chops 4 min per side, rest. Sauté mushrooms and garlic, pour in cream, simmer with thyme. Spoon over chops.",'🍖','keto',0,1,1],
        ['Cheese Crisp Nachos','snack',15,330,18,4,27,1,"60g shredded cheddar|Jalapeño slices|2 tbsp sour cream|Salsa","Bake small piles of cheddar at 200°C for 6-8 min until crisp. Cool, top with jalapeño, sour cream, salsa.",'🧀','keto',0,0,1],
        ['Fat Bomb Bites','snack',10,190,2,2,20,1,"60g cream cheese|30g butter|1 tbsp cocoa|Erythritol|Chopped pecans","Soften cream cheese and butter, mix with cocoa and sweetener. Roll into balls, coat in pecans, freeze 30 min.",'🍫','keto',0,1,1],
        ['Deviled Eggs','snack',15,210,12,1,17,0,"3 boiled eggs|2 tbsp mayo|1 tsp mustard|Paprika","Halve eggs, mash yolks with mayo and mustard, pipe back in. Dust with paprika.",'🥚','keto',0,1,1],
        ['Keto Berry Smoothie','snack',5,240,12,8,18,4,"50g raspberries|150ml almond milk|1 tbsp almond butter|1/2 scoop whey|Ice","Blend everything until smooth. Add ice for thickness.",'🫐','keto',1,1,1],
        ['Guacamole & Veggie Sticks','snack',10,220,3,10,19,8,"1 avocado|Lime, cilantro, onion|Celery & cucumber sticks","Mash avocado with lime, minced onion, cilantro, salt. Dip the veggie sticks.",'🥒','keto',1,1,1],

        // ── Balanced / Mediterranean (heart-smart, everyday) ──
        ['Overnight Oats with Berries','breakfast',5,350,20,45,10,8,"50g rolled oats|150g Greek yogurt|1 tbsp chia seeds|80g mixed berries|Cinnamon","Mix oats, yogurt, chia and a splash of milk in a jar. Refrigerate overnight. Top with berries and cinnamon in the morning.",'🥣','balanced',1,1,1],
        ['Veggie Egg-White Omelet','breakfast',10,250,24,8,12,2,"4 egg whites + 1 whole egg|Spinach, tomato, mushrooms|30g feta|1 tsp olive oil","Sauté the vegetables 2 min, pour in the eggs, cook until just set. Crumble feta over and fold.",'🍳','balanced',1,1,1],
        ['Avocado Toast with Egg','breakfast',10,380,16,34,20,8,"1 slice whole-grain bread|1/2 avocado|1 poached egg|Chili flakes, lemon","Toast the bread, mash avocado with lemon and spread. Top with a poached egg and chili flakes.",'🥑','balanced',1,1,1],
        ['Apple Cinnamon Yogurt Bowl','breakfast',5,300,18,38,8,5,"200g Greek yogurt|1 apple, diced|1 tbsp walnuts|Cinnamon","Stir cinnamon into the yogurt, top with diced apple and walnuts.",'🍎','balanced',1,1,0],
        ['Grilled Chicken Quinoa Bowl','lunch',25,480,38,42,16,7,"120g chicken breast|80g cooked quinoa|Cucumber, tomato, red onion|1 tbsp olive oil, lemon","Grill the chicken and slice. Toss quinoa with chopped vegetables, olive oil and lemon. Top with chicken.",'🥙','balanced',1,1,1],
        ['Mediterranean Chickpea Salad','lunch',15,400,15,45,18,11,"150g chickpeas|Cucumber, tomato, red onion|40g feta|Olives|1 tbsp olive oil, oregano","Combine everything in a bowl, dress with olive oil, lemon and oregano. Better after 10 minutes of resting.",'🥗','balanced',1,0,1],
        ['Lentil Soup','lunch',35,320,18,45,6,12,"150g red lentils|Carrot, celery, onion|1 tsp cumin|Low-sodium vegetable broth|1 tsp olive oil","Sauté the vegetables, add lentils, cumin and broth. Simmer 25 min until soft. Season with lemon.",'🍲','balanced',1,1,1],
        ['Turkey & Hummus Wrap','lunch',10,420,30,38,16,7,"1 whole-wheat tortilla|80g turkey breast|2 tbsp hummus|Spinach, cucumber, bell pepper","Spread hummus on the tortilla, layer turkey and vegetables, roll tightly and halve.",'🌯','balanced',1,0,1],
        ['Baked Cod with Roasted Vegetables','dinner',30,380,34,28,14,7,"180g cod fillet|Zucchini, bell pepper, cherry tomatoes|1 tbsp olive oil|Lemon, herbs","Toss vegetables in oil, roast 15 min at 200°C. Add the cod on top, season, roast 12 min more. Finish with lemon.",'🐟','balanced',1,1,1],
        ['Salmon with Quinoa & Greens','dinner',25,520,36,35,24,6,"160g salmon|80g cooked quinoa|Steamed broccoli or green beans|1 tsp olive oil, lemon","Bake or pan-sear the salmon (no butter needed). Serve over quinoa with greens and a squeeze of lemon.",'🍣','balanced',1,1,1],
        ['Chicken & Veggie Stir-Fry','dinner',20,480,35,52,12,6,"140g chicken breast|Broccoli, carrot, snap peas|Low-sodium soy sauce, ginger, garlic|80g brown rice","Stir-fry chicken until golden, add vegetables and sauce, cook 4 min. Serve over brown rice.",'🥦','balanced',1,0,1],
        ['Turkey Chili','dinner',40,420,35,35,12,10,"200g lean ground turkey|Kidney beans, tomatoes|Onion, garlic, chili powder, cumin","Brown the turkey with onion and garlic. Add beans, tomatoes and spices; simmer 25 min. Even better next day.",'🌶️','balanced',1,1,1],
        ['Stuffed Bell Peppers','dinner',45,400,28,38,14,8,"2 bell peppers|150g lean ground beef or turkey|60g cooked rice|Tomato, onion|30g mozzarella","Mix meat, rice, tomato and onion; stuff the peppers. Bake covered 30 min at 190°C, top with cheese, bake 10 more.",'🫑','balanced',1,1,1],
        ['Cottage Cheese & Fruit Bowl','snack',5,250,24,22,7,3,"200g cottage cheese|100g strawberries or peach|1 tsp honey|Few almonds","Top the cottage cheese with fruit, a small drizzle of honey and almonds.",'🍓','balanced',1,1,0],
        ['Crispy Roasted Chickpeas','snack',35,220,8,30,8,8,"150g chickpeas|1 tsp olive oil|Paprika, garlic powder, salt","Pat chickpeas dry, toss with oil and spices, roast 30 min at 200°C shaking once. Cool to crisp up.",'🫘','balanced',1,1,1],

        // ── Low-carb (not strict keto) ──
        ['Asian Chicken Lettuce Wraps','lunch',20,350,30,18,16,4,"150g ground chicken|Water chestnuts, scallions|Low-sodium soy, ginger, garlic|Butter lettuce cups","Brown the chicken with ginger and garlic, add chopped water chestnuts and sauce. Spoon into lettuce cups.",'🥬','lowcarb',1,0,1],
        ['Greek Chicken Bowl','dinner',25,420,38,15,22,5,"150g chicken thigh|Cucumber, tomato, red onion|40g feta|Tzatziki|Olive oil, oregano","Grill oregano-marinated chicken. Serve over chopped salad with feta and a spoon of tzatziki — no rice needed.",'🇬🇷','lowcarb',1,0,1],

        // ── More everyday favorites ──
        ['Protein Pancakes','breakfast',15,330,28,32,9,3,"1 banana|2 eggs|30g oats|1/2 scoop whey|Cinnamon|1 tsp oil for the pan","Blend everything into a batter. Cook small pancakes 2 min per side over medium heat. Top with berries.",'🥞','balanced',1,1,1],
        ['Scrambled Eggs on Sourdough','breakfast',10,360,20,30,17,2,"2 eggs|1 slice sourdough|1 tsp olive oil|Chives, pepper","Whisk eggs, cook low and slow in olive oil, stirring. Pile onto toasted sourdough with chives.",'🍞','balanced',1,1,1],
        ['Caprese Salad','lunch',10,340,15,8,28,2,"125g fresh mozzarella|2 tomatoes|Basil leaves|1 tbsp olive oil, balsamic","Slice mozzarella and tomatoes, layer with basil. Drizzle with oil and a little balsamic.",'🍅','lowcarb',1,1,1],
        ['Chicken Noodle Soup','lunch',35,320,28,32,8,3,"120g chicken breast|Egg noodles|Carrot, celery, onion|Low-sodium chicken broth|Dill","Simmer vegetables in broth 10 min, add chicken and noodles, cook until tender. Finish with dill.",'🍜','balanced',1,0,1],
        ['Tuna White-Bean Salad','lunch',10,380,32,30,14,8,"1 can tuna|150g white beans|Red onion, parsley|1 tbsp olive oil, lemon","Toss drained tuna and beans with onion, parsley, oil and lemon. Sturdy enough to pack for work.",'🥫','balanced',1,0,1],
        ['Hummus Veggie Sandwich','lunch',10,390,13,52,15,10,"2 slices whole-grain bread|3 tbsp hummus|Cucumber, tomato, sprouts|Avocado slices","Spread hummus generously, stack the vegetables and avocado, season, and press together.",'🥪','balanced',1,0,1],
        ['Beef & Broccoli Stir-Fry','dinner',20,430,35,18,25,4,"180g flank steak, sliced thin|300g broccoli|Low-sodium soy, garlic, ginger|1 tsp sesame oil","Sear the beef hard 2 min, remove. Stir-fry broccoli, return beef with sauce, toss 1 min. No rice needed.",'🥦','lowcarb',1,0,1],
        ['Shrimp Tacos','dinner',20,460,32,42,17,6,"180g shrimp|2 corn tortillas|Cabbage slaw with lime|Yogurt-chipotle sauce|Cilantro","Sear seasoned shrimp 2 min per side. Load tortillas with slaw, shrimp and a drizzle of the sauce.",'🌮','balanced',1,0,1],
        ['Sheet-Pan Chicken Fajitas','dinner',30,440,38,32,17,5,"180g chicken breast, sliced|Bell peppers & onion|Fajita spices, olive oil|2 small tortillas","Toss everything on a sheet pan, roast 20 min at 220°C. Serve sizzling with warm tortillas.",'🫑','balanced',1,1,1],
        ['Light Egg Fried Rice','dinner',15,420,18,55,13,3,"150g day-old cooked rice|2 eggs|Peas, carrot, scallion|Low-sodium soy|1 tsp sesame oil","Scramble the eggs, set aside. Stir-fry vegetables, add rice on high heat, return eggs, season, toss.",'🍳','balanced',1,0,1],
        ['Apple & Peanut Butter','snack',5,270,8,30,14,6,"1 apple, sliced|2 tbsp peanut butter|Cinnamon","Slice, dip, sprinkle. The fiber-fat combo keeps you full for hours.",'🍎','balanced',1,1,0],
        ['Turkey Roll-Ups','snack',5,180,22,4,8,1,"80g turkey slices|2 tbsp cream cheese|Cucumber sticks|Everything seasoning","Spread cream cheese on turkey slices, add a cucumber stick, roll and slice into pinwheels.",'🌀','lowcarb',0,0,1],

        // ── Persian ──
        ['Joojeh Kabab with Grilled Tomato','dinner',35,420,45,8,22,2,"400g chicken thigh or breast, cubed|Saffron bloomed in hot water|1/2 grated onion, lemon juice|2 tomatoes for grilling|Olive oil","Marinate chicken in saffron, onion, lemon and oil for 2+ hours. Skewer and grill 12-15 min, turning; grill tomatoes alongside. Serve with salad shirazi instead of rice to keep it light.",'🍢','lowcarb',1,1,1,'persian'],
        ['Kabab Koobideh & Salad Shirazi','dinner',40,520,35,10,38,3,"300g ground lamb/beef (20% fat)|1 grated onion, squeezed dry|Salt, pepper, sumac|Cucumber, tomato, onion salad with lime","Knead meat with onion and seasoning until sticky. Press onto skewers and grill over high heat 8-10 min. Serve with salad shirazi and sumac — skip the rice for low-carb.",'🥩','keto',0,0,1,'persian'],
        ['Ghormeh Sabzi (lighter) with Rice','dinner',75,480,28,38,22,6,"250g lean beef or lamb, cubed|Sabzi ghormeh herbs (parsley, cilantro, fenugreek, leek)|Kidney beans|Dried limes (limoo amani)|1 tbsp olive oil|1/2 cup cooked basmati per serving","Sauté herbs in olive oil until dark and fragrant. Brown the meat, add beans, dried limes and water; simmer 1+ hour until rich. Serve over a modest portion of rice.",'🍲','balanced',1,0,1,'persian'],
        ['Khoresh Gheymeh (lighter)','dinner',60,450,26,40,18,5,"250g lean beef, cubed|Yellow split peas|Tomato paste, dried limes|Baked potato wedges instead of fries|1/2 cup rice per serving","Brown meat with onion and turmeric. Add split peas, tomato paste, dried limes and water; simmer 45 min. Top with oven-baked potato wedges instead of deep-fried.",'🍛','balanced',1,0,1,'persian'],
        ['Kuku Sabzi (herb frittata)','lunch',30,310,16,10,23,4,"4 eggs|Big bunch parsley, cilantro, dill, chives — finely chopped|1 tbsp dried barberries + walnuts (optional)|1 tbsp olive oil","Mix eggs with the mountain of herbs, barberries and walnuts. Cook gently in olive oil, covered, until set; flip or finish under the broiler. Cut into wedges.",'🥬','lowcarb',1,1,1,'persian'],
        ['Mirza Ghasemi with Lavash','lunch',25,320,10,28,18,5,"2 smoked/roasted eggplants, mashed|3 garlic cloves|2 tomatoes, grated|1 egg|Olive oil|Half a lavash","Fry garlic in olive oil, add mashed eggplant and tomato; cook down 10 min. Stir in the egg until just set. Scoop with warm lavash.",'🍆','balanced',1,1,1,'persian'],
        ['Ash Reshteh (lighter)','lunch',55,380,16,55,10,10,"Chickpeas, kidney beans, lentils|Persian noodles (reshteh)|Spinach, parsley, cilantro|Fried mint & a little kashk on top","Simmer beans until tender, add herbs and noodles for the last 15 min. Finish with fried mint oil and a light drizzle of kashk (it's salty — a little goes far).",'🍜','balanced',1,0,1,'persian'],
        ['Zereshk Polo ba Morgh','dinner',55,520,35,55,14,3,"2 chicken pieces, braised with onion, turmeric & saffron|1 cup basmati|Barberries (zereshk) sautéed with a touch of honey|Pistachio slivers","Braise chicken until tender. Steam the rice; fold in the tart sautéed barberries. Serve chicken over the jeweled rice.",'🍚','balanced',1,1,0,'persian'],
        ['Salad Shirazi','snack',10,90,2,12,4,3,"Cucumber, tomato, red onion — finely diced|Lime juice, dried mint|1 tsp olive oil","Dice everything small and even. Dress with lime, mint and olive oil just before serving.",'🥗','balanced',1,1,1,'persian'],
        ['Mast-o-Khiar','snack',5,120,7,9,6,1,"200g Greek yogurt|1 cucumber, grated or diced|Dried mint, a few walnuts & raisins (optional)","Stir cucumber and mint into the yogurt. Top with walnuts. Cooling side for any kabab — or a snack on its own.",'🥒','lowcarb',1,1,1,'persian'],
    ];
    $st = $pdo->prepare("INSERT INTO recipes (name,tag,minutes,kcal,protein,carbs,fat,fiber,ingredients,instructions,emoji,diet,heart,lowsodium,diabetic,cuisine) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($recipes as $r) $st->execute(array_pad($r, 16, ''));
}

function seed_exercises(PDO $pdo): void {
    // [name, category, difficulty, back_safe, low_impact, kcal/30min, description, emoji]
    $exercises = [
        ['Walking (brisk)','cardio','easy',1,1,140,"The single best fat-loss exercise for beginners and anyone with back issues. Keep a pace where talking is possible but singing isn't. Aim for 8-10k steps daily.",'🚶'],
        ['Incline Treadmill Walk','cardio','easy',1,1,180,"Walking at 8-12% incline burns significantly more than flat walking with zero jumping impact. Don't hold the rails — swing your arms.",'⛰️'],
        ['Stationary Bike','cardio','easy',1,1,210,"Zero spinal load, great for bad backs and knees. Keep resistance moderate; 20-40 min steady pace. Adjust seat so your knee stays slightly bent.",'🚴'],
        ['Swimming','cardio','medium',1,1,250,"The gold standard for back problems — water supports your spine completely while working the whole body. Backstroke is especially back-friendly.",'🏊'],
        ['Water Aerobics','cardio','easy',1,1,150,"Joint-friendly full-body cardio. Excellent for higher body weights since water carries the load.",'💦'],
        ['Elliptical','cardio','easy',1,1,220,"Smooth low-impact cardio. Stand tall, don't lean on the handles. Good treadmill alternative for sensitive backs.",'🏃'],
        ['Rowing (light, good form)','cardio','medium',0,1,240,"Great calorie burner but requires a healthy back and strict form — drive with legs, keep spine neutral. Skip if you have disc issues.",'🚣'],
        ['Bird Dog','core','easy',1,1,80,"A spine-safe core classic prescribed by back specialists. On all fours, extend opposite arm and leg, hold 5s, keep hips level. 3 sets of 8 per side.",'🐦'],
        ['Dead Bug','core','easy',1,1,80,"Lie on your back, arms up, knees at 90°. Lower opposite arm and leg slowly while pressing your lower back into the floor. 3 sets of 10 per side.",'🪲'],
        ['Glute Bridge','strength','easy',1,1,90,"Strengthens glutes and takes load off the lower back. Lie down, knees bent, drive hips up, squeeze 2s at the top. 3 sets of 12.",'🌉'],
        ['Side Plank (modified)','core','medium',1,1,90,"Strengthens the obliques and QL muscles that stabilize your spine. Start from knees, work up to 30s per side, 3 rounds.",'📐'],
        ['Front Plank','core','medium',1,1,100,"Keep a straight line from head to heels — no sagging hips. Start with 20s holds, build to 60s. Stop if lower back aches.",'🧱'],
        ['Wall Sit','strength','easy',1,1,90,"Back flat against the wall, thighs parallel to floor. Builds leg strength with the spine fully supported. 3 x 30-45s.",'🧗'],
        ['Bodyweight Squat (to box)','strength','medium',1,1,140,"Squat down to a chair/box and stand back up — the box keeps depth safe. Chest up, weight in heels. 3 sets of 10-15.",'🪑'],
        ['Goblet Squat (light)','strength','medium',1,1,160,"Hold a light dumbbell at your chest. The front load keeps your torso upright, which is easier on the lower back. 3 x 10.",'🏋️'],
        ['Dumbbell Bench Press','strength','medium',1,1,130,"Lying on a bench fully supports your spine. Works chest, shoulders, triceps. 3 sets of 8-12.",'💪'],
        ['Seated Cable Row (chest supported)','strength','medium',1,1,130,"Strengthens the upper back — key for posture. Use chest-supported machines to protect the lower back. 3 x 10-12.",'🎯'],
        ['Lat Pulldown','strength','medium',1,1,130,"Seated and supported — a back-friendly way to build the lats. Pull to the collarbone, no leaning back. 3 x 10-12.",'⬇️'],
        ['Resistance Band Work','strength','easy',1,1,110,"Rows, presses, pull-aparts with a band — cheap, joint-friendly, travel-friendly full-body strength.",'🎗️'],
        ['Cat-Cow Stretch','mobility','easy',1,1,50,"Gentle spinal mobility. On all fours, alternate arching and rounding your back slowly with your breath. 10 slow reps, great as a morning routine.",'🐱'],
        ['Child\'s Pose','mobility','easy',1,1,40,"A gentle lower-back decompression stretch. Sit back on your heels, arms stretched forward, breathe deeply for 60s.",'🧘'],
        ['Hip Flexor Stretch','mobility','easy',1,1,40,"Tight hip flexors pull on the lower back. Half-kneeling lunge, tuck pelvis, gentle push forward. 30s per side, twice.",'🦵'],
        ['Hamstring Stretch (lying, w/ strap)','mobility','easy',1,1,40,"Tight hamstrings worsen back pain. Lie on your back, loop a towel around one foot, raise the leg gently. 30s per side.",'🎽'],
        ['Yoga (gentle/restorative)','mobility','easy',1,1,120,"Improves flexibility, core strength and stress levels. Choose gentle or beginner classes; tell the instructor about your back.",'🕉️'],
        ['Tai Chi','mobility','easy',1,1,110,"Slow, flowing movement that improves balance and core control with zero impact. Especially good for 40+.",'☯️'],
        ['Jump Rope','cardio','hard',0,0,340,"Very high burn but high impact — only for healthy backs, knees and lower body weights.",'🪢'],
        ['Running','cardio','hard',0,0,320,"Efficient calorie burner but repetitive impact can aggravate back issues. Prefer brisk walking or the bike until pain-free.",'👟'],
        ['HIIT Circuit (low-impact)','cardio','hard',1,1,280,"30s work / 30s rest: squats to box, wall push-ups, marching in place, band rows. Big burn without jumping.",'⚡'],
    ];
    $st = $pdo->prepare("INSERT INTO exercises (name,category,difficulty,back_safe,low_impact,kcal30,description,emoji) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($exercises as $e) $st->execute($e);
}

function seed_lessons(PDO $pdo): void {
    // Noom-style micro-lessons: one unlocks per day. [emoji, category, title, body]
    $L = [
        ['🎯','Mindset','Why diets fail (and what actually works)',
         "Around 95% of crash diets end in regain — not because people are weak, but because willpower is a battery that drains. What works is making the healthy choice the easy choice: logging your food (awareness beats restriction), keeping trigger foods out of the house, and aiming for a moderate deficit you can sustain for months, not a brutal one you can survive for two weeks. VitaTrack already set your deficit at a sustainable pace. Your only job today: log everything you eat, honestly. That's it. Awareness comes first; change follows on its own."],
        ['🧠','Mindset','Find your deeper why',
         "\"Lose 15 kg\" is a number, not a reason. Dig one layer down: what will be different when you get there? Playing with your kids without back pain? Getting off blood-pressure meds? Feeling confident in photos? Write your real why somewhere you'll see it — phone lock screen works great. On hard days, numbers won't move you, but your why will. Research on behavior change shows people with a clearly articulated personal reason stick to plans roughly twice as long as people chasing a number."],
        ['🍽️','Nutrition','Calorie density: eat MORE, weigh less',
         "A plate of 500 kcal of vegetables and chicken is physically enormous; 500 kcal of donut fits in your hand. That's calorie density — and it's why the color dots exist in your diary. Green foods (under ~100 kcal per 100g) fill your stomach for few calories: vegetables, berries, lean fish. Yellow (100-300) is fine in normal portions. Orange (300+) isn't forbidden — it just needs a watchful eye: oils, nuts, cheese, fried anything. The trick isn't eating less food. It's eating more of the light stuff so there's less room for the dense stuff."],
        ['🥩','Nutrition','Protein is your best friend',
         "Protein does three jobs no other nutrient can: it's the most filling macronutrient per calorie, it costs your body ~25% of its calories just to digest (the thermic effect), and it protects your muscle while you lose fat — so the weight you lose is fat, not strength. Aim for your protein target every single day; it's the one macro you should never shortchange. Practical trick: build every meal around the protein first, then add the rest. Eggs, chicken, fish, Greek yogurt, and cottage cheese are your cheapest, easiest wins."],
        ['📉','Mindset','The scale lies (daily)',
         "Your weight can swing 1-2 kg in a single day from water, salt, carbs, hormones, and what's still in your digestive system — none of which is fat. A salty dinner can 'gain' you a kilo overnight; it's water, and it leaves. This is why you weigh daily but judge weekly: the morning weigh-in is just one data point, and the 7-day trend line in your Progress charts is the truth. Rule: never react to a single weigh-in. React only to two weeks of trend. Fat loss is slow and boring on purpose — that's what makes it permanent."],
        ['💧','Nutrition','Liquid calories are stealth calories',
         "Your brain barely registers calories you drink — a 500 kcal smoothie leaves you hungry again in an hour, while 500 kcal of chicken and vegetables keeps you full half the day. Soda, juice ('healthy' or not), fancy coffees, and alcohol are the four big leaks. Alcohol is a double hit: 7 kcal per gram AND it lowers your inhibition around food. You don't have to go dry — but log every drink honestly, and watch what it does to your week. Water, black coffee, and tea are always free."],
        ['🔥','Keto','How ketosis actually works',
         "Your body's preferred fuel is glucose from carbs. Cut carbs below ~25-50g a day and after 2-4 days your liver starts converting fat into ketones — an alternative fuel your brain runs on happily. That switch is ketosis: your body becomes a fat-burning machine by default, hunger typically drops, and energy stabilizes (no more sugar spikes and crashes). It's not magic — the deficit still does the losing — but many people find the deficit far easier to hold in ketosis because the appetite quiets down. Your keto target in VitaTrack keeps net carbs at 25g."],
        ['🌾','Keto','Net carbs, explained in 30 seconds',
         "Fiber is a carbohydrate your body can't digest for energy — it feeds your gut bacteria and keeps things moving instead. So it doesn't count against ketosis. Net carbs = total carbs minus fiber, and that's the number VitaTrack tracks. This is great news: an avocado has 8.5g of carbs but 6.7g of fiber — under 2g net. Non-starchy vegetables are nearly free. Berries in moderation are fine. What isn't fine: 'low-carb' processed products sweetened with things that spike you anyway. Whole foods first."],
        ['🤕','Keto','Keto flu is an electrolyte problem',
         "Headache, fatigue, and irritability in week one of keto aren't withdrawal — they're sodium leaving your body. Low insulin makes your kidneys dump sodium (and water, which is why the first kilos drop so fast). The fix is simple: salt your food generously, drink broth, and keep magnesium and potassium up (spinach, avocado, nuts). Most 'keto flu' vanishes within a day of getting electrolytes right. If you take blood-pressure medication, talk to your doctor — your needs may genuinely change as weight drops."],
        ['⏱️','Fasting','Why 16:8 works',
         "Intermittent fasting isn't magic — it works mainly because it's a fence around eating. No late-night snacking window means hundreds of calories that never happen. But there are real bonuses: during the fasted hours insulin stays low, which pairs beautifully with keto's fat-burning state, and many people report sharper focus in the fasting window. Start gently: push breakfast an hour later each day until you're at your window. Coffee (black), tea, and water are all allowed and make the morning easy."],
        ['🍵','Fasting','What actually breaks a fast',
         "The honest rules: water, black coffee, plain tea, and sparkling water — all fine. A splash of cream? Technically breaks the seal but the practical effect is tiny; if it's the difference between fasting and not fasting, take the splash. Diet soda is zero-calorie but can trigger cravings in some people — test yourself. What clearly breaks it: anything with sugar, milk-heavy drinks, juice, 'just a bite.' If you slip, don't write off the day — a 13-hour fast is still a win over none. Perfection is not the standard; consistency is."],
        ['🌊','Fasting','Hunger comes in waves',
         "Here's the secret nobody tells you: hunger doesn't build forever. It arrives as a wave — rises, peaks for 10-20 minutes, and passes, especially if you drink something warm and get busy. Most people eat at the first ripple. Fasters learn to surf: notice the wave, name it ('this is the 11am wave, it always comes'), drink water or tea, and let it break. By week two the waves get smaller as your ghrelin (hunger hormone) rhythm adapts to your new schedule. Hunger is a suggestion, not an emergency."],
        ['🧂','Nutrition','Sodium and the scale',
         "Ate clean all week, then the scale jumps a kilo after restaurant night? That's sodium, not fat. Salt makes your body retain water — restaurant meals routinely pack 2-3x the sodium of home cooking. VitaTrack now tracks sodium in your diary detail: the general guideline is under ~2300mg a day. Watch the big offenders: cured meats, soy sauce, pickles, cheese, and anything from a deep fryer. You don't need to fear salt (especially on keto, where you need more) — you need to recognize its fingerprints on the scale so it never demoralizes you."],
        ['🍬','Nutrition','Develop your sugar radar',
         "Sugar hides under 60+ names: syrup, dextrose, maltose, 'evaporated cane juice,' fruit concentrate. Ketchup is 22% sugar. 'Healthy' granola can out-sugar a donut. Your radar: check the sugar line in your diary detail, and be suspicious of anything processed that tastes good and claims to be healthy. The guideline for added sugar is under ~30g a day; on keto you'll naturally be far below. One reframe that helps: sugar isn't 'bad' — it's just the most expensive thing you can spend your carb budget on, with the least fullness per calorie."],
        ['🍔','Nutrition','Restaurant survival guide',
         "You can eat out and lose weight — with a plan. Before: check the menu online and decide before you're hungry and surrounded by bread. Order: protein + vegetables as the anchor (steak and broccoli, grilled fish and salad), sauce on the side, swap fries for greens (most places do it free). Say the magic words 'no bread basket, thanks.' Drink water first. Log it honestly, even if it's an estimate — a rough log beats no log every time. And if the meal went sideways? One meal is one meal. The next one is a fresh start."],
        ['🏠','Habits','Design your environment',
         "You will eventually eat whatever is in your house — so choose at the store, not at the pantry. That's environment design, and it beats willpower every time. The rules: nothing you binge on lives at home (if it's not there, the 10pm craving needs a car trip — the craving loses). Healthy food sits at eye level, washed and ready. Junk that must exist (family, kids) goes in one opaque bin on a high shelf. Water bottle always visible on your desk. You're not weak for eating what's in reach — you're human. So control what's in reach."],
        ['😴','Habits','Sleep is a fat-loss drug',
         "One bad night of sleep raises ghrelin (hunger up), lowers leptin (fullness down), and makes your brain rate junk food as more rewarding — measured effects, not folklore. People sleeping 5-6 hours eat roughly 200-400 more kcal the next day without noticing. If your diet feels impossibly hard, check your sleep first. The basics work: consistent bedtime, dark cool room, no screens the last 30 minutes, no caffeine after mid-afternoon, and — bonus — finishing dinner earlier (hello, 16:8) reliably improves sleep quality. Track sleep in your biometrics and watch the pattern."],
        ['😤','Habits','Stress eating: break the loop',
         "Stress raises cortisol, cortisol raises appetite — specifically for dense, sugary comfort food. The loop: trigger → craving → eat → brief relief → guilt → more stress. You can't remove stress, but you can rewire the middle: when the craving hits, pause 90 seconds and name what you actually feel (bored? angry? tired?). Half the time, food isn't the fix — a walk, a shower, or texting a friend is. When you do eat emotionally, log it without shame and note the trigger. Patterns you can see are patterns you can change."],
        ['🚶','Movement','NEAT: the invisible calorie burner',
         "Non-Exercise Activity Thermogenesis — everything that isn't formal exercise: walking, stairs, cooking, fidgeting, standing. NEAT can differ by 300-800 kcal a day between two similar people, dwarfing what most gym sessions burn. Better yet, it doesn't spike hunger like hard workouts can. Easy wins: park farther, take every stairs option, pace during phone calls, a 10-minute walk after each meal (which also blunts blood-sugar spikes). With back issues, NEAT is your safest, highest-volume tool. Steps are trackable in your biometrics — aim to beat last week."],
        ['🏋️','Movement','Muscle is your metabolism',
         "Every kilo of muscle burns calories around the clock and gives lost weight somewhere good to come from. Without strength work, up to a quarter of what you lose can be muscle — that's how people end up 'skinny but soft' with a slower metabolism. Twice a week of the back-safe strength work in your Workouts tab is enough to keep and even build muscle in a deficit, especially with your protein target hit. Focus on: glute bridges, goblet squats, supported rows, bench press. Strong glutes and core also directly protect a cranky lower back."],
        ['🚫','Mindset','All-or-nothing is the real enemy',
         "\"I ate one cookie, the day is ruined, might as well finish the box\" — that thought does more damage than the cookie ever could. It's called the abstinence violation effect, and it turns 150-calorie slips into 1500-calorie spirals. The fix is arithmetic honesty: one cookie is 3% of your week. The box is 30%. A slip only becomes a setback when you multiply it. New rule: the next meal is always a fresh start — not tomorrow, not Monday. Log the cookie, close the loop, move on. People who master this one thought pattern are the ones who keep weight off."],
        ['🍽️','Mindset','Fog eating: the calories you never taste',
         "Chips while scrolling. Finishing the kids' plates. Grazing while cooking. 'Fog eating' is eating without noticing — and it can add hundreds of unlogged calories a day. The antidote is embarrassingly simple: eat only while eating. Sit down, no screen, and actually taste the first three bites (attention naturally drifts after that, and that's fine). Bonus: your fullness signal takes ~20 minutes to arrive, so slower meals mean less food before the 'enough' bell rings. Tonight, try one fully attentive meal and notice how much sooner you feel done."],
        ['📊','Mindset','Plateaus: what they mean and what to do',
         "Weight stalls for 2-3 weeks even when you're doing everything right — water retention masks fat loss (especially after workouts), and a lighter body simply burns fewer calories. Before changing anything, audit: are you still logging everything? Portions creeping? Weekend counting? If genuinely stalled for 3+ weeks: add 10 minutes of daily walking, tighten logging accuracy, or update your weight in Settings so your targets recalculate for the new, lighter you. What you must NOT do: crash-cut calories in frustration. Plateaus break; panic diets break you."],
        ['🥑','Keto','Not all fats are equal',
         "Keto means fat is your main fuel — so choose it like it matters. Prioritize: olive oil, avocado, fatty fish (omega-3s), nuts, and eggs. Moderate: butter, cream, cheese, coconut oil. Watch: processed meats every day (sodium + preservatives), and deep-fried anything (oxidized seed oils). VitaTrack now shows your saturated fat total in the diary detail — the guideline is keeping it to roughly a third of your fat intake. With heart conditions or high cholesterol in the family, get bloodwork a few months into keto; most people improve, but verify, don't assume."],
        ['🕵️','Keto','Hidden carbs: where ketosis quietly dies',
         "You're 'doing keto' but not losing? Hunt the hidden carbs: sauces (BBQ sauce is ~30% sugar), dressings, marinades, 'a little' honey in tea, breaded coatings, cashews (3x the carbs of pecans), milk in coffee (5g per glass splash adds up), medications and gum with sugar, and restaurant 'low-carb' dishes with sugary glazes. The barcode scanner is your weapon — scan before you buy, check the real carbs. Rule of thumb: if it's sweet, sticky, crispy-coated, or comes in a packet, it's guilty until the label proves otherwise."],
        ['💊','Nutrition','Micronutrients on a deficit',
         "Eating less food means fewer vitamins riding along — deficiencies make you tired, hungry, and miserable, which kills diets. Cover the bases: leafy greens most days (magnesium, folate), fatty fish twice a week (omega-3, vitamin D), eggs (nearly everything), dairy or almonds (calcium), and colorful vegetables (the rainbow isn't marketing — different colors are literally different antioxidants). On keto, electrolytes are non-negotiable: sodium, potassium, magnesium daily. A basic multivitamin is cheap insurance during weight loss, but it supplements food — it doesn't replace it."],
        ['⚖️','Habits','The daily weigh-in ritual',
         "Weigh daily, same conditions: morning, after the bathroom, before food or water, minimal clothes. Log it in VitaTrack even when it's ugly — especially when it's ugly, because skipping bad-news weigh-ins is how tracking dies. Daily weighers lose more and keep it off better in studies, but only when they've internalized lesson five: the number is noise, the trend is signal. Your Progress chart smooths the noise for you. One more thing: never weigh after a restaurant night and panic — you know about sodium now. Data without drama."],
        ['🏆','Mindset','Maintenance starts on day one',
         "Here's the mindset that separates permanent losers from yo-yo dieters: you're not 'on a diet' you'll finish — you're building the eating pattern you'll keep at goal weight, just with slightly fewer calories for now. Every habit you build now (logging, protein-first meals, walking, weekly weigh-in trends) is a maintenance habit. When you hit goal, calories go up a few hundred; nothing else changes. That's why sustainable beats fast every time you're forced to choose. You've finished the course — now it repeats in your life, one logged day at a time. You've got this. 🎉"],
    ];
    $st = $pdo->prepare("INSERT INTO lessons (ord,emoji,category,title,body) VALUES (?,?,?,?,?)");
    foreach ($L as $i => $l) $st->execute([$i + 1, $l[0], $l[1], $l[2], $l[3]]);
}
