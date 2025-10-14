<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

$response = ['ok' => false, 'message' => '', 'result' => []];

// Read and validate input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['init_data'])) {
    echo json_encode(['ok' => false, 'message' => 'Missing or invalid init_data.']); exit;
}

$init_data = $input['init_data'];
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);

if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data. Telegram authentication failed.']); exit;
}

$user = $verify['data']['user'];
$user_id = $user['id'];

// Get user balance and address (no phone/bank/binance)
$stmt = $pdo->prepare("SELECT balance, address FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userData) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); exit;
}

// Get withdraw constraints and fee from settings
$settings = $pdo->query("
    SELECT 
        min_withdraw, 
        max_withdraw, 
        withdraw_fee
    FROM settings 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    echo json_encode(['ok' => false, 'message' => 'Settings not configured.']); exit;
}

// Get withdrawal amounts (sum by status)
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0)  AS total_success,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS total_pending
    FROM withdrawals
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$withdrawalAmounts = $stmt->fetch(PDO::FETCH_ASSOC);

// Numbers
$balance        = (float) $userData['balance'];
$min_withdraw   = isset($settings['min_withdraw']) ? (float) $settings['min_withdraw'] : 0.0;
$max_withdraw   = isset($settings['max_withdraw']) ? (float) $settings['max_withdraw'] : 0.0;
$withdraw_fee   = isset($settings['withdraw_fee']) ? (float) $settings['withdraw_fee'] : 0.0;


$eligible = ($balance >= $min_withdraw) ? $balance : 0.0;
if ($eligible > 0 && $max_withdraw > 0) {
    $eligible = min($eligible, $max_withdraw);
}

// Final result
$response['ok'] = true;
$response['result'] = [
    'balance'         => number_format($balance,2),
    'address'         => $userData['address'] ?? null,
    'min_withdraw'    => $min_withdraw,
    'max_withdraw'    => $max_withdraw,
    'withdraw_fee'    => $withdraw_fee,
    'total_success'   => (float) $withdrawalAmounts['total_success'],
    'total_pending'   => (float) $withdrawalAmounts['total_pending'],
    'to_be_withdrawn' => $eligible
];

echo json_encode($response);
