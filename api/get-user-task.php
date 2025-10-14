<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

// ✅ Read JSON input
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

// ✅ Fetch user data and settings
$sql = "SELECT 
            u.completed_task, 
            u.gems,
            u.last_ad_task,
            s.scratch_card_price,
            s.lucky_box_price,
            s.ad_task_reward,
            s.ad_task_interval
        FROM users u
        CROSS JOIN settings s
        WHERE u.user_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo json_encode(['ok' => false, 'message' => 'User not found in database.']); exit;
}

// ✅ Decode completed tasks
$completedTasks = [];
if (!empty($data['completed_task'])) {
    $decoded = json_decode($data['completed_task'], true);
    if (is_array($decoded)) {
        $completedTasks = $decoded;
    }
}

// ✅ Fetch ALL active tasks
$stmt = $pdo->query("SELECT task_id, name, task_image, link, reward 
                     FROM tasks 
                     WHERE status = 'active' 
                     AND (max_completion = 0 OR completed < max_completion)");

$tasks = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['completed'] = in_array($row['task_id'], $completedTasks);
    $tasks[] = $row;
}

// ✅ Ad task status
$adTaskReward = (float)$data['ad_task_reward'];
$adTaskInterval = (int)$data['ad_task_interval'];
$lastAdTask = $data['last_ad_task'];

$adTaskStatus = 'ready';
if (!empty($lastAdTask)) {
    $lastTime = strtotime($lastAdTask);
    $nextAllowed = $lastTime + ($adTaskInterval * 60);
    $now = time();

    if ($now < $nextAllowed) {
        $minutesLeft = ceil(($nextAllowed - $now) / 60);
        $adTaskStatus = "$minutesLeft min left";
    }
}

// ✅ Final response
echo json_encode([
    'ok' => true,
    'message' => count($tasks) > 0 ? 'Tasks fetched successfully.' : 'No available tasks.',
    'result' => [
        'tasks' => $tasks,
        'user_data' => [
            'gems' => (float)$data['gems']
        ],
        'prices' => [
            'scratch_card' => (float)$data['scratch_card_price'],
            'lucky_box' => (float)$data['lucky_box_price']
        ],
        'ad_task' => [
            'status' => $adTaskStatus,
            'reward' => $adTaskReward
        ]
    ]
]);
