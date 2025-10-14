<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

// Function to generate random task_id
function generateTaskId() {
    return strtoupper(substr(md5(uniqid(rand(), true)), 0, 12));
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$init_data = $input['init_data'] ?? '';
$action = $input['action'] ?? '';
if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']);
    exit;
}
if (!in_array($action, ['add', 'edit'])) {
    echo json_encode(['ok' => false, 'message' => 'Invalid action. Must be "add" or "edit".']);
    exit;
}

// Validate init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data']);
    exit;
}

$user = $verify['data']['user'];
$user_id = $user['id'];

// Fetch user info
$stmt = $pdo->prepare("SELECT balance_usdt, mytasks FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user_data) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']);
    exit;
}

$balance_usdt = (float)$user_data['balance_usdt'];
$mytasks = json_decode($user_data['mytasks'], true);
if (!is_array($mytasks)) $mytasks = [];

// Fetch settings
$settingsStmt = $pdo->query("SELECT price_per_members, min_order, max_order, client_task_reward FROM settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    echo json_encode(['ok' => false, 'message' => 'Settings not found.']);
    exit;
}
$price_per_members = (float)$settings['price_per_members'];
$min_order = (int)$settings['min_order'];
$max_order = (int)$settings['max_order'];
$client_task_reward = (float)$settings['client_task_reward'];

if ($action === 'add') {
    // Required fields for add
    $type = $input['type'] ?? '';
    $name = $input['name'] ?? '';
    $link = $input['link'] ?? '';
    $order_amount = isset($input['order_amount']) ? (int)$input['order_amount'] : 0;
    $bot_token = $input['bot_token'] ?? null;
    $chat_id = $input['chat_id'] ?? null;
    $task_image = $input['task_image'] ?? 'https://i.ibb.co/MDNxjMy3/rupee.png'; // Default image

    // Validate required fields
    if (!in_array($type, ['bot', 'channel', 'other'])) {
        echo json_encode(['ok' => false, 'message' => 'Invalid type. Must be "bot", "channel", or "other".']);
        exit;
    }

    // Check missing fields
    $missing_fields = [];
    if (empty($name)) $missing_fields[] = 'name';
    if (empty($link)) $missing_fields[] = 'link';
    if ($order_amount <= 0) $missing_fields[] = 'order_amount';
    if ($type === 'bot' && empty($bot_token)) $missing_fields[] = 'bot_token';
    if ($type === 'channel' && empty($chat_id)) $missing_fields[] = 'chat_id';

    if (!empty($missing_fields)) {
        echo json_encode(['ok' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing_fields)]);
        exit;
    }

    // Validate order_amount against min_order and max_order
    if ($order_amount < $min_order) {
        echo json_encode(['ok' => false, 'message' => "Minimum order amount is $min_order."]);
        exit;
    }
    if ($max_order > 0 && $order_amount > $max_order) {
        echo json_encode(['ok' => false, 'message' => "Maximum order amount is $max_order."]);
        exit;
    }

    // Calculate cost
    $total_cost = $order_amount * $price_per_members;
    if ($balance_usdt < $total_cost) {
        echo json_encode(['ok' => false, 'message' => 'Insufficient USDT balance. Required: ' . $total_cost]);
        exit;
    }

    // Generate task_id
    $task_id = generateTaskId();

    // Insert task with client_task_reward
    $stmt = $pdo->prepare("INSERT INTO tasks (task_id, task_image, name, type, chat_id, bot_token, link, max_completion, completed, status, reward, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'inactive', ?, NOW())");
    $stmt->execute([$task_id, $task_image, $name, $type, $type === 'channel' ? $chat_id : null, $type === 'bot' ? $bot_token : null, $link, $order_amount, $client_task_reward]);

    // Update user balance and mytasks
    $mytasks[] = $task_id;
    $stmt = $pdo->prepare("UPDATE users SET balance_usdt = balance_usdt - ?, mytasks = ? WHERE user_id = ?");
    $stmt->execute([$total_cost, json_encode($mytasks), $user_id]);

    echo json_encode(['ok' => true, 'message' => 'Task added successfully.', 'result' => ['task_id' => $task_id]]);
} else {
    // Edit action
    $task_id = $input['task_id'] ?? '';
    if (empty($task_id)) {
        echo json_encode(['ok' => false, 'message' => 'task_id is required for edit action.']);
        exit;
    }

    // Check if user owns the task
    if (!in_array($task_id, $mytasks)) {
        echo json_encode(['ok' => false, 'message' => 'You are not the owner of this task.']);
        exit;
    }

    // Check if task exists
    $stmt = $pdo->prepare("SELECT id FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Task not found.']);
        exit;
    }

    // Collect fields to update
    $updates = [];
    $params = [];
    if (isset($input['name']) && !empty($input['name'])) {
        $updates[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['link']) && !empty($input['link'])) {
        $updates[] = "link = ?";
        $params[] = $input['link'];
    }
    if (isset($input['bot_token']) && !empty($input['bot_token'])) {
        $updates[] = "bot_token = ?";
        $params[] = $input['bot_token'];
    }
    if (isset($input['chat_id']) && !empty($input['chat_id'])) {
        $updates[] = "chat_id = ?";
        $params[] = $input['chat_id'];
    }
    if (isset($input['task_image']) && !empty($input['task_image'])) {
        $updates[] = "task_image = ?";
        $params[] = $input['task_image'];
    }

    if (empty($updates)) {
        echo json_encode(['ok' => false, 'message' => 'At least one field (name, link, bot_token, chat_id, task_image) must be provided to edit.']);
        exit;
    }

    // Update task
    $params[] = $task_id;
    $stmt = $pdo->prepare("UPDATE tasks SET " . implode(', ', $updates) . " WHERE task_id = ?");
    $stmt->execute($params);

    echo json_encode(['ok' => true, 'message' => 'Task updated successfully.', 'result' => ['task_id' => $task_id]]);
}
?>