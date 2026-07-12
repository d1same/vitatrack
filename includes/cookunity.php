<?php
// CookUnity integration (opt-in, per user).
// Talks to CookUnity's own GraphQL API (same endpoints their site uses) to list
// the user's ordered meals with nutrition, so they can be logged in one tap.
// Endpoint/flow reference: github.com/ggonzalezaleman/cookunity-mcp (MIT).

const CU_AUTH_BASE = 'https://auth.cookunity.com';
const CU_CLIENT_ID = 'E3AWy6rDb3S3ErYliO64fnY171Ec1xhf';
const CU_REALM = 'cookunity';
const CU_REDIRECT = 'https://www.cookunity.com';
const CU_MENU_URL = 'https://subscription.cookunity.com/menu-service/graphql';
const CU_SUB_URL = 'https://subscription.cookunity.com/subscription-back/graphql/user';

function cu_curl(string $url, array $opts): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    return ['code' => $code, 'headers' => substr($resp, 0, $hsize), 'body' => substr($resp, $hsize), 'error' => null];
}

// Auth0 password-realm flow (cookie jar + redirect chase). Returns
// ['token' =>, 'expires' =>] or ['error' =>].
function cu_authenticate(string $email, string $password): array {
    $jar = tempnam(sys_get_temp_dir(), 'cu');
    $common = [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar];

    // 1. login ticket
    $r = cu_curl(CU_AUTH_BASE . '/co/authenticate', $common + [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'client_id' => CU_CLIENT_ID,
            'credential_type' => 'http://auth0.com/oauth/grant-type/password-realm',
            'username' => $email,
            'password' => $password,
            'realm' => CU_REALM,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: https://www.cookunity.com'],
    ]);
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || empty($j['login_ticket'])) {
        @unlink($jar);
        $msg = $j['error_description'] ?? $j['description'] ?? ('login failed (HTTP ' . $r['code'] . ')');
        return ['error' => 'cookunity.com: ' . $msg];
    }

    // 2. authorize — follow redirects manually until ?code= appears
    $url = CU_AUTH_BASE . '/authorize?' . http_build_query([
        'client_id' => CU_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => CU_REDIRECT,
        'scope' => 'openid profile email',
        'realm' => CU_REALM,
        'login_ticket' => $j['login_ticket'],
    ]);
    $code = null;
    for ($i = 0; $i < 5; $i++) {
        $r = cu_curl($url, $common + [CURLOPT_FOLLOWLOCATION => false]);
        if (!preg_match('/^Location:\s*(.+)$/mi', $r['headers'], $m)) break;
        $loc = trim($m[1]);
        if (!preg_match('#^https?://#', $loc)) $loc = CU_AUTH_BASE . $loc;
        $qs = parse_url($loc, PHP_URL_QUERY) ?: '';
        parse_str($qs, $params);
        if (!empty($params['code'])) { $code = $params['code']; break; }
        $url = $loc;
    }
    if (!$code) { @unlink($jar); return ['error' => 'cookunity.com: could not complete login flow']; }

    // 3. exchange code for tokens
    $r = cu_curl(CU_AUTH_BASE . '/oauth/token', $common + [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'client_id' => CU_CLIENT_ID,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => CU_REDIRECT,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    @unlink($jar);
    $j = json_decode($r['body'], true);
    if (empty($j['access_token'])) return ['error' => 'cookunity.com: token exchange failed'];
    return ['token' => $j['access_token'], 'expires' => time() + (int)($j['expires_in'] ?? 86400)];
}

function cu_graphql(string $token, string $endpoint, string $query, array $vars = [], ?string $opName = null): array {
    $body = ['query' => $query, 'variables' => $vars ?: new stdClass()];
    if ($opName) $body['operationName'] = $opName;
    $r = cu_curl($endpoint, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $token,
            'Content-Type: application/json',
            'User-Agent: VitaTrack/1.0',
        ],
    ]);
    $j = json_decode($r['body'], true);
    if ($r['code'] === 401) return ['error' => 'auth_expired'];
    if (!empty($j['errors'])) return ['error' => 'cookunity.com: ' . ($j['errors'][0]['message'] ?? 'API error')];
    if ($r['code'] !== 200 || !isset($j['data'])) return ['error' => 'cookunity.com: HTTP ' . $r['code']];
    return ['data' => $j['data']];
}

