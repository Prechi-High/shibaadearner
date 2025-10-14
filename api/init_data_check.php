<?php
function verifyTelegramWebApp($bot_token, $init_data) {
    parse_str($init_data, $data);
    if (!isset($data['hash'])) return ['ok' => false];
    
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);

    $data_check_string = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($data), array_values($data)));
    $secret_key = hash_hmac('sha256', $bot_token, 'WebAppData', true);
    $calculated_hash = bin2hex(hash_hmac('sha256', $data_check_string, $secret_key, true));

    if (isset($data['user']) && is_string($data['user'])) {
        $decoded_user = json_decode($data['user'], true);
        if (json_last_error() === JSON_ERROR_NONE) $data['user'] = $decoded_user;
    }

    return ['ok' => hash_equals($hash, $calculated_hash), 'data' => $data];
}
?>
