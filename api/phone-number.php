<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';

// Clean old OTP records (older than 10 minutes)
function cleanOldOtps($pdo) {
    $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    $pdo->prepare("DELETE FROM otp_verification WHERE created_at < ? AND verified_at IS NULL")->execute([$tenMinutesAgo]);
}

// Check if phone number is already linked to any user
function isPhoneNumberLinked($pdo, $phone_number, $exclude_user_id = null) {
    $sql = "SELECT COUNT(*) as count FROM users WHERE phone_number = ?";
    $params = [$phone_number];
    
    if ($exclude_user_id !== null) {
        $sql .= " AND user_id != ?";
        $params[] = $exclude_user_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] > 0;
}

// Main API
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$init_data = $input['init_data'] ?? '';
$action = $input['action'] ?? '';
if (empty($init_data) || empty($action)) {
    echo json_encode(['ok' => false, 'message' => 'Missing init_data or action']);
    exit;
}

// Verify user
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data']);
    exit;
}
$user_id = $verify['data']['user']['id'];

// Clean old OTPs before processing
cleanOldOtps($pdo);

// Get settings
$settingsStmt = $pdo->query("SELECT sms_api_key FROM settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$sms_api_key = $settings['sms_api_key'] ?? '';

if (empty($sms_api_key)) {
    echo json_encode(['ok' => false, 'message' => 'SMS service not configured']);
    exit;
}

// Get current user's phone number status
$userStmt = $pdo->prepare("SELECT phone_number FROM users WHERE user_id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$current_phone = $user['phone_number'] ?? null;

// Process actions
if ($action === 'send-otp') {
    $phone_number = $input['phone_number'] ?? '';
    if (empty($phone_number)) {
        echo json_encode(['ok' => false, 'message' => 'Phone number is required']);
        exit;
    }

    // Check if current user already has a phone number linked
    if (!empty($current_phone)) {
        echo json_encode(['ok' => false, 'message' => 'You already have a phone number linked']);
        exit;
    }

    // Validate phone number format (simple validation)
    if (!preg_match('/^[0-9]{10,15}$/', $phone_number)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid phone number format']);
        exit;
    }

    // Check if phone number is already linked to any other user
    if (isPhoneNumberLinked($pdo, $phone_number)) {
        echo json_encode(['ok' => false, 'message' => 'This phone number is already linked to another account']);
        exit;
    }

    // Check OTP request limit (5 per day)
    $today = date('Y-m-d');
    $otpCountStmt = $pdo->prepare("SELECT COUNT(*) as count FROM otp_verification 
                                  WHERE user_id = ? AND DATE(created_at) = ?");
    $otpCountStmt->execute([$user_id, $today]);
    $otpCount = $otpCountStmt->fetch(PDO::FETCH_ASSOC)['count'];

    $max_otp_attempts = 5; // Can be made configurable from settings
    if ($otpCount >= $max_otp_attempts) {
        echo json_encode(['ok' => false, 'message' => 'Maximum OTP requests reached for today']);
        exit;
    }

    // Generate 6-digit OTP
    $otp = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // Check if there's an existing unverified OTP
    $existingOtpStmt = $pdo->prepare("SELECT id, request_count FROM otp_verification 
                                     WHERE user_id = ? AND phone_number = ? AND verified_at IS NULL
                                     ORDER BY created_at DESC LIMIT 1");
    $existingOtpStmt->execute([$user_id, $phone_number]);
    $existingOtp = $existingOtpStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingOtp) {
        // Update existing OTP record
        $pdo->prepare("UPDATE otp_verification SET 
                      otp = ?, 
                      request_count = request_count + 1,
                      last_request_time = NOW()
                      WHERE id = ?")
            ->execute([$otp, $existingOtp['id']]);
    } else {
        // Create new OTP record
        $pdo->prepare("INSERT INTO otp_verification (user_id, phone_number, otp) 
                      VALUES (?, ?, ?)")
            ->execute([$user_id, $phone_number, $otp]);
    }

    // Send OTP via SMS
    $API = $sms_api_key;
    $PHONE = $phone_number;
    $OTP = $otp;
    $URL = "https://sms.renflair.in/V1.php?API=$API&PHONE=$PHONE&OTP=$OTP";
    
    $curl = curl_init($URL);
    curl_setopt($curl, CURLOPT_URL, $URL);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($curl);
    curl_close($curl);
    $data = json_decode($resp, true);

    if ($data['return'] ?? false) {
        echo json_encode([
            'ok' => true,
            'message' => 'OTP sent successfully',
            'otp_request_count' => ($existingOtp['request_count'] ?? 0) + 1,
            'remaining_attempts' => $max_otp_attempts - ($otpCount + 1)
        ]);
    } else {
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to send OTP: ' . ($data['message'] ?? 'Unknown error')
        ]);
    }

} elseif ($action === 'verify-otp') {
    $phone_number = $input['phone_number'] ?? '';
    $otp = $input['otp'] ?? '';
    
    if (empty($phone_number) || empty($otp)) {
        echo json_encode(['ok' => false, 'message' => 'Phone number and OTP are required']);
        exit;
    }

    // Check if current user already has a phone number linked
    if (!empty($current_phone)) {
        echo json_encode(['ok' => false, 'message' => 'You already have a phone number linked']);
        exit;
    }

    // Check if phone number is already linked to any other user
    if (isPhoneNumberLinked($pdo, $phone_number)) {
        echo json_encode(['ok' => false, 'message' => 'This phone number is already linked to another account']);
        exit;
    }

    // Check for valid unverified OTP
    $otpStmt = $pdo->prepare("SELECT id, created_at FROM otp_verification 
                             WHERE user_id = ? AND phone_number = ? AND otp = ? AND verified_at IS NULL
                             ORDER BY created_at DESC LIMIT 1");
    $otpStmt->execute([$user_id, $phone_number, $otp]);
    $otpRecord = $otpStmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpRecord) {
        echo json_encode(['ok' => false, 'message' => 'Invalid OTP or OTP expired']);
        exit;
    }

    // Check if OTP is expired (10 minutes)
    $otpTime = strtotime($otpRecord['created_at']);
    if (time() - $otpTime > 600) { // 10 minutes in seconds
        echo json_encode(['ok' => false, 'message' => 'OTP expired']);
        exit;
    }

    // Mark OTP as verified
    $pdo->prepare("UPDATE otp_verification SET verified_at = NOW() WHERE id = ?")
        ->execute([$otpRecord['id']]);

    // Update user's phone number
    $pdo->prepare("UPDATE users SET phone_number = ? WHERE user_id = ?")
        ->execute([$phone_number, $user_id]);

    echo json_encode([
        'ok' => true,
        'message' => 'Phone number verified successfully',
        'phone_number' => $phone_number
    ]);

} else {
    echo json_encode(['ok' => false, 'message' => 'Invalid action']);
}