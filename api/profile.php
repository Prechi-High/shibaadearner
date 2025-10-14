<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

// Read JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['init_data'])) {
    echo json_encode(['ok' => false, 'message' => 'Missing or invalid init_data.']); 
    exit;
}

$init_data = $input['init_data'];

// Validate user
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']); 
    exit;
}

$userData = $verify['data']['user'];
$user_id = $userData['id'];

// Fetch user data
$stmt = $pdo->prepare("
    SELECT balance, gems, my_badge, completed_task, joined_at, total_7_day_strike
    FROM users
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); 
    exit;
}

// Calculate total task complete
$completedTasks = json_decode($user['completed_task'], true);
$totalTasksCompleted = is_array($completedTasks) ? count($completedTasks) : 0;

// Calculate lifetime withdraw
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM withdrawals 
    WHERE user_id = ? AND status = 'paid'
");
$stmt->execute([$user_id]);
$lifetimeWithdraw = (float)$stmt->fetchColumn();

// Response
echo json_encode([
    'ok' => true,
    'message' => 'User summary fetched successfully.',
    'result' => [
        'balance' => (float)$user['balance'],
        'gems' => (float)$user['gems'],
        'lifetime_withdraw' => $lifetimeWithdraw,
        'my_badge' => $user['my_badge'],
        'total_task_complete' => $totalTasksCompleted,
        'joined_at' => $user['joined_at'],
        'streaks' => (int)$user['total_7_day_strike'] // return as streaks
    ]
]);
