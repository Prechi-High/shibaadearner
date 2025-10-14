<?php
// draw-winner.php — Cron job to pick winner when all tickets sold at even hour

require_once __DIR__ . '/../config.php';

try {
    // 1. Check if all 1000 tickets are sold
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tickets");
    $totalTickets = (int)$stmt->fetch()['total'];
    
    if ($totalTickets < 1000) {
        echo "Draw skipped: Not all tickets sold ({$totalTickets}/1000)\n";
        exit;
    }

    // 2. Check if current hour is even
    $currentHour = (int)date('H');
    if ($currentHour % 2 !== 0) {
        echo "Draw skipped: Current hour ($currentHour) is odd. Waiting for next even hour.\n";
        exit;
    }

    // 3. Check if draw already happened this hour (prevent duplicate)
    $stmt = $pdo->prepare("SELECT id FROM winners WHERE DATE(created_at) = CURDATE() AND HOUR(created_at) = ?");
    $stmt->execute([$currentHour]);
    if ($stmt->fetch()) {
        echo "Draw skipped: Winner already selected this hour.\n";
        exit;
    }

    // 4. Get all ticket holders
    $stmt = $pdo->query("SELECT user_id FROM tickets");
    $ticketHolders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ticketHolders)) {
        echo "Error: No ticket holders found.\n";
        exit;
    }

    // 5. Pick random winner
    $winnerUserId = $ticketHolders[array_rand($ticketHolders)];

    // 6. Calculate prize (total pot = 1000 * 0.0025 = $2.50 USDT)
    $totalPot = 1000 * 0.0025; // = 2.5
    $fee = 0.0; // Set to 0.1 if you want 10¢ fee
    $prize = $totalPot - $fee;

    // 7. Credit winner
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$prize, $winnerUserId]);

    // 8. Log winner
    $stmt = $pdo->prepare("INSERT INTO winners (user_id, prize, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$winnerUserId, $prize]);

    // 9. Reset game: clear all tickets
    $pdo->exec("TRUNCATE TABLE tickets");

    // 10. Notify winner & channel
    $winnerStmt = $pdo->prepare("SELECT telegram_id, username, first_name FROM users WHERE id = ?");
    $winnerStmt->execute([$winnerUserId]);
    $winner = $winnerStmt->fetch();

    $winnerName = $winner['username'] ?? $winner['first_name'] ?? 'Anonymous';
    $winnerId = $winner['telegram_id'];

    $message = "🎉 **LUCKY DRAW WINNER** 🎉\n\n" .
               "User @$winnerName has won **$" . number_format($prize, 4) . " USDT**!\n" .
               "Total tickets sold: 1000\n" .
               "Next draw starts now! Buy your ticket for $0.0025.";

    // Send to your notification channel
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $API_URL . "sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id' => NOTIFICATION_CHANNEL_ID,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    // Optional: Send direct message to winner
    /*
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $API_URL . "sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id' => $winnerId,
        'text' => "🎊 Congratulations! You won $" . number_format($prize, 4) . " USDT in the Lucky Draw!\nYour balance has been updated.",
        'parse_mode' => 'Markdown'
    ]));
    curl_exec($ch);
    curl_close($ch);
    */

    echo "✅ Winner selected: @$winnerName ($winnerId) | Prize: $" . number_format($prize, 4) . "\n";

} catch (Exception $e) {
    error_log("Draw error: " . $e->getMessage());
    echo "❌ Error: " . $e->getMessage() . "\n";
}