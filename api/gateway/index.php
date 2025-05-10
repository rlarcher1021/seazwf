<?php
// api/gateway/index.php
// Entry point for the Unified API Gateway

// --- Configuration & Includes ---
header('Content-Type: application/json');

// IMPORTANT: Replace with the actual path to your database connection script
// Assuming db_connect.php provides a $conn (mysqli or PDO) object
require_once __DIR__ . '/../../includes/db_connect.php';

// DEBUG START - Header Inspection
$debug_timestamp_headers = date('[Y-m-d H:i:s] ');
if (function_exists('getallheaders')) {
    $all_headers_arr = getallheaders();
} else {
    $all_headers_arr = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $all_headers_arr[$header_name] = $value;
        } elseif ($name == 'CONTENT_TYPE') {
            $all_headers_arr['Content-Type'] = $value;
        } elseif ($name == 'CONTENT_LENGTH') {
            $all_headers_arr['Content-Length'] = $value;
        }
    }
}
$log_message_headers = $debug_timestamp_headers . "All Headers: " . json_encode($all_headers_arr) . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_headers, FILE_APPEND | LOCK_EX);

$auth_header_value = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : 'Not set';
$log_message_auth = $debug_timestamp_headers . "Authorization Header: " . $auth_header_value . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_auth, FILE_APPEND | LOCK_EX);

$x_api_key_value = isset($_SERVER['HTTP_X_AGENT_API_KEY']) ? $_SERVER['HTTP_X_AGENT_API_KEY'] : 'Not set';
$log_message_x_key = $debug_timestamp_headers . "X-Agent-API-Key Header: " . $x_api_key_value . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_x_key, FILE_APPEND | LOCK_EX);

$raw_request_body_content = file_get_contents('php://input');
$log_message_body = $debug_timestamp_headers . "Raw Request Body: " . ($raw_request_body_content ?: 'Empty or not readable') . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_body, FILE_APPEND | LOCK_EX);
// DEBUG END - Header Inspection

// Placeholder for the Gateway's dedicated internal V1 API Key
define('INTERNAL_API_KEY_PLACEHOLDER', 'e6e532dd83d0456d163c7f38b6a0f6d96930e67bf627eb2ef1b987c0a3a5da79');
// Base URL for the internal V1 API
define('INTERNAL_API_V1_BASE_URL', 'https://seazwf.com/api/v1'); // Adjust if your local/dev URL is different

// --- Helper Functions ---

/**
 * Sends a JSON response.
 * @param int $statusCode HTTP status code.
 * @param array $data The data to send.
 */
function send_json_response($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Sends a success JSON response.
 * @param mixed $data Optional data payload.
 * @param string $message Optional success message.
 */
function send_success_response($data = null, $message = null) {
    $response = ['status' => 'success'];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($message !== null) {
        $response['message'] = $message;
    }
    send_json_response(200, $response);
}

/**
 * Sends an error JSON response.
 * @param int $statusCode HTTP status code.
 * @param string $errorCode Custom error code for the gateway.
 * @param string $message Descriptive error message.
 * @param mixed $details Optional additional error details.
 */
function send_error_response($statusCode, $errorCode, $message, $details = null) {
    $response = [
        'status' => 'error',
        'error' => [
            'code' => $errorCode,
            'message' => $message,
        ],
    ];
    if ($details !== null) {
        $response['error']['details'] = $details;
    }
    send_json_response($statusCode, $response);
}

/**
 * Makes a cURL request to an internal V1 API endpoint.
 * @param string $method HTTP method (e.g., 'GET', 'POST').
 * @param string $endpoint The internal API endpoint (e.g., '/checkins/123').
 * @param array $params Optional parameters for the request (query for GET, body for POST).
 * @return array Decoded JSON response from the internal API or an error structure.
 */
function call_internal_api($method, $endpoint, $params = []) {
    $url = INTERNAL_API_V1_BASE_URL . $endpoint;
    $ch = curl_init();

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . INTERNAL_API_KEY_PLACEHOLDER, // Using Bearer token
        // Or 'X-API-Key: ' . INTERNAL_API_KEY_PLACEHOLDER, // If V1 API expects X-API-Key
    ];

    if (strtoupper($method) === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if (strtoupper($method) === 'POST' && !empty($params)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // Re-set headers if Content-Type added
    }
    
    // In a production environment, you should configure SSL verification properly.
    // For development, you might temporarily disable strict checks if using self-signed certs.
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // NOT FOR PRODUCTION
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // NOT FOR PRODUCTION

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => true, 'code' => 'CURL_ERROR', 'message' => 'cURL Error: ' . $curlError, 'http_code' => $httpCode];
    }

    $decodedResponse = json_decode($response, true);

    if ($httpCode >= 400) {
         return ['error' => true, 'code' => 'INTERNAL_API_ERROR', 'message' => 'Internal API returned an error.', 'http_code' => $httpCode, 'response_body' => $decodedResponse ?: $response];
    }
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => true, 'code' => 'INVALID_JSON_RESPONSE', 'message' => 'Internal API returned invalid JSON.', 'http_code' => $httpCode, 'response_body' => $response];
    }

    return $decodedResponse;
}


