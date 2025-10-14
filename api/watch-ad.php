<?php
// watch-ad.php
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

// Check ad limit: max 5 per hour
$oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ad_logs WHERE user_id = ? AND created_at > ?");
$stmt->execute([$u['id'], $oneHourAgo]);
$count = (int)$stmt->fetch()['count'];

if ($count >= 5) {
    echo json_encode(['ok' => false, 'message' => 'Ad limit reached']);
    exit;
}

// Add $0.005 to balance
$reward = 0.005;
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$reward, $u['id']]);

    $stmt = $pdo->prepare("INSERT INTO ad_logs (user_id, reward, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$u['id'], $reward]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'reward' => $reward]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Failed to credit reward']);
}