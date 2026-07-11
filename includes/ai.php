<?php
// AI photo food analysis via the Claude API (vision).
// Requires the user to save their Anthropic API key in Settings.

function analyze_food_photo(string $apiKey, string $dataUrl): array {
    if (!preg_match('#^data:image/(jpeg|png|webp|gif);base64,(.+)$#s', $dataUrl, $m)) {
        return ['ok' => false, 'error' => 'Invalid image data'];
    }
    $mediaType = 'image/' . $m[1];
    $b64 = $m[2];
    if (strlen($b64) > 7_000_000) {
        return ['ok' => false, 'error' => 'Image too large — please retake with lower resolution'];
    }

    $payload = json_encode([
        'model' => 'claude-sonnet-5',
        'max_tokens' => 1024,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $b64]],
                ['type' => 'text', 'text' =>
                    "Analyze this food photo. Identify each food item and estimate its portion and nutrition. " .
                    "Respond with ONLY valid JSON, no markdown, in this exact shape:\n" .
                    '{"items":[{"name":"...","grams":0,"kcal":0,"protein":0,"carbs":0,"fat":0,"fiber":0,"sugar":0,"sodium":0,"satfat":0}],"notes":"one short sentence about confidence/assumptions"}' .
                    "\nsodium is in mg; sugar/satfat in g." .
                    "\nUse realistic portion estimates. Numbers only (no units in values). If it is not food, return {\"items\":[],\"notes\":\"No food detected\"}."],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'Connection failed: ' . $err];
    $json = json_decode($resp, true);
    if ($code !== 200) {
        $msg = $json['error']['message'] ?? ('API error (HTTP ' . $code . ')');
        return ['ok' => false, 'error' => $msg];
    }

    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    // Tolerate accidental markdown fences
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['items'])) {
        return ['ok' => false, 'error' => 'Could not parse the analysis — please try again'];
    }

    $items = [];
    foreach ($data['items'] as $it) {
        $items[] = [
            'name'    => substr((string)($it['name'] ?? 'Food item'), 0, 80),
            'grams'   => round((float)($it['grams'] ?? 100)),
            'kcal'    => round((float)($it['kcal'] ?? 0)),
            'protein' => round((float)($it['protein'] ?? 0), 1),
            'carbs'   => round((float)($it['carbs'] ?? 0), 1),
            'fat'     => round((float)($it['fat'] ?? 0), 1),
            'fiber'   => round((float)($it['fiber'] ?? 0), 1),
            'sugar'   => round((float)($it['sugar'] ?? 0), 1),
            'sodium'  => round((float)($it['sodium'] ?? 0)),
            'satfat'  => round((float)($it['satfat'] ?? 0), 1),
        ];
    }
    return ['ok' => true, 'items' => $items, 'notes' => substr((string)($data['notes'] ?? ''), 0, 200)];
}
