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

// Which reminder (if any) is due for this user at their local time right now?
// Mirrors the in-app schedule: weigh-in 8:00, meals 8/13/19, water odd hours 9–21.
function compute_due_reminder(PDO $pdo, int $uid, int $tzMin): ?array {
    $st = $pdo->prepare("SELECT key, value FROM settings WHERE user_id=?");
    $st->execute([$uid]);
    $s = [];
    foreach ($st->fetchAll() as $r) $s[$r['key']] = $r['value'];

    $now = time() + $tzMin * 60;
    $h = (int)gmdate('G', $now);
    $mi = (int)gmdate('i', $now);
    $slot = gmdate('Y-m-d-G', $now);
    if ($mi >= 25) return null; // only fire near the top of the hour

    $due = null;
    if (($s['reminders_weight'] ?? '') === '1' && $h === 8) {
        $due = ['type' => 'weigh', 'title' => 'Morning weigh-in', 'body' => 'Best time to weigh: after waking, before eating. Log it now.'];
    }
    if (!$due && ($s['reminders_meals'] ?? '') === '1' && in_array($h, [8, 13, 19], true)) {
        $meal = $h === 8 ? 'breakfast' : ($h === 13 ? 'lunch' : 'dinner');
        $due = ['type' => 'meal', 'title' => ucfirst($meal) . ' time', 'body' => "Log your $meal — your streak is counting on you."];
    }
    if (!$due && ($s['reminders_water'] ?? '') === '1' && $h >= 9 && $h <= 21 && $h % 2 === 1) {
        $due = ['type' => 'water', 'title' => 'Water break', 'body' => 'Time for a glass of water — hydration helps fat loss.'];
    }
    if (!$due) return null;
    if (($s['push_sent_' . $due['type']] ?? '') === $slot) return null; // already sent this hour
    $due['slot'] = $slot;
    return $due;
}
