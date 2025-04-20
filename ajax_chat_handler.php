<?php
// ajax_chat_handler.php

// --- Configuration ---
// IMPORTANT: Assumes config file is one level ABOVE the web root (public_html)
$configFilePath = __DIR__ . '/../config/ai_chat_setting.ini'; 
define('CONFIG_FILE_PATH', $configFilePath);

// --- Session & Security ---
session_start();

// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Access Denied: Not logged in.']);
    exit;
}

// 2. Check ACTIVE user role (cannot be 'kiosk') - Use active_role for consistency with UI/permissions
if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'kiosk') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Access Denied: Invalid role.']);
    exit;
}

// 3. Check Request Method (must be POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// 4. CSRF Token Validation
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request: CSRF token mismatch.']);
    exit; // Stop execution if CSRF token is invalid
}

// 5. Check if message is provided
if (!isset($_POST['message']) || trim($_POST['message']) === '') {
    http_response_code(400); // Bad Request
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
    exit;
}

$userMessage = trim($_POST['message']);

// --- Database Connection ---
// Required for fetching site name
require_once 'includes/db_connect.php'; // Contains the $pdo object

// --- Load Configuration ---
$n8nWebhookUrl = null;
$n8nWebhookSecret = null;

if (!file_exists(CONFIG_FILE_PATH)) {
    // Log this error server-side if possible
    error_log("AI Chat Error: Configuration file not found at " . CONFIG_FILE_PATH);
    http_response_code(500);
    header('Content-Type: application/json');
    // Generic error for the user
    echo json_encode(['success' => false, 'error' => 'Configuration error. Please contact administrator.']);
    exit;
}

$config = parse_ini_file(CONFIG_FILE_PATH);

if ($config === false) {
    error_log("AI Chat Error: Failed to parse configuration file at " . CONFIG_FILE_PATH);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Configuration error. Please contact administrator.']);
    exit;
}

if (!isset($config['N8N_WEBHOOK_URL']) || !isset($config['N8N_WEBHOOK_SECRET'])) {
    error_log("AI Chat Error: Missing N8N_WEBHOOK_URL or N8N_WEBHOOK_SECRET in " . CONFIG_FILE_PATH);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Configuration error. Please contact administrator.']);
    exit;
}

$n8nWebhookUrl = $config['N8N_WEBHOOK_URL'];
$n8nWebhookSecret = $config['N8N_WEBHOOK_SECRET'];

// --- Prepare Data for n8n ---

// Retrieve user details from session
$userFullName = $_SESSION['full_name'] ?? 'User'; // Default to 'User' if not set
$userSiteId = $_SESSION['active_site_id'] ?? null; // Use active site ID
$userSiteName = null; // Initialize site name

// Fetch site name if site ID is available
if ($userSiteId !== null && isset($pdo)) { // Check if $pdo is set from db_connect.php
    try {
        $siteSql = "SELECT name FROM sites WHERE id = :site_id";
        $siteStmt = $pdo->prepare($siteSql);
        $siteStmt->bindParam(':site_id', $userSiteId, PDO::PARAM_INT);
        $siteStmt->execute();
        $siteResult = $siteStmt->fetch(PDO::FETCH_ASSOC);
        if ($siteResult) {
            $userSiteName = $siteResult['name'];
        }
    } catch (PDOException $e) {
        // Log the error but continue execution, sending null for site name
        error_log("AI Chat Error: Failed to fetch site name for site ID {$userSiteId}. Error: " . $e->getMessage());
    }
}

// Use active_role to reflect the current user context (including impersonation)
$payload = json_encode([
    'message' => $userMessage,
    'user_role' => $_SESSION['active_role'] ?? null, // Send the role the user is currently acting as
    'user_department_identifier' => $_SESSION['department_slug'] ?? null, // Use slug, updated key name
    'user_id' => $_SESSION['user_id'], // This should be the actual logged-in user's ID, not impersonated
    'user_full_name' => $userFullName, // Added user's full name
    'user_site_name' => $userSiteName // Added user's site name (or null)
]);

if ($payload === false) {
    error_log("AI Chat Error: Failed to encode JSON payload for user ID " . $_SESSION['user_id']);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Internal server error preparing request.']);
    exit;
}

// --- Call n8n Webhook using cURL ---
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $n8nWebhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload),
    'X-Chat-API-Key: ' . $n8nWebhookSecret // Custom auth header
]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds connection timeout
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds total timeout

$responseBody = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// --- Handle n8n Response ---
header('Content-Type: application/json');

if ($curlError) {
    error_log("AI Chat cURL Error: " . $curlError . " for URL: " . $n8nWebhookUrl);
    // Don't expose detailed cURL errors to the user
    echo json_encode(['success' => false, 'error' => 'Sorry, the AI assistant is unavailable right now. If this message persists please contact an administrator. [CURL]']);
    exit;
}

if ($httpStatusCode === 200) {
    $responseData = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($responseData['message'])) {
        // Success case
        echo json_encode(['success' => true, 'reply' => $responseData['message']]);
    } else {
        // Valid HTTP status, but invalid JSON or missing 'message' key
        error_log("AI Chat Error: Received 200 OK but invalid JSON or missing 'message' key from n8n. Response: " . $responseBody);
        echo json_encode(['success' => false, 'error' => 'Received an invalid response from the AI assistant.']);
    }
} else {
    // n8n returned an error status code
    $errorDetail = "n8n returned HTTP status code: " . $httpStatusCode;
    $n8nError = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($n8nError['error'])) {
        $errorDetail .= ". n8n Error: " . $n8nError['error'];
    } else {
        $errorDetail .= ". Response Body: " . $responseBody;
    }
    error_log("AI Chat Error: " . $errorDetail);
    // Generic error for the user
    echo json_encode(['success' => false, 'error' => 'Sorry, the AI assistant is unavailable right now. If this message persists please contact an administrator. [HTTP ' . $httpStatusCode . ']']);
}

exit; // End script

?>