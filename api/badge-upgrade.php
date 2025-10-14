<?php
header('Content-Type: application/json');
require 'config.php';

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$user_id = $input['user_id'] ?? '';
$pin = $input['pin'] ?? '';
$amount_sent = isset($input['amount_sent']) ? (float)$input['amount_sent'] : 0;

if (empty($user_id) || empty($pin) || $amount_sent <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing required parameters: user_id, pin, and amount_sent']);
    exit;
}

// Verify PIN (hardcoded)
if ($pin !== '786786') {
    echo json_encode(['ok' => false, 'message' => 'Invalid PIN']);
    exit;
}

// Fetch user badge
$stmt = $pdo->prepare("SELECT my_badge FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found']);
    exit;
}

$currentBadge = $user['my_badge'] ?? 'lv0';
$currentLevel = (int) str_replace('lv', '', strtolower($currentBadge));
$nextLevel = $currentLevel + 1;

if ($nextLevel > 6) {
    echo json_encode(['ok' => false, 'message' => 'User already has the maximum badge level']);
    exit;
}

// Fetch badges settings
$settingsStmt = $pdo->query("SELECT badges FROM settings LIMIT 1");
$settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);

if (!$settingsRow || empty($settingsRow['badges'])) {
    echo json_encode(['ok' => false, 'message' => 'Badge settings not found in database']);
    exit;
}

$badgesConfig = json_decode($settingsRow['badges'], true);
if (!is_array($badgesConfig)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid badge configuration format']);
    exit;
}

// Find the next level config from numeric array
$nextBadgeConfig = null;
foreach ($badgesConfig as $badge) {
    if (isset($badge['level']) && strtolower($badge['level']) === 'lv' . $nextLevel) {
        $nextBadgeConfig = $badge;
        break;
    }
}

if (!$nextBadgeConfig) {
    echo json_encode(['ok' => false, 'message' => 'Next badge configuration not found']);
    exit;
}

$requiredPrice = (float)$nextBadgeConfig['price'];

// Validate sent amount
if ($amount_sent < $requiredPrice) {
    echo json_encode(['ok' => false, 'message' => "Amount sent is less than required price ({$requiredPrice})"]);
    exit;
}

// Update badge
$newBadge = 'lv' . $nextLevel;
$stmt = $pdo->prepare("UPDATE users SET my_badge = ? WHERE user_id = ?");
$stmt->execute([$newBadge, $user_id]);

echo json_encode([
    'ok' => true,
    'message' => "Badge upgraded successfully to $newBadge",
    'result' => [
        'user_id' => $user_id,
        'new_badge' => $newBadge,
        'required_price' => $requiredPrice,
        'amount_sent' => $amount_sent
    ]
]);
