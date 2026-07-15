<?php
// Web Push (VAPID, RFC 8292) in plain PHP — no libraries needed.
// We send payload-FREE pushes (no encryption required); the service worker
// wakes up and fetches the reminder text from the server (action=due_reminder).

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

// P-256 keypair, generated once and stored in data/vapid.json.
function vapid_keys(): array {
    $file = __DIR__ . '/../data/vapid.json';
    if (is_file($file)) {
        $k = json_decode(file_get_contents($file), true);
        if (is_array($k) && !empty($k['public'])) return $k;
    }
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($res === false) throw new RuntimeException('This server\'s OpenSSL cannot create EC keys');
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $pub = b64url("\x04"
        . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
        . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
    $keys = ['private_pem' => $pem, 'public' => $pub];
    file_put_contents($file, json_encode($keys));
    @chmod($file, 0600);
    return $keys;
}

function vapid_public_key(): string {
    return vapid_keys()['public'];
}

// DER ECDSA signature → raw 64-byte R||S (JWS format)
function ecdsa_der_to_raw(string $der): string {
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) throw new RuntimeException('bad DER');
    $len = ord($der[$pos++]);
    if ($len & 0x80) $pos += $len & 0x7f;
    if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('bad DER');
    $rl = ord($der[$pos++]); $r = substr($der, $pos, $rl); $pos += $rl;
    if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('bad DER');
    $sl = ord($der[$pos++]); $s = substr($der, $pos, $sl);
    $r = ltrim($r, "\0"); $s = ltrim($s, "\0");
    return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
}

// Short-lived ES256 JWT identifying this server to the push service.
function vapid_jwt(string $aud): string {
    $keys = vapid_keys();
    $seg = function (array $a): string { return b64url(json_encode($a)); };
    $unsigned = $seg(['typ' => 'JWT', 'alg' => 'ES256']) . '.'
              . $seg(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:admin@vitatrack.app']);
    if (!openssl_sign($unsigned, $der, $keys['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('JWT signing failed');
    }
    return $unsigned . '.' . b64url(ecdsa_der_to_raw($der));
}

// POST an empty push message to a subscription endpoint. Returns HTTP status;
// 404/410 mean the subscription is dead and should be deleted.
function send_push(string $endpoint): int {
    $p = parse_url($endpoint);
    if (!$p || ($p['scheme'] ?? '') !== 'https') return 0;
    $aud = 'https://' . $p['host'];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . vapid_jwt($aud) . ', k=' . vapid_public_key(),
            'TTL: 3600',
            'Urgency: normal',
            'Content-Length: 0',
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

// Parse an "HH:MM" setting into minutes-of-day, falling back to a default.
function reminder_min(string $v, int $dh, int $dm): int {
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) return (((int)$m[1]) % 24) * 60 + (((int)$m[2]) % 60);
    return $dh * 60 + $dm;
}

// Which reminder (if any) is due for this user at their local time right now?
// Mirrors the in-app reminderSchedule(): user-configured meal/weigh/water times,
// each fires once per day within a grace window after its set time.
function compute_due_reminder(PDO $pdo, int $uid, int $tzMin): ?array {
    $st = $pdo->prepare("SELECT key, value FROM settings WHERE user_id=?");
    $st->execute([$uid]);
    $s = [];
    foreach ($st->fetchAll() as $r) $s[$r['key']] = $r['value'];

    $now = time() + $tzMin * 60;
    $today = gmdate('Y-m-d', $now);
    $nowMin = (int)gmdate('G', $now) * 60 + (int)gmdate('i', $now);

    $sched = [];
    if (($s['reminders_weight'] ?? '') === '1') {
        $sched[] = ['type' => 'weigh', 'min' => reminder_min($s['weigh_time'] ?? '', 8, 0),
            'title' => 'Morning weigh-in', 'body' => 'Best time to weigh: after waking, before eating. Log it now.'];
    }
    // Skip meal reminders entirely while a fast is running (if opted in).
    $skipMeals = false;
    if (($s['meals_skip_fasting'] ?? '1') !== '0') {
        $af = $pdo->prepare("SELECT 1 FROM fasts WHERE user_id=? AND end_ts IS NULL LIMIT 1");
        $af->execute([$uid]);
        $skipMeals = (bool)$af->fetchColumn();
    }
    if (!$skipMeals && ($s['reminders_meals'] ?? '') === '1') {
        foreach ([['meal_b', 'breakfast', 'meal_breakfast', 8, 0, 'Breakfast'], ['meal_l', 'lunch', 'meal_lunch', 13, 0, 'Lunch'], ['meal_d', 'dinner', 'meal_dinner', 19, 0, 'Dinner']] as $mm) {
            if (($s['meal_' . $mm[1] . '_on'] ?? '1') === '0') continue; // this meal turned off
            $sched[] = ['type' => $mm[0], 'min' => reminder_min($s[$mm[2]] ?? '', $mm[3], $mm[4]),
                'title' => $mm[5] . ' time', 'body' => 'Log your ' . strtolower($mm[5]) . ' — your streak is counting on you.'];
        }
    }
    if (($s['reminders_water'] ?? '') === '1') {
        $start = reminder_min($s['water_start'] ?? '', 9, 0);
        $end = reminder_min($s['water_end'] ?? '', 21, 0);
        $every = max(1, (int)($s['water_every'] ?? 2)) * 60;
        for ($t = $start; $t <= $end; $t += $every) {
            $sched[] = ['type' => 'water_' . $t, 'min' => $t,
                'title' => 'Water break', 'body' => 'Time for a glass of water — hydration helps fat loss.'];
        }
    }

    // Fire the first reminder whose time has passed within the last hour and
    // hasn't been sent today. Grace covers gaps between cron runs / downtime.
    foreach ($sched as $d) {
        $delta = $nowMin - $d['min'];
        if ($delta < 0 || $delta >= 60) continue;
        if (($s['push_sent_' . $d['type']] ?? '') === $today) continue;
        return ['type' => $d['type'], 'title' => $d['title'], 'body' => $d['body'], 'slot' => $today];
    }
    return null;
}
