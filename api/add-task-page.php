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
if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']);
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
$stmt = $pdo->prepare("SELECT usdt_deposit_address, balance_usdt, mytasks FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user_data) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']);
    exit;
}

// Fetch settings
$settingsStmt = $pdo->query("SELECT price_per_members, min_order, max_order, oxapay_api FROM settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    echo json_encode(['ok' => false, 'message' => 'Settings not found.']);
    exit;
}

// Prepare variables
$usdt_deposit_address = $user_data['usdt_deposit_address'];
$balance_usdt = (float)$user_data['balance_usdt'];
$price_per_members = (float)$settings['price_per_members'];
$min_order = (int)$settings['min_order'];
$max_order = (int)$settings['max_order'];
$oxapay_api_key = $settings['oxapay_api'];
$mytasks = json_decode($user_data['mytasks'], true);
if (!is_array($mytasks)) $mytasks = [];

// Generate USDT deposit address if not available
if (empty($usdt_deposit_address)) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.oxapay.com/v1/payment/static-address',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'network' => 'BEP20',
            'callback_url' => "https://bot.tgbro.link/luckygroot/api/deposit.php?user_id=$user_id"
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "merchant_api_key: $oxapay_api_key"
        ]
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $response_data = json_decode($response, true);
    if ($http_code === 200 && isset($response_data['data']['address'])) {
        $usdt_deposit_address = $response_data['data']['address'];
        // Update user with new deposit address
        $updateStmt = $pdo->prepare("UPDATE users SET usdt_deposit_address = ? WHERE user_id = ?");
        $updateStmt->execute([$usdt_deposit_address, $user_id]);
    } else {
        $error_message = $response_data['message'] ?? 'Failed to generate USDT deposit address.';
        echo json_encode(['ok' => false, 'message' => $error_message]);
        exit;
    }
}

// Fetch mytasks details
$tasks_data = [];
if (!empty($mytasks)) {
    $placeholders = implode(',', array_fill(0, count($mytasks), '?'));
    $stmt = $pdo->prepare("SELECT task_id, name, link, task_image, status, max_completion, completed, type, bot_token, chat_id FROM tasks WHERE task_id IN ($placeholders)");
    $stmt->execute($mytasks);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tasks as $task) {
        $task_status = ($task['status'] === 'active') ? 'running' :
                       ($task['completed'] >= $task['max_completion'] && $task['status'] === 'active' ? 'done' :
                       ($task['completed'] == 0 && $task['status'] === 'inactive' ? 'reviewing' : 'stop'));
        $task_data = [
            'task_id' => $task['task_id'],
            'name' => $task['name'],
            'link' => $task['link'],
            'task_image' => $task['task_image'],
            'status' => $task_status,
            'order_amount' => (int)$task['max_completion'],
            'completed' => (int)$task['completed'],
            'type' => $task['type']
        ];
        if ($task['type'] === 'bot' && !empty($task['bot_token'])) {
            $task_data['bot_token'] = $task['bot_token'];
        } elseif ($task['type'] === 'channel' && $task['chat_id'] !== null) {
            $task_data['chat_id'] = (string)$task['chat_id'];
        }
        $tasks_data[] = $task_data;
    }
}

// Response
echo json_encode([
    'ok' => true,
    'message' => 'USDT info loaded.',
    'result' => [
        'usdt_deposit_address' => $usdt_deposit_address,
        'balance_usdt' => $balance_usdt,
        'price_per_members' => $price_per_members,
        'min_order' => $min_order,
        'max_order' => $max_order,
        'mytasks' => $tasks_data
    ]
]);
?>