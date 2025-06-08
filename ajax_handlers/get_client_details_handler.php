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

try {
    // 1. Get client's site_id and profile details
    $stmt_client = $pdo->prepare("SELECT id, username, first_name, last_name, email, site_id, email_preference_jobs FROM clients WHERE id = ? AND deleted_at IS NULL");
    $stmt_client->execute([$client_id]);
    $client_profile = $stmt_client->fetch(PDO::FETCH_ASSOC);

    if (!$client_profile) {
        $response['message'] = 'Client not found.';
        echo json_encode($response);
        exit;
    }
    $client_site_id = $client_profile['site_id'];

    // Permission checks
    $is_global_admin_or_director = isset($_SESSION['active_role']) && in_array($_SESSION['active_role'], ['administrator', 'director']);
    $is_site_admin_session = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
    $session_site_id = isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== '' ? (int)$_SESSION['active_site_id'] : null;
    
    $can_view_this_client = false;
    if ($is_global_admin_or_director) {
        $can_view_this_client = true;
    } elseif ($is_site_admin_session && $client_site_id == $session_site_id) {
        $can_view_this_client = true;
    } elseif (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'azwk_staff' && isset($session_site_id) && $client_site_id == $session_site_id) {
        $can_view_this_client = true;
    }

    if (!$can_view_this_client) {
        $response['message'] = 'Permission Denied: You do not have permission to view this client\'s details.';
        echo json_encode($response);
        exit;
    }

    // 2. Fetch questions for the client's site
    $stmt_questions = $pdo->prepare("
        SELECT gq.id, gq.question_text, gq.question_title
        FROM site_questions sq
        JOIN global_questions gq ON sq.global_question_id = gq.id
        WHERE sq.site_id = ? AND sq.is_active = 1
        ORDER BY sq.display_order
    ");
    $stmt_questions->execute([$client_site_id]);
    $questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch client's answers for those questions
    $question_ids = array_column($questions, 'id');
    $client_answers = [];
    if (!empty($question_ids)) {
        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        $stmt_answers = $pdo->prepare("
            SELECT question_id, answer
            FROM client_answers
            WHERE client_id = ? AND question_id IN ($placeholders)
        ");
        $params = array_merge([$client_id], $question_ids);
        $stmt_answers->execute($params);
        $answers_raw = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);
        
        // Re-key the array by question_id for easier lookup on the client-side
        foreach ($answers_raw as $answer) {
            $client_answers[$answer['question_id']] = $answer['answer'];
        }
    }
    
    // For the site dropdown in the modal
    $all_sites = getAllSites($pdo);

    $response['success'] = true;
    $response['message'] = 'Client details fetched successfully.';
    $response['data'] = [
        'profile' => $client_profile,
        'answers' => $client_answers, // Now an object like { question_id: "Yes", ... }
        'questions' => $questions,     // Array of {id, question_text, question_title}
        'sites' => $all_sites       // Array of {id, name}
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