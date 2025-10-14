<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

$response = ['ok' => false, 'message' => '', 'result' => []];

// Validate input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $response['message'] = 'Invalid JSON input.';
    echo json_encode($response); exit;
}

// Check required parameters
$init_data = $input['init_data'] ?? '';
$redeem_to = $input['redeem_to'] ?? '';
if (empty($init_data) || empty($redeem_to)) {
    $response['message'] = 'Missing required parameters: init_data and redeem_to (must be "card" or "box")';
    echo json_encode($response); exit;
}

// Validate redeem_to value
if (!in_array($redeem_to, ['card', 'box'])) {
    $response['message'] = 'Invalid redeem_to value. Must be "card" or "box"';
    echo json_encode($response); exit;
}

// Validate Telegram init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    $response['message'] = 'Invalid init_data.';
    echo json_encode($response); exit;
}

$user_id = $verify['data']['user']['id'];

// Start transaction
$pdo->beginTransaction();

try {
    // Get user data and prices
    $sql = "SELECT 
                u.id, u.gems, u.gems_record,
                u.scratch_cards, u.lucky_boxes,
                s.scratch_card_price, s.lucky_box_price
            FROM users u
            CROSS JOIN settings s
            WHERE u.user_id = ?
            FOR UPDATE";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found in database.');
    }

    // Determine price and type
    $price_field = $redeem_to === 'card' ? 'scratch_card_price' : 'lucky_box_price';
    $item_field = $redeem_to === 'card' ? 'scratch_cards' : 'lucky_boxes';
    $price = (float)$user[$price_field];
    $current_gems = (float)$user['gems'];

    // Validate balance
    if ($current_gems < $price) {
        throw new Exception('Not enough gems for this redemption.');
    }

    $amount = floor($current_gems / $price);
    if ($amount < 1) {
        throw new Exception('Not enough gems for even one item.');
    }

    $total_deduction = $price * $amount;
    $newGems = $current_gems - $total_deduction;

    // Prepare new gem record
    $gemsRecord = json_decode($user['gems_record'], true);
    if (!is_array($gemsRecord)) $gemsRecord = [];

    $gemsRecord[] = [
        'amount' => -$total_deduction,
        'date' => date('Y-m-d'),
        'reason' => $redeem_to === 'card' ? 'Convert to Scratch Card' : 'Convert to Lucky Box'
    ];

    // Update DB
    $update_sql = "UPDATE users SET 
                    gems = ?, 
                    $item_field = $item_field + ?, 
                    gems_record = ?
                   WHERE id = ?";
    $stmt = $pdo->prepare($update_sql);
    $stmt->execute([
        $newGems,
        $amount,
        json_encode($gemsRecord),
        $user['id']
    ]);

    $pdo->commit();

    $response = [
        'ok' => true,
        'message' => "Successfully redeemed $amount $redeem_to".($amount > 1 ? 's' : ''),
        'result' => [
            'redeem_to' => $redeem_to,
            'amount' => $amount,
            'gems_deducted' => $total_deduction,
            'remaining_gems' => $newGems
        ]
    ];

} catch (Exception $e) {
    $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
