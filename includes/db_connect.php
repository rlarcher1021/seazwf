<?php
error_log("[DB_CONNECT_DEBUG] db_connect.php execution started.");
// includes/db_connect.php

// --- Add PHPMailer use statements at the top (even if not used directly here, good practice) ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
// --- End Add ---
// Define Upload Path Constants (Used by configurations.php and checkin.php)
define('AD_UPLOAD_PATH', dirname(__DIR__) . '/assets/uploads/ads/'); // Use dirname(__DIR__) for reliability
define('AD_UPLOAD_URL_BASE', 'assets/uploads/ads/');    // Relative URL path for src attribute
// Define the path to the configuration file relative to this script
// Go up one level from 'includes' (to public_html), then up one MORE level, then into 'config'
$configPath = dirname(__DIR__, 2) . '/config/config.ini';

if (!file_exists($configPath)) {
    error_log("CRITICAL ERROR: Configuration file not found at expected location: " . $configPath);
    http_response_code(500); // Internal Server Error
    // Ensure JSON header even on early exit, might be needed if called by AJAX handler
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Server configuration error. Please contact administrator.']);
    exit;
}

// Parse the INI file
$config = parse_ini_file($configPath, true);

if ($config === false || !isset($config['database'])) {
    error_log("CRITICAL ERROR: Failed to parse configuration file or missing [database] section.");
    http_response_code(500); // Internal Server Error
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Server configuration reading error. Please contact administrator.']);
    exit;
}

// Database credentials from config file
$db_host = $config['database']['host'];
$db_name = $config['database']['dbname'];
$db_user = $config['database']['username'];
$db_pass = $config['database']['password'];
$db_charset = $config['database']['charset'] ?? 'utf8mb4';

// PDO options
$options = [
    // --- PRODUCTION RECOMMENDED SETTING ---
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Use exceptions for robust error handling
    // --- TEMPORARY DEBUGGING SETTING ---
    // PDO::ATTR_ERRMODE            => PDO::ERRMODE_WARNING, // TEMPORARY DEBUGGING SETTING (DISABLED)
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Data Source Name (DSN)
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

    // DEBUG START - PDO Object Creation Status
    $debug_timestamp_pdo_created = date('[Y-m-d H:i:s] ');
    $pdo_creation_status = ($pdo instanceof PDO) ? 'PDO object successfully created.' : 'PDO object NOT created or invalid.';
    $log_message_pdo_created = $debug_timestamp_pdo_created . "DB Connect: " . $pdo_creation_status . PHP_EOL;
    // Ensure the logs directory exists and is writable by the web server.
    // For gateway, logs are in api/gateway/debug.log, let's try to keep it consistent or make a global log.
    // For now, let's assume a global log file or adjust if gateway's log is preferred.
    // Using a path relative to this file for simplicity, assuming 'logs' dir is in 'public_html'
    $log_file_path = dirname(__DIR__) . '/logs/db_connect_debug.log';
    file_put_contents($log_file_path, $log_message_pdo_created, FILE_APPEND | LOCK_EX);
    // DEBUG END - PDO Object Creation Status

} catch (\PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please contact administrator.']);
    exit;
}

if (isset($pdo) && $pdo !== null && $pdo instanceof PDO) {
    error_log("[DB_CONNECT_DEBUG] db_connect.php execution finished. \$pdo is a valid PDO object.");
} elseif (isset($pdo)) {
    error_log("[DB_CONNECT_DEBUG] db_connect.php execution finished. \$pdo IS SET but is NOT a valid PDO object or is null. Type: " . gettype($pdo));
} 
else {
    error_log("[DB_CONNECT_DEBUG] db_connect.php execution finished. \$pdo IS NOT SET.");
}

// ===============================================
// --- Helper Functions ---
// ===============================================





// --- REMOVED Email Helper Functions ---
// function fetchAdminEmails($pdo) { ... }
// function sendAdminColumnNotificationEmail(...) { ... }

?>