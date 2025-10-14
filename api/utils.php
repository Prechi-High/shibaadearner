<?php
// utils.php
require_once __DIR__ . '/../config.php';

function validateInitData($init_data) {
    if (!$init_data) return null;

    parse_str($init_data, $params);
    $user = json_decode($params['user'] ?? '{}', true);
    if (!$user || !isset($user['id'])) return null;

    // Validate hash
    $check_hash = $params['hash'] ?? '';
    unset($params['hash']);
    ksort($params);
    $data_check_string = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));
    $secret_key = hash('sha256', BOT_TOKEN, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);

    if (!hash_equals($hash, $check_hash)) return null;
    return $user;
}

function getOrCreateUser($user) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, balance, username, first_name FROM users WHERE telegram_id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, first_name, balance, created_at) VALUES (?, ?, ?, 0.0, NOW())");
        $stmt->execute([
            $user['id'],
            $user['username'] ?? null,
            $user['first_name'] ?? 'User'
        ]);
        $row = ['id' => $pdo->lastInsertId(), 'balance' => 0.0, 'username' => $user['username'] ?? $user['first_name']];
    }
    return $row;
}