// --- Request Handling ---

// 1. Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error_response(405, 'METHOD_NOT_ALLOWED', 'Only POST requests are accepted.');
}

// 2. Agent-to-Gateway Authentication
$agentApiKey = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $agentApiKey = $matches[1];
    }
} elseif (isset($_SERVER['HTTP_X_AGENT_API_KEY'])) {
    $agentApiKey = $_SERVER['HTTP_X_AGENT_API_KEY'];
}
// DEBUG START - Extracted API Key
$debug_timestamp_extraction = date('[Y-m-d H:i:s] ');
$log_message_extracted_key = $debug_timestamp_extraction . "Attempted Extracted Agent API Key: " . ($agentApiKey ? $agentApiKey : 'NULL or Empty') . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_extracted_key, FILE_APPEND | LOCK_EX);
// DEBUG END - Extracted API Key

if (!$agentApiKey) {
    send_error_response(401, 'AUTHENTICATION_FAILED', 'API key is missing.');
}

// Prepare to query the database for the API key
// Ensure $conn is available from db_connect.php
if (!isset($conn) || ($conn instanceof mysqli && $conn->connect_error) || ($conn instanceof PDO && !$conn)) {
    // Log this critical error, as it means the gateway cannot function
    error_log("Gateway Error: Database connection not available or failed.");
    send_error_response(500, 'INTERNAL_SERVER_ERROR', 'Database connection error. Cannot authenticate agent.');
}

$stmt = null;
$keyRecord = null;

try {
    if ($conn instanceof mysqli) {
        // Using a prepared statement to prevent SQL injection, even though we are not directly inserting the key
        // We are looking for ANY key, then verifying. A more direct SELECT on a hashed key might be better if feasible.
        // However, password_verify needs the plain key and the hash. So we fetch potential hashes.
        // For this phase, we'll fetch all non-revoked keys and iterate. This is NOT ideal for performance with many keys.
        // A better approach for Phase 2 would be to require the agent_name or a key_id as part of the request
        // or implement a more direct lookup if the raw key can be part of a secure lookup mechanism (e.g. HMAC against a known part of the key).
        // For now, fetching all and verifying is simpler given the `password_verify` constraint.
        $sql = "SELECT id, agent_name, key_hash, associated_user_id, associated_site_id, permissions, revoked_at FROM agent_api_keys WHERE revoked_at IS NULL";
        $stmt = $conn->prepare($sql);
        // No parameters to bind for this broad select
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (password_verify($agentApiKey, $row['key_hash'])) {
                $keyRecord = $row;
                break; // Found a matching, valid key
            }
        }
        $stmt->close();
    } elseif ($conn instanceof PDO) {
        $sql = "SELECT id, agent_name, key_hash, associated_user_id, associated_site_id, permissions, revoked_at FROM agent_api_keys WHERE revoked_at IS NULL";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (password_verify($agentApiKey, $row['key_hash'])) {
                $keyRecord = $row;
                break;
            }
        }
        $stmt->closeCursor(); // PDO way
    }
} catch (Exception $e) {
    error_log("Gateway DB Error: " . $e->getMessage());
    send_error_response(500, 'DATABASE_ERROR', 'Error during agent authentication.');
}


