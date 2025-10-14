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
if (count($request) === 1 && $request[0] === 'get') {
    // Get all gift codes
    $stmt = $pdo->query("SELECT id, gift_code, gift_name, total_user, reward_amount, claimed, created_at FROM gift_codes");
    $gift_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($gift_codes as &$code) {
        $code['claimed'] = json_decode($code['claimed'], true);
    }
    echo json_encode([
        'ok' => true,
        'message' => 'Gift codes retrieved successfully',
        'result' => $gift_codes
    ]);
} elseif (count($request) === 1 && $request[0] === 'create') {
    // Create new gift code
    $required_fields = ['gift_code', 'gift_name', 'total_user', 'reward_amount'];
    $provided_fields = array_intersect($required_fields, array_keys($data));
    
    if (count($provided_fields) !== count($required_fields)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Missing required fields: ' . implode(', ', array_diff($required_fields, $provided_fields)),
            'result' => []
        ]);
        exit;
    }
    
    $gift_code = $data['gift_code'];
    $gift_name = $data['gift_name'];
    $total_user = (int)$data['total_user'];
    $reward_amount = (float)$data['reward_amount'];
    $claimed = json_encode($data['claimed'] ?? []);
    
    // Check if gift_code already exists
    $stmt = $pdo->prepare("SELECT id FROM gift_codes WHERE gift_code = ?");
    $stmt->execute([$gift_code]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Gift code already exists. Provide a unique gift_code or delete the existing one.',
            'result' => []
        ]);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO gift_codes (gift_code, gift_name, total_user, reward_amount, claimed, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $result = $stmt->execute([$gift_code, $gift_name, $total_user, $reward_amount, $claimed]);
    
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Gift code created successfully',
            'result' => ['gift_code' => $gift_code]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to create gift code',
            'result' => []
        ]);
    }
} elseif (count($request) === 2 && $request[0] === 'delete' && !empty($request[1])) {
    // Delete gift code
    $gift_code = $request[1];
    $stmt = $pdo->prepare("DELETE FROM gift_codes WHERE gift_code = ?");
    $result = $stmt->execute([$gift_code]);
    
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Gift code deleted successfully',
            'result' => []
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Gift code not found or failed to delete',
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