// Get a working token for the user (cached in settings, re-auths when needed).
function cu_token(PDO $pdo, int $uid, bool $forceAuth = false): array {
    $st = $pdo->prepare("SELECT key, value FROM settings WHERE user_id=? AND key IN ('cookunity_email','cookunity_pass','cookunity_token','cookunity_token_exp')");
    $st->execute([$uid]);
    $s = [];
    foreach ($st->fetchAll() as $r) $s[$r['key']] = $r['value'];
    if (empty($s['cookunity_email']) || empty($s['cookunity_pass'])) return ['error' => 'CookUnity is not connected — add your login in Settings'];
    if (!$forceAuth && !empty($s['cookunity_token']) && (int)($s['cookunity_token_exp'] ?? 0) > time() + 60) {
        return ['token' => $s['cookunity_token']];
    }
    $auth = cu_authenticate($s['cookunity_email'], $s['cookunity_pass']);
    if (isset($auth['error'])) return $auth;
    $up = $pdo->prepare("INSERT INTO settings (user_id,key,value) VALUES (?,?,?) ON CONFLICT(user_id,key) DO UPDATE SET value=excluded.value");
    $up->execute([$uid, 'cookunity_token', $auth['token']]);
    $up->execute([$uid, 'cookunity_token_exp', (string)$auth['expires']]);
    return ['token' => $auth['token']];
}

// The user's upcoming deliveries with per-meal nutrition.
function cu_upcoming_meals(PDO $pdo, int $uid): array {
    $t = cu_token($pdo, $uid);
    if (isset($t['error'])) return $t;
    $token = $t['token'];

    $q = 'query upcomingDays { upcomingDays { date displayDate skip
            cart { product { inventoryId name chef_firstname chef_lastname } qty }
            order { items { qty product { inventoryId name chef_firstname chef_lastname } } }
          } }';
    $r = cu_graphql($token, CU_SUB_URL, $q, [], 'upcomingDays');
    if (($r['error'] ?? '') === 'auth_expired') {
        $t = cu_token($pdo, $uid, true);
        if (isset($t['error'])) return $t;
        $token = $t['token'];
        $r = cu_graphql($token, CU_SUB_URL, $q, [], 'upcomingDays');
    }
    if (isset($r['error'])) return $r;

    // collect up to 2 delivery days that actually have meals
    $days = [];
    foreach (($r['data']['upcomingDays'] ?? []) as $d) {
        if (!empty($d['skip'])) continue;
        $items = [];
        foreach (($d['order']['items'] ?? []) as $it) {
            $items[] = ['inventoryId' => $it['product']['inventoryId'] ?? '', 'name' => $it['product']['name'] ?? '',
                        'chef' => trim(($it['product']['chef_firstname'] ?? '') . ' ' . ($it['product']['chef_lastname'] ?? '')), 'qty' => (int)($it['qty'] ?? 1)];
        }
        if (!$items) {
            foreach (($d['cart'] ?? []) as $it) {
                $items[] = ['inventoryId' => $it['product']['inventoryId'] ?? '', 'name' => $it['product']['name'] ?? '',
                            'chef' => trim(($it['product']['chef_firstname'] ?? '') . ' ' . ($it['product']['chef_lastname'] ?? '')), 'qty' => (int)($it['qty'] ?? 1)];
            }
        }
        if ($items) $days[] = ['date' => $d['date'], 'displayDate' => $d['displayDate'] ?? $d['date'], 'items' => $items];
        if (count($days) >= 2) break;
    }
    if (!$days) return ['ok' => true, 'days' => []];

    // enrich with nutrition from the menu service (one query per delivery date)
    $mq = 'query getMenu($date: String!, $filters: MenuFilters!) { menu(date: $date, filters: $filters) {
             meals { inventoryId name nutritionalFacts { calories fat carbs sodium fiber protein sugar } } } }';
    foreach ($days as &$day) {
        $m = cu_graphql($token, CU_MENU_URL, $mq, ['date' => $day['date'], 'filters' => new stdClass()]);
        $byId = [];
        foreach (($m['data']['menu']['meals'] ?? []) as $meal) {
            $byId[$meal['inventoryId']] = $meal['nutritionalFacts'] ?? null;
        }
        foreach ($day['items'] as &$it) {
            $n = $byId[$it['inventoryId']] ?? null;
            $it['kcal']    = $n ? round((float)($n['calories'] ?? 0)) : null;
            $it['protein'] = $n ? round((float)($n['protein'] ?? 0), 1) : null;
            $it['carbs']   = $n ? round((float)($n['carbs'] ?? 0), 1) : null;
            $it['fat']     = $n ? round((float)($n['fat'] ?? 0), 1) : null;
            $it['fiber']   = $n ? round((float)($n['fiber'] ?? 0), 1) : null;
            $it['sugar']   = $n ? round((float)($n['sugar'] ?? 0), 1) : null;
            $it['sodium']  = $n ? round((float)($n['sodium'] ?? 0)) : null;
        }
    }
    return ['ok' => true, 'days' => $days];
}
