<?php
/**
 * Kiosk Manual Check-in Handler
 *
 * Handles POST requests from the manual check-in form on kiosk_checkin.php.
 * Validates input, performs security checks, inserts a check-in record
 * without a client_id link, and redirects back with status messages.
 *
 * Source: Arizona@Work Check-In System - Living Plan.php v1.40, Section 6
 */

// Start session - essential for CSRF, user auth, and flash messages
session_start();

// Include necessary files
require_once 'includes/db_connect.php'; // Provides $pdo (assuming connection setup returns PDO)
require_once 'includes/utils.php';     // Provides sanitize_input(), validation functions etc.
require_once 'includes/data_access/question_data.php'; // Provides getActiveQuestionsForSite()
require_once 'includes/data_access/checkin_data.php';  // Provides saveCheckinAnswers()

// --- Security Checks ---

// 1. Check Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Kiosk Manual Handler: Invalid request method.");
    // Set a generic error message or redirect silently
    $_SESSION['kiosk_message'] = "Error: Invalid request.";
    header('Location: kiosk_checkin.php');
    exit;
}

// 2. Check CSRF Token
// Assumes 'csrf_token' is generated and stored in $_SESSION['csrf_token'] on the form page
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    error_log("Kiosk Manual Handler: CSRF token mismatch.");
    $_SESSION['kiosk_message'] = "Security error. Please try submitting the form again.";
    // Optionally regenerate token here if needed elsewhere, but redirecting is key
    header('Location: kiosk_checkin.php');
    exit;
}
// It's good practice to unset the token after successful validation to prevent reuse,
// but ensure the form page regenerates it on load.
// unset($_SESSION['csrf_token']);


// 3. Check Staff Session and Role ('kiosk')
// Assumes session variables 'user_id', 'user_role', 'site_id' are set on staff login
// Check for PDO connection availability from db_connect.php
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log("Kiosk Manual Handler: PDO database connection not available.");
    $_SESSION['kiosk_message'] = "Database connection error. Please contact support.";
    $_SESSION['message_type'] = 'danger'; // Use consistent session keys if possible
    header('Location: kiosk_checkin.php');
    exit;
}

// 3. Check Staff Session and Role ('kiosk') and Site ID
$kiosk_site_id = $_SESSION['site_id'] ?? null; // Use a variable for clarity
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'kiosk' || !$kiosk_site_id) {
    error_log("Kiosk Manual Handler: Access denied. User ID: " . ($_SESSION['user_id'] ?? 'Not set') . ", Role: " . ($_SESSION['user_role'] ?? 'Not set') . ", Site ID: " . ($kiosk_site_id ?? 'Not set'));
    // Redirect to a generic page or login page. Avoid revealing specific failure reasons.
    $_SESSION['message'] = "Access denied. Please log in with appropriate privileges."; // Use generic message keys
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php'); // Redirect to the main login page
    exit;
}

// --- Form Data Processing & Validation ---

$errors = [];
$formData = []; // Store sanitized data for re-populating form on error

// Fetch active questions for this kiosk's site to validate against
$active_site_questions = [];
$expected_question_ids = [];
try {
    $active_site_questions = getActiveQuestionsForSite($pdo, $kiosk_site_id);
    $expected_question_ids = array_column($active_site_questions, 'id');
} catch (Exception $e) {
    error_log("Kiosk Manual Handler: Failed to fetch active questions for site ID {$kiosk_site_id}. Error: " . $e->getMessage());
    $errors['questions'] = "Could not load questions for validation. Please try again.";
    // Proceed without question validation if fetching failed? Or stop? Let's stop for now.
    $_SESSION['kiosk_errors'] = $errors; // Use consistent session keys
    $_SESSION['kiosk_form_data'] = $_POST; // Store raw POST data for repopulation
    header('Location: kiosk_checkin.php');
    exit;
}

// Define allowed answers for dynamic questions
$allowedAnswers = ['Yes', 'No'];

// Sanitize and retrieve form data
// Use filter_input for better security and clarity
$firstName = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_SPECIAL_CHARS);
$lastName = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_SPECIAL_CHARS);
$email = filter_input(INPUT_POST, 'client_email', FILTER_SANITIZE_EMAIL); // Use correct name 'client_email'
$submitted_questions = filter_input(INPUT_POST, 'question', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

// Store sanitized data for potential re-population (use original POST for repopulation)
$formData = $_POST; // Keep original POST data for repopulating form fields easily

// Validation Rules
if (empty($firstName)) {
    $errors['first_name'] = "First name is required.";
} elseif (strlen($firstName) > 100) { // Example length limit
    $errors['first_name'] = "First name is too long.";
}

if (empty($lastName)) {
    $errors['last_name'] = "Last name is required.";
} elseif (strlen($lastName) > 100) { // Example length limit
    $errors['last_name'] = "Last name is too long.";
}

// Email validation (allow empty, but validate if provided)
if ($email === false) { // Check if sanitization failed (invalid chars)
    $errors['client_email'] = "Invalid characters in email address.";
} elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['client_email'] = "Please enter a valid email address.";
} elseif (strlen($email) > 255) {
    $errors['client_email'] = "Email address is too long.";
}

// Validate dynamic questions
$validated_answers = [];
if (!empty($expected_question_ids)) {
    if ($submitted_questions === null || $submitted_questions === false) {
         $errors['questions'] = "Please answer all required questions.";
         // Add specific errors for each missing question
         foreach ($expected_question_ids as $qid) {
             $errors["question_{$qid}"] = "This question requires an answer.";
         }
    } else {
        foreach ($expected_question_ids as $qid) {
            if (!isset($submitted_questions[$qid])) {
                $errors["question_{$qid}"] = "This question requires an answer.";
            } elseif (!in_array($submitted_questions[$qid], $allowedAnswers, true)) {
                $errors["question_{$qid}"] = "Invalid answer submitted.";
            } else {
                // Store validated answer
                $validated_answers[$qid] = $submitted_questions[$qid];
            }
        }
        // Check if any expected questions were missing from the submission entirely
        if (count($validated_answers) !== count($expected_question_ids)) {
             if (!isset($errors['questions'])) { // Avoid duplicate generic message
                 $errors['questions'] = "Please answer all required questions.";
             }
        }
    }
}


// --- Core Logic ---

if (empty($errors)) {
    // Validation passed, proceed with database insertion using PDO
    $checkInId = null; // Initialize check-in ID

    // Prepare SQL statement for check_ins table (removed old q_* columns)
    $sql_checkin = "INSERT INTO check_ins (site_id, first_name, last_name, client_email, check_in_time, notified_staff_id, client_id)
                    VALUES (:site_id, :first_name, :last_name, :client_email, NOW(), NULL, NULL)"; // client_id is NULL for manual

    try {
        $stmt_checkin = $pdo->prepare($sql_checkin);

        if (!$stmt_checkin) {
             error_log("Kiosk Manual Handler DB Prepare Error (Checkin): " . implode(" | ", $pdo->errorInfo()));
             throw new Exception("Error preparing check-in record.");
        }

        // Bind parameters
        $emailToInsert = !empty($email) ? $email : null;
        $stmt_checkin->bindParam(':site_id', $kiosk_site_id, PDO::PARAM_INT);
        $stmt_checkin->bindParam(':first_name', $firstName, PDO::PARAM_STR);
        $stmt_checkin->bindParam(':last_name', $lastName, PDO::PARAM_STR);
        $stmt_checkin->bindParam(':client_email', $emailToInsert, PDO::PARAM_STR); // Bind null if empty

        if ($stmt_checkin->execute()) {
            // Check-in Success
            $checkInId = $pdo->lastInsertId();

            // Now, save the dynamic answers
            if (!empty($validated_answers) && $checkInId) {
                $answers_saved = saveCheckinAnswers($pdo, (int)$checkInId, $validated_answers);
                if (!$answers_saved) {
                    // Log error, but maybe don't fail the whole check-in?
                    // Or add to errors array to inform user. Let's add an error.
                    error_log("Kiosk Manual Handler: Failed to save dynamic answers for checkin ID {$checkInId}.");
                    $errors['answers'] = "Check-in recorded, but failed to save question answers. Please contact support.";
                    // Set a less positive success message
                     $_SESSION['message'] = "Check-in partially successful for " . htmlspecialchars($firstName) . " " . htmlspecialchars($lastName) . ". Answers could not be saved.";
                     $_SESSION['message_type'] = 'warning';

                } else {
                     // Full success
                     $_SESSION['message'] = "Check-in successful for " . htmlspecialchars($firstName) . " " . htmlspecialchars($lastName) . ".";
                     $_SESSION['message_type'] = 'success';
                }
            } else {
                 // No answers to save, or check-in failed (checkInId is null) - still consider check-in successful if checkInId exists
                 if ($checkInId) {
                     $_SESSION['message'] = "Check-in successful for " . htmlspecialchars($firstName) . " " . htmlspecialchars($lastName) . ".";
                     $_SESSION['message_type'] = 'success';
                 } else {
                      // This case should ideally not be reached if execute() succeeded but lastInsertId failed
                      throw new Exception("Check-in insertion reported success, but failed to get ID.");
                 }
            }

            $_SESSION['last_checkin_id'] = $checkInId; // Store ID if needed

            // Placeholder: Future AI Enrollment Check/Logic
            // if ($emailToInsert) {
            //     // check_ai_enrollment_status($emailToInsert, $checkInId);
            // }

            // Placeholder: Future Notification Trigger
            // trigger_staff_notification($siteId, $checkInId, $firstName, $lastName);

            // Clear any previous error/form data session variables
            unset($_SESSION['kiosk_errors']);
            unset($_SESSION['kiosk_form_data']);

            // Placeholder: Future Notification Trigger
            // trigger_staff_notification($siteId, $checkInId, $firstName, $lastName);

            // Clear any previous error/form data session variables ONLY on full success
            if (empty($errors['answers'])) { // Only clear if answers also saved correctly
                 unset($_SESSION['kiosk_errors']);
                 unset($_SESSION['kiosk_form_data']);
            } else {
                 // If answers failed, keep form data for potential correction/resubmit?
                 // Or just display warning and clear form? Let's clear form data but keep error.
                 unset($_SESSION['kiosk_form_data']);
                 $_SESSION['kiosk_errors'] = $errors; // Ensure answer error is stored
            }


        } else {
            // Database execution error for check-in
            error_log("Kiosk Manual Handler DB Execute Error (Checkin): " . implode(" | ", $stmt_checkin->errorInfo()));
            throw new Exception("Error recording check-in.");
        }

    } catch (Exception $e) {
        error_log("Kiosk Manual Handler Exception: " . $e->getMessage());
        $errors['database'] = $e->getMessage() . " Please try again later or contact support.";
        $_SESSION['kiosk_errors'] = $errors;
        $_SESSION['kiosk_form_data'] = $formData; // Keep data for correction
        // Clear any success message
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }

} else {
    // Validation failed
    $_SESSION['kiosk_errors'] = $errors;
    $_SESSION['kiosk_form_data'] = $formData; // Store submitted data for re-population
    // Clear any success message from previous attempts
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// --- Redirect ---
// Always redirect back to the kiosk check-in page after processing
header('Location: kiosk_checkin.php');
exit;

?>