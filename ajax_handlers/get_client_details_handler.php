<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off for AJAX, log errors instead
ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // Set a path for error logging

session_start();

header('Content-Type: application/json');

require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_once '../includes/utils.php'; // For potential CSRF or other utils, though less critical for GET
require_once '../includes/data_access/client_data.php';
require_once '../includes/data_access/site_data.php';
require_once '../includes/data_access/question_data.php';

$response = ['success' => false, 'message' => 'An unknown error occurred.', 'data' => null];

// Basic security checks
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required.';
    echo json_encode($response);
    exit;
}

if (!isset($_GET['client_id'])) {
    $response['message'] = 'Client ID not provided.';
    echo json_encode($response);
    exit;
}

$client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);

if (!$client_id) {
    $response['message'] = 'Invalid Client ID.';
    echo json_encode($response);
    exit;
}

// Permission checks
$is_global_admin_or_director = isset($_SESSION['active_role']) && in_array($_SESSION['active_role'], ['administrator', 'director']);
$is_site_admin_session = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
$session_site_id = isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== '' ? (int)$_SESSION['active_site_id'] : null;

try {
    $client_details_full = getClientDetailsForEditing($pdo, $client_id);

    if (!$client_details_full || !$client_details_full['profile']) {
        $response['message'] = 'Client not found.';
        echo json_encode($response);
        exit;
    }

    $client_profile = $client_details_full['profile'];
    $client_answers = $client_details_full['answers']; // This is an array of {question_id, answer}

    // Perform permission check based on fetched client's site_id
    $can_view_this_client = false;
    $client_actual_site_id = $client_profile['site_id'];

    if ($is_global_admin_or_director) {
        $can_view_this_client = true;
    } elseif ($is_site_admin_session && $client_actual_site_id === $session_site_id) {
        $can_view_this_client = true;
    }

    if (!$can_view_this_client) {
        $response['message'] = 'Permission Denied: You do not have permission to view this client\'s details.';
        echo json_encode($response);
        exit;
    }

    // Fetch all global questions and all sites for the form
    $all_global_questions = getAllGlobalQuestions($pdo);
    $all_sites = getAllSites($pdo);

    $response['success'] = true;
    $response['message'] = 'Client details fetched successfully.';
    $response['data'] = [
        'profile' => $client_profile,
        'answers' => $client_answers, // Array of {question_id, answer}
        'all_questions' => $all_global_questions, // Array of {id, question_text, question_title}
        'sites' => $all_sites // Array of {id, name}
    ];

} catch (PDOException $e) {
    error_log("Database error in get_client_details_handler: " . $e->getMessage());
    $response['message'] = 'Database error. Please try again later.';
} catch (Exception $e) {
    error_log("General error in get_client_details_handler: " . $e->getMessage());
    $response['message'] = 'An unexpected error occurred. Please try again later.';
}

echo json_encode($response);
exit;
?>