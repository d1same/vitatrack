<?php
// Open Food Facts integration — free, open product database (no API key needed).
// Used for barcode lookups and online food search.

function off_http(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'VitaTrack/1.0 (self-hosted personal health app)',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;
    return json_decode($resp, true);
}

function off_normalize(array $p): ?array {
    $n = $p['nutriments'] ?? [];
    $kcal = $n['energy-kcal_100g'] ?? null;
    if ($kcal === null && isset($n['energy_100g'])) $kcal = $n['energy_100g'] / 4.184; // kJ → kcal
    if ($kcal === null) return null;
    $name = trim((string)($p['product_name'] ?? ''));
    if ($name === '') return null;
    $brand = trim(explode(',', (string)($p['brands'] ?? ''))[0]);
    if ($brand && stripos($name, $brand) === false) $name = $name . ' (' . $brand . ')';
    $carbs = (float)($n['carbohydrates_100g'] ?? 0);
    $fiber = (float)($n['fiber_100g'] ?? 0);
    $sodium = isset($n['sodium_100g']) ? (float)$n['sodium_100g'] * 1000  // OFF stores grams → mg
            : (isset($n['salt_100g']) ? (float)$n['salt_100g'] * 400 : 0); // salt ≈ 40% sodium
    return [
        'name'    => mb_substr($name, 0, 80),
        'kcal'    => round((float)$kcal, 1),
        'protein' => round((float)($n['proteins_100g'] ?? 0), 1),
        'carbs'   => round($carbs, 1),
        'fat'     => round((float)($n['fat_100g'] ?? 0), 1),
        'fiber'   => round($fiber, 1),
        'keto'    => ($carbs - $fiber) <= 8 ? 1 : 0,
        'sugar'   => round((float)($n['sugars_100g'] ?? 0), 1),
        'sodium'  => round($sodium),
        'satfat'  => round((float)($n['saturated-fat_100g'] ?? 0), 1),
    ];
}

// Look up one product by barcode. Returns a per-100g food array or null.
function off_lookup(string $code): ?array {
    $j = off_http('https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($code)
        . '.json?fields=product_name,brands,nutriments');
    if (!$j || ($j['status'] ?? 0) != 1 || empty($j['product'])) return null;
    return off_normalize($j['product']);
}

// Free-text product search. Returns an array of per-100g food arrays.
function off_search(string $q): array {
    $j = off_http('https://world.openfoodfacts.org/cgi/search.pl?action=process&json=1'
        . '&search_simple=1&page_size=15&sort_by=unique_scans_n'
        . '&fields=product_name,brands,nutriments&search_terms=' . rawurlencode($q));
    $out = [];
    foreach (($j['products'] ?? []) as $p) {
        $f = off_normalize($p);
        if ($f) $out[] = $f;
        if (count($out) >= 12) break;
    }
    return $out;
}
