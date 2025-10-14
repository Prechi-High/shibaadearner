<?php
// Database connection
$host = "localhost";
$dbname = "kkiqkkiu_lumora";
$username = "kkiqkkiu_lumora";
$password = "kkiqkkiu_lumora";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(["ok" => false, "message" => "DB Connection Failed: " . $e->getMessage()]));
}

// Bot Token
$BOT_TOKEN = "8070029164:AAHMCYk0bMIemsuVV9v6JP9suuLUwqQe-ms";

// Telegram API URL
$API_URL = "https://api.telegram.org/bot$BOT_TOKEN/";
?>
