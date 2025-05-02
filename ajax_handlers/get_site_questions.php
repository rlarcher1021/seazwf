<?php
/*
 * File: get_site_questions.php
 * Path: /ajax_handlers/get_site_questions.php
 * Created: 2025-04-29
 * Author: Roo (AI Assistant)
 * Description: AJAX handler to fetch active questions for a specific site.
 */

header('Content-Type: application/json');

// Basic check for AJAX request (optional but good practice)
// if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
//     http_response_code(403); // Forbidden
//     echo json_encode(['error' => 'Direct access not allowed.']);
//     exit;
// }

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/data_access/question_data.php';

$response = ['success' => false, 'questions' => [], 'error' => ''];

// Validate site_id input
$site_id = filter_input(INPUT_GET, 'site_id', FILTER_VALIDATE_INT);

if ($site_id === false || $site_id <= 0) {
    http_response_code(400); // Bad Request
    $response['error'] = 'Invalid or missing site ID.';
    echo json_encode($response);
    exit;
}

// Verify PDO connection
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500); // Internal Server Error
    error_log("FATAL: PDO connection object not established in ajax_handlers/get_site_questions.php");
    $response['error'] = 'Database connection error.';
    echo json_encode($response);
    exit;
}

try {
    $questions = getActiveQuestionsForSite($pdo, $site_id);

    if ($questions === false) {
        // Function indicated an error (e.g., PDOException caught inside)
        http_response_code(500);
        $response['error'] = 'Error fetching questions from the database.';
        error_log("Error returned from getActiveQuestionsForSite for site_id: " . $site_id);
    } elseif (is_array($questions)) {
        $response['success'] = true;
        $response['questions'] = $questions;
    } else {
        // Should not happen if function returns array or false, but handle defensively
        http_response_code(500);
        $response['error'] = 'Unexpected response format when fetching questions.';
        error_log("Unexpected response type from getActiveQuestionsForSite for site_id: " . $site_id);
    }

} catch (PDOException $e) {
    http_response_code(500);
    $response['error'] = 'Database error while fetching questions.';
    error_log("PDOException in ajax_handlers/get_site_questions.php: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred.';
    error_log("Exception in ajax_handlers/get_site_questions.php: " . $e->getMessage());
}

echo json_encode($response);
exit;
?>