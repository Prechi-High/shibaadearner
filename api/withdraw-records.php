<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// ✅ Read JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['init_data'])) {
    echo json_encode(['ok' => false, 'message' => 'Missing init_data']);
    exit;
}

$init_data = $input['init_data'];

// ✅ Verify init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data']);
    exit;
}

$user_id = $verify['data']['user']['id'];

// ✅ Fetch withdrawals for user
$stmt = $pdo->prepare("SELECT amount, date, withdraw_method, status FROM withdrawals WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$user_id]);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Calculate totals
$total_success = 0;
$total_pending = 0;
$history = [];

foreach ($withdrawals as $w) {
    if ($w['status'] === 'paid') {
        $total_success += (float)$w['amount'];
    } elseif ($w['status'] === 'pending') {
        $total_pending += (float)$w['amount'];
    }

    $history[] = [
        'amount' => (float)number_format($w['amount'], 2),
        'date' => $w['date'],
        'method' => $w['withdraw_method'],
        'status' => $w['status']
    ];
}

// ✅ Response
echo json_encode([
    'ok' => true,
    'message' => 'Withdrawal history fetched successfully.',
    'result' => [
        'total_success' => $total_success,
        'total_pending' => number_format($total_pending, 2),
        'history' => $history
    ]
]);
