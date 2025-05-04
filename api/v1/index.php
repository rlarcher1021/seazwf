<?php

// --- Configuration & Includes ---

// Set default timezone (optional, but good practice)
date_default_timezone_set('America/Phoenix'); // Or your preferred timezone

// Include necessary files
// Assuming db_connect.php is in the root 'includes' directory
require_once __DIR__ . '/../../includes/db_connect.php'; // Provides $pdo
require_once __DIR__ . '/includes/error_handler.php'; // Provides sendJsonError

// --- Database Connection Check ---
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Log the error for server-side details
    error_log("CRITICAL API ERROR: Failed to establish PDO database connection in api/v1/index.php.");
    // Send a generic error response - Ensure error_handler.php is included before this point
    // Check if function exists before calling, in case error_handler failed to load
    if (function_exists('sendJsonError')) {
        sendJsonError(500, 'Database connection failed. Please contact administrator.', 'DB_CONNECTION_FAILED');
    } else {
        // Fallback if error handler isn't available
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed and error handler unavailable.']);
    }
    // Exit script to prevent further execution without a DB connection
    exit;
}
// --- End Database Connection Check ---

require_once __DIR__ . '/includes/auth_functions.php'; // Uses $pdo
require_once __DIR__ . '/data_access/allocation_data_api.php'; // Uses $pdo
// require_once __DIR__ . '/data_access/forum_data_api.php'; // REMOVED: Included conditionally or by specific handlers (e.g., POST /forum/posts) to avoid redeclaration errors.
require_once __DIR__ . '/handlers/report_handler.php'; // Uses $pdo, auth_functions, error_handler
require_once __DIR__ . '/handlers/forum_handler.php'; // Uses $pdo, auth_functions, error_handler, includes/data_access/forum_data.php

