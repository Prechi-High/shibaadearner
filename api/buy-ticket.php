<?php
// buy-ticket.php
header('Content-Type: application/json');
require_once 'utils.php';

$input = json_decode(file_get_contents('php://input'), true);
$init_data = $input['init_data'] ?? '';

$user = validateInitData($init_data);
if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'Invalid user']);
    exit;
}

$u = getOrCreateUser($user);
$cost = 0.0025;

if ((float)$u['balance'] < $cost) {
    echo json_encode(['ok' => false, 'message' => 'Insufficient balance']);
    exit;
}

global $pdo;
$pdo->beginTransaction();

try {
    $stmt = $pdo->query("SELECT COUNT(*) as used FROM tickets");
    $used = (int)$stmt->fetch()['used'];
    if ($used >= 1000) {
        throw new Exception('All tickets sold!');
    }

    // Deduct balance
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$cost, $u['id']]);

    // Assign next serial ticket
    $ticket_number = $used + 1;
    $stmt = $pdo->prepare("INSERT INTO tickets (user_id, ticket_number, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$u['id'], $ticket_number]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'ticket' => $ticket_number]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}