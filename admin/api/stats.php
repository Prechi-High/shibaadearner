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
// Validate admin password
$stmt = $pdo->query("SELECT admin_password FROM settings WHERE id = 1");
$admin_password = $stmt->fetchColumn();
if ($provided_password !== $admin_password) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'invalid-password',
        'result' => []
    ]);
    exit;
}
// Get request path
$request = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
// Route handling
if (count($request) === 1 && $request[0] === 'stats') {
    // Get global statistics
    $today = date('Y-m-d'); // Current date for new_users_today
    
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $total_users = $stmt->fetchColumn();
    
    // Total balance (sum of balance and balance_usdt)
    $stmt = $pdo->query("SELECT COALESCE(SUM(balance + balance_usdt), 0) as total_balance FROM users");
    $total_balance = $stmt->fetchColumn();
    
    // Total gems
    $stmt = $pdo->query("SELECT COALESCE(SUM(gems), 0) as total_gems FROM users");
    $total_gems = $stmt->fetchColumn();
    
    // New users today
    $stmt = $pdo->prepare("SELECT COUNT(*) as new_users_today FROM users WHERE joined_at = ?");
    $stmt->execute([$today]);
    $new_users_today = $stmt->fetchColumn();
    
    // Pending withdrawals count
    $stmt = $pdo->query("SELECT COUNT(*) as pending_withdrawals FROM withdrawals WHERE status = 'pending'");
    $pending_withdrawals = $stmt->fetchColumn();
    
    // Pending withdrawals amount
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as pending_withdraw_amount FROM withdrawals WHERE status = 'pending'");
    $pending_withdraw_amount = $stmt->fetchColumn();
    
    // Successful withdrawals count
    $stmt = $pdo->query("SELECT COUNT(*) as success_withdraw FROM withdrawals WHERE status = 'paid'");
    $success_withdraw = $stmt->fetchColumn();
    
    // Successful withdrawals amount
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as success_withdraw_amount FROM withdrawals WHERE status = 'paid'");
    $success_withdraw_amount = $stmt->fetchColumn();
    
    echo json_encode([
        'ok' => true,
        'message' => 'Statistics retrieved successfully',
        'result' => [
            'total_users' => (int)$total_users,
            'total_balance' => (float)$total_balance,
            'total_gems' => (float)$total_gems,
            'new_users_today' => (int)$new_users_today,
            'pending_withdrawals' => (int)$pending_withdrawals,
            'pending_withdraw_amount' => (float)$pending_withdraw_amount,
            'success_withdraw' => (int)$success_withdraw,
            'success_withdraw_amount' => (float)$success_withdraw_amount
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