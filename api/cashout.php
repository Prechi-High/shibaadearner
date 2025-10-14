<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// Read input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); exit;
}

$init_data = $input['init_data'] ?? '';
$amountIn  = $input['amount'] ?? null;

if (empty($init_data) || $amountIn === null) {
    echo json_encode(['ok' => false, 'message' => 'Missing init_data or amount']); exit;
}

// Normalize amount (two decimals, positive)
$amount = (float) number_format((float)$amountIn, 2, '.', '');
if ($amount <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Amount must be greater than 0.']); exit;
}

// Verify user
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data']); exit;
}
$user_id = $verify['data']['user']['id'];

// Fetch user balance and USDT address
$stmt = $pdo->prepare("SELECT balance, address FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); exit;
}

$balance = (float)$user['balance'];
$address = trim((string)$user['address']);

// Require address set
if ($address === '') {
    echo json_encode(['ok' => false, 'message' => 'Please set your USDT address first.']); exit;
}

// Load withdraw rules
$settings = $pdo->query("
    SELECT min_withdraw, max_withdraw, withdraw_fee
    FROM settings
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    echo json_encode(['ok' => false, 'message' => 'Settings not configured.']); exit;
}

$minWithdraw = (float)$settings['min_withdraw'];
$maxWithdraw = (float)$settings['max_withdraw']; // 0 => no cap
$feePercent  = (float)$settings['withdraw_fee']; // treated as percentage

// Validate amount against min/max
if ($amount < $minWithdraw) {
    echo json_encode(['ok' => false, 'message' => "Minimum withdrawal is {$minWithdraw}."]); exit;
}
if ($maxWithdraw > 0 && $amount > $maxWithdraw) {
    echo json_encode(['ok' => false, 'message' => "Maximum withdrawal is {$maxWithdraw}."]); exit;
}
if ($balance < $amount) {
    echo json_encode(['ok' => false, 'message' => 'Insufficient balance.']); exit;
}

// Fee math
$feeAmount   = round(($feePercent / 100) * $amount, 2);
$finalAmount = round($amount - $feeAmount, 2);
if ($finalAmount <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Amount too small after fees.']); exit;
}

$date        = date('Y-m-d');
$withdraw_id = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8);

// Transaction with row lock
try {
    $pdo->beginTransaction();

    // Lock user row
    $lock = $pdo->prepare("SELECT balance FROM users WHERE user_id = ? FOR UPDATE");
    $lock->execute([$user_id]);
    $locked = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$locked) {
        throw new Exception('User not found during lock.');
    }

    $currentBalance = (float)$locked['balance'];
    if ($currentBalance < $amount) {
        throw new Exception('Insufficient balance at commit time.');
    }

    // Deduct requested amount
    $upd = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE user_id = ?");
    $upd->execute([$amount, $user_id]);

    // Insert withdrawal (net after fee).
    // NOTE: withdraw_method is enum('bank','BEP20'); using 'BEP20' as placeholder.
    $ins = $pdo->prepare("
        INSERT INTO withdrawals (withdraw_id, amount, date, user_id, withdraw_method, status)
        VALUES (?, ?, ?, ?, 'BEP20', 'pending')
    ");
    $ins->execute([$withdraw_id, $finalAmount, $date, $user_id]);

    $pdo->commit();

    $newBalance = round($currentBalance - $amount, 2);

    echo json_encode([
        'ok' => true,
        'message' => "Withdrawal request created. Net: {$finalAmount} (fee {$feePercent}% on {$amount}).",
        'result' => [
            'withdraw_id'       => $withdraw_id,
            'requested_amount'  => number_format($amount,2),
            'fee_percent'       => $feePercent,
            'fee_amount'        => $feeAmount,
            'final_amount'      => $finalAmount,
            'address'           => $address,
            'status'            => 'pending',
            'balance_before'    => $currentBalance,
            'balance_after'     => $newBalance,
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Transaction failed.']);
}
