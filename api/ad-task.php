<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// ✅ Read input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); exit;
}

$init_data = $input['init_data'] ?? '';
if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']); exit;
}

// ✅ Validate init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']); exit;
}

$userData = $verify['data']['user'];
$user_id = $userData['id'];
$now = new DateTime('now', new DateTimeZone('UTC'));

// ✅ Get user
$stmt = $pdo->prepare("SELECT gems, last_ad_task, gems_record FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); exit;
}

// ✅ Get settings
$stmt = $pdo->query("SELECT ad_task_interval, ad_task_reward FROM settings LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
$intervalMinutes = (int)($settings['ad_task_interval'] ?? 10);
$rewardGems = (int)($settings['ad_task_reward'] ?? 5);

// ✅ Check cooldown
$lastAd = $user['last_ad_task'] ? new DateTime($user['last_ad_task'], new DateTimeZone('UTC')) : null;
$nextAllowed = $lastAd ? clone $lastAd : null;
if ($nextAllowed) $nextAllowed->modify("+{$intervalMinutes} minutes");

if ($lastAd && $now < $nextAllowed) {
    $wait = $nextAllowed->getTimestamp() - $now->getTimestamp();
    echo json_encode(['ok' => false, 'message' => "Please wait {$wait} seconds before watching another ad."]); exit;
}

// ✅ Update gems and record
$gems = (int)$user['gems'];
$newGems = $gems + $rewardGems;
$today = date('d/m/Y');

// Update gems record
$record = json_decode($user['gems_record'] ?? '[]', true);
if (!is_array($record)) $record = [];

$record[] = [
    'amount' => $rewardGems,
    'date' => $today,
    'reason' => 'Ad Task Reward'
];

// ✅ Update DB
$stmt = $pdo->prepare("UPDATE users SET gems = ?, last_ad_task = ?, gems_record = ? WHERE user_id = ?");
$stmt->execute([$newGems, $now->format('Y-m-d H:i:s'), json_encode($record), $user_id]);

// ✅ Done
echo json_encode([
    'ok' => true,
    'message' => "You earned {$rewardGems} gems for watching an ad.",
    'result' => [
        'reward' => $rewardGems,
        'next_available_in_seconds' => $intervalMinutes * 60,
        'total_gems' => $newGems
    ]
]);
