<?php
// kiosk/qr_checkin.php
// Handles AJAX POST requests from kiosk.js for QR code check-ins.

// Strict error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session management
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include necessary files
require_once '../includes/db_connect.php'; // Database connection
require_once '../includes/auth.php';       // Authentication functions (ensure this sets session vars)
// require_once '../includes/utils.php'; // May need utility functions later

// Set content type to JSON for all responses
header('Content-Type: application/json');
// --- Security Checks ---

// 1. Check Request Method (Must be POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// 2. Verify CSRF Token
// Assumption: kiosk.js sends the token in an 'X-CSRF-Token' header.
// The token should be generated and stored in the session when the kiosk page loads.
$csrf_token_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (empty($csrf_token_header) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token_header)) {
    http_response_code(403); // Forbidden
    // Invalidate session token to prevent reuse (optional but good practice)
    // unset($_SESSION['csrf_token']);
    echo json_encode(['status' => 'error', 'message' => 'CSRF token validation failed.']);
    exit;
}

// 3. Verify Staff Session and Role
// Assumption: auth.php sets $_SESSION['user_id'] and $_SESSION['user_role'] upon successful login.
// Use 'active_role' consistent with includes/auth.php
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role']) || strtolower($_SESSION['active_role']) !== 'kiosk') {
    http_response_code(403); // Forbidden
    error_log("Kiosk QR Check-in Error: Access denied due to invalid session or role. Expected 'kiosk', found: " . ($_SESSION['active_role'] ?? 'Not Set')); // Added logging here too
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Invalid session or role. Kiosk role required.']);
    exit;
}

// 4. Get Kiosk Site ID from Session
// Use 'active_site_id' consistent with index.php login logic.
// Also check if it's not empty or null.
if (!isset($_SESSION['active_site_id']) || empty($_SESSION['active_site_id'])) {
    http_response_code(500); // Internal Server Error (Configuration issue)
    $userIdForLog = $_SESSION['user_id'] ?? 'Unknown'; // Safely get user ID for logging
    error_log("Kiosk QR Check-in Error: active_site_id not found or empty in session for user ID: " . $userIdForLog . ". Session active_site_id: " . print_r($_SESSION['active_site_id'] ?? 'Not Set', true));
    echo json_encode(['status' => 'error', 'message' => 'Kiosk session error: Site ID missing or invalid.']);
    exit;
}
$kiosk_site_id = (int)$_SESSION['active_site_id']; // Cast to int for safety

// --- Process Request ---

// 1. Get JSON Input
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// 2. Validate Input
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON format received.']);
    exit;
}

if (!isset($data['qr_identifier']) || empty(trim($data['qr_identifier']))) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Missing or empty qr_identifier in request.']);
    exit;
}

// 3. Sanitize Input (Basic trim, further sanitization via prepared statements)
$qr_identifier = trim($data['qr_identifier']);

// --- Database Operations ---

global $pdo; // Use the PDO connection from db_connect.php