// DEBUG START - DB Query Check
$debug_timestamp_db_check = date('[Y-m-d H:i:s] ');
$log_message_db_key_lookup = $debug_timestamp_db_check . "DB Check: API Key used for lookup: " . ($agentApiKey ? $agentApiKey : 'NULL or Empty') . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_db_key_lookup, FILE_APPEND | LOCK_EX);

$log_message_db_found_record = $debug_timestamp_db_check . "DB Check: Key Record Found in agent_api_keys: " . ($keyRecord ? 'Yes (ID: ' . $keyRecord['id'] . ', Agent: ' . $keyRecord['agent_name'] . ')' : 'No') . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_db_found_record, FILE_APPEND | LOCK_EX);
// DEBUG END - DB Query Check
if (!$keyRecord) {
    send_error_response(401, 'AUTHENTICATION_FAILED', 'Invalid or revoked API key.');
}

// Authentication successful, store agent details for potential use (Phase 2 permissions)
$authenticated_agent_info = [
    'id' => $keyRecord['id'],
    'name' => $keyRecord['agent_name'],
    'associated_user_id' => $keyRecord['associated_user_id'],
    'associated_site_id' => $keyRecord['associated_site_id'],
    'permissions' => $keyRecord['permissions'] // For future use
];

// Update last_used_at for the key
try {
    $updateSql = "UPDATE agent_api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?";
    if ($conn instanceof mysqli) {
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param('i', $keyRecord['id']);
        $updateStmt->execute();
        $updateStmt->close();
    } elseif ($conn instanceof PDO) {
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$keyRecord['id']]);
    }
} catch (Exception $e) {
    // Log this, but don't fail the request if updating last_used_at fails
    error_log("Gateway Info: Failed to update last_used_at for agent_api_key ID " . $keyRecord['id'] . ": " . $e->getMessage());
}


// 3. Parse Request Body
$requestBody = file_get_contents('php://input');
$requestData = json_decode($requestBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    send_error_response(400, 'INVALID_REQUEST_FORMAT', 'Invalid JSON in request body.', ['json_error' => json_last_error_msg()]);
}

if (!isset($requestData['action']) || !is_string($requestData['action'])) {
    send_error_response(400, 'MISSING_ACTION', 'Required "action" parameter is missing or not a string.');
}

$action = $requestData['action'];
$params = isset($requestData['params']) && is_array($requestData['params']) ? $requestData['params'] : [];

// --- Action Routing & Mapping ---

switch ($action) {
    case 'fetchCheckinDetails':
        if (!isset($params['checkin_id'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "checkin_id" for action "fetchCheckinDetails".');
        }
        $checkinId = $params['checkin_id'];
        // Basic validation, can be more robust
        if (!is_numeric($checkinId) || $checkinId <= 0) {
             send_error_response(400, 'INVALID_PARAMS', '"checkin_id" must be a positive integer.');
        }

        $internalResponse = call_internal_api('GET', '/checkins/' . intval($checkinId));
        
        if (isset($internalResponse['error'])) {
            send_error_response(
                $internalResponse['http_code'] ?: 500, 
                'INTERNAL_API_ERROR', 
                $internalResponse['message'],
                isset($internalResponse['response_body']) ? ['internal_response' => $internalResponse['response_body']] : null
            );
        }
        send_success_response($internalResponse);
        break;

    case 'queryClients':
        // Optional parameters: lastName, firstName, email, qr_identifier, page, limit
        $allowedParams = ['lastName', 'firstName', 'email', 'qr_identifier', 'page', 'limit'];
        $queryParams = [];
        foreach ($allowedParams as $paramName) {
            if (isset($params[$paramName])) {
                $queryParams[$paramName] = $params[$paramName];
            }
        }

        $internalResponse = call_internal_api('GET', '/clients', $queryParams);

        if (isset($internalResponse['error'])) {
             send_error_response(
                $internalResponse['http_code'] ?: 500, 
                'INTERNAL_API_ERROR', 
                $internalResponse['message'],
                isset($internalResponse['response_body']) ? ['internal_response' => $internalResponse['response_body']] : null
            );
        }
        send_success_response($internalResponse);
        break;

    default:
        send_error_response(404, 'ACTION_NOT_FOUND', 'The requested action "' . htmlspecialchars($action) . '" is not implemented.');
        break;
}

?>