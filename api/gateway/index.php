<?php
// api/gateway/index.php
// Entry point for the Unified API Gateway

// --- Configuration & Includes ---
header('Content-Type: application/json');

// IMPORTANT: Replace with the actual path to your database connection script
// Assuming db_connect.php provides a $conn (mysqli or PDO) object
require_once __DIR__ . '/../../includes/db_connect.php';

// DEBUG START - PDO Status After Include
$debug_timestamp_after_include = date('[Y-m-d H:i:s] ');
$pdo_status_in_gateway = 'Not set or invalid in gateway';
if (isset($pdo)) {
    if ($pdo instanceof PDO) {
        $pdo_status_in_gateway = 'PDO object successfully available in gateway.';
        // Optionally, you could try a minimal check like $pdo->getAttribute(PDO::ATTR_SERVER_INFO)
        // but be cautious of exceptions if connection truly failed in db_connect.php
        try {
            $server_info = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); // Light check
            $pdo_status_in_gateway .= ' Server version: ' . $server_info;
        } catch (PDOException $e) {
            $pdo_status_in_gateway .= ' Error during light check: ' . $e->getMessage();
        }
    } else {
        $pdo_status_in_gateway = 'Variable $pdo is set in gateway, but not a PDO object. Type: ' . gettype($pdo);
    }
}
$log_message_pdo_in_gateway = $debug_timestamp_after_include . "Gateway: PDO Status After Include: " . $pdo_status_in_gateway . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_pdo_in_gateway, FILE_APPEND | LOCK_EX);
// DEBUG END - PDO Status After Include

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
// Ensure $pdo is available from db_connect.php
// DEBUG START - Database Connection Status Before Agent Auth Query
$debug_timestamp_db_auth_check = date('[Y-m-d H:i:s] ');
$db_auth_conn_status = 'Not set or invalid before agent auth query';
$db_auth_conn_error = '';
if (isset($pdo)) {
    if ($pdo instanceof PDO) {
        $db_auth_conn_status = 'PDO object available before agent auth query.';
        try {
            // A light check to see if the connection is alive
            $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            $db_auth_conn_status .= ' (Connection attribute readable)';
        } catch (PDOException $e) {
            $db_auth_conn_status .= ' (Error getting connection attribute: ' . $e->getMessage() . ')';
            $db_auth_conn_error = $e->getMessage();
        }
    } else {
        $db_auth_conn_status = '$pdo is set, but not a PDO object before agent auth query. Type: ' . gettype($pdo);
    }
}
$log_message_db_auth_conn = $debug_timestamp_db_auth_check . "Gateway Auth: DB Connection Status: " . $db_auth_conn_status . ($db_auth_conn_error ? " Error: " . $db_auth_conn_error : "") . PHP_EOL;
file_put_contents(__DIR__ . '/debug.log', $log_message_db_auth_conn, FILE_APPEND | LOCK_EX);
// DEBUG END - Database Connection Status Before Agent Auth Query

if (!isset($pdo) || !($pdo instanceof PDO)) { // Simplified check for PDO
    // Log this critical error, as it means the gateway cannot function
    error_log("Gateway Error: PDO Database connection not available or not a PDO object before agent auth query.");
    send_error_response(500, 'INTERNAL_SERVER_ERROR', 'Database connection error. Cannot authenticate agent.');
}

$stmt = null;
$keyRecord = null;

