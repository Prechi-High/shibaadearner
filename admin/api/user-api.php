<?php
header('Content-Type: application/json');

// DB config
require_once 'config.php';

// Minimal helpers
function respond($ok, $message, $result = [], $http = 200) {
    http_response_code($http);
    echo json_encode(['ok' => $ok, 'message' => $message, 'result' => $result]);
    exit;
}

// DB connect
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    respond(false, 'Database connection failed');
}

// Parse JSON body safely
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

// Admin auth
$provided_password = $data['admin_password'] ?? '';
$stmt = $pdo->query("SELECT admin_password FROM settings WHERE id = 1 LIMIT 1");
$admin_password = $stmt->fetchColumn();
if (!$admin_password || $provided_password !== $admin_password) {
    respond(false, 'invalid-password', [], 401);
}

// Route
$path = trim($_SERVER['PATH_INFO'] ?? '', '/');
$parts = $path === '' ? [] : explode('/', $path);

/**
 * ROUTES:
 *  GET   /users                      -> list users with totals
 *  GET   /users/{user_id}            -> single user details (with address + withdraw_record)
 *  POST  /users/{user_id}/setbalance -> update balance/balance_usdt/gems
 */

// /users
if (count($parts) === 1 && $parts[0] === 'users') {
    // Aggregate total paid withdrawals via subquery for deterministic results
    $stmt = $pdo->query("
        SELECT 
            u.user_id,
            u.name,
            u.address,
            u.balance,
            u.balance_usdt,
            u.gems,
            u.my_badge,
            u.joined_at,
            COALESCE(w.total_withdrawal, 0) AS total_withdrawal
        FROM users u
        LEFT JOIN (
            SELECT user_id, SUM(amount) AS total_withdrawal
            FROM withdrawals
            WHERE status = 'paid'
            GROUP BY user_id
        ) w ON u.user_id = w.user_id
        ORDER BY u.id DESC
    ");
    $users = $stmt->fetchAll();
    respond(true, 'Users retrieved successfully', $users);
}

// /users/{id}
if (count($parts) === 2 && $parts[0] === 'users' && ctype_digit($parts[1])) {
    $user_id = $parts[1];

    // Keep only relevant fields; phone/bank/binance removed
    $stmt = $pdo->prepare("
        SELECT 
            id, user_id, inviter_id, name, ip_address,
            address,
            balance, balance_usdt, gems, scratch_cards, lucky_boxes,
            my_badge, reflist, invited_by, check_ins, total_7_day_strike,
            completed_task, created_at, joined_at, 
            ref_income, gems_record, last_ad_task, gift_claims,
            mytasks, reminder
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, 'User not found', [], 404);
    }

    // Decode JSON-ish columns defensively
    foreach (['reflist','check_ins','completed_task','ref_income','gems_record','gift_claims','mytasks'] as $k) {
        if (isset($user[$k])) {
            $decoded = json_decode($user[$k], true);
            $user[$k] = is_array($decoded) ? $decoded : $user[$k];
        }
    }

    // Successful withdrawals record
    $stmt = $pdo->prepare("
        SELECT date, withdraw_method, amount, status
        FROM withdrawals
        WHERE user_id = ? AND status = 'paid'
        ORDER BY date DESC, amount DESC
    ");
    $stmt->execute([$user_id]);
    $user['withdraw_record'] = $stmt->fetchAll();

    respond(true, 'User details retrieved successfully', [$user]);
}

// /users/{id}/setbalance
if (count($parts) === 3 && $parts[0] === 'users' && ctype_digit($parts[1]) && $parts[2] === 'setbalance') {
    $user_id      = $parts[1];
    $balance      = $data['balance'] ?? null;
    $balance_usdt = $data['balance_usdt'] ?? null;
    $gems         = $data['gem'] ?? null;

    if ($balance === null && $balance_usdt === null && $gems === null) {
        respond(false, 'At least one of balance, balance_usdt, or gem must be provided', [], 400);
    }

    // Only update provided fields
    $fields = [];
    $params = [];
    if ($balance !== null) {
        $fields[] = "balance = ?";
        $params[] = $balance;
    }
    if ($balance_usdt !== null) {
        $fields[] = "balance_usdt = ?";
        $params[] = $balance_usdt;
    }
    if ($gems !== null) {
        $fields[] = "gems = ?";
        $params[] = $gems;
    }
    $params[] = $user_id;

    $stmt = $pdo->prepare("UPDATE users SET ".implode(', ', $fields)." WHERE user_id = ?");
    $ok = $stmt->execute($params);

    if ($ok && $stmt->rowCount() > 0) {
        respond(true, 'Balance updated successfully');
    }
    // If no rows affected, user may not exist or values unchanged
    respond(true, 'No changes applied (user not found or values identical)', []);
}

// Unknown route
respond(false, 'Invalid endpoint', [], 404);
