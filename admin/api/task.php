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
if (count($request) === 1 && $request[0] === 'task') {
    // Get all tasks
    $stmt = $pdo->query("SELECT id, task_id, task_image, name, type, chat_id, bot_token, link, max_completion, completed, status, reward, created_at FROM tasks");
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'message' => 'Tasks retrieved successfully',
        'result' => $tasks
    ]);
} elseif (count($request) === 2 && $request[0] === 'task' && $request[1] === 'create') {
    // Create new task
    $required_fields = ['task_id', 'task_image', 'name', 'type', 'link', 'max_completion', 'reward'];
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
    
    $task_id = $data['task_id'];
    $task_image = $data['task_image'];
    $name = $data['name'];
    $type = in_array($data['type'], ['channel', 'bot', 'other']) ? $data['type'] : null;
    $link = $data['link'];
    $max_completion = (int)$data['max_completion'];
    $reward = (float)$data['reward'];
    $chat_id = $data['chat_id'] ?? null;
    $bot_token = $data['bot_token'] ?? null;
    $status = in_array($data['status'] ?? 'active', ['active', 'inactive']) ? $data['status'] : 'active';
    
    if (!$type) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Invalid task type',
            'result' => []
        ]);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO tasks (task_id, task_image, name, type, chat_id, bot_token, link, max_completion, completed, status, reward, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())");
    $result = $stmt->execute([$task_id, $task_image, $name, $type, $chat_id, $bot_token, $link, $max_completion, $status, $reward]);
    
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Task created successfully',
            'result' => ['task_id' => $task_id]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to create task',
            'result' => []
        ]);
    }
} elseif (count($request) === 2 && $request[0] === 'task' && !empty($request[1])) {
    // Get specific task details
    $task_id = $request[1];
    $stmt = $pdo->prepare("SELECT id, task_id, task_image, name, type, chat_id, bot_token, link, max_completion, completed, status, reward, created_at FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($task) {
        echo json_encode([
            'ok' => true,
            'message' => 'Task details retrieved successfully',
            'result' => [$task]
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Task not found',
            'result' => []
        ]);
    }
} elseif (count($request) === 3 && $request[0] === 'task' && !empty($request[1]) && $request[2] === 'edit') {
    // Edit task details
    $task_id = $request[1];
    $allowed_fields = ['task_image', 'name', 'type', 'chat_id', 'bot_token', 'link', 'max_completion', 'reward', 'status'];
    $provided_fields = array_intersect($allowed_fields, array_keys($data));
    
    if (empty($provided_fields)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'At least one field must be provided for update',
            'result' => []
        ]);
        exit;
    }
    
    $updates = [];
    $params = [];
    foreach ($provided_fields as $field) {
        if ($field === 'type' && !in_array($data['type'], ['channel', 'bot', 'other'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid task type',
                'result' => []
            ]);
            exit;
        }
        if ($field === 'status' && !in_array($data['status'], ['active', 'inactive'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid status',
                'result' => []
            ]);
            exit;
        }
        $updates[] = "$field = ?";
        $params[] = $data[$field];
    }
    $params[] = $task_id;
    
    $stmt = $pdo->prepare("UPDATE tasks SET " . implode(', ', $updates) . " WHERE task_id = ?");
    $result = $stmt->execute($params);
    
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Task updated successfully',
            'result' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to update task',
            'result' => []
        ]);
    }
} elseif (count($request) === 3 && $request[0] === 'task' && !empty($request[1]) && $request[2] === 'delete') {
    // Delete task
    $task_id = $request[1];
    $stmt = $pdo->prepare("DELETE FROM tasks WHERE task_id = ?");
    $result = $stmt->execute([$task_id]);
    
    if ($result) {
        echo json_encode([
            'ok' => true,
            'message' => 'Task deleted successfully',
            'result' => []
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Task not found or failed to delete',
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