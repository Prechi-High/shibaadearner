<?php
header('Content-Type: application/json');

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database connection failed', 'result' => []]);
    exit;
}

// Parse JSON body
$data = json_decode(file_get_contents('php://input'), true);
$provided_password = $data['admin_password'] ?? '';

// Validate admin password
$stmt = $pdo->query("SELECT admin_password FROM settings WHERE id = 1");
$admin_password = $stmt->fetchColumn();
if ($provided_password !== $admin_password) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'invalid-password', 'result' => []]);
    exit;
}

// Route
$request = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));

if (count($request) === 1 && $request[0] === 'get') {
    // Return withdrawals + user name + user address (no bank/binance lookups)
    $stmt = $pdo->query("
        SELECT 
            w.id,
            w.withdraw_id,
            w.user_id,
            u.name         AS user_name,
            u.address      AS address,
            w.amount,
            w.date,
            w.withdraw_method,
            w.status
        FROM withdrawals w
        LEFT JOIN users u ON w.user_id = u.user_id
        ORDER BY w.id DESC
    ");
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'message' => 'Withdrawals retrieved successfully',
        'result' => $withdrawals
    ]);
}
elseif (count($request) === 2 && $request[0] === 'delete' && !empty($request[1])) {
    $withdraw_id = $request[1];
    $stmt = $pdo->prepare("DELETE FROM withdrawals WHERE withdraw_id = ?");
    $ok = $stmt->execute([$withdraw_id]);

    if ($ok) {
        echo json_encode(['ok' => true, 'message' => 'Withdrawal deleted successfully', 'result' => []]);
    } else {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Withdrawal not found or failed to delete', 'result' => []]);
    }
}
elseif (count($request) === 2 && in_array($request[0], ['paid', 'rejected', 'pending'], true) && !empty($request[1])) {
    $withdraw_id = $request[1];
    $new_status  = $request[0];

    // Fetch withdrawal + user (address included if you want to use it in the message)
    $stmt = $pdo->prepare("
        SELECT w.user_id, w.amount, w.withdraw_method, u.name, u.address
        FROM withdrawals w
        LEFT JOIN users u ON w.user_id = u.user_id
        WHERE w.withdraw_id = ?
    ");
    $stmt->execute([$withdraw_id]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$withdrawal) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Withdrawal not found', 'result' => []]);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE withdrawals SET status = ? WHERE withdraw_id = ?");
    $ok = $stmt->execute([$new_status, $withdraw_id]);

    if ($ok) {
        if ($new_status === 'paid') {
            // Notify user (kept simple; include address if useful)
            $amount = $withdrawal['amount'];
            $addr   = $withdrawal['address'] ?? '';
            $when   = date('Y-m-d H:i:s');

            $lines = [
                "<b>✅ Withdrawal Approved!</b>",
                "",
                "<b>💵 Amount:</b> {$amount}",
                $addr !== '' ? "<b>🏦 Address:</b> {$addr}" : "",
                "",
                "<b>⏰ Date:</b> {$when}",
                "",
                "🎉 Your withdrawal request has been approved!",
            ];
            $message = implode("\n", array_filter($lines, fn($l) => $l !== ""));

            $chat_id = $withdrawal['user_id'];
            $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage?chat_id={$chat_id}&text=" . urlencode($message) . "&parse_mode=HTML";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }

        echo json_encode(['ok' => true, 'message' => "Withdrawal status updated to {$new_status}", 'result' => []]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Failed to update withdrawal status', 'result' => []]);
    }
}
else {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Invalid endpoint', 'result' => []]);
}
