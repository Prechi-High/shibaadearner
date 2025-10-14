<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); exit;
}

$init_data  = $input['init_data'] ?? '';
$ad_watched = $input['ad_watched'] ?? true; // default true

if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']); exit;
}

// Validate init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']); exit;
}

$userData = $verify['data']['user'];
$user_id  = $userData['id'];
$today    = date('d/m/Y');

// Fetch user details
$stmt = $pdo->prepare("SELECT gems, check_ins, total_7_day_strike, gems_record FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); exit;
}

// Fetch daily rewards + penalty
$settingsStmt = $pdo->query("SELECT daily_checkin_rewards, check_in_without_ads FROM settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$checkinRewards = json_decode($settings['daily_checkin_rewards'] ?? '{}', true);
$penaltyPercent = isset($settings['check_in_without_ads']) ? (int)$settings['check_in_without_ads'] : 0;

// Parse user's check-ins
$checkIns = json_decode($user['check_ins'] ?? '[]', true);
if (!is_array($checkIns)) $checkIns = [];

// Already checked in today?
if (in_array($today, $checkIns, true)) {
    echo json_encode(['ok' => false, 'message' => 'You already checked in today.']); exit;
}

// Determine streak day based on yesterday continuity
$strike = 1; // what "day" this check-in represents BEFORE adding today
if (!empty($checkIns)) {
    $lastDate  = end($checkIns);
    $yesterday = date('d/m/Y', strtotime('-1 day'));

    if ($lastDate === $yesterday) {
        // consecutive
        $strike = count($checkIns) + 1; // 2..N (could be 8 on the 8th consecutive day)
    } else {
        // broken streak -> start fresh
        $strike = 1;
        $checkIns = [];
    }
}

// Add today's date to check-in list (we may overwrite this later for the 8th day case)
$checkIns[] = $today;

// Effective reward day: cap at 7; for 8th consecutive, reward Day 1
$effectiveDay = ($strike >= 1 && $strike <= 7) ? $strike : 1;

// Get reward and apply penalty if ad not watched
$baseReward   = $checkinRewards["day_{$effectiveDay}"] ?? ($checkinRewards["day_1"] ?? 0);
$rewardAmount = $ad_watched ? $baseReward : round($baseReward * ((100 - $penaltyPercent) / 100), 2);

// Handle 7th and 8th day rules
$total7DayStrike = (int)$user['total_7_day_strike'];

if ($strike === 7) {
    // Completed a 7-day streak -> increment counter, DO NOT clear check-ins
    $total7DayStrike++;
} elseif ($strike === 8) {
    // 8th consecutive day -> reward Day 1, reset check_ins to only today (remove olds)
    $checkIns = [$today];
    // Do NOT increment total_7_day_strike here
}

// Update gems record
$gemsRecord = json_decode($user['gems_record'] ?? '[]', true);
if (!is_array($gemsRecord)) $gemsRecord = [];
$gemsRecord[] = [
    'amount' => (float)$rewardAmount,
    'date'   => $today,
    'reason' => 'Check in reward'
];

// Update DB
$newGems = (float)$user['gems'] + (float)$rewardAmount;

$upd = $pdo->prepare("
    UPDATE users
    SET gems = ?, check_ins = ?, total_7_day_strike = ?, gems_record = ?
    WHERE user_id = ?
");
$upd->execute([
    $newGems,
    json_encode(array_values($checkIns), JSON_UNESCAPED_UNICODE),
    $total7DayStrike,
    json_encode($gemsRecord, JSON_UNESCAPED_UNICODE),
    $user_id
]);

// Respond
echo json_encode([
    'ok'      => true,
    'message' => "Check-in successful! Day {$effectiveDay} reward: {$rewardAmount} gems.",
    'result'  => [
        'day'                 => $effectiveDay,
        'reward'              => $rewardAmount,
        'total_gems'          => $newGems,
        'total_7_day_strike'  => $total7DayStrike,
        'check_ins_count'     => count($checkIns)
    ]
]);
