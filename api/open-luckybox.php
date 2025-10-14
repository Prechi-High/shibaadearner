<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';
$response = ['ok' => false, 'message' => '', 'result' => []];

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$init_data = $input['init_data'] ?? '';
if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']);
    exit;
}

// Verify init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']);
    exit;
}

$userData = $verify['data']['user'];
$user_id = $userData['id'];

// Fetch user info
$stmt = $pdo->prepare("SELECT lucky_boxes, balance FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']);
    exit;
}

if ((int)$user['lucky_boxes'] <= 0) {
    echo json_encode(['ok' => false, 'message' => 'No lucky boxes available.']);
    exit;
}

// Get lucky box reward JSON from settings
$settingsStmt = $pdo->query("SELECT luckybox_reward FROM settings LIMIT 1");
$luckybox_reward_json = $settingsStmt->fetchColumn();
if (!$luckybox_reward_json) {
    echo json_encode(['ok' => false, 'message' => 'Lucky box reward configuration not found.']);
    exit;
}

// Parse JSON
$luckybox_rewards = json_decode($luckybox_reward_json, true);
if (!is_array($luckybox_rewards)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid lucky box reward configuration.']);
    exit;
}

// Get user's balance
$user_balance = (float)$user['balance'];

// Find matching reward range based on user balance
$selected_reward = null;
foreach ($luckybox_rewards as $range) {
    if (!isset($range['balance']) || !isset($range['reward'])) {
        continue;
    }
    list($min_balance, $max_balance) = array_map('floatval', explode('-', trim($range['balance'])));
    if ($user_balance >= $min_balance && $user_balance <= $max_balance) {
        $selected_reward = $range['reward'];
        break;
    }
}

if (!$selected_reward) {
    echo json_encode(['ok' => false, 'message' => 'No reward range found for user balance.']);
    exit;
}

// Parse reward range
list($min_reward, $max_reward) = array_map('floatval', explode('-', trim($selected_reward)));

// Generate reward amount (2 decimal places)
$amount = number_format(mt_rand($min_reward * 100, $max_reward * 100) / 100, 2, '.', '');

// Deduct one lucky box and add reward to balance
$pdo->beginTransaction();
try {
    $update = $pdo->prepare("UPDATE users SET lucky_boxes = lucky_boxes - 1, balance = balance + ? WHERE user_id = ?");
    $update->execute([$amount, $user_id]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Failed to update balance.']);
    exit;
}

$newBal = $user['balance'] + $amount;

// Response
echo json_encode([
    'ok' => true,
    'message' => "Lucky box opened! You received $amount.",
    'result' => [
        'amount' => number_format($amount, 2),
        'new_balance' => number_format($newBal, 2)
    ]
]);
?>