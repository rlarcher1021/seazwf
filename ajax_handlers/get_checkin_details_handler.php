<?php
// ajax_handlers/get_checkin_details_handler.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Adjust the path as necessary if db_connect.php is located elsewhere
require_once '../includes/db_connect.php';
// Assuming auth.php handles session start and any necessary authentication/authorization
// If not strictly needed for this public-facing data fetch, it can be omitted,
// but it's good practice for consistency if other handlers use it.
// require_once '../includes/auth.php'; // Uncomment if auth checks are needed

$response = ['success' => false, 'message' => '', 'data' => null];

if (!isset($_GET['check_in_id']) || !filter_var($_GET['check_in_id'], FILTER_VALIDATE_INT)) {
    $response['message'] = 'Invalid or missing Check-in ID.';
    // error_log("[DEBUG] get_checkin_details_handler: Invalid or missing Check-in ID. GET data: " . print_r($_GET, true)); // DEBUG
    echo json_encode($response);
    exit;
}

$check_in_id = (int)$_GET['check_in_id'];
error_log("[DEBUG] get_checkin_details_handler: Received check_in_id: " . $check_in_id); // DEBUG

try {
    $pdo->beginTransaction();

    // 1. Fetch standard fields from check_ins and associated client information
    $stmt_checkin_client = $pdo->prepare("
        SELECT 
            ci.id AS check_in_id, 
            ci.site_id, 
            ci.check_in_time,
            ci.first_name AS checkin_first_name,
            ci.last_name AS checkin_last_name,
            ci.client_email AS checkin_client_email,
            c.id AS client_id,
            c.username AS client_username,
            c.email AS client_email_primary,
            c.first_name AS client_first_name,
            c.last_name AS client_last_name,
            s.name AS site_name
        FROM check_ins ci
        LEFT JOIN clients c ON ci.client_id = c.id
        JOIN sites s ON ci.site_id = s.id
        WHERE ci.id = :check_in_id
    ");
    $stmt_checkin_client->bindParam(':check_in_id', $check_in_id, PDO::PARAM_INT);
    $stmt_checkin_client->execute();
    $checkin_data = $stmt_checkin_client->fetch(PDO::FETCH_ASSOC);
    // error_log("[DEBUG] get_checkin_details_handler: Fetched checkin_data: " . print_r($checkin_data, true)); // DEBUG

    if (!$checkin_data) {
        $response['message'] = 'Check-in record not found.';
        error_log("[DEBUG] get_checkin_details_handler: Check-in record not found for ID: " . $check_in_id); // DEBUG
        $pdo->rollBack();
        echo json_encode($response);
        exit;
    }

    $site_id = $checkin_data['site_id'];
    error_log("[DEBUG] get_checkin_details_handler: Site ID from checkin_data: " . $site_id); // DEBUG

    // 2. Fetch dynamic questions for the check-in's site_id and their answers
    // Fetches all active questions for the site and LEFT JOINs any answers for this specific check-in
    $stmt_questions_answers = $pdo->prepare("
        SELECT 
            gq.id AS question_id,
            gq.question_text,
            gq.question_title,
            sq.display_order,
            ca.answer AS client_answer
        FROM site_questions sq
        JOIN global_questions gq ON sq.global_question_id = gq.id
        LEFT JOIN checkin_answers ca ON gq.id = ca.question_id AND ca.check_in_id = :check_in_id
        WHERE sq.site_id = :site_id AND sq.is_active = 1
        ORDER BY sq.display_order ASC, gq.question_title ASC
    ");
    $stmt_questions_answers->bindParam(':check_in_id', $check_in_id, PDO::PARAM_INT);
    $stmt_questions_answers->bindParam(':site_id', $site_id, PDO::PARAM_INT);
    $stmt_questions_answers->execute();
    $questions_answers = $stmt_questions_answers->fetchAll(PDO::FETCH_ASSOC);
    // error_log("[DEBUG] get_checkin_details_handler: Fetched questions_answers: " . print_r($questions_answers, true)); // DEBUG

    $pdo->commit();

    $response['success'] = true;
    $response['data'] = [
        'check_in_details' => $checkin_data,
        'dynamic_questions' => $questions_answers
    ];
    error_log("[DEBUG] get_checkin_details_handler: Successfully prepared response data."); // DEBUG

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('[ERROR] get_checkin_details_handler: PDOException: ' . $e->getMessage()); // DEBUG
    // For production, log the detailed error and return a generic message
    // error_log('PDOException in get_checkin_details_handler.php: ' . $e->getMessage());
    // $response['message'] = 'An error occurred while fetching check-in details.';
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['message'] = 'General error: ' . $e->getMessage();
    error_log('[ERROR] get_checkin_details_handler: Exception: ' . $e->getMessage()); // DEBUG
    // error_log('Exception in get_checkin_details_handler.php: ' . $e->getMessage());
    // $response['message'] = 'An unexpected error occurred.';
}

// error_log("[DEBUG] get_checkin_details_handler: Final response object before json_encode: " . print_r($response, true)); // DEBUG
echo json_encode($response);
?>