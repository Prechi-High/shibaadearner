<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// ✅ Read JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); exit;
}

$init_data = $input['init_data'] ?? '';
if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']); exit;
}

// ✅ Verify init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']); exit;
}

$userData = $verify['data']['user'];
$user_id = $userData['id'];
$today = date('d/m/Y');

// ✅ Fetch user details
$stmt = $pdo->prepare("SELECT balance, gems, scratch_cards, lucky_boxes, name, check_ins FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'not-found']); exit;
}

// ✅ Fetch settings: min_withdraw, daily_checkin_rewards, check_in_without_ads
$settingsStmt = $pdo->query("SELECT min_withdraw, daily_checkin_rewards, check_in_without_ads FROM settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$min_withdraw = isset($settings['min_withdraw']) ? (float)$settings['min_withdraw'] : 0;
$checkinRewards = json_decode($settings['daily_checkin_rewards'] ?? '[]', true);
$check_in_without_ads = isset($settings['check_in_without_ads']) ? (int)$settings['check_in_without_ads'] : 0;

// ✅ Handle check-in logic
$checkIns = json_decode($user['check_ins'] ?? '[]', true);
if (!is_array($checkIns)) $checkIns = [];

$checkInDay = 1;
$checkedIn = false;

if (!empty($checkIns)) {
    $lastDate = end($checkIns);
    $yesterday = date('d/m/Y', strtotime('-1 day'));

    if ($lastDate === $today) {
        $checkInDay = count($checkIns);
        $checkedIn = true;
    } elseif ($lastDate === $yesterday) {
        $checkInDay = count($checkIns) + 1;
        if ($checkInDay > 7) $checkInDay = 1;
    } else {
        $checkInDay = 1;
    }
}

// ✅ Final response
echo json_encode([
    'ok' => true,
    'message' => 'User details fetched successfully.',
    'result' => [
        'balance' => (float)$user['balance'],
        'gems' => (float)$user['gems'],
        'scratch_cards' => (int)$user['scratch_cards'],
        'lucky_boxes' => (int)$user['lucky_boxes'],
        'name' => $user['name'] ?? null,
        'min_withdraw' => $min_withdraw,
        'check_in_day' => $checkInDay,
        'check_in_rewards' => $checkinRewards,
        'checked_in' => $checkedIn,
        'check_in_without_ads' => $check_in_without_ads
    ]
]);


