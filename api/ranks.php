<?php
header('Content-Type: application/json');
require 'config.php';
require 'init_data_check.php';
// Read input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['init_data'])) {
    echo json_encode(['ok' => false, 'message' => 'Missing or invalid init_data.']);
    exit;
}
$init_data = $input['init_data'];
$verify = verifyTelegramWebApp($BOT_TOKEN, $init_data);
if (!$verify['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Invalid init_data.']);
    exit;
}
$userData = $verify['data']['user'];
$user_id = $userData['id'];
$today = date('d/m/Y');
$last_sunday = date('d/m/Y', strtotime('last sunday'));
$month_start = date('d/m/Y', strtotime('first day of this month'));
// Fetch all users with non-empty reflist
$sql = "SELECT user_id, name, reflist FROM users WHERE reflist IS NOT NULL AND reflist != '[]'";
$stmt = $pdo->query($sql);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
function countRefs(array $reflist, $filter = 'all') {
    $count = 0;
    $today = date('d/m/Y');
    $last_sunday = date('d/m/Y', strtotime('last sunday'));
    $month_start = date('d/m/Y', strtotime('first day of this month'));
    foreach ($reflist as $ref) {
        $date = $ref['date'] ?? null;
        if (!$date) continue;
        // Convert date formats for comparison
        $ref_date = DateTime::createFromFormat('d/m/Y', $date);
        $ref_timestamp = $ref_date ? $ref_date->getTimestamp() : 0;
        if ($filter === 'today' && $date === $today) {
            $count++;
        } elseif ($filter === 'this_week' && $ref_timestamp >= strtotime('last sunday')) {
            $count++;
        } elseif ($filter === 'this_month' && $ref_timestamp >= strtotime('first day of this month')) {
            $count++;
        } elseif ($filter === 'all') {
            $count++;
        }
    }
    return $count;
}
function prepareRankList(array $users, string $filter, int $currentUserId) {
    $list = [];
    foreach ($users as $user) {
        $reflist = json_decode($user['reflist'], true);
        if (!is_array($reflist)) continue;
        $count = countRefs($reflist, $filter);
        if ($count > 0) {
            $list[] = [
                'user_id' => $user['user_id'],
                'name' => $user['name'],
                'total_invites' => $count
            ];
        }
    }
    // Sort descending
    usort($list, fn($a, $b) => $b['total_invites'] <=> $a['total_invites']);
    // Get my rank & my refers
    $my_rank = null;
    $my_refers = 0;
    foreach ($list as $index => $row) {
        if ($row['user_id'] == $currentUserId) {
            $my_rank = $index + 1;
            $my_refers = $row['total_invites'];
            break;
        }
    }
    return [
        'top_referrers' => array_slice($list, 0, 100),
        'my_rank' => $my_rank ?? 0,
        'my_refers' => $my_refers
    ];
}
$result = [
    'today' => prepareRankList($users, 'today', $user_id),
    'this_week' => prepareRankList($users, 'this_week', $user_id),
    'this_month' => prepareRankList($users, 'this_month', $user_id),
    'all_time' => prepareRankList($users, 'all', $user_id),
];
echo json_encode([
    'ok' => true,
    'message' => 'Referral rankings fetched successfully.',
    'result' => $result
]);
?>