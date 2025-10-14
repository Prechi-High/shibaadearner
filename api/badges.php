<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// Read input
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

$user_id = $verify['data']['user']['id'];

// Fetch user badge and badges config
$sql = "SELECT u.my_badge, s.badges 
        FROM users u 
        CROSS JOIN settings s 
        WHERE u.user_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']);
    exit;
}

// Parse badges JSON
$badges = json_decode($row['badges'], true);
if (!is_array($badges)) {
    $badges = [];
}

// Response
echo json_encode([
    'ok' => true,
    'message' => 'Badge information retrieved.',
    'result' => [
        'my_badge' => $row['my_badge'] ?? 'lv0',
        'levels' => $badges
    ]
]);
