<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';
// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}
$init_data = $input['init_data'] ?? '';
$task_id = $input['task_id'] ?? '';
if (empty($init_data) || empty($task_id)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameters: init_data or task_id']);
    exit;
}
// Validate init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']);
    exit;
}
$userData = $verify['data']['user'];
$user_id = $userData['id'];
$today = date('d-m-Y');
// Fetch user details
$stmt = $pdo->prepare("SELECT completed_task, gems, invited_by, gems_record, my_badge FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']);
    exit;
}
// Check if already completed
$completedTasks = json_decode($user['completed_task'], true) ?: [];
if (in_array($task_id, $completedTasks)) {
    echo json_encode(['ok' => false, 'message' => 'Task already completed.']);
    exit;
}
// Fetch task details
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE task_id = ? AND status = 'active'");
$stmt->execute([$task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$task) {
    echo json_encode(['ok' => false, 'message' => 'Task not found or inactive.']);
    exit;
}
// Validate completion limits
if ($task['max_completion'] > 0 && $task['completed'] >= $task['max_completion']) {
    echo json_encode(['ok' => false, 'message' => 'Task already reached max completion.']);
    exit;
}
// Check membership for channel tasks
if ($task['type'] === 'channel') {
    $chat_id = $task['chat_id'];
    $apiUrl = "https://api.telegram.org/bot$BOT_TOKEN/getChatMember?chat_id=$chat_id&user_id=$user_id";
    $responseTG = file_get_contents($apiUrl);
    $tgData = json_decode($responseTG, true);
    if (!isset($tgData['ok']) || !$tgData['ok']) {
        echo json_encode(['ok' => false, 'message' => 'Bot is not admin or cannot access the channel. Contact admin.']);
        exit;
    }
    $status = $tgData['result']['status'] ?? '';
    if (!in_array($status, ['member', 'administrator', 'creator'])) {
        echo json_encode(['ok' => false, 'message' => 'Join the provided channel to claim the reward.']);
        exit;
    }
}
// Check if user has started the bot for bot tasks
if ($task['type'] === 'bot') {
    $bot_token = $task['bot_token'];
    if (empty($bot_token)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid bot token: not found.']);
        exit;
    }
    $ch = curl_init("https://api.telegram.org/bot$bot_token/getChat");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $user_id]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $responseTG = curl_exec($ch);
    $tgData = json_decode($responseTG, true);
    curl_close($ch);
    if (!isset($tgData['ok']) || !$tgData['ok']) {
        $errorMessage = $tgData['description'] ?? 'Failed to verify bot interaction.';
        echo json_encode(['ok' => false, 'message' => "Start the bot to claim the reward. Error: $errorMessage"]);
        exit;
    }
}
// Calculate reward with badge boost
$reward = (float)$task['reward'];
// Fetch badges from settings
$badgeStmt = $pdo->query("SELECT badges FROM settings LIMIT 1");
$badgesJson = $badgeStmt->fetchColumn();
$badges = json_decode($badgesJson, true) ?: [];
$task_increase = 0;
foreach ($badges as $badge) {
    if ($badge['level'] === $user['my_badge']) {
        $task_increase = (float)$badge['task_increase'];
        break;
    }
}
$boostedReward = $reward + ($reward * $task_increase / 100);
// Get referral commission from settings (based on original reward)
$commissionStmt = $pdo->query("SELECT referral_commission FROM settings LIMIT 1");
$referral_commission = (float)$commissionStmt->fetchColumn();
$commissionAmount = 0;
$inviterId = $user['invited_by'] ?? null;
if (!empty($inviterId)) {
    $commissionAmount = round(($reward * $referral_commission) / 100, 2);
}
// Decode and prepare gems record
$gemsRecord = json_decode($user['gems_record'], true);
if (!is_array($gemsRecord)) $gemsRecord = [];
$gemsRecord[] = [
    'amount' => $boostedReward,
    'date' => $today,
    'reason' => 'Task Completion'
];
// Begin DB Transaction
$completedTasks[] = $task_id;
$newCompletedJson = json_encode($completedTasks);
$pdo->beginTransaction();
try {
    // Update current user
    $pdo->prepare("UPDATE users SET gems = gems + ?, completed_task = ?, gems_record = ? WHERE user_id = ?")
        ->execute([$boostedReward, $newCompletedJson, json_encode($gemsRecord), $user_id]);
    // Update inviter gems, gems_record, and ref_income if commission applies
    if ($commissionAmount > 0) {
        // Fetch inviter's current data
        $stmt = $pdo->prepare("SELECT gems_record, ref_income FROM users WHERE user_id = ?");
        $stmt->execute([$inviterId]);
        $inviter = $stmt->fetch(PDO::FETCH_ASSOC);
        // Gems record update
        $inviterRecord = json_decode($inviter['gems_record'] ?? '[]', true);
        if (!is_array($inviterRecord)) $inviterRecord = [];
        $inviterRecord[] = [
            'amount' => $commissionAmount,
            'date' => $today,
            'reason' => 'Referral Commission'
        ];
        // Ref income update
        $refIncome = json_decode($inviter['ref_income'] ?? '[]', true);
        if (!is_array($refIncome)) $refIncome = [];
        $refIncome[] = [
            'from_user' => $user_id,
            'date' => $today,
            'amount' => $commissionAmount
        ];
        // Update inviter in DB
        $pdo->prepare("UPDATE users SET gems = gems + ?, gems_record = ?, ref_income = ? WHERE user_id = ?")
            ->execute([$commissionAmount, json_encode($inviterRecord), json_encode($refIncome), $inviterId]);
    }
    // Update task completion
    $pdo->prepare("UPDATE tasks SET completed = completed + 1 WHERE task_id = ?")
        ->execute([$task_id]);
    // Deactivate if max reached
    $pdo->prepare("UPDATE tasks SET status = 'inactive' WHERE task_id = ? AND max_completion > 0 AND completed >= max_completion")
        ->execute([$task_id]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Failed to update records.']);
    exit;
}
// Final response
$msg = "Task completed successfully! You earned {$boostedReward} gems.";
if ($commissionAmount > 0) {
    $msg .= " Your inviter earned {$commissionAmount} gems as commission.";
}
echo json_encode([
    'ok' => true,
    'message' => $msg,
    'result' => [
        'task_id' => $task_id,
        'reward' => $boostedReward,
        'commission' => $commissionAmount
    ]
]);