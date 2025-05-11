<?php
// api/gateway/index.php
// Entry point for the Unified API Gateway

// --- Configuration & Includes ---
header('Content-Type: application/json');

// IMPORTANT: Replace with the actual path to your database connection script
// Assuming db_connect.php provides a $conn (mysqli or PDO) object
require_once __DIR__ . '/../../includes/db_connect.php';



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

if (!$agentApiKey) {
    send_error_response(401, 'AUTHENTICATION_FAILED', 'API key is missing.');
}

// Prepare to query the database for the API key
// Ensure $pdo is available from db_connect.php

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


        $stmt = $pdo->prepare($sql); // Corrected to use $pdo


        if ($stmt) {
            $exec_success = $stmt->execute();


            if ($exec_success) {
                 // PDO doesn't have a direct num_rows equivalent for SELECT * without fetching first.
                 // We'll log row count after fetching.

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // Verify the provided API key against the hashed key from the database
                    if (password_verify($agentApiKey, $row['key_hash'])) {
                        $keyRecord = $row;
                        break;
                    }
                }
            }
        }
        if ($stmt) {
             $stmt->closeCursor(); // PDO way
        }
    } else {
        // This case should ideally not be reached if db_connect.php always provides a PDO $pdo object
        // or exits on failure.
        // This implies a problem with db_connect.php or the $pdo variable being overwritten.
        // The earlier check `if (!isset($pdo) || !($pdo instanceof PDO))` should catch this.
    }
} catch (Exception $e) {
    error_log("Gateway DB Error: " . $e->getMessage());
    send_error_response(500, 'DATABASE_ERROR', 'Error during agent authentication.');
}


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

