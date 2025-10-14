<?php
header('Content-Type: application/json', true, 200);
require 'config.php';

// Function to send Telegram notification
function sendTelegramNotification($user_id, $message, $bot_token) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.telegram.org/bot$bot_token/sendMessage",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'chat_id' => $user_id,
            'text' => $message,
            'parse_mode' => 'HTML' // Enables bold, italic, etc.
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    curl_exec($curl);
    curl_close($curl);
}

// Read user_id from URL parameters
$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) {
    echo json_encode(['ok' => false, 'message' => 'Missing user_id parameter']);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$track_id = $input['track_id'] ?? '';
$status = $input['status'] ?? '';
$currency = $input['currency'] ?? '';
$sent_amount = isset($input['sent_amount']) ? (float)$input['sent_amount'] : 0;

// Validate required fields
if (empty($track_id) || empty($status) || empty($currency)) {
    echo json_encode(['ok' => false, 'message' => 'Missing required fields: track_id, status, or currency']);
    exit;
}

// Ignore if currency is not USDT
if ($currency !== 'USDT') {
    echo json_encode(['ok' => false, 'message' => 'Currency must be USDT']);
    exit;
}

// Check if track_id already used
$stmt = $pdo->prepare("SELECT id FROM used_trackid WHERE track_id = ?");
$stmt->execute([$track_id]);
if ($stmt->fetch()) {
    // Already processed, ignore
    echo json_encode(['ok' => true, 'message' => 'Track ID already processed']);
    exit;
}

// Fetch settings for oxapay_api
$settingsStmt = $pdo->query("SELECT oxapay_api FROM settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    echo json_encode(['ok' => false, 'message' => 'Settings not found']);
    exit;
}
$oxapay_api_key = $settings['oxapay_api'];

// Validate payment via OxaPay API
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.oxapay.com/v1/payment/$track_id",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "merchant_api_key: $oxapay_api_key"
    ]
]);
$response = curl_exec($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$response_data = json_decode($response, true);

if ($http_code !== 200 || 
    !isset($response_data['data']['status']) || 
    !isset($response_data['data']['txs'][0]['currency']) || 
    $response_data['data']['txs'][0]['currency'] !== 'USDT') {
    echo json_encode(['ok' => false, 'message' => 'Payment validation failed or invalid currency', 'response' => $response_data]);
    exit;
}

$validated_status = $response_data['data']['txs'][0]['status'];
$validated_amount = (float)$response_data['data']['txs'][0]['amount'];

// Check if user exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'message' => 'User not found']);
    exit;
}

// Process based on validated status
if (strtolower($validated_status) !== 'confirmed') {
    sendTelegramNotification(
        $user_id,
        "⏳ <b>Payment Confirming</b>\n\n" .
        "Your payment of <b>\${$validated_amount} USDT</b> (Track ID: <b>$track_id</b>) is currently confirming on the blockchain.",
        $BOT_TOKEN
    );
    echo json_encode(['ok' => true, 'message' => 'Payment is confirming on the blockchain']);
    exit;
}

// If paid, update balance and store track_id
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("UPDATE users SET balance_usdt = balance_usdt + ? WHERE user_id = ?");
    $stmt->execute([$validated_amount, $user_id]);

    $stmt = $pdo->prepare("INSERT INTO used_trackid (track_id, user_id, amount, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$track_id, $user_id, $validated_amount]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Database error']);
    exit;
}

// Send success notification
sendTelegramNotification(
    $user_id,
    "✅ <b>Payment Successful</b>\n\n" .
    "💵 Amount: <b>\${$validated_amount} USDT</b>\n" .
    "🆔 Track ID: <code>$track_id</code>\n\n" .
    "Your balance has been updated successfully.",
    $BOT_TOKEN
);

echo json_encode(['ok' => true, 'message' => 'Payment processed and balance updated']);
?>