// --- Global Error Handling ---
// Set a top-level exception handler to catch unhandled errors
set_exception_handler(function ($exception) {
    // Log the detailed error
    error_log("Unhandled API Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    // Send a generic 500 error response
    if (function_exists('sendJsonError')) {
        sendJsonError(500, "An internal server error occurred.", "INTERNAL_SERVER_ERROR");
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Internal server error and error handler unavailable.']);
    }
});

// --- Request Parsing ---

// Get request method
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Get request URI and parse the path
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api/v1'; // Define the base path for this API version

// Remove base path and query string to get the route path
$routePath = parse_url($requestUri, PHP_URL_PATH);

// Find the position of the base path
$basePathPos = strpos($routePath, $basePath);
if ($basePathPos !== false) {
    // If found, take the part after the base path
    $routePath = substr($routePath, $basePathPos + strlen($basePath));
    // ADDED: Explicitly remove potential /index.php prefix if present right after base path
    if (strpos($routePath, '/index.php') === 0) {
         $routePath = substr($routePath, strlen('/index.php'));
    }
}
// If basePath wasn't found, $routePath remains unchanged here.

// Ensure leading slash and remove trailing slash for consistency
$routePath = '/' . trim($routePath, '/');

// --- Routing ---

// Handle specific parameterized routes first using regex
if ($requestMethod === 'GET' && preg_match('#^/checkins/(\d+)$#', $routePath, $matches)) {
    $checkinId = (int)$matches[1];

    // Validate ID format (must be a positive integer)
    if ($checkinId <= 0) {
         sendJsonError(400, 'Invalid Check-in ID format. ID must be a positive integer.', 'INVALID_ID_FORMAT');
    }

    // --- Authentication ---
    $apiKeyData = authenticateApiKey($pdo); // Pass $pdo
    if ($apiKeyData === false) {
        // Explicitly handle authentication failure here, as authenticateApiKey doesn't always exit.
        sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
        exit;
    }

    // --- Authorization ---
    $requiredPermission = "read:checkin_data"; // As per Living Plan
    if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
        // If permission check fails, send 403 Forbidden
        sendJsonError(403, "Permission denied. API key requires the '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
        exit;
    }

    // --- Endpoint Logic: Fetch Check-in Data ---
    try {
        // Prepare statement using PDO
        $stmt = $pdo->prepare("SELECT * FROM check_ins WHERE id = :id");
        $stmt->bindParam(':id', $checkinId, PDO::PARAM_INT);
        $stmt->execute();

        // Fetch using PDO
        $checkinData = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch a single row

        if ($checkinData) { // Check if data was found
            // Record found
            // Set headers and output JSON
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode($checkinData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } else {
            // Record not found
            sendJsonError(404, "Check-in not found for ID: {$checkinId}.", "NOT_FOUND");
        }
        // No need to close PDO statement explicitly

    } catch (PDOException $e) { // Catch PDOException
        // Catch specific database errors
        error_log("API Database Error (GET /checkins/{$checkinId}): " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
        sendJsonError(500, 'Database error occurred while fetching check-in data.', 'DB_EXECUTION_ERROR');
    } catch (Exception $e) {
         // Catch any other unexpected errors during logic execution
         error_log("API Logic Error (GET /checkins/{$checkinId}): " . $e->getMessage());
         sendJsonError(500, 'An unexpected error occurred processing the request.', 'UNEXPECTED_ERROR');
    }

    exit; // Crucial: Stop script execution after handling the request

// --- Route: Add Check-in Note ---
} elseif ($requestMethod === 'POST' && preg_match('#^/checkins/(\d+)/notes$#', $routePath, $matches)) {
    $checkinId = (int)$matches[1];

    // Validate ID format (must be a positive integer)
    if ($checkinId <= 0) {
         sendJsonError(400, 'Invalid Check-in ID format. ID must be a positive integer.', 'INVALID_ID_FORMAT');
    }

    // --- Authentication ---
    $apiKeyData = authenticateApiKey($pdo); // Pass $pdo
    if ($apiKeyData === false) {
        // Explicitly handle authentication failure here.
        sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
        exit;
    }

    // --- Authorization ---
    $requiredPermission = "create:checkin_note"; // As per Living Plan
    if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
        // If permission check fails, send 403 Forbidden
        sendJsonError(403, "Permission denied. API key requires the '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
        exit;
    }

    // --- Endpoint Logic: Add Check-in Note ---
    try {
        // 1. Get and Validate JSON Payload
        $jsonPayload = file_get_contents('php://input');
        $data = json_decode($jsonPayload, true); // true for associative array

        if (json_last_error() !== JSON_ERROR_NONE) {
            sendJsonError(400, 'Invalid JSON payload: ' . json_last_error_msg(), 'INVALID_JSON');
        }

        if (!isset($data['note_text']) || trim($data['note_text']) === '') {
            sendJsonError(400, 'Missing or empty note_text.', 'MISSING_NOTE_TEXT');
        }
        $noteText = trim($data['note_text']);
        $apiKeyId = $apiKeyData['id']; // Get the ID from authenticated key

        // 2. Check if Check-in Exists (using PDO)
        $stmtCheck = $pdo->prepare("SELECT id FROM check_ins WHERE id = :id");
        $stmtCheck->bindParam(':id', $checkinId, PDO::PARAM_INT);
        $stmtCheck->execute();
        if ($stmtCheck->fetchColumn() === false) { // Check if any row was returned
            // No need to close PDO statement explicitly
            sendJsonError(404, "Check-in not found for ID: {$checkinId}.", "CHECKIN_NOT_FOUND");
        }
        // No need to close PDO statement explicitly

        // 3. Insert Note (using PDO)
        $sqlInsert = "INSERT INTO checkin_notes (check_in_id, note_text, created_by_api_key_id, created_at) VALUES (:check_in_id, :note_text, :api_key_id, NOW())";
        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->bindParam(':check_in_id', $checkinId, PDO::PARAM_INT);
        $stmtInsert->bindParam(':note_text', $noteText, PDO::PARAM_STR);
        $stmtInsert->bindParam(':api_key_id', $apiKeyId, PDO::PARAM_INT);

        if (!$stmtInsert->execute()) {
             // PDOException will be caught below if execute fails
             // Log specific insert error? PDOException message should be logged by catch block.
             // No need to close PDO statement explicitly
             // Let exception handler catch and log
             throw new PDOException("Database error during note insertion."); // Throw exception
        }
        $newNoteId = $pdo->lastInsertId(); // Get the ID of the inserted note
        // No need to close PDO statement explicitly

        // 4. Fetch the Created Note (using PDO)
        $stmtFetch = $pdo->prepare("SELECT * FROM checkin_notes WHERE id = :id");
        $stmtFetch->bindParam(':id', $newNoteId, PDO::PARAM_INT);
        $stmtFetch->execute();
        $createdNote = $stmtFetch->fetch(PDO::FETCH_ASSOC); // Fetch single row

        if ($createdNote) { // Check if fetch was successful
             // No need to close PDO statement explicitly

            // 5. Send Response
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(201); // Created
            echo json_encode($createdNote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } else {
             // No need to close PDO statement explicitly
             // This case should ideally not happen if insert succeeded, but handle defensively
             error_log("API Error: Failed to fetch newly created note (ID: {$newNoteId}) after insertion.");
             sendJsonError(500, 'Failed to retrieve the created note after insertion.', 'FETCH_AFTER_INSERT_FAILED');
        }

    } catch (PDOException $e) { // Catch PDOException
        // Catch specific database errors during the process
        error_log("API Database Error (POST /checkins/{$checkinId}/notes): " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
        sendJsonError(500, 'Database error occurred while adding check-in note.', 'DB_ERROR');
    } catch (Exception $e) {
         // Catch any other unexpected errors during logic execution
         error_log("API Logic Error (POST /checkins/{$checkinId}/notes): " . $e->getMessage());
         sendJsonError(500, 'An unexpected error occurred processing the request.', 'UNEXPECTED_ERROR');
    }

    exit; // Crucial: Stop script execution after handling the request

// --- NEW ROUTE FOR REPORTS (Corrected Routing) ---
} elseif ($requestMethod === 'GET' && $routePath === '/reports') { // Match exact path /reports

    // --- Authentication ---
    $apiKeyData = authenticateApiKey($pdo); // Pass $pdo
    if ($apiKeyData === false) {
        // Explicitly handle authentication failure here.
         sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
         exit; // Ensure exit after sending error
    }

    // --- Authorization (Base Permission) ---
    $requiredPermission = "generate:reports"; // As per Living Plan
    if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
        // If permission check fails, send 403 Forbidden
        sendJsonError(403, "Permission denied. API key requires the '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
        exit; // Ensure exit after sending error
    }

    // --- Endpoint Logic (Call Handler) ---
    try {
        // Get query parameters
        $queryParams = $_GET;

        // Call the handler function from report_handler.php
        $reportResult = handleGetReports($pdo, $apiKeyData, $queryParams);

        // Send success response
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode($reportResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    } catch (InvalidArgumentException $e) {
        // Handle bad request errors (400) or permission errors (403) from the handler
        $statusCode = $e->getCode() === 403 ? 403 : 400; // Use code from exception if 403
        $errorCode = $statusCode === 403 ? 'FORBIDDEN' : 'BAD_REQUEST';
        // Log permission errors specifically if desired
        if($statusCode === 403) {
            error_log("API Permission Error (GET /reports): " . $e->getMessage() . " for API Key ID: " . ($apiKeyData['id'] ?? 'unknown'));
        }
        sendJsonError($statusCode, $e->getMessage(), $errorCode);
    } catch (RuntimeException $e) {
        // Handle internal server errors (500) from the handler
        error_log("API Runtime Error (GET /reports): " . $e->getMessage());
        sendJsonError(500, 'An internal error occurred while generating the report.', 'REPORT_GENERATION_ERROR');
    } catch (Exception $e) {
        // Catch any other unexpected errors during handler execution
        error_log("API Unexpected Error (GET /reports): " . $e->getMessage());
        sendJsonError(500, 'An unexpected error occurred processing the report request.', 'UNEXPECTED_REPORT_ERROR');
    }

    exit; // Crucial: Stop script execution after handling the request

} else {
     // --- Fallback to switch for non-parameterized routes ---
     switch ($routePath) {
         case '/example':
             if ($requestMethod === 'GET') {
                 // 1. Authentication
            $apiKeyData = authenticateApiKey($pdo); // Pass $pdo
            if ($apiKeyData === false) {
                // Explicitly handle authentication failure here.
                sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
                exit;
            }

            // 2. Authorization
            $requiredPermission = "read:example"; // Placeholder permission
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // If permission check fails, send 403 Forbidden
                sendJsonError(403, "Permission denied. API key does not have the required '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
                exit; // Ensure exit after sending error
            }

            // 3. Placeholder Success Response (No actual logic in Phase 1)
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                'status' => 'OK',
                'message' => "Route /example reached and authorized.",
                'key_id' => $apiKeyData['id'] // Optionally include key ID for debugging/logging
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;

        } else {
            // Method not allowed for this route
            sendJsonError(405, "Method Not Allowed. Only GET is supported for /example.", "METHOD_NOT_ALLOWED");
        }
        break;

    // --- Route: Query Allocations ---
    case '/allocations':
        if ($requestMethod === 'GET') {
            // --- Authentication ---
            $apiKeyData = authenticateApiKey($pdo); // Pass $pdo
            if ($apiKeyData === false) {
                // Explicitly handle authentication failure here.
                sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
                exit;
            }

            // --- Authorization ---
            $requiredPermission = "read:budget_allocations"; // As per Living Plan
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // If permission check fails, send 403 Forbidden
                sendJsonError(403, "Permission denied. API key does not have the required '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
                exit; // Ensure exit after sending error
            }

            // --- Endpoint Logic: Query Allocations ---
            try {
                // Get query parameters
                $queryParams = $_GET;

                // Call the data access function (now expects PDO)
                $result = getAllocations($pdo, $queryParams); // Pass $pdo

                // Send success response
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            } catch (InvalidArgumentException $e) {
                // Handle invalid query parameter errors (400)
                sendJsonError(400, $e->getMessage(), 'INVALID_QUERY_PARAM');
            } catch (RuntimeException $e) {
                // Handle database or other runtime errors (500)
                error_log("API Runtime Error (GET /allocations): " . $e->getMessage());
                sendJsonError(500, 'An internal error occurred while fetching allocations.', 'ALLOCATION_FETCH_ERROR');
            } catch (Exception $e) {
                // Catch any other unexpected errors
                error_log("API Unexpected Error (GET /allocations): " . $e->getMessage());
                sendJsonError(500, 'An unexpected error occurred.', 'UNEXPECTED_ERROR');
            }
            exit; // Stop script execution

        } else {
            // Method not allowed for this route
            sendJsonError(405, "Method Not Allowed. Only GET is supported for /allocations.", "METHOD_NOT_ALLOWED");
        }
        break;

    // --- Route: Forum Posts ---
    case '/forum/posts':
        // --- GET: Read All Forum Posts ---
        if ($requestMethod === 'GET') {
            // --- Authentication ---
            $apiKeyData = authenticateApiKey($pdo);
            if ($apiKeyData === false) {
                // Explicitly handle authentication failure here.
                sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
                exit;
            }

            // --- Authorization ---
            $requiredPermission = "read:all_forum_posts"; // Permission required for this endpoint
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // If permission check fails, send 403 Forbidden
                sendJsonError(403, "Permission denied. API key requires the '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
                exit; // Ensure exit after sending error
            }

            // --- Endpoint Logic: Call Handler ---
            try {
                $queryParams = $_GET;
                $result = handleGetAllForumPosts($pdo, $apiKeyData, $queryParams); // Call the handler function

                // Send success response
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            } catch (InvalidArgumentException $e) {
                // Handle bad request errors (400) from the handler
                sendJsonError(400, $e->getMessage(), 'BAD_REQUEST');
            } catch (RuntimeException $e) {
                // Handle internal server errors (500) from the handler
                error_log("API Runtime Error (GET /forum/posts): " . $e->getMessage());
                sendJsonError(500, 'An internal error occurred while fetching forum posts.', 'FORUM_FETCH_ERROR');
            } catch (Exception $e) {
                // Catch any other unexpected errors
                error_log("API Unexpected Error (GET /forum/posts): " . $e->getMessage());
                sendJsonError(500, 'An unexpected error occurred.', 'UNEXPECTED_ERROR');
            }
            exit; // Stop script execution

        // --- POST: Create Forum Post ---
        } elseif ($requestMethod === 'POST') {
            // --- Authentication ---
            $apiKeyData = authenticateApiKey($pdo); // Pass $pdo
            if ($apiKeyData === false) {
                // Explicitly handle authentication failure here.
                sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
                exit;
            }

            // --- Authorization ---
            $requiredPermission = "create:forum_post"; // As per Living Plan
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // If permission check fails, send 403 Forbidden
                sendJsonError(403, "Permission denied. API key requires the '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
                exit; // Ensure exit after sending error
            }

            // --- Endpoint Logic: Create Forum Post ---
            try {
                // 1. Get and Validate JSON Payload
                $jsonPayload = file_get_contents('php://input');
                $data = json_decode($jsonPayload, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    sendJsonError(400, 'Invalid JSON payload: ' . json_last_error_msg(), 'INVALID_JSON');
                }

                // Validate required fields
                if (!isset($data['topic_id']) || !filter_var($data['topic_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])) {
                    sendJsonError(400, 'Missing or invalid topic_id. Must be a positive integer.', 'INVALID_TOPIC_ID');
                }
                if (!isset($data['post_body']) || trim($data['post_body']) === '') {
                    sendJsonError(400, 'Missing or invalid post_body. Cannot be empty.', 'INVALID_POST_BODY');
                }

                $topicId = (int)$data['topic_id'];
                $postBody = trim($data['post_body']);
                $apiKeyId = $apiKeyData['id'];

                // Start Transaction (PDO)
                $pdo->beginTransaction();

                // 2. Check if Topic Exists and is not locked (Pass $pdo)
                if (!checkTopicExists($pdo, $topicId)) {
                    $pdo->rollBack(); // Rollback before sending error (PDO uses rollBack)
                    sendJsonError(404, "Forum topic not found or is locked for ID: {$topicId}.", "TOPIC_NOT_FOUND_OR_LOCKED");
                }

                // 3. Insert Post (Pass $pdo)
                $newPostId = createForumPostApi($pdo, $topicId, $postBody, $apiKeyId); // Corrected function call
                if ($newPostId === false) {
                    $pdo->rollBack();
                    // Error logged within createForumPost
                    // Let exception handler catch and log
                    throw new RuntimeException('Database error during post insertion.'); // Throw exception
                }

                // 4. Update Topic Last Post Timestamp (Pass $pdo)
// 4. Validate API Key User Association
                // Check if user_id exists, is not null, and is a valid integer
                if (!isset($apiKeyData['user_id']) || $apiKeyData['user_id'] === null || !filter_var($apiKeyData['user_id'], FILTER_VALIDATE_INT)) {
                    $pdo->rollBack(); // Rollback transaction before sending error
                    // Log the specific error for server-side diagnostics
                    error_log("API key user association error for key ID: {$apiKeyId}. Associated user_id ('" . ($apiKeyData['user_id'] ?? 'NULL or missing') . "') is invalid.");
                    // Send a structured error response to the client
                    sendJsonError(
                        500, // Internal Server Error seems appropriate as it's a server-side configuration/data issue
                        "API key user association error. Cannot update topic.",
                        "API_KEY_USER_ASSOC_ERROR",
                        ["apiKeyId" => $apiKeyId] // Include API Key ID in context if helpful
                    );
                    // Exit script execution after sending the error response
                    exit;
                }

                // Renumbering the next step comment for clarity
                // 5. Update Topic Last Post Timestamp (Pass $pdo)
                if (!updateTopicLastPost($pdo, $topicId, $apiKeyData['user_id'])) { // Pass user_id associated with the API key
                    $pdo->rollBack();
                    // Error logged within updateTopicLastPost
                    // Let exception handler catch and log
                    throw new RuntimeException('Database error during topic update.'); // Throw exception
                }

                // 5. Commit Transaction (PDO)
                $pdo->commit();

                // 6. Fetch the Created Post (Pass $pdo)
                $createdPost = getForumPostById($pdo, $newPostId);
                if ($createdPost === null) {
                    // This is unlikely if insert/commit succeeded, but handle defensively
                    error_log("API Error: Failed to fetch newly created post (ID: {$newPostId}) after transaction commit.");
                    // Send a 201 but indicate retrieval failure in message maybe? Or stick to 500?
                    // Let's send 500 as the final state is inconsistent with expectation.
                    sendJsonError(500, 'Failed to retrieve the created post after insertion.', 'FETCH_AFTER_INSERT_FAILED');
                }

                // 7. Send Success Response
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(201); // Created
                echo json_encode($createdPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            } catch (PDOException $e) { // Catch PDOException
                // Rollback transaction if active and an SQL error occurred
                if ($pdo->inTransaction()) { // PDO uses inTransaction()
                    $pdo->rollBack(); // PDO uses rollBack()
                }
                error_log("API Database Error (POST /forum/posts): " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
                sendJsonError(500, 'Database error occurred while creating forum post.', 'DB_ERROR');
            } catch (RuntimeException $e) { // Catch custom runtime exceptions
                 if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("API Runtime Error (POST /forum/posts): " . $e->getMessage());
                // Send specific error message if available, otherwise generic
                sendJsonError(500, $e->getMessage() ?: 'An internal error occurred processing the forum post.', 'RUNTIME_FORUM_ERROR');
            } catch (Exception $e) {
                 // Rollback transaction if active and a general error occurred
                if ($pdo->inTransaction()) { // PDO uses inTransaction()
                    $pdo->rollBack(); // PDO uses rollBack()
                }
                error_log("API Logic Error (POST /forum/posts): " . $e->getMessage());
                sendJsonError(500, 'An unexpected error occurred processing the request.', 'UNEXPECTED_ERROR');
            }
            exit; // Stop script execution

        } else {
            // Method not allowed for this route
            sendJsonError(405, "Method Not Allowed. Only GET and POST are supported for /forum/posts.", "METHOD_NOT_ALLOWED");
        }
        break; // End case '/forum/posts'

    // --- Route: Recent Forum Posts ---
    case '/forum/posts/recent':
        if ($requestMethod === 'GET') {
            // --- Authentication ---
            $apiKeyData = authenticateApiKey($pdo);
            if ($apiKeyData === false) {
                // Explicitly handle authentication failure here.
                sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
                exit;
            }

            // --- Authorization ---
            $requiredPermission = "read:recent_forum_posts"; // Permission for this endpoint
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // If permission check fails, send 403 Forbidden
                sendJsonError(403, "Permission denied. API key requires the '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
                exit; // Ensure exit after sending error
            }

            // --- Endpoint Logic: Call Handler ---
            try {
                $queryParams = $_GET;
                // Call the new handler function from forum_handler.php
                $result = handleGetRecentForumPosts($pdo, $apiKeyData, $queryParams);

                // Send success response (directly returning the array of posts)
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            } catch (InvalidArgumentException $e) {
                // Handle bad request errors (400) from the handler (e.g., invalid limit)
                sendJsonError(400, $e->getMessage(), 'BAD_REQUEST');
            } catch (RuntimeException $e) {
                // Handle internal server errors (500) from the handler
                error_log("API Runtime Error (GET /forum/posts/recent): " . $e->getMessage());
                sendJsonError(500, 'An internal error occurred while fetching recent forum posts.', 'RECENT_FORUM_FETCH_ERROR');
            } catch (Exception $e) {
                // Catch any other unexpected errors
                error_log("API Unexpected Error (GET /forum/posts/recent): " . $e->getMessage());
                sendJsonError(500, 'An unexpected error occurred.', 'UNEXPECTED_ERROR');
            }
            exit; // Stop script execution

        } else {
            // Method not allowed for this route
            sendJsonError(405, "Method Not Allowed. Only GET is supported for /forum/posts/recent.", "METHOD_NOT_ALLOWED");
        }
        break; // End case '/forum/posts/recent'

    // Add more cases here for future endpoints in Phase 2
    // case '/checkins':
    //     if ($requestMethod === 'GET') { /* ... */ }
    //     break;
    // case '/checkins/{id}/notes': // Need more sophisticated routing for path parameters
    //     if ($requestMethod === 'POST') { /* ... */ }
    //     break;

         // Add other non-parameterized routes here if needed

         default:
             // Route not found (neither parameterized nor specific case matched)
             // Ensure we use the most current request method value in the error message
             $currentRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'; // Re-fetch or default
             sendJsonError(404, "Not Found. The requested endpoint '{$routePath}' does not exist or method '{$currentRequestMethod}' is not supported for this endpoint.", "NOT_FOUND");
             break;
     }
}

// Restore the previous exception handler if needed (though exit usually terminates)
// restore_exception_handler(); // Generally not needed due to exit calls

?>