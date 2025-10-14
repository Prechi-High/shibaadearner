<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

// Read request body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); exit;
}

$init_data = $input['init_data'] ?? '';
$gift_code = $input['gift_code'] ?? '';

if (empty($init_data) || empty($gift_code)) {
    echo json_encode(['ok' => false, 'message' => 'Missing init_data or gift_code']); exit;
}

// Validate init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data']); exit;
}

$user_id = $verify['data']['user']['id'];
$today = date('d/m/Y');

// Fetch gift info
$stmt = $pdo->prepare("SELECT * FROM gift_codes WHERE gift_code = ?");
$stmt->execute([$gift_code]);
$gift = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gift) {
    echo json_encode(['ok' => false, 'message' => 'Gift code not found']); exit;
}

// Decode claimed list
$claimedList = json_decode($gift['claimed'], true) ?: [];

// Already claimed by this user?
if (in_array($user_id, $claimedList)) {
    echo json_encode(['ok' => false, 'message' => 'You have already claimed this gift']); exit;
}

// Check total user limit
if (count($claimedList) >= $gift['total_user']) {
    echo json_encode(['ok' => false, 'message' => 'Gift claim limit reached']); exit;
}

// Fetch user info
$stmt = $pdo->prepare("SELECT gems, gems_record, gift_claims FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found']); exit;
}

$reward = (float)$gift['reward_amount'];

// Update claimed list
$claimedList[] = $user_id;

// Update gift table
$pdo->prepare("UPDATE gift_codes SET claimed = ? WHERE id = ?")
    ->execute([json_encode($claimedList), $gift['id']]);

// Update user's gems
$gemsRecord = json_decode($user['gems_record'], true) ?: [];
$gemsRecord[] = [
    'amount' => $reward,
    'date' => $today,
    'reason' => "Gift Claim: {$gift['gift_name']}"
];

$giftClaims = json_decode($user['gift_claims'], true) ?: [];
$giftClaims[] = [
    'date' => $today,
    'amount' => $reward,
    'gift_name' => $gift['gift_name']
];

// Save to DB
$pdo->prepare("UPDATE users SET gems = gems + ?, gems_record = ?, gift_claims = ? WHERE user_id = ?")
    ->execute([$reward, json_encode($gemsRecord), json_encode($giftClaims), $user_id]);

echo json_encode([
    'ok' => true,
    'message' => "Gift claimed successfully! You received {$reward} gems.",
    'result' => [
        'gift_name' => $gift['gift_name'],
        'reward' => $reward
    ]
]);
