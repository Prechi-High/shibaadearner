<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// ✅ Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$init_data = $input['init_data'] ?? '';
if (empty($init_data)) {
    echo json_encode(['ok' => false, 'message' => 'Missing parameter: init_data']);
    exit;
}

// ✅ Validate init_data
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data']);
    exit;
}

$user = $verify['data']['user'];
$user_id = $user['id'];

// ✅ Fetch user info
$stmt = $pdo->prepare("SELECT inviter_id, balance, reflist, invited_by, ref_income FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']);
    exit;
}

// ✅ Prepare variables
$inviter_id = $data['inviter_id'];
$balance = (float)$data['balance'];

// ✅ Decode ref_income JSON and calculate total income
$ref_income_data = json_decode($data['ref_income'], true);
if (!is_array($ref_income_data)) $ref_income_data = [];

$total_ref_income = 0;
foreach ($ref_income_data as $entry) {
    if (isset($entry['amount'])) {
        $total_ref_income += (float)$entry['amount'];
    }
}

// ✅ Prepare referral list
$reflist = json_decode($data['reflist'], true);
if (!is_array($reflist)) $reflist = [];
$recent_reflist = array_slice(array_reverse($reflist), 0, 100);

$invited_by = $data['invited_by'] ?? null;

// ✅ Get commission from settings
$settingsStmt = $pdo->query("SELECT referral_commission FROM settings LIMIT 1");
$invite_commission = (float) $settingsStmt->fetchColumn();

// ✅ Response
echo json_encode([
    'ok' => true,
    'message' => 'Referral info loaded.',
    'result' => [
        'invite_link' => "https://t.me/$BOT_USERNAME?startapp=$inviter_id",
        'balance' => $balance,
        'invite_commission' => $invite_commission,
        'total_invite' => count($reflist),
        'income_from_refers' => $total_ref_income,
        'reflist' => array_map(function($ref) {
            return [
                'name' => $ref['name'] ?? '',
                'date' => $ref['date'] ?? ''
            ];
        }, $recent_reflist),
        'invited_by' => $invited_by
    ]
]);
