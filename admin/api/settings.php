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
if (count($request) === 1 && $request[0] === 'getall') {
    // Get all settings
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    $settings['badges'] = json_decode($settings['badges'], true);
    $settings['daily_checkin_rewards'] = json_decode($settings['daily_checkin_rewards'], true);
    $settings['badge_benefits'] = json_decode($settings['badge_benefits'], true);
    $settings['scratch_reward'] = json_decode($settings['scratch_reward'], true);
    $settings['luckybox_reward'] = json_decode($settings['luckybox_reward'], true);
    echo json_encode([
        'ok' => true,
        'message' => 'Settings retrieved successfully',
        'result' => [$settings]
    ]);
} elseif (count($request) === 1 && $request[0] === 'set-badges-price') {
    // Update badges prices
    $badges = json_decode($pdo->query("SELECT badges FROM settings WHERE id = 1")->fetchColumn(), true);
    $provided_levels = $data['badges'] ?? [];
    if (empty($provided_levels)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one badge level must be provided',
            'result' => []
        ]);
        exit;
    }
    foreach ($provided_levels as $level => $values) {
        if (!in_array($level, ['lv1', 'lv2', 'lv3', 'lv4', 'lv5', 'lv6']) || !isset($values['price']) || !isset($values['task_increase'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid level or missing price/task_increase',
                'result' => []
            ]);
            exit;
        }
        foreach ($badges as &$badge) {
            if ($badge['level'] === $level) {
                $badge['price'] = (float)$values['price'];
                $badge['task_increase'] = (float)$values['task_increase'];
            }
        }
    }
    $stmt = $pdo->prepare("UPDATE settings SET badges = ? WHERE id = 1");
    $result = $stmt->execute([json_encode($badges)]);
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Badges updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update badges',
            'result' => []
        ]);
    }
} elseif (count($request) === 1 && $request[0] === 'set-check-in-reward') {
    // Update daily check-in rewards
    $rewards = json_decode($pdo->query("SELECT daily_checkin_rewards FROM settings WHERE id = 1")->fetchColumn(), true);
    $provided_days = array_intersect(array_keys($data), ['day_1', 'day_2', 'day_3', 'day_4', 'day_5', 'day_6', 'day_7']);
    if (empty($provided_days)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one day reward must be provided',
            'result' => []
        ]);
        exit;
    }
    foreach ($provided_days as $day) {
        $rewards[$day] = (float)$data[$day];
    }
    $stmt = $pdo->prepare("UPDATE settings SET daily_checkin_rewards = ? WHERE id = 1");
    $result = $stmt->execute([json_encode($rewards)]);
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Check-in rewards updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update check-in rewards',
            'result' => []
        ]);
    }
} elseif (count($request) === 1 && $request[0] === 'set-badges') {
    // Update badge benefits
    $badge_benefits = json_decode($pdo->query("SELECT badge_benefits FROM settings WHERE id = 1")->fetchColumn(), true);
    $provided_levels = $data['badge_benefits'] ?? [];
    if (empty($provided_levels)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one badge level must be provided',
            'result' => []
        ]);
        exit;
    }
    foreach ($provided_levels as $level => $values) {
        if (!in_array($level, ['lv1', 'lv2', 'lv3', 'lv4', 'lv5', 'lv6']) || !isset($values['price']) || !isset($values['check_in_increase']) || !isset($values['task_increase'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid level or missing price/check_in_increase/task_increase',
                'result' => []
            ]);
            exit;
        }
        $badge_benefits[$level] = [
            'price' => (float)$values['price'],
            'check_in_increase' => (float)$values['check_in_increase'],
            'task_increase' => (float)$values['task_increase']
        ];
    }
    $stmt = $pdo->prepare("UPDATE settings SET badge_benefits = ? WHERE id = 1");
    $result = $stmt->execute([json_encode($badge_benefits)]);
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Badge benefits updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update badge benefits',
            'result' => []
        ]);
    }
} elseif (count($request) === 1 && $request[0] === 'card-reward') {
    // Update scratch reward
    $scratch_reward = json_decode($pdo->query("SELECT scratch_reward FROM settings WHERE id = 1")->fetchColumn(), true);
    $provided_rewards = $data['scratch_reward'] ?? [];
    if (empty($provided_rewards)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one reward range must be provided',
            'result' => []
        ]);
        exit;
    }
    $new_rewards = [];
    foreach ($provided_rewards as $reward) {
        if (!isset($reward['balance']) || !isset($reward['reward'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid balance or reward range',
                'result' => []
            ]);
            exit;
        }
        $new_rewards[] = [
            'balance' => $reward['balance'],
            'reward' => $reward['reward']
        ];
    }
    $stmt = $pdo->prepare("UPDATE settings SET scratch_reward = ? WHERE id = 1");
    $result = $stmt->execute([json_encode($new_rewards)]);
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Scratch rewards updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update scratch rewards',
            'result' => []
        ]);
    }
} elseif (count($request) === 1 && $request[0] === 'gift-reward') {
    // Update luckybox reward
    $luckybox_reward = json_decode($pdo->query("SELECT luckybox_reward FROM settings WHERE id = 1")->fetchColumn(), true);
    $provided_rewards = $data['luckybox_reward'] ?? [];
    if (empty($provided_rewards)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one reward range must be provided',
            'result' => []
        ]);
        exit;
    }
    $new_rewards = [];
    foreach ($provided_rewards as $reward) {
        if (!isset($reward['balance']) || !isset($reward['reward'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid balance or reward range',
                'result' => []
            ]);
            exit;
        }
        $new_rewards[] = [
            'balance' => $reward['balance'],
            'reward' => $reward['reward']
        ];
    }
    $stmt = $pdo->prepare("UPDATE settings SET luckybox_reward = ? WHERE id = 1");
    $result = $stmt->execute([json_encode($new_rewards)]);
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Luckybox rewards updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update luckybox rewards',
            'result' => []
        ]);
    }
} elseif (count($request) === 1 && $request[0] === 'normal-settings') {
    // Update normal settings
    $allowed_fields = [
        'client_task_reward', 'oxapay_api', 'max_order', 'min_order', 'price_per_members',
        'ad_task_reward', 'ad_task_interval', 'check_in_without_ads', 'sms_api_key',
        'binance_withdraw_fee', 'bank_withdraw_fee', 'new_admin_password',
        'lucky_box_price', 'scratch_card_price', 'min_withdraw', 'referral_commission'
    ];
    $provided_fields = array_intersect($allowed_fields, array_keys($data));
    if (empty($provided_fields)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one setting must be provided',
            'result' => []
        ]);
        exit;
    }
    $updates = [];
    $params = [];
    foreach ($provided_fields as $field) {
        $db_field = ($field === 'new_admin_password') ? 'admin_password' : $field;
        $updates[] = "$db_field = ?";
        $params[] = $data[$field];
    }
    $stmt = $pdo->prepare("UPDATE settings SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = 1");
    $result = $stmt->execute($params);
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Settings updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update settings',
            'result' => []
        ]);
    }
} else {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid endpoint',
        'result' => []
    ]);
}
?>