try {
    // Since db_connect.php establishes a PDO connection into $pdo, we should only use the PDO path.
    // The mysqli path ($conn instanceof mysqli) is now effectively dead code if db_connect.php is consistent.
    // We will proceed assuming $pdo is the correct variable and it's a PDO instance.

    // if ($conn instanceof mysqli) { ... mysqli specific code removed for brevity ... }
    
    // elseif ($conn instanceof PDO) { // This will now be the primary path
    if ($pdo instanceof PDO) { // Corrected to use $pdo
        $sql = "SELECT id, agent_name, key_hash, associated_user_id, associated_site_id, permissions, revoked_at FROM agent_api_keys WHERE revoked_at IS NULL";

        // DEBUG START - SQL Query Logging (PDO)
        $debug_timestamp_sql_pdo = date('[Y-m-d H:i:s] ');
        $log_message_sql_pdo = $debug_timestamp_sql_pdo . "SQL Query (PDO): " . $sql . PHP_EOL;
        file_put_contents(__DIR__ . '/debug.log', $log_message_sql_pdo, FILE_APPEND | LOCK_EX);
        // DEBUG END - SQL Query Logging (PDO)

        $stmt = $pdo->prepare($sql); // Corrected to use $pdo

        // DEBUG START - Query Preparation Status (PDO)
        $debug_timestamp_prep_pdo = date('[Y-m-d H:i:s] ');
        $log_message_prep_pdo = $debug_timestamp_prep_pdo . "Query Preparation (PDO): " . ($stmt ? "Successful" : "Failed - " . json_encode($pdo->errorInfo())) . PHP_EOL; // Corrected to use $pdo
        file_put_contents(__DIR__ . '/debug.log', $log_message_prep_pdo, FILE_APPEND | LOCK_EX);
        // DEBUG END - Query Preparation Status (PDO)

        if ($stmt) {
            $exec_success = $stmt->execute();

            // DEBUG START - Query Execution Result (PDO)
            $debug_timestamp_exec_pdo = date('[Y-m-d H:i:s] ');
            $log_message_exec_pdo = $debug_timestamp_exec_pdo . "Query Execution (PDO): " . ($exec_success ? "Successful" : "Failed - " . json_encode($stmt->errorInfo())) . PHP_EOL;
            file_put_contents(__DIR__ . '/debug.log', $log_message_exec_pdo, FILE_APPEND | LOCK_EX);
            // DEBUG END - Query Execution Result (PDO)

            if ($exec_success) {
                 // PDO doesn't have a direct num_rows equivalent for SELECT * without fetching first.
                 // We'll log row count after fetching.

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // DEBUG START - Row Data and password_verify (PDO)
                    $debug_timestamp_row_pdo = date('[Y-m-d H:i:s] ');
                    $log_message_row_pdo = $debug_timestamp_row_pdo . "Fetched Row (PDO): " . json_encode($row) . PHP_EOL;
                    file_put_contents(__DIR__ . '/debug.log', $log_message_row_pdo, FILE_APPEND | LOCK_EX);

                    $key_hash_pdo = isset($row['key_hash']) ? $row['key_hash'] : 'N/A';
                    $log_message_hash_pdo = $debug_timestamp_row_pdo . "Key Hash from DB (PDO): " . $key_hash_pdo . PHP_EOL;
                    file_put_contents(__DIR__ . '/debug.log', $log_message_hash_pdo, FILE_APPEND | LOCK_EX);

                    $password_verify_result_pdo = password_verify($agentApiKey, $row['key_hash']);
                    $log_message_verify_pdo = $debug_timestamp_row_pdo . "password_verify result (PDO): " . ($password_verify_result_pdo ? 'True' : 'False') . PHP_EOL;
                    file_put_contents(__DIR__ . '/debug.log', $log_message_verify_pdo, FILE_APPEND | LOCK_EX);
                    // DEBUG END - Row Data and password_verify (PDO)

                    if ($password_verify_result_pdo) {
                        $keyRecord = $row;
                        break;
                    }
                }
                 // DEBUG START - Row Count (PDO) - Logged after fetching
                $debug_timestamp_rows_pdo = date('[Y-m-d H:i:s] ');
                // To get row count in PDO after fetching, you might need to rewind or re-query,
                // or fetch all into an array first. For simple logging, we'll just note if a record was found.
                // A more accurate count would require fetching all into an array and counting.
                $log_message_rows_pdo = $debug_timestamp_rows_pdo . "Row Count (PDO): Logged after fetching. Check if keyRecord is set below." . PHP_EOL;
                file_put_contents(__DIR__ . '/debug.log', $log_message_rows_pdo, FILE_APPEND | LOCK_EX);
                // DEBUG END - Row Count (PDO)
            }
        }
        if ($stmt) {
             $stmt->closeCursor(); // PDO way
        }
    } else {
        // This case should ideally not be reached if db_connect.php always provides a PDO $pdo object
        // or exits on failure.
        $debug_timestamp_no_db_type = date('[Y-m-d H:i:s] ');
        $log_message_no_db_type = $debug_timestamp_no_db_type . "Gateway Auth: DB object \$pdo was not a PDO instance. Type: " . (isset($pdo) ? gettype($pdo) : 'not set') . PHP_EOL;
        file_put_contents(__DIR__ . '/debug.log', $log_message_no_db_type, FILE_APPEND | LOCK_EX);
        // This implies a problem with db_connect.php or the $pdo variable being overwritten.
        // The earlier check `if (!isset($pdo) || !($pdo instanceof PDO))` should catch this.
    }
} catch (Exception $e) {
    // DEBUG START - Exception Logging
    $debug_timestamp_exception = date('[Y-m-d H:i:s] ');
    $log_message_exception = $debug_timestamp_exception . "Caught Exception during DB interaction: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . PHP_EOL;
    file_put_contents(__DIR__ . '/debug.log', $log_message_exception, FILE_APPEND | LOCK_EX);
    // DEBUG END - Exception Logging
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
    // Assuming $pdo is a PDO object if we've reached this point
    if ($pdo instanceof PDO) { // Explicitly check, though it should be
        $updateStmt = $pdo->prepare($updateSql); // Corrected to use $pdo
        $updateStmt->execute([$keyRecord['id']]);
    } else {
        // Log if $pdo is not what we expect, though previous checks should prevent this.
        error_log("Gateway Info: Failed to update last_used_at because \$pdo was not a PDO instance for agent_api_key ID " . $keyRecord['id']);
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