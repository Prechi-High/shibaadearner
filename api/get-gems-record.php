<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

$response = ['ok' => false, 'message' => '', 'result' => []];

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

$user_id = $verify['data']['user']['id'];

// ✅ Fetch gems_record
$stmt = $pdo->prepare("SELECT gems_record FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); exit;
}

$records = json_decode($row['gems_record'] ?? '[]', true);
if (!is_array($records)) $records = [];

// ✅ Final Response
echo json_encode([
    'ok' => true,
    'message' => 'Gems record fetched successfully.',
    'result' => $records
]);