$action = lcfirst(trim($requestData['action']));
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

    case 'queryCheckins':
        // Permissions: read:all_checkin_data, read:site_checkin_data
        // Optional params: site_id, start_date, end_date, limit, page
        $allowedV1Params = ['site_id', 'start_date', 'end_date', 'limit', 'page'];
        $v1QueryParams = [];

        // Apply granular permission for site_id if agent has an associated_site_id
        if (!empty($authenticated_agent_info['associated_site_id'])) {
            // If agent's key is tied to a specific site, enforce it.
            // This overrides any site_id provided in params, as per initial simpler approach.
            $v1QueryParams['site_id'] = $authenticated_agent_info['associated_site_id'];
        } elseif (isset($params['site_id'])) {
            // Only use agent-provided site_id if their key is not restricted to a specific site.
            $v1QueryParams['site_id'] = $params['site_id'];
        }

        // Process other allowed parameters
        foreach ($allowedV1Params as $paramName) {
            if ($paramName === 'site_id' && isset($v1QueryParams['site_id'])) {
                continue; // site_id already handled by permission logic
            }
            if (isset($params[$paramName])) {
                $v1QueryParams[$paramName] = $params[$paramName];
            }
        }
        
        $internalResponse = call_internal_api('GET', '/checkins', $v1QueryParams);

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

    case 'addCheckinNote':
        // Permission: create:checkin_note (handled by V1 API based on gateway's internal key)
        // Required params: checkin_id, note_text
        if (!isset($params['checkin_id'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "checkin_id" for action "addCheckinNote".');
        }
        if (!isset($params['note_text'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "note_text" for action "addCheckinNote".');
        }

        $checkinId = $params['checkin_id'];
        $noteText = $params['note_text'];

        if (!is_numeric($checkinId) || $checkinId <= 0) {
            send_error_response(400, 'INVALID_PARAMS', '"checkin_id" must be a positive integer for "addCheckinNote".');
        }
        if (!is_string($noteText) || empty(trim($noteText))) {
            send_error_response(400, 'INVALID_PARAMS', '"note_text" must be a non-empty string for "addCheckinNote".');
        }

        $internalApiParams = ['note_text' => $noteText];
        $internalResponse = call_internal_api('POST', '/checkins/' . intval($checkinId) . '/notes', $internalApiParams);

        if (isset($internalResponse['error'])) {
            send_error_response(
                $internalResponse['http_code'] ?: 500,
                'INTERNAL_API_ERROR',
                $internalResponse['message'],
                isset($internalResponse['response_body']) ? ['internal_response' => $internalResponse['response_body']] : null
            );
        }
        // V1 API for addCheckinNote returns 201 Created with the note data.
        // send_success_response by default sends 200. We should align if possible,
        // or ensure the agent understands the gateway's 200 means the V1's 201 was successful.
        // For now, stick to existing send_success_response which sends 200.
        send_success_response($internalResponse, 'Check-in note added successfully.'); // Or use $internalResponse directly if it contains a success message
        break;

    case 'queryAllocations':
        // Permissions: read:budget_allocations, read:all_allocation_data, read:own_allocation_data
        // Optional params: fiscal_year, grant_id, user_id, department_id, budget_id, page, limit
        $allowedV1Params = ['fiscal_year', 'grant_id', 'user_id', 'department_id', 'budget_id', 'page', 'limit'];
        $v1QueryParams = [];

        // Apply granular permission for user_id if agent has an associated_user_id
        // This is relevant for the 'read:own_allocation_data' scope.
        // The V1 API /allocations endpoint is expected to filter by user_id if provided.
        if (!empty($authenticated_agent_info['associated_user_id'])) {
            // If agent's key is tied to a specific user, enforce it for 'own_allocation_data' scenarios.
            // The V1 API should handle filtering if user_id is passed.
            // We assume if associated_user_id is present, it's for "own data" type access.
            $v1QueryParams['user_id'] = $authenticated_agent_info['associated_user_id'];
        } elseif (isset($params['user_id'])) {
            // Only use agent-provided user_id if their key is not restricted to a specific user.
            $v1QueryParams['user_id'] = $params['user_id'];
        }
        
        // Process other allowed parameters
        foreach ($allowedV1Params as $paramName) {
            if ($paramName === 'user_id' && isset($v1QueryParams['user_id'])) {
                continue; // user_id already handled by permission logic
            }
            if (isset($params[$paramName])) {
                $v1QueryParams[$paramName] = $params[$paramName];
            }
        }

        $internalResponse = call_internal_api('GET', '/allocations', $v1QueryParams);

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

    case 'createForumPost':
        // Permission: create:forum_post (handled by V1 API)
        // Required params: topic_id, post_body
        if (!isset($params['topic_id'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "topic_id" for action "createForumPost".');
        }
        if (!isset($params['post_body'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "post_body" for action "createForumPost".');
        }

        $topicId = $params['topic_id'];
        $postBody = $params['post_body'];

        if (!is_numeric($topicId) || $topicId <= 0) {
            send_error_response(400, 'INVALID_PARAMS', '"topic_id" must be a positive integer for "createForumPost".');
        }
        if (!is_string($postBody) || empty(trim($postBody))) {
            send_error_response(400, 'INVALID_PARAMS', '"post_body" must be a non-empty string for "createForumPost".');
        }

        $internalApiParams = ['topic_id' => intval($topicId), 'post_body' => $postBody];
        $internalResponse = call_internal_api('POST', '/forum/posts', $internalApiParams);

        if (isset($internalResponse['error'])) {
            send_error_response(
                $internalResponse['http_code'] ?: 500,
                'INTERNAL_API_ERROR',
                $internalResponse['message'],
                isset($internalResponse['response_body']) ? ['internal_response' => $internalResponse['response_body']] : null
            );
        }
        send_success_response($internalResponse, 'Forum post created successfully.');
        break;

    case 'generateReports':
        // Permission: generate:reports (V1 API handles specific report type permissions)
        // Required param: type
        // Optional params: start_date, end_date, site_id, limit, page (common)
        if (!isset($params['type'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "type" for action "generateReports".');
        }
        if (!is_string($params['type']) || empty(trim($params['type']))) {
            send_error_response(400, 'INVALID_PARAMS', '"type" must be a non-empty string for "generateReports".');
        }

        $v1QueryParams = ['type' => $params['type']];
        $allowedV1Params = ['start_date', 'end_date', 'site_id', 'limit', 'page']; // Add other V1 supported params as needed

        // Apply granular permission for site_id if agent has an associated_site_id
        if (!empty($authenticated_agent_info['associated_site_id'])) {
            $v1QueryParams['site_id'] = $authenticated_agent_info['associated_site_id'];
        } elseif (isset($params['site_id'])) {
            $v1QueryParams['site_id'] = $params['site_id'];
        }

        foreach ($allowedV1Params as $paramName) {
            if ($paramName === 'site_id' && isset($v1QueryParams['site_id'])) {
                continue;
            }
            if (isset($params[$paramName])) {
                $v1QueryParams[$paramName] = $params[$paramName];
            }
        }

        $internalResponse = call_internal_api('GET', '/reports', $v1QueryParams);

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

    case 'readAllForumPosts':
        // Permission: read:all_forum_posts (handled by V1 API)
        // Optional params: page, limit
        $allowedV1Params = ['page', 'limit'];
        $v1QueryParams = [];
        foreach ($allowedV1Params as $paramName) {
            if (isset($params[$paramName])) {
                $v1QueryParams[$paramName] = $params[$paramName];
            }
        }

        $internalResponse = call_internal_api('GET', '/forum/posts', $v1QueryParams);

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

    case 'readRecentForumPosts':
        // Permission: read:recent_forum_posts (handled by V1 API)
        // Optional params: limit
        $allowedV1Params = ['limit'];
        $v1QueryParams = [];
        foreach ($allowedV1Params as $paramName) {
            if (isset($params[$paramName])) {
                $v1QueryParams[$paramName] = $params[$paramName];
            }
        }

        $internalResponse = call_internal_api('GET', '/forum/posts/recent', $v1QueryParams);

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

    case 'fetchClientDetails':
        // Permission: read:client_data (handled by V1 API)
        // Required param: client_id
        if (!isset($params['client_id'])) {
            send_error_response(400, 'INVALID_PARAMS', 'Missing required parameter "client_id" for action "fetchClientDetails".');
        }
        $clientId = $params['client_id'];
        if (!is_numeric($clientId) || $clientId <= 0) {
            send_error_response(400, 'INVALID_PARAMS', '"client_id" must be a positive integer for "fetchClientDetails".');
        }

        $internalResponse = call_internal_api('GET', '/clients/' . intval($clientId));

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