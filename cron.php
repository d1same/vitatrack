<?php
// Reminder push sender — run every 15 minutes via cPanel → Cron Jobs:
//   /usr/local/bin/php /home/dsamecom/health.mobasheri.us/cron.php
// (Or via URL with the token from data/cron.key: cron.php?token=…)
declare(strict_types=1);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/push.php';

if (PHP_SAPI !== 'cli') {
    $keyFile = __DIR__ . '/data/cron.key';
    if (!is_file($keyFile)) file_put_contents($keyFile, bin2hex(random_bytes(16)));
    if (!hash_equals(trim((string)file_get_contents($keyFile)), (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain');
}

$pdo = db();
$subs = $pdo->query("SELECT user_id, endpoint, tz_offset FROM push_subs ORDER BY user_id")->fetchAll();
$byUser = [];
foreach ($subs as $s) $byUser[$s['user_id']][] = $s;

$sent = 0;
$upsert = $pdo->prepare("INSERT INTO settings (user_id,key,value) VALUES (?,?,?)
    ON CONFLICT(user_id,key) DO UPDATE SET value=excluded.value");

foreach ($byUser as $uid => $rows) {
    $due = compute_due_reminder($pdo, (int)$uid, (int)$rows[0]['tz_offset']);
    if (!$due) continue;
    $upsert->execute([$uid, 'push_sent_' . $due['type'], $due['slot']]);
    $upsert->execute([$uid, 'pending_push', json_encode(['title' => $due['title'], 'body' => $due['body']])]);
    foreach ($rows as $r) {
        $code = send_push($r['endpoint']);
        if ($code === 404 || $code === 410) {
            $pdo->prepare("DELETE FROM push_subs WHERE endpoint=?")->execute([$r['endpoint']]);
        } elseif ($code >= 200 && $code < 300) {
            $sent++;
        }
    }
}
echo "sent=$sent subs=" . count($subs) . "\n";
