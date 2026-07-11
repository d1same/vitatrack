<?php
// AI photo food analysis — supports Anthropic (Claude) and OpenAI keys.
// The provider is auto-detected from the key prefix the user saves in Settings:
//   sk-ant-…  → Claude (claude-haiku-4-5, ~$0.0025/scan)
//   sk-…      → OpenAI (gpt-4o-mini, ~$0.004/scan)

const FOOD_PROMPT =
    "Analyze this food photo. Identify each food item and estimate its portion and nutrition. " .
    "Respond with ONLY valid JSON, no markdown, in this exact shape:\n" .
    '{"items":[{"name":"...","grams":0,"kcal":0,"protein":0,"carbs":0,"fat":0,"fiber":0,"sugar":0,"sodium":0,"satfat":0}],"notes":"one short sentence about confidence/assumptions"}' .
    "\nsodium is in mg; sugar/satfat in g." .
    "\nUse realistic portion estimates. Numbers only (no units in values). If it is not food, return {\"items\":[],\"notes\":\"No food detected\"}.";

function analyze_food_photo(string $apiKey, string $dataUrl): array {
    if (!preg_match('#^data:image/(jpeg|png|webp|gif);base64,(.+)$#s', $dataUrl, $m)) {
        return ['ok' => false, 'error' => 'Invalid image data'];
    }
    $mediaType = 'image/' . $m[1];
    $b64 = $m[2];
    if (strlen($b64) > 7_000_000) {
        return ['ok' => false, 'error' => 'Image too large — please retake with lower resolution'];
    }

    if (str_starts_with($apiKey, 'sk-ant-')) {
        $text = claude_vision($apiKey, $mediaType, $b64, $err);
    } else {
        $text = openai_vision($apiKey, $dataUrl, $err);
    }
    if ($text === null) return ['ok' => false, 'error' => $err];

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

function http_post_json(string $url, array $headers, string $payload, ?string &$err): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) { $err = 'Connection failed: ' . $curlErr; return null; }
    $json = json_decode($resp, true);
    if ($code !== 200) {
        $err = $json['error']['message'] ?? ('API error (HTTP ' . $code . ')');
        return null;
    }
    return $json;
}

// Claude vision (Messages API) — returns the response text, or null with $err set.
function claude_vision(string $apiKey, string $mediaType, string $b64, ?string &$err): ?string {
    $payload = json_encode([
        // Haiku 4.5: cheapest vision-capable Claude model — plenty for food recognition
        'model' => 'claude-haiku-4-5',
        'max_tokens' => 1024,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $b64]],
                ['type' => 'text', 'text' => FOOD_PROMPT],
            ],
        ]],
    ]);
    $json = http_post_json('https://api.anthropic.com/v1/messages', [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ], $payload, $err);
    if ($json === null) return null;

    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return $text;
}

// OpenAI vision (Chat Completions) — returns the response text, or null with $err set.
function openai_vision(string $apiKey, string $dataUrl, ?string &$err): ?string {
    $payload = json_encode([
        // gpt-4o-mini: cheapest vision-capable OpenAI model
        'model' => 'gpt-4o-mini',
        'max_tokens' => 1024,
        'response_format' => ['type' => 'json_object'],
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => FOOD_PROMPT],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'auto']],
            ],
        ]],
    ]);
    $json = http_post_json('https://api.openai.com/v1/chat/completions', [
        'Authorization: Bearer ' . $apiKey,
    ], $payload, $err);
    if ($json === null) return null;

    return (string)($json['choices'][0]['message']['content'] ?? '');
}
