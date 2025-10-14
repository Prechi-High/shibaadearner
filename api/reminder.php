<?php
header('Content-Type: application/json');
// Include database configuration
require_once 'config.php';
// Database connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Database connection failed',
        'result' => []
    ]);
    exit;
}
// Parse JSON body
$data = json_decode(file_get_contents('php://input'), true);
$provided_password = $data['admin_password'] ?? '';

// Get request path
$request = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
// Route handling
if (count($request) === 1 && $request[0] === 'send-reminders') {
    $today = date('d/m/Y');
    $today_date = date('Y-m-d');
    
    // Fetch up to 20 users who haven't checked in today and haven't received a reminder today
    $stmt = $pdo->query("
        SELECT user_id, name, check_ins 
        FROM users 
        WHERE check_ins IS NOT NULL 
        AND check_ins != '[]'
        AND (reminder IS NULL OR DATE(reminder) != '$today_date')
        LIMIT 20
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sent_count = 0;
    $failed = [];
    
    foreach ($users as $user) {
        $check_ins = json_decode($user['check_ins'], true);
        if (!is_array($check_ins) || in_array($today, $check_ins)) {
            continue; // Skip users who checked in today
        }
        
        // Update reminder timestamp before sending notification
        $stmt = $pdo->prepare("UPDATE users SET reminder = NOW() WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        
        // Prepare Telegram message
        $message = "👋 Hey <b>{$user['name']}</b>, it’s been a while!\n" .
                   "We noticed you haven’t played for long days. To welcome you back, we’ve credited a special bonus 🎁💎 in your account.\n\n" .
                   "⚡️ Don’t let your streak fade, come and claim it now!";
        
        // Prepare inline buttons (on separate rows)
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '👉 Claim My Bonus 💎', 'web_app' => ['url' => $WEB_APP_LINK]]],
                [['text' => '🎮 Play & Earn 💰', 'web_app' => ['url' => $WEB_APP_LINK]]]
            ]
        ];
        
        // Send Telegram notification
        $chat_id = $user['user_id'];
        $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage?chat_id={$chat_id}&text=" . urlencode($message) . "&parse_mode=HTML&reply_markup=" . urlencode(json_encode($keyboard));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $sent_count++;
        } else {
            $failed[] = $user['user_id'];
        }
    }
    
    echo json_encode([
        'ok' => true,
        'message' => "Reminders sent to {$sent_count} users successfully.",
        'result' => [
            'sent_count' => $sent_count,
            'failed_user_ids' => $failed
        ]
    ]);
} else {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid endpoint',
        'result' => []
    ]);
}
?>