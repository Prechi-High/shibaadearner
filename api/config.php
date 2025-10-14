<?php
// config.php

// === DATABASE ===
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

// === TELEGRAM BOT ===
$BOT_TOKEN = "8241010521:AAGIiN4JPQ8vpU9enrnqSKYiRK1imYeRxuM";
$API_URL = "https://api.telegram.org/bot" . $BOT_TOKEN . "/";

// === GAME SETTINGS ===
define('BOT_TOKEN', $BOT_TOKEN);
define('DEPOSIT_WALLET', '0xYourUSDTWalletAddressHere'); // ← REPLACE THIS

// Optional: Keep your existing constants if used elsewhere
define('WEB_APP_LINK', "https://shibaadearner.top/lumora/app/");
define('WELCOME_SCRATCH_CARDS', 3);
define('BOT_USERNAME', "LumoraHubBot");
define('NOTIFICATION_CHANNEL_ID', "-1003167874029");
