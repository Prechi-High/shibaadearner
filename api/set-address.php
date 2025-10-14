<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); exit;
}

$init_data = $input['init_data'] ?? '';
$address   = $input['address']  ?? '';

if ($init_data === '' || $address === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing init_data or address']); exit;
}

$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']); exit;
}

$userData = $verify['data']['user'];
$user_id  = $userData['id'];
$name     = $userData['first_name'] ?? '';

$address = trim($address);
if (strlen($address) < 20 || strlen($address) > 120) {
    echo json_encode(['ok' => false, 'message' => 'Invalid address length.']); exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$exists = $stmt->fetchColumn();

if (!$exists) {
    $ins = $pdo->prepare("INSERT INTO users (user_id, name) VALUES (?, ?)");
    $ins->execute([$user_id, $name]);
}

$stmt = $pdo->prepare("SELECT user_id FROM users WHERE address = ? AND user_id != ?");
$stmt->execute([$address, $user_id]);
if ($stmt->fetchColumn()) {
    echo json_encode(['ok' => false, 'message' => 'Address is already used by another user.']); exit;
}

$upd = $pdo->prepare("UPDATE users SET address = ? WHERE user_id = ?");
$upd->execute([$address, $user_id]);

echo json_encode(['ok' => true, 'message' => 'USDT address saved successfully.']);