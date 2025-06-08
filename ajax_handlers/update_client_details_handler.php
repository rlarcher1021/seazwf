<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off for production, on for dev
session_start();

header('Content-Type: application/json');

require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_once '../includes/utils.php';
require_once '../includes/data_access/client_data.php';
require_once '../includes/data_access/question_data.php';
require_once '../includes/data_access/audit_log_data.php';

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

// 1. CSRF Check
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $response['message'] = 'Invalid CSRF token. Please refresh and try again.';
    echo json_encode($response);
    exit;
}

// 2. User Authentication & Basic Role Check
if (!is_logged_in()) {
    $response['message'] = 'Authentication required. Please log in.';
    echo json_encode($response);
    exit;
}

$session_user_role = $_SESSION['active_role'] ?? null; // Define session role
$is_global_admin_or_director = $session_user_role && in_array($session_user_role, ['administrator', 'director']);
$is_site_admin = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
$is_azwk_staff = $session_user_role === 'azwk_staff'; // Define if user is azwk_staff
$session_site_id = isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== '' ? (int)$_SESSION['active_site_id'] : null;
$session_user_id = $_SESSION['user_id'] ?? null;

// Ensure session_user_id is set, as it's crucial for audit logging
if ($session_user_id === null) {
    $response['message'] = 'User session is invalid or user ID is missing. Cannot proceed.';
    echo json_encode($response);
    exit;
}

// Allow administrators, directors, site admins, or azwk_staff to proceed to more specific checks
if (!($is_global_admin_or_director || $is_site_admin || $is_azwk_staff)) {
    $response['message'] = 'Access Denied: You do not have permission to update client details.';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 3. Retrieve and Sanitize Input
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    $first_name = trim(filter_input(INPUT_POST, 'first_name', FILTER_DEFAULT));
    $last_name = trim(filter_input(INPUT_POST, 'last_name', FILTER_DEFAULT));
    $site_id_form = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT); // Renamed to avoid conflict
    $email_preference_jobs = isset($_POST['email_preference_jobs']) ? 1 : 0;

    $submitted_answers = [];
    // The new form submission uses names like "dynamic_answers[QUESTION_ID]"
    if (isset($_POST['dynamic_answers']) && is_array($_POST['dynamic_answers'])) {
        foreach ($_POST['dynamic_answers'] as $question_id => $answer) {
            if (filter_var($question_id, FILTER_VALIDATE_INT)) {
                // Sanitize the answer to ensure it's one of the allowed values
                $submitted_answers[(int)$question_id] = in_array($answer, ['Yes', 'No', '']) ? $answer : '';
            }
        }
    }

    // 4. Input Validation
    $errors = [];
    if (empty($client_id)) $errors['client_id'] = "Client ID is missing.";
    if (empty($first_name)) $errors['first_name'] = "First name is required.";
    if (empty($last_name)) $errors['last_name'] = "Last name is required.";
    if ($site_id_form === false || $site_id_form <= 0) $errors['site_id'] = "Please select a valid site.";
    // Add more validation as needed

    if (!empty($errors)) {
        $response['message'] = 'Validation failed. Please check the form.';
        $response['errors'] = $errors;
        echo json_encode($response);
        exit;
    }

