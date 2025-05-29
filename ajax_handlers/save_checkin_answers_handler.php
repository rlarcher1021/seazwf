<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/auth.php'; // For isLoggedIn() and potentially other auth functions

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Check if staff session variables are set (mimicking includes/auth.php)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$check_in_id = isset($_POST['check_in_id']) ? filter_var($_POST['check_in_id'], FILTER_VALIDATE_INT) : null;
$answers_data = isset($_POST['answers']) ? $_POST['answers'] : null;

// --- Permission Check 1: User is site admin ---
$stmt_user = $pdo->prepare("SELECT is_site_admin, site_id FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt_user->execute([$user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$user || !$user['is_site_admin']) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Permission denied: User is not a site administrator.']);
    exit;
}

$user_site_id = $user['site_id'];

// --- Basic Validation for check_in_id ---
if ($check_in_id === false || $check_in_id === null || $check_in_id <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid Check-in ID provided.']);
    exit;
}

// --- Permission Check 2: User's site_id matches check_in's site_id ---
$stmt_checkin_site = $pdo->prepare("SELECT site_id FROM check_ins WHERE id = ?");
$stmt_checkin_site->execute([$check_in_id]);
$check_in_details = $stmt_checkin_site->fetch(PDO::FETCH_ASSOC);

if (!$check_in_details) {
    http_response_code(404); // Not Found
    echo json_encode(['success' => false, 'message' => 'Check-in record not found.']);
    exit;
}

if ($check_in_details['site_id'] != $user_site_id) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Permission denied: User site does not match check-in site.']);
    exit;
}

// --- Validate answers data ---
if (!is_array($answers_data) || empty($answers_data)) {
    // It's possible to save with no answers if the user clears them all
    // However, if answers_data is provided but not an array, it's an error.
    if ($answers_data !== null && !is_array($answers_data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid answers format.']);
        exit;
    }
    // If answers_data is null or an empty array, proceed to allow clearing answers.
    // For this task, we assume if answers_data is empty, it means no changes or clear existing.
    // The logic below will handle inserts/updates only if answers are provided.
}

$pdo->beginTransaction();

try {
    foreach ($answers_data as $question_id_str => $answer_text) {
        // Extract numeric part of question_id if it's like 'answer_X'
        if (strpos($question_id_str, 'answer_') === 0) {
            $question_id = filter_var(substr($question_id_str, strlen('answer_')), FILTER_VALIDATE_INT);
        } else {
            $question_id = filter_var($question_id_str, FILTER_VALIDATE_INT);
        }

        if ($question_id === false || $question_id === null || $question_id <= 0) {
            throw new Exception("Invalid Question ID: " . htmlspecialchars($question_id_str));
        }

        if (!in_array($answer_text, ['Yes', 'No'], true)) {
            // If an answer is provided but it's not 'Yes' or 'No', it's an error.
            // If $answer_text is empty, it could mean "remove this answer".
            // For now, we'll strictly require 'Yes' or 'No' if an answer is submitted for a question.
            // To "remove" an answer, the client should not send that question_id or send a specific signal.
            // Based on current task, we only update/insert 'Yes'/'No'.
            throw new Exception("Invalid answer for Question ID " . htmlspecialchars($question_id) . ": Must be 'Yes' or 'No'.");
        }

        // Check if answer exists
        $stmt_check = $pdo->prepare("SELECT id FROM checkin_answers WHERE check_in_id = ? AND question_id = ?");
        $stmt_check->execute([$check_in_id, $question_id]);
        $existing_answer = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing_answer) {
            // Update existing answer
            $stmt_update = $pdo->prepare("UPDATE checkin_answers SET answer = ? WHERE id = ?");
            if (!$stmt_update->execute([$answer_text, $existing_answer['id']])) {
                throw new Exception("Failed to update answer for Question ID " . htmlspecialchars($question_id));
            }
        } else {
            // Insert new answer
            $stmt_insert = $pdo->prepare("INSERT INTO checkin_answers (check_in_id, question_id, answer) VALUES (?, ?, ?)");
            if (!$stmt_insert->execute([$check_in_id, $question_id, $answer_text])) {
                 throw new Exception("Failed to insert answer for Question ID " . htmlspecialchars($question_id));
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Check-in answers saved successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400); // Bad Request or 500 Internal Server Error depending on exception
    // Log $e->getMessage() for server-side debugging
    error_log("Error in save_checkin_answers_handler.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while saving answers: ' . $e->getMessage()]);
}

exit;
?>