<?php

// --- Configuration & Includes ---

// Set default timezone (optional, but good practice)
date_default_timezone_set('America/Phoenix'); // Or your preferred timezone

// Include necessary files
// Assuming db_connect.php is in the root 'includes' directory
require_once __DIR__ . '/../../includes/db_connect.php'; // Provides $conn
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/data_access/allocation_data_api.php'; // Added for allocation endpoint
require_once __DIR__ . '/data_access/forum_data_api.php'; // Added for forum endpoint

// --- Global Error Handling ---
// Set a top-level exception handler to catch unhandled errors
set_exception_handler(function ($exception) {
    // Log the detailed error
    error_log("Unhandled API Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    // Send a generic 500 error response
    sendJsonError(500, "An internal server error occurred.", "INTERNAL_SERVER_ERROR");
});

// --- Request Parsing ---

// Get request method
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Get request URI and parse the path
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api/v1'; // Define the base path for this API version

// Remove base path and query string to get the route path
$routePath = parse_url($requestUri, PHP_URL_PATH);
if (strpos($routePath, $basePath) === 0) {
    $routePath = substr($routePath, strlen($basePath));
}
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
    $apiKeyData = authenticateApiKey($conn);
    if ($apiKeyData === false) {
        // Error response handled within authenticateApiKey or sendJsonError called directly if needed
        // Example: sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
        // Assuming authenticateApiKey calls sendJsonError on failure and exits.
    }

    // --- Authorization ---
    $requiredPermission = "read:checkin_data"; // As per Living Plan
    if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
        // Error response handled within checkApiKeyPermission or sendJsonError called directly
        // Example: sendJsonError(403, "Permission denied. API key does not have the required '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
         // Assuming checkApiKeyPermission calls sendJsonError on failure and exits.
    }

    // --- Endpoint Logic: Fetch Check-in Data ---
    try {
        // Prepare statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT * FROM check_ins WHERE id = ?");
        if (!$stmt) {
             // Log the specific prepare error
             error_log("API Error: Failed to prepare statement for check-in fetch: " . $conn->error);
             sendJsonError(500, 'Database error during statement preparation.', 'DB_PREPARE_ERROR');
        }

        $stmt->bind_param("i", $checkinId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            // Record found
            $checkinData = $result->fetch_assoc();

            // Set headers and output JSON
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode($checkinData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } else {
            // Record not found
            sendJsonError(404, "Check-in not found for ID: {$checkinId}.", "NOT_FOUND");
        }

        $stmt->close();

    } catch (mysqli_sql_exception $e) {
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
    $apiKeyData = authenticateApiKey($conn);
    if ($apiKeyData === false) {
        // Error response handled within authenticateApiKey (it calls sendJsonError and exits)
    }

    // --- Authorization ---
    $requiredPermission = "create:checkin_note"; // As per Living Plan
    if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
        // Error response handled within checkApiKeyPermission (it calls sendJsonError and exits)
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

        // 2. Check if Check-in Exists (using prepared statement)
        $stmtCheck = $conn->prepare("SELECT id FROM check_ins WHERE id = ?");
        if (!$stmtCheck) {
             error_log("API Error: Failed to prepare statement for check-in existence check: " . $conn->error);
             sendJsonError(500, 'Database error during statement preparation.', 'DB_PREPARE_ERROR');
        }
        $stmtCheck->bind_param("i", $checkinId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        if ($resultCheck->num_rows === 0) {
            $stmtCheck->close(); // Close statement before sending error
            sendJsonError(404, "Check-in not found for ID: {$checkinId}.", "CHECKIN_NOT_FOUND");
        }
        $stmtCheck->close();

        // 3. Insert Note (using prepared statement)
        $stmtInsert = $conn->prepare("INSERT INTO checkin_notes (check_in_id, note_text, created_by_api_key_id, created_at) VALUES (?, ?, ?, NOW())");
         if (!$stmtInsert) {
             error_log("API Error: Failed to prepare statement for note insertion: " . $conn->error);
             sendJsonError(500, 'Database error during statement preparation.', 'DB_PREPARE_ERROR');
         }
        $stmtInsert->bind_param("isi", $checkinId, $noteText, $apiKeyId);

        if (!$stmtInsert->execute()) {
             // Log specific insert error
             error_log("API Database Error (POST /checkins/{$checkinId}/notes - Insert): " . $stmtInsert->error . " (Code: " . $stmtInsert->errno . ")");
             $stmtInsert->close(); // Close statement before sending error
             sendJsonError(500, 'Database error during note insertion.', 'DB_INSERT_ERROR');
        }
        $newNoteId = $conn->insert_id; // Get the ID of the inserted note
        $stmtInsert->close();

        // 4. Fetch the Created Note (using prepared statement)
        $stmtFetch = $conn->prepare("SELECT * FROM checkin_notes WHERE id = ?");
         if (!$stmtFetch) {
             error_log("API Error: Failed to prepare statement for fetching created note: " . $conn->error);
             sendJsonError(500, 'Database error during statement preparation.', 'DB_PREPARE_ERROR');
         }
        $stmtFetch->bind_param("i", $newNoteId);
        $stmtFetch->execute();
        $resultFetch = $stmtFetch->get_result();

        if ($resultFetch->num_rows === 1) {
            $createdNote = $resultFetch->fetch_assoc();
             $stmtFetch->close(); // Close statement after fetching

            // 5. Send Response
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(201); // Created
            echo json_encode($createdNote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } else {
             $stmtFetch->close(); // Close statement before sending error
             // This case should ideally not happen if insert succeeded, but handle defensively
             error_log("API Error: Failed to fetch newly created note (ID: {$newNoteId}) after insertion.");
             sendJsonError(500, 'Failed to retrieve the created note after insertion.', 'FETCH_AFTER_INSERT_FAILED');
        }

    } catch (mysqli_sql_exception $e) {
        // Catch specific database errors during the process
        error_log("API Database Error (POST /checkins/{$checkinId}/notes): " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
        sendJsonError(500, 'Database error occurred while adding check-in note.', 'DB_ERROR');
    } catch (Exception $e) {
         // Catch any other unexpected errors during logic execution
         error_log("API Logic Error (POST /checkins/{$checkinId}/notes): " . $e->getMessage());
         sendJsonError(500, 'An unexpected error occurred processing the request.', 'UNEXPECTED_ERROR');
    }

    exit; // Crucial: Stop script execution after handling the request

// --- NEW ROUTE FOR REPORTS ---
} elseif ($requestMethod === 'GET' && preg_match('#^/reports/([^/]+)$#', $routePath, $matches)) { // Match /reports/ followed by non-slash characters
    $reportType = trim($matches[1]);

    // Validate report type (must be non-empty string)
    // Basic validation: check if it's not empty after trimming.
    // More specific validation (e.g., allowed report types) would go here in full implementation.
    if (empty($reportType)) {
        // This case might be redundant due to regex [^/]+, but good for robustness.
        sendJsonError(400, 'Missing or invalid report type.', 'INVALID_REPORT_TYPE');
    }

    // --- Authentication ---
    $apiKeyData = authenticateApiKey($conn);
    if ($apiKeyData === false) {
        // Error response handled within authenticateApiKey (it calls sendJsonError and exits)
        // No further action needed here if authenticateApiKey exits on failure.
    }

    // --- Authorization ---
    $requiredPermission = "generate:reports"; // As per Living Plan line 80
    if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
        // Error response handled within checkApiKeyPermission (it calls sendJsonError and exits)
        // No further action needed here if checkApiKeyPermission exits on failure.
    }

    // --- Endpoint Logic (Placeholder) ---
    try {
        // Set headers and output JSON placeholder
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode([
            'status' => 'OK',
            'message' => 'Placeholder for report generation.',
            'requested_report' => $reportType // Use the extracted report type
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    } catch (Exception $e) {
         // Catch any unexpected errors during this minimal logic execution
         error_log("API Logic Error (GET /reports/{$reportType}): " . $e->getMessage());
         sendJsonError(500, 'An unexpected error occurred processing the report request.', 'UNEXPECTED_REPORT_ERROR');
    }

    exit; // Crucial: Stop script execution after handling the request
} else {
     // --- Fallback to switch for non-parameterized routes ---
     switch ($routePath) {
         case '/example':
             if ($requestMethod === 'GET') {
                 // 1. Authentication
            $apiKeyData = authenticateApiKey($conn);
            if ($apiKeyData === false) {
                sendJsonError(401, "Authentication required. Invalid or missing API Key.", "AUTH_FAILED");
            }

            // 2. Authorization
            $requiredPermission = "read:example"; // Placeholder permission
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                sendJsonError(403, "Permission denied. API key does not have the required '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
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
            $apiKeyData = authenticateApiKey($conn);
            if ($apiKeyData === false) {
                // Assuming authenticateApiKey calls sendJsonError on failure and exits.
                // If not, uncomment below:
                // sendJsonError(401, "Authentication failed. Invalid or missing API Key.", "AUTH_FAILED");
            }

            // --- Authorization ---
            $requiredPermission = "read:budget_allocations"; // As per Living Plan
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // Assuming checkApiKeyPermission calls sendJsonError on failure and exits.
                // If not, uncomment below:
                // sendJsonError(403, "Permission denied. API key does not have the required '{$requiredPermission}' permission.", "AUTH_FORBIDDEN");
            }

            // --- Endpoint Logic: Query Allocations ---
            try {
                // Get query parameters
                $queryParams = $_GET;

                // Call the data access function
                $result = getAllocations($conn, $queryParams);

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

    // --- Route: Create Forum Post ---
    case '/forum/posts':
        if ($requestMethod === 'POST') {
            // --- Authentication ---
            $apiKeyData = authenticateApiKey($conn);
            if ($apiKeyData === false) {
                // Error handled within authenticateApiKey
            }

            // --- Authorization ---
            $requiredPermission = "create:forum_post"; // As per Living Plan
            if (!checkApiKeyPermission($requiredPermission, $apiKeyData)) {
                // Error handled within checkApiKeyPermission
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

                // Start Transaction
                $conn->begin_transaction();

                // 2. Check if Topic Exists and is not locked
                if (!checkTopicExists($conn, $topicId)) {
                    $conn->rollback(); // Rollback before sending error
                    sendJsonError(404, "Forum topic not found or is locked for ID: {$topicId}.", "TOPIC_NOT_FOUND_OR_LOCKED");
                }

                // 3. Insert Post
                $newPostId = createForumPost($conn, $topicId, $postBody, $apiKeyId);
                if ($newPostId === false) {
                    $conn->rollback();
                    // Error logged within createForumPost
                    sendJsonError(500, 'Database error during post insertion.', 'DB_POST_INSERT_ERROR');
                }

                // 4. Update Topic Last Post Timestamp
                if (!updateTopicLastPost($conn, $topicId)) {
                    $conn->rollback();
                    // Error logged within updateTopicLastPost
                    sendJsonError(500, 'Database error during topic update.', 'DB_TOPIC_UPDATE_ERROR');
                }

                // 5. Commit Transaction
                $conn->commit();

                // 6. Fetch the Created Post
                $createdPost = getForumPostById($conn, $newPostId);
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

            } catch (mysqli_sql_exception $e) {
                // Rollback transaction if active and an SQL error occurred
                if ($conn->in_transaction) {
                    $conn->rollback();
                }
                error_log("API Database Error (POST /forum/posts): " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
                sendJsonError(500, 'Database error occurred while creating forum post.', 'DB_ERROR');
            } catch (Exception $e) {
                 // Rollback transaction if active and a general error occurred
                if ($conn->in_transaction) {
                    $conn->rollback();
                }
                error_log("API Logic Error (POST /forum/posts): " . $e->getMessage());
                sendJsonError(500, 'An unexpected error occurred processing the request.', 'UNEXPECTED_ERROR');
            }
            exit; // Stop script execution

        } else {
            // Method not allowed for this route
            sendJsonError(405, "Method Not Allowed. Only POST is supported for /forum/posts.", "METHOD_NOT_ALLOWED");
        }
        break;

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
             sendJsonError(404, "Not Found. The requested endpoint '{$routePath}' does not exist or method '{$requestMethod}' is not supported for this endpoint.", "NOT_FOUND");
             break;
     }
}

// Restore the previous exception handler if needed (though exit usually terminates)
restore_exception_handler();

?>