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
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']);
    exit;
}

$user_id = $verify['data']['user']['id'];

// Fetch user's gift claims
$stmt = $pdo->prepare("SELECT gift_claims FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

$gift_claims = json_decode($data['gift_claims'], true);
if (!is_array($gift_claims)) $gift_claims = [];

// Sort by latest first
usort($gift_claims, function($a, $b) {
    return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
});

echo json_encode([
    'ok' => true,
    'message' => count($gift_claims) > 0 ? 'Gift claim history loaded.' : 'No gift claims found.',
    'result' => $gift_claims
]);
