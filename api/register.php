<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';
$response = ['ok' => false, 'message' => '', 'result' => []];
// ✅ Read JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}
// Extract input
$init_data = $input['init_data'] ?? null;
$ip_address = $input['ip_address'] ?? null;
$incoming_inviter_id = $input['inviter_id'] ?? null;
$missing = [];
if (!$init_data) $missing[] = 'init_data';
if (!$ip_address) $missing[] = 'ip_address';
if ($missing) {
    echo json_encode(['ok' => false, 'message' => 'Missing: ' . implode(', ', $missing)]);
    exit;
}
// ✅ Verify Telegram init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid Telegram init_data']);
    exit;
}
$userData = $verify['data']['user'];
$user_id = $userData['id'];
$name = $userData['first_name'] ?? 'Guest';
$username = isset($userData['username']) && $userData['username'] !== '' ? '@' . $userData['username'] : 'no username';
$dateNow = date('Y-m-d H:i:s');
$createdDate = date('d/m/Y');
// ✅ Check for duplicate IP (excluding self)
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE ip_address = ? AND user_id != ?");
$stmt->execute([$ip_address, $user_id]);
if ($stmt->rowCount() > 0) {
    echo json_encode(['ok' => false, 'message' => "IP address already used by another account."]);
    exit;
}
// ✅ If user already registered
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
if ($stmt->rowCount() > 0) {
    echo json_encode([
        'ok' => true,
        'message' => 'User already registered.',
        'result' => ['user_id' => $user_id, 'name' => $name]
    ]);
    exit;
}
// ✅ Check if user joined required channels
foreach ($REQUIRED_CHANNELS as $channel) {
    $postData = [
        'chat_id' => $channel,
        'user_id' => $user_id
    ];
    $ch = curl_init($API_URL . "getChatMember");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $status = $response['result']['status'] ?? null;
    if (!in_array($status, ['member', 'administrator', 'creator'])) {
        echo json_encode(['ok' => false, 'message' => "You must join all channels to register."]);
        exit;
    }
}
// ✅ Generate unique inviter_id (for current user)
function generateRandomId($length = 20): string {
    return bin2hex(random_bytes($length / 2));
}
$generated_inviter_id = generateRandomId();
// ✅ Prepare invite mapping
$invited_by = null;
if ($incoming_inviter_id) {
    $stmt = $pdo->prepare("SELECT user_id, reflist FROM users WHERE inviter_id = ?");
    $stmt->execute([$incoming_inviter_id]);
    if ($stmt->rowCount()) {
        $inviter = $stmt->fetch(PDO::FETCH_ASSOC);
        $invited_by = $inviter['user_id'];
        // Add scratch card to inviter
        $pdo->prepare("UPDATE users SET scratch_cards = scratch_cards + 1 WHERE user_id = ?")
            ->execute([$invited_by]);
        // Update reflist of inviter
        $reflist = json_decode($inviter['reflist'], true) ?? [];
        $reflist[] = [
            'user_id' => $user_id,
            'name' => $name,
            'date' => $createdDate
        ];
        $pdo->prepare("UPDATE users SET reflist = ? WHERE user_id = ?")
            ->execute([json_encode($reflist), $invited_by]);
        // Send message to inviter
        sendMessage($invited_by, "🎉 New Referral!\n👤 <a href='tg://user?id=$user_id'>$name</a>\n📅 $createdDate\nYou'll earn commission from this user!");
    }
}
// ✅ Register user
$stmt = $pdo->prepare("INSERT INTO users (user_id, name, ip_address, balance, gems, scratch_cards, lucky_boxes, reflist, invited_by, inviter_id, check_ins, completed_task, total_7_day_strike, created_at, ref_income)
VALUES (?, ?, ?, 0, 0, ?, 0, '[]', ?, ?, '[]', '[]', 0, ?, '[]')");
$stmt->execute([$user_id, $name, $ip_address, $WELCOME_SCRATCH_CARDS, $invited_by, $generated_inviter_id, $dateNow]);

// ✅ Welcome message to user
sendMessage($user_id, "✅ Welcome to our app!\nWe added $WELCOME_SCRATCH_CARDS Scratch Cards as your welcome bonus.");

// ✅ New user notification to admin/notification channel
if (isset($NOTIFICATION_CHANNEL_ID) && !empty($NOTIFICATION_CHANNEL_ID)) {
    // Get total users count
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $totalRow = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalUsers = $totalRow['total'] ?? 0;

    // Prepare referred by text
    $referredByText = '';
    if ($invited_by) {
        $referredByText = " (ID: $invited_by )";
    }

    // Sanitize fields for HTML parse_mode (basic)
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE);
    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE);
    $safeIp = htmlspecialchars($ip_address, ENT_QUOTES | ENT_SUBSTITUTE);

    $notifText = "🆕 New user notification\n";
    $notifText .= "🧒 Name : $safeName\n";
    $notifText .= "🤫 Username : $safeUsername\n";
    $notifText .= "🫆 IP Address : $safeIp\n";
    $notifText .= "🆔 User's ID : $user_id\n";
    $notifText .= "👥 Referred by : $referredByText\n";
    $notifText .= "🤩 Total users : $totalUsers";

    // send to channel (use HTML)
    sendMessage($NOTIFICATION_CHANNEL_ID, $notifText);
}

// ✅ Done
echo json_encode([
    'ok' => true,
    'message' => "User registered successfully.",
    'result' => [
        'user_id' => $user_id,
        'name' => $name,
        'inviter_id' => $generated_inviter_id
    ]
]);
// --- Helpers ---
function sendMessage($chat_id, $text) {
    global $API_URL;
    $postData = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    $ch = curl_init($API_URL . "sendMessage");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}
