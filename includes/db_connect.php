<?php
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
    die("A critical configuration error occurred. Please contact the system administrator.");
}

// Parse the INI file
$config = parse_ini_file($configPath, true);

if ($config === false || !isset($config['database'])) {
    error_log("CRITICAL ERROR: Failed to parse configuration file or missing [database] section.");
    die("A critical configuration error occurred while reading settings. Please contact the system administrator.");
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
} catch (\PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed. Please notify the administrator.");
}


// ===============================================
// --- Helper Functions ---
// ===============================================





// --- REMOVED Email Helper Functions ---
// function fetchAdminEmails($pdo) { ... }
// function sendAdminColumnNotificationEmail(...) { ... }

?>