try {
    // 4. Find Client by QR Identifier
    // Assumption: q_veteran, q_age, q_interviewing are the relevant fields in both tables.
    $sql_find_client = "SELECT
                            id, first_name, last_name, email AS client_email
                        FROM clients
                        WHERE client_qr_identifier = :qr_identifier
                          AND deleted_at IS NULL";
    $stmt_find = $pdo->prepare($sql_find_client);
    $stmt_find->bindParam(':qr_identifier', $qr_identifier, PDO::PARAM_STR);
    $stmt_find->execute();
    $client = $stmt_find->fetch(PDO::FETCH_ASSOC);

    // 5. Process Result
    if ($client) {
        // Client Found - Create Check-in Record
        $client_id = $client['id'];
        $client_first_name = $client['first_name'];

        // --- Fetch Client Answers (including question_id and title) ---
        $sql_get_answers = "SELECT gq.id AS question_id, gq.question_title, ca.answer
                            FROM client_answers ca
                            JOIN global_questions gq ON ca.question_id = gq.id
                            WHERE ca.client_id = :client_id";
        $stmt_answers = $pdo->prepare($sql_get_answers);
        $stmt_answers->bindParam(':client_id', $client_id, PDO::PARAM_INT);
        $stmt_answers->execute();
        $processedClientAnswers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

        // Prepare a map for q_ columns in check_ins for convenience
        $clientAnswersForCheckInsMap = [];
        if ($processedClientAnswers) {
            foreach ($processedClientAnswers as $row) {
                if (isset($row['question_title']) && isset($row['answer'])) {
                    $clientAnswersForCheckInsMap[$row['question_title']] = $row['answer'];
                }
            }
        }
        // --- End Fetch Client Answers ---

        // Prepare data for check_ins table
        $check_in_data = [
            'site_id' => $kiosk_site_id,
            'first_name' => $client['first_name'],
            'last_name' => $client['last_name'],
            'client_email' => $client['client_email'] ?? null,
            'client_id' => $client_id,
            // Map answers for existing q_ columns, defaulting to NULL if not found
            // 'q_veteran' => $clientAnswersForCheckInsMap['veteran'] ?? null, // Deprecated: q_* columns no longer populated for dynamic answers
            // 'q_age' => $clientAnswersForCheckInsMap['age'] ?? null, // Deprecated: q_* columns no longer populated for dynamic answers
            // 'q_interviewing' => $clientAnswersForCheckInsMap['interviewing'] ?? null, // Column does not exist
            // Add other existing q_ columns from your check_ins table here if needed
            // e.g. 'q_unemployment_assistance' => $clientAnswersForCheckInsMap['unemployment_assistance'] ?? null, // Deprecated
            //      'q_school' => $clientAnswersForCheckInsMap['school'] ?? null, // Deprecated
            //      'q_employment_layoff' => $clientAnswersForCheckInsMap['employment_layoff'] ?? null, // Deprecated
            //      'q_unemployment_claim' => $clientAnswersForCheckInsMap['unemployment_claim'] ?? null, // Deprecated
            //      'q_employment_services' => $clientAnswersForCheckInsMap['employment_services'] ?? null, // Deprecated
            //      'q_equus' => $clientAnswersForCheckInsMap['equus'] ?? null, // Deprecated
            //      'q_seasonal_farmworker' => $clientAnswersForCheckInsMap['seasonal_farmworker'] ?? null, // Deprecated
        ];
        // Filter out null values to only include actual q_ columns that have answers
        // or if you want to explicitly set them to NULL, keep them.
        // For now, we assume any q_ column listed should be in the insert.
        error_log("Kiosk QR Check-in: Data prepared for check_ins (q_* columns intentionally omitted): " . print_r($check_in_data, true));

        // Build INSERT statement dynamically based on available fields
        $columns = implode(', ', array_keys($check_in_data));
        $placeholders = ':' . implode(', :', array_keys($check_in_data));
        // Add check_in_time explicitly
        $columns .= ', check_in_time';
        $placeholders .= ', NOW()';

        $sql_insert_checkin = "INSERT INTO check_ins ($columns) VALUES ($placeholders)";
        $stmt_insert = $pdo->prepare($sql_insert_checkin);

        // Bind parameters
        foreach ($check_in_data as $key => $value) {
            // Determine PDO type (basic check, adjust if needed for specific types)
            $param_type = PDO::PARAM_STR;
            if (is_int($value)) {
                $param_type = PDO::PARAM_INT;
            } elseif (is_null($value)) {
                $param_type = PDO::PARAM_NULL;
            }
            $stmt_insert->bindValue(':' . $key, $value, $param_type);
        }

        // Execute the statement
        $execution_result = $stmt_insert->execute();

        if ($execution_result) {
            // Check if any row was actually inserted
            $rowCount = $stmt_insert->rowCount();

            if ($rowCount > 0) {
                $new_check_in_id = $pdo->lastInsertId();

                // --- Save answers to checkin_answers table ---
                if ($processedClientAnswers && $new_check_in_id) {
                    // error_log("Kiosk QR Check-in: Preparing to insert into checkin_answers for check_in_id $new_check_in_id. Data: " . print_r($processedClientAnswers, true));
                    $sql_insert_checkin_answer = "INSERT INTO checkin_answers (check_in_id, question_id, answer)
                                                  VALUES (:check_in_id, :question_id, :answer)";
                    $stmt_insert_answer = $pdo->prepare($sql_insert_checkin_answer);

                    foreach ($processedClientAnswers as $answer_row) {
                        if (isset($answer_row['question_id']) && isset($answer_row['answer'])) {
                            $stmt_insert_answer->bindParam(':check_in_id', $new_check_in_id, PDO::PARAM_INT);
                            $stmt_insert_answer->bindParam(':question_id', $answer_row['question_id'], PDO::PARAM_INT);
                            $stmt_insert_answer->bindParam(':answer', $answer_row['answer'], PDO::PARAM_STR); // Assuming answer is 'Yes'/'No'
                            
                            if (!$stmt_insert_answer->execute()) {
                                // Log error but don't necessarily stop the whole check-in success response
                                error_log("Kiosk QR Check-in Warning: Failed to insert into checkin_answers for check_in_id $new_check_in_id, question_id {$answer_row['question_id']}. PDO Error: " . implode(":", $stmt_insert_answer->errorInfo()));
                            }
                        }
                    }
                }
                // --- End Save answers to checkin_answers table ---

                // --- Placeholder Triggers ---
                // TODO: Trigger automatic AI enrollment based on client email ($client['client_email'])
                // TODO: Trigger enhanced notifications (e.g., to specific staff based on client needs or site rules)

                // Return Success Response
                http_response_code(200); // OK
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Welcome ' . htmlspecialchars($client_first_name, ENT_QUOTES, 'UTF-8') . ', Checked In!'
                ]);
                exit;
            } else {
                // Execution succeeded, but no rows were inserted (unexpected)
                http_response_code(500); // Internal Server Error
                error_log("Kiosk QR Check-in Warning: Insert execution succeeded but affected 0 rows for client ID: $client_id. Check database triggers or constraints.");
                echo json_encode(['status' => 'error', 'message' => 'Check-in recorded successfully but data processing failed. Please contact support.']);
                exit;
            }

        } else {
            // Database Insert Execution Error
            http_response_code(500); // Internal Server Error
            // Ensure $stmt_insert is available here before calling errorInfo()
            $errorInfo = $stmt_insert ? $stmt_insert->errorInfo() : ['N/A', 'N/A', 'Statement object not available'];
            error_log("Kiosk QR Check-in Error: Failed to execute insert statement for client ID: $client_id. PDO Error: " . implode(":", $errorInfo));
            echo json_encode(['status' => 'error', 'message' => 'Failed to record check-in due to a database error. Please try again or contact support.']);
            exit;
        }

    } else {
        // Client Not Found
        http_response_code(404); // Not Found
        echo json_encode(['status' => 'error', 'message' => 'Invalid QR Code or Client Not Found.']);
        exit;
    }

} catch (PDOException $e) {
    // Database Connection or Query Error
    http_response_code(500); // Internal Server Error
    error_log("Kiosk QR Check-in Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Please contact support.']);
    exit;
} catch (Exception $e) {
    // General Error
    http_response_code(500); // Internal Server Error
    error_log("Kiosk QR Check-in General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred. Please contact support.']);
    exit;
}

?>