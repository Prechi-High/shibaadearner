<?php
// game1-data.php
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
global $pdo;

// Total tickets = 1000
$total = 1000;
$stmt = $pdo->query("SELECT COUNT(*) as used FROM tickets");
$used = (int)$stmt->fetch()['used'];

// User's tickets
$stmt = $pdo->prepare("SELECT ticket_number FROM tickets WHERE user_id = ? ORDER BY ticket_number ASC");
$stmt->execute([$u['id']]);
$tickets = array_column($stmt->fetchAll(), 'ticket_number');

// Leaderboard (last 3 winners)
$stmt = $pdo->query("SELECT u.username, u.first_name, w.prize FROM winners w JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC LIMIT 3");
$leaderboard = [];
while ($row = $stmt->fetch()) {
    $name = $row['username'] ?? $row['first_name'];
    $leaderboard[] = ['name' => $name, 'prize' => number_format((float)$row['prize'], 4)];
}

echo json_encode([
    'ok' => true,
    'result' => [
        'balance' => (float)$u['balance'],
        'used_tickets' => $used,
        'tickets' => $tickets,
        'leaderboard' => $leaderboard
    ]
]);