// The actual permission block (if/elseif/else) starts after this.
    // 5. Permission Check (Crucial - based on ORIGINAL client data's site_id)
    $can_edit_this_client = false;
    $originalClientData = getClientDetailsForEditing($pdo, $client_id); // Re-fetch for security

    if ($originalClientData && $originalClientData['profile']) {
        $original_client_site_id = $originalClientData['profile']['site_id'];
        if ($is_global_admin_or_director) {
            $can_edit_this_client = true;
        } elseif ($is_site_admin && $original_client_site_id === $session_site_id) {
            $can_edit_this_client = true;
        } elseif ($is_azwk_staff && $original_client_site_id == $session_site_id) {
            // Allow azwk_staff to edit clients if the client belongs to their site
            $can_edit_this_client = true;
        }
    } else {
        $response['message'] = "Client not found or could not be loaded for permission check.";
        echo json_encode($response);
        exit;
    }

    if (!$can_edit_this_client) {
        $response['message'] = "Permission Denied: You do not have permission to modify this client's profile.";
        echo json_encode($response);
        exit;
    }

    // 6. Process Updates
    try {
        $pdo->beginTransaction();
        $update_success = true;
        $audit_log_success = true;

        $old_profile_values = $originalClientData['profile'];
        $old_answers_map = [];
        foreach ($originalClientData['answers'] as $ans) {
            $old_answers_map[$ans['question_id']] = $ans['answer'];
        }

        // --- Update Profile Fields ---
        $profileDataToUpdate = [];
        if ($first_name !== $old_profile_values['first_name']) $profileDataToUpdate['first_name'] = $first_name;
        if ($last_name !== $old_profile_values['last_name']) $profileDataToUpdate['last_name'] = $last_name;
        if ($site_id_form !== (int)$old_profile_values['site_id']) $profileDataToUpdate['site_id'] = $site_id_form;
        if ($email_preference_jobs !== (int)$old_profile_values['email_preference_jobs']) $profileDataToUpdate['email_preference_jobs'] = $email_preference_jobs;

        if (!empty($profileDataToUpdate)) {
            if (!updateClientProfileFields($pdo, $client_id, $profileDataToUpdate)) {
                $update_success = false;
                $response['message'] = "Failed to update client profile information.";
            } else {
                foreach ($profileDataToUpdate as $field => $newValue) {
                    $oldValue = $old_profile_values[$field] ?? null;
                    $oldValueText = ($field === 'email_preference_jobs') ? ((int)$oldValue == 1 ? 'Opted In' : 'Opted Out') : (string)$oldValue;
                    $newValueText = ($field === 'email_preference_jobs') ? ((int)$newValue == 1 ? 'Opted In' : 'Opted Out') : (string)$newValue;
                    if (!logClientProfileChange($pdo, $client_id, $session_user_id, $field, $oldValueText, $newValueText)) {
                        $audit_log_success = false; // Log failure but continue
                    }
                }
            }
        }

        // --- Update Answers ---
        if ($update_success) {
            // Filter out null answers (invalid input) before saving, but keep empty strings ('')
            $valid_submitted_answers = array_filter($submitted_answers, fn($v) => $v !== null);

            // For client_answers, it's often simpler to delete existing and insert new ones for the given client & questions.
            // Or, more granularly, update existing, delete removed, insert new.
            // For this implementation, let's use the saveClientAnswers which should handle this logic.
            // We need to ensure saveClientAnswers can handle an array of [question_id => answer]
            // and compare against existing to log changes.

            // Re-fetch all global questions to ensure we process all possible questions
            $all_db_questions = getAllGlobalQuestions($pdo);
            $all_question_ids_from_db = array_map(fn($q) => $q['id'], $all_db_questions);

            // Delete all existing answers for this client for the questions that *could* be on the form
            // This is a simpler approach than figuring out diffs for deletion if a question was removed from form or no answer submitted
            // However, saveClientAnswers in client_data.php might already do this or an upsert.
            // Let's assume saveClientAnswers handles the logic of updating/inserting.
            // We will need to log changes carefully.

            if (!saveClientAnswers($pdo, $client_id, $valid_submitted_answers)) {
                 $update_success = false;
                 $response['message'] = "Failed to update client answers.";
            } else {
                // Log answer changes
                $all_relevant_question_ids = array_unique(array_merge(array_keys($old_answers_map), array_keys($valid_submitted_answers), $all_question_ids_from_db));

                foreach ($all_relevant_question_ids as $qid) {
                    $oldValue = $old_answers_map[$qid] ?? '';
                    $newValue = $valid_submitted_answers[$qid] ?? ''; // If not in submitted, means it was 'No Answer' or removed

                    $oldValueComparable = ($oldValue === null || $oldValue === '') ? '' : $oldValue;
                    $newValueComparable = ($newValue === null || $newValue === '') ? '' : $newValue;

                    if ($oldValueComparable !== $newValueComparable) {
                         $oldLogValue = ($oldValueComparable === '') ? 'No Answer' : $oldValueComparable;
                         $newLogValue = ($newValueComparable === '') ? 'No Answer' : $newValueComparable;
                         if (!logClientProfileChange($pdo, $client_id, $session_user_id, "question_id_{$qid}", $oldLogValue, $newLogValue)) {
                             $audit_log_success = false;
                         }
                    }
                }
            }
        }

        if ($update_success) {
            $pdo->commit();
            $response['success'] = true;
            $response['message'] = "Client details updated successfully." . (!$audit_log_success ? " (Warning: Some audit log entries may have failed)" : "");
        } else {
            $pdo->rollBack();
            // $response['message'] is already set if an update failed
            if (empty($response['message'])) { // Default if not set by specific failures
                 $response['message'] = "An error occurred while saving changes. Transaction rolled back.";
            }
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['message'] = "Database error: " . $e->getMessage();
        error_log("Database error in update_client_details_handler.php: " . $e->getMessage());
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['message'] = "General error: " . $e->getMessage();
        error_log("General error in update_client_details_handler.php: " . $e->getMessage());
    }

} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
exit;
?>