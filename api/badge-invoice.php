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

$user_id = $verify['data']['user']['id'];

// Fetch user badge and badge config
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

$myBadge = $row['my_badge'] ?? 'lv0';
$badges = json_decode($row['badges'], true);
if (!is_array($badges)) {
    echo json_encode(['ok' => false, 'message' => 'Badges configuration invalid.']);
    exit;
}

// Determine next badge
$currentLevel = (int) str_replace('lv', '', strtolower($myBadge));
$nextLevel = $currentLevel + 1;

if ($nextLevel > 6) {
    echo json_encode(['ok' => false, 'message' => 'You already have the maximum badge level.']);
    exit;
}

$nextBadgeKey = 'lv' . $nextLevel;
$nextBadgeData = null;

foreach ($badges as $badge) {
    if (isset($badge['level']) && strtolower($badge['level']) === strtolower($nextBadgeKey)) {
        $nextBadgeData = $badge;
        break;
    }
}

if (!$nextBadgeData) {
    echo json_encode(['ok' => false, 'message' => 'Next badge configuration not found.']);
    exit;
}

$price = isset($nextBadgeData['price']) ? (int)$nextBadgeData['price'] : 0;

if ($price <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid badge price.']);
    exit;
}

// Telegram createInvoiceLink API request
$payload = $nextBadgeKey . '-' . $user_id;
$title = "Badge Upgrade";
$description = "Upgrade to $nextBadgeKey badge.";
$currency = "XTR";
$prices = urlencode(json_encode([["label" => "Badge", "amount" => $price]]));

$telegramUrl = "https://api.telegram.org/bot$BOT_TOKEN/createInvoiceLink"
    . "?title=" . urlencode($title)
    . "&description=" . urlencode($description)
    . "&payload=" . urlencode($payload)
    . "&provider_token=" . urlencode("")
    . "&currency=" . urlencode($currency)
    . "&prices=$prices";

$response = file_get_contents($telegramUrl);
$tgResult = json_decode($response, true);

// API Response
if (isset($tgResult['ok']) && $tgResult['ok']) {
    echo json_encode([
        'ok' => true,
        'message' => "Invoice link generated successfully.",
        'result' => $tgResult['result']
    ]);
} else {
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to create invoice link.',
        'telegram_response' => $tgResult
    ]);
}
