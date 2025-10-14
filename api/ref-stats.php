<?php
header('Content-Type: application/json');

require 'config.php';
require 'init_data_check.php';

// ✅ Read JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']); 
    exit;
}

$init_data = $input['init_data'] ?? '';
$maxLevel = isset($input['levels']) ? (int)$input['levels'] : 10; // Default 6 levels

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

$user_id = $verify['data']['user']['id'];

// ✅ Fetch user data
$stmt = $pdo->prepare("SELECT reflist, ref_income FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo json_encode(['ok' => false, 'message' => 'User not found.']); 
    exit;
}

// ✅ Decode JSON fields
$reflist = json_decode($data['reflist'], true);
if (!is_array($reflist)) $reflist = [];

$refIncome = json_decode($data['ref_income'], true);
if (!is_array($refIncome)) $refIncome = [];

// ✅ Date helpers
$today = date('d/m/Y');
$startOfWeek = strtotime("-6 days");
$startOfMonth = date('m/Y');

// --- INVITE STATS ---
$total_invite = count($reflist);
$invite_today = 0;
$invite_this_week = 0;
$invite_this_month = 0;

foreach ($reflist as $invite) {
    $inviteDate = $invite['date'] ?? '';
    if ($inviteDate === $today) $invite_today++;
    $inviteTs = strtotime(str_replace('/', '-', $inviteDate));
    if ($inviteTs >= $startOfWeek) $invite_this_week++;
    if (strpos($inviteDate, $startOfMonth) !== false) $invite_this_month++;
}

// --- EARNING STATS ---
$total_earning = 0;
$earning_today = 0;
$earning_this_week = 0;
$earning_this_month = 0;

$fromUserIds = array_unique(array_column($refIncome, 'from_user'));
$namesMap = [];

if (!empty($fromUserIds)) {
    $placeholders = rtrim(str_repeat('?,', count($fromUserIds)), ',');
    $stmt = $pdo->prepare("SELECT user_id, name FROM users WHERE user_id IN ($placeholders)");
    $stmt->execute($fromUserIds);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $namesMap[$row['user_id']] = $row['name'];
    }
}

foreach ($refIncome as &$earning) {
    $amt = (float)($earning['amount'] ?? 0);
    $total_earning += $amt;

    $earnDate = $earning['date'] ?? '';
    if ($earnDate === $today) $earning_today += $amt;
    $earnTs = strtotime(str_replace('/', '-', $earnDate));
    if ($earnTs >= $startOfWeek) $earning_this_week += $amt;
    if (strpos($earnDate, $startOfMonth) !== false) $earning_this_month += $amt;

    $uid = $earning['from_user'];
    $earning['from_user'] = $namesMap[$uid] ?? $uid;
}
unset($earning);

usort($refIncome, function($a, $b) {
    return strtotime(str_replace('/', '-', $b['date'])) <=> strtotime(str_replace('/', '-', $a['date']));
});

// --- TEAM SUBORDINATE STATS ---
$teamLevels = [];
$totalTeamSize = 0;

$currentLevelUsers = array_column($reflist, 'user_id');

for ($level = 1; $level <= $maxLevel; $level++) {
    if (empty($currentLevelUsers)) break;

    $placeholders = rtrim(str_repeat('?,', count($currentLevelUsers)), ',');
    $stmt = $pdo->prepare("SELECT reflist FROM users WHERE user_id IN ($placeholders)");
    $stmt->execute($currentLevelUsers);
    $nextLevelUsers = [];
    $levelCount = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $subRefs = json_decode($row['reflist'], true);
        if (is_array($subRefs)) {
            foreach ($subRefs as $sub) {
                $levelCount++;
                $nextLevelUsers[] = $sub['user_id'];
            }
        }
    }

    $teamLevels["level_$level"] = $levelCount;
    $totalTeamSize += $levelCount;
    $currentLevelUsers = $nextLevelUsers;
}

// ✅ Response
echo json_encode([
    'ok' => true,
    'message' => 'Referral statistics loaded.',
    'result' => [
        'invite_stats' => [
            'total_invite' => $total_invite,
            'invite_today' => $invite_today,
            'invite_this_week' => $invite_this_week,
            'invite_this_month' => $invite_this_month
        ],
        'team_subordinate' => [
            'team_size' => $totalTeamSize,
            'levels' => $teamLevels
        ],
        'earning_stats' => [
            'total_earning' => number_format($total_earning, 2),
            'earning_today' => number_format($earning_today, 2),
            'earning_this_week' => number_format($earning_this_week, 2),
            'earning_this_month' => number_format($earning_this_month, 2),
            'earning_history' => $refIncome
        ]
    ]
]);
