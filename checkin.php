<?php
/*
 * File: checkin.php
 * Path: /checkin.php
 * Created: 2024-08-01 11:45:00 MST (Adjust Timezone)
 * Updated: 2025-04-14 - Corrected brace structure in POST handler.
 *
 * Description: Handles the client check-in process. Displays dynamic questions
 *              assigned to the site via site_questions/global_questions.
 *              Saves check-in data to dynamic columns. Sends email notifications.
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/vendor/autoload.php'; // Composer Autoloader
require_once 'includes/db_connect.php';         // Provides $pdo
require_once 'includes/auth.php';             // Authentication & Session validation
require_once 'includes/utils.php';              // Utility functions
require_once 'includes/data_access/site_data.php'; // Site data functions
require_once 'includes/data_access/question_data.php'; // Question data functions
require_once 'includes/data_access/notifier_data.php'; // Notifier data functions
require_once 'includes/data_access/ad_data.php'; // Ad data functions
require_once 'includes/data_access/checkin_data.php'; // Checkin data functions

// --- Load Configuration ---
$config = null;
// Go up one level from public_html (__DIR__) and then into the 'config' directory
$configPath = dirname(__DIR__) . '/config/config.ini';
if (!file_exists($configPath)) {
    error_log("CRITICAL Checkin Error: Configuration file not found at " . $configPath);
} else {
    $config = parse_ini_file($configPath, true);
    if ($config === false) { $config = null; error_log("CRITICAL Checkin Error: Failed to parse config file: " . $configPath); }
    elseif (!isset($config['smtp'])) { error_log("Checkin Warning: Config file is missing [smtp] section."); }
}

// --- Site Determination Logic ---
$site_id_for_checkin = null;
$site_name = 'Unknown Site';
$site_email_description = 'Provide your email for follow-up:'; // Default
$config_error = null;

if (!isset($pdo) || !$pdo instanceof PDO) { die("A critical database error occurred. Ref: DB_CONN_FAIL"); }
if (!isset($_SESSION['active_role'])) { header('Location: index.php?status=error&reason=session_expired'); exit; }
$current_role = $_SESSION['active_role'];

$manual_site_id = filter_input(INPUT_GET, 'manual_site_id', FILTER_VALIDATE_INT);
if ($manual_site_id && in_array($current_role, ['administrator', 'director', 'azwk_staff', 'outside_staff'])) {
    // Use data access function to get site details
    $site_details = getActiveSiteDetailsById($pdo, $manual_site_id);
    if ($site_details) {
        $site_id_for_checkin = (int)$site_details['id'];
        $site_name = $site_details['name'];
        if (!empty($site_details['email_collection_desc'])) {
            $site_email_description = $site_details['email_collection_desc'];
        }
        // error_log("Checkin: Using manual_site_id {$site_id_for_checkin} for role {$current_role}");
    } else {
        // getActiveSiteDetailsById returns null on error or not found/inactive
        $config_error = "Error: Site specified (" . htmlspecialchars($manual_site_id) . ") invalid/inactive.";
        // Log is handled within the function
    }
}

// If no valid manual site ID, use the session site ID
if ($site_id_for_checkin === null && $config_error === null) {
    $session_site_id = $_SESSION['active_site_id'] ?? null;
    if ($session_site_id !== null && is_numeric($session_site_id)) {
        // Use data access function to get site details
        $site_details = getActiveSiteDetailsById($pdo, (int)$session_site_id);
        if ($site_details) {
            $site_id_for_checkin = (int)$site_details['id']; // Use ID from fetched details
            $site_name = $site_details['name'];
            if (!empty($site_details['email_collection_desc'])) {
                $site_email_description = $site_details['email_collection_desc'];
            }
            // error_log("Checkin: Using session_site_id {$site_id_for_checkin} for role {$current_role}");
        } else {
            // getActiveSiteDetailsById returns null on error or not found/inactive
            $config_error = "Error: Your assigned site (ID: " . htmlspecialchars($session_site_id) . ") inactive/not found.";
            // Log is handled within the function
        }
    }
}

// If still no valid site ID, exit with error
if ($site_id_for_checkin === null || $config_error !== null) {
    ob_start();
    // Ensure main.css is linked even for error page
    echo '<!DOCTYPE html><html lang="en"><head><title>Error</title><link rel="stylesheet" href="assets/css/main.css?v='.filemtime(__DIR__ . '/assets/css/main.css').'"></head><body class="checkin-page">';
    echo '<div class="message-area message-error m-3 p-3 mx-auto bg-white checkin-container-max-width">'; // Style error box similar to checkin container
    echo '<h1>Configuration Error</h1><p>' . htmlspecialchars($config_error ?: "Error: Cannot determine site context. Ref: SITE_CTX_FAIL") . '</p>';
    echo '<p><a href="index.php">Return to Login</a></p>';
    echo '</div></body></html>';
    ob_end_flush();
    exit;
}
// --- END Site Determination ---

$site_id = $site_id_for_checkin; // Use this validated site ID

// --- Fetch Site Boolean Configs & Data for Form Display ---
$assigned_questions = [];
$staff_notifiers = [];
$allow_email_collection = false; // Default to false
$allow_notifier = false; // Default to false
$active_site_ads = [];
$left_ad = null;
$right_ad = null;

try {
    // Fetch boolean configurations using data access function
    $checkin_configs = getSiteCheckinConfigFlags($pdo, $site_id);
    $allow_email_collection = $checkin_configs['allow_email_collection'];
    $allow_notifier = $checkin_configs['allow_notifier'];
    // error_log("[DEBUG checkin.php] Site {$site_id} Config Flags: AllowEmail=" . ($allow_email_collection ? '1' : '0') . ", AllowNotifier=" . ($allow_notifier ? '1' : '0'));

    // Fetch active questions assigned to this site using data access function
    $assigned_questions = getActiveQuestionsForSite($pdo, $site_id);
    if ($assigned_questions === []) {
         error_log("Checkin Warning: No active questions found or error fetching questions for site {$site_id}.");
    }

    // Fetch active staff notifiers (only if notifier feature is enabled)
    if ($allow_notifier) {
        $staff_notifiers = getActiveStaffNotifiersForSite($pdo, $site_id);
        if ($staff_notifiers === []) {
             error_log("Checkin Warning: Notifier enabled for site {$site_id}, but no active staff notifiers found or error fetching them.");
        }
    }

    // Fetch active ads using data access function
    $active_site_ads = getActiveAdsForSite($pdo, $site_id);
    if (!empty($active_site_ads)) {
        $left_ad = array_shift($active_site_ads); // Get the first random ad for left
        if (!empty($active_site_ads)) {
            $right_ad = array_shift($active_site_ads); // Get the next random ad for right
        }
    }
    // error_log("Checkin Ad Fetch - Site ID {$site_id} - Left Ad: " . ($left_ad ? $left_ad['ad_title'] ?? $left_ad['ad_type'] : 'None') . ", Right Ad: " . ($right_ad ? $right_ad['ad_title'] ?? $right_ad['ad_type'] : 'None'));


} catch (PDOException $e) { // Catch potential PDO errors during fetch
    error_log("Checkin PDOException - Fetching config/questions/notifiers for site {$site_id}: " . $e->getMessage());
    $config_error = "Error loading check-in configuration."; // Show non-fatal error on page
} catch (Exception $e) { // Catch other potential errors
    error_log("Checkin General Exception - Fetching config/questions/notifiers for site {$site_id}: " . $e->getMessage());
    $config_error = "An unexpected error occurred while loading configuration.";
}

// --- Variable Setup for Form ---
$submission_message = $config_error ?? ''; // Display config fetch errors if any
$message_type = !empty($config_error) ? 'error' : '';
// Get flash messages from session if redirected after POST
if (isset($_SESSION['flash_message'])) {
    $submission_message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}
$form_data = $_SESSION['form_data'] ?? []; // Repopulate form on validation error
unset($_SESSION['form_data']);


// --- Process Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        // Token mismatch, missing, or session expired.
        error_log("CSRF token validation failed for checkin.php from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        // Set a user-friendly error message and redirect
        $_SESSION['flash_message'] = 'Security token validation failed. Please try submitting the form again.';
        $_SESSION['flash_type'] = 'error';
        // Preserve manual_site_id if it was used
        $manual_site_id_get = filter_input(INPUT_GET, 'manual_site_id', FILTER_VALIDATE_INT);
        $redirect_url_csrf = "checkin.php";
        if ($manual_site_id_get) {
            $redirect_url_csrf .= "?manual_site_id=" . $manual_site_id_get;
        }
        header("Location: " . $redirect_url_csrf);
        exit; // Stop processing immediately
    }
    // --- End CSRF Token Verification ---



    // 1. Sanitize and Validate Input Data
    $first_name = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
    $last_name = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_SPECIAL_CHARS) ?: '');
    $client_email_input = trim(filter_input(INPUT_POST, 'collect_email', FILTER_SANITIZE_EMAIL));
    $client_email = ''; // Default
    $notified_staff_id = filter_input(INPUT_POST, 'notify_staff', FILTER_VALIDATE_INT);
    $check_in_time = date('Y-m-d H:i:s'); // Define check-in time

    $errors = [];
    if (empty($first_name)) $errors[] = "First Name is required.";
    if (empty($last_name)) $errors[] = "Last Name is required.";

    // Validate email only if allowed and provided
    if ($allow_email_collection) {
        if (!empty($client_email_input)) {
            if (filter_var($client_email_input, FILTER_VALIDATE_EMAIL)) {
                $client_email = $client_email_input;
            } else {
                $errors[] = "The provided email address format is invalid.";
            }
        }
    }

    // Validate dynamic questions
    $question_answers_for_check_ins = []; // For existing q_* columns in check_ins table
    $question_answers_for_checkin_answers_table = []; // For the separate checkin_answers table

    foreach ($assigned_questions as $question) {
        $original_question_id = $question['id'];
        $q_input_key = 'q_' . $original_question_id;
        // Sanitize raw input first. FILTER_SANITIZE_SPECIAL_CHARS is suitable for text that might be displayed or logged.
        $raw_answer_value = filter_input(INPUT_POST, $q_input_key, FILTER_SANITIZE_SPECIAL_CHARS);
        
        $processed_answer_value = null;
        if ($raw_answer_value !== null) {
            // Trim whitespace and convert to uppercase for comparison
            $processed_answer_value = strtoupper(trim($raw_answer_value));
        }

        // $question_title_base = $question['question_title']; // This line is not strictly needed for the new logic here.

        if ($processed_answer_value === 'YES') {
            // Store with exact casing 'Yes' for ENUM compatibility
            $question_answers_for_checkin_answers_table[$original_question_id] = 'Yes';
        } elseif ($processed_answer_value === 'NO') {
            // Store with exact casing 'No' for ENUM compatibility
            $question_answers_for_checkin_answers_table[$original_question_id] = 'No';
        } else {
            // The answer was not 'YES' or 'NO'.
            // This includes cases where the question was not answered (raw_answer_value is null)
            // or an unexpected value was submitted.

            // Log if an actual unexpected value was submitted (and it wasn't just an empty string after processing)
            if ($raw_answer_value !== null && $processed_answer_value !== '' && $processed_answer_value !== null) {
                error_log("Checkin Info: Unexpected value received for dynamic question ID {$original_question_id} for site {$site_id}. Raw Value: '{$raw_answer_value}'. Processed Value: '{$processed_answer_value}'. This answer will be skipped for saving.");
            }
            
            // Add to errors array. This ensures that if a question is mandatory,
            // submitting an invalid answer or no answer is still treated as a validation error for the user.
            $errors[] = "Please answer the question: \"" . htmlspecialchars($question['question_text']) . "\" with 'Yes' or 'No'.";
        }
        // The deprecated q_* column logic for the check_ins table remains outside this modification's scope.
    }

    // Validate selected staff notifier
    if ($allow_notifier) {
        if ($notified_staff_id) {
            if (!isset($staff_notifiers[$notified_staff_id])) {
                $errors[] = "The selected staff member is not valid.";
                $notified_staff_id = null;
            }
        } else {
             $notified_staff_id = null;
        }
    } else {
         $notified_staff_id = null;
    }

// error_log("[DEBUG checkin.php POST] Site ID for checkin: " . $site_id);
// error_log("[DEBUG checkin.php POST] Raw \$_POST['notify_staff']: " . ($_POST['notify_staff'] ?? 'NOT SET'));
// error_log("[DEBUG checkin.php POST] \$notified_staff_id (staff_notifications.id or null) before save: " . print_r($notified_staff_id, true));
// error_log("[DEBUG checkin.php POST] \$staff_notifiers array structure (should be keyed by staff_notifications.id): " . print_r($staff_notifiers, true));
    // If no validation errors, proceed to save
    if (empty($errors)) {
        error_log("Checkin Manual: Data being prepared for checkin_answers table: " . print_r($question_answers_for_checkin_answers_table, true));
        error_log("Checkin Manual: Data for q_* columns in check_ins (should be empty or not used for q_* population): " . print_r($question_answers_for_check_ins, true));

        // Prepare data array for the saveCheckin function
        $checkin_data_to_save = [
            'site_id' => $site_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'check_in_time' => $check_in_time, // Use defined time
            'notified_staff_id' => $notified_staff_id,
            'client_email' => $client_email,
            'question_answers' => [], // Pass empty array as q_* columns are deprecated for dynamic answers
            'answers_for_separate_table' => $question_answers_for_checkin_answers_table // This is for checkin_answers table
        ];

        // Call the data access function to save the check-in
        $check_in_id = saveCheckin($pdo, $checkin_data_to_save);

        if ($check_in_id !== false) {

            // --- START: AI Agent Email Notification Logic ---
            try {
                // Fetch AI Agent settings using existing data access function
                // Note: $allow_email_collection is already fetched earlier
                $ai_agent_enabled = (int)(getSiteConfigurationValue($pdo, $site_id, 'ai_agent_email_enabled') ?? 0);
                $ai_agent_email = getSiteConfigurationValue($pdo, $site_id, 'ai_agent_email_address');
                $ai_agent_message_template = getSiteConfigurationValue($pdo, $site_id, 'ai_agent_email_message');

                // Check conditions for sending AI Agent email
                if (
                    !empty($client_email) &&         // Client provided an email
                    $allow_email_collection == 1 &&  // General email collection is ON
                    $ai_agent_enabled == 1 &&       // AI Agent feature is ON
                    !empty($ai_agent_email) &&      // AI agent email is configured
                    filter_var($ai_agent_email, FILTER_VALIDATE_EMAIL) && // AI agent email is valid
                    !empty($ai_agent_message_template) // AI agent message template is configured
                ) {
                    // Construct the email body
                    $ai_email_body = $ai_agent_message_template;
                    $ai_email_body .= "\n\n--- Client Details ---"; // Add separator
                    $ai_email_body .= "\nFirst Name: " . $first_name;
                    $ai_email_body .= "\nLast Name: " . $last_name;
                    $ai_email_body .= "\nEmail: " . $client_email;
                    $ai_email_body .= "\nCheck-in Time: " . $check_in_time;
                    $ai_email_body .= "\nSite: " . $site_name;

                    // Construct the subject
                    $ai_email_subject = "Client Check-in Information: " . $first_name . " " . $last_name;

                    // Use a new PHPMailer instance for the AI agent email
                    $ai_mail = new PHPMailer(true);
                    try {
                        // Configure SMTP settings (reuse from main config if available)
                        if ($config && isset($config['smtp'])) {
                            $ai_mail->isSMTP(); $ai_mail->Host = $config['smtp']['host']; $ai_mail->SMTPAuth = true;
                            $ai_mail->Username = $config['smtp']['username']; $ai_mail->Password = $config['smtp']['password'];
                            $ai_smtp_port = $config['smtp']['port'] ?? 587; $ai_mail->Port = (int)$ai_smtp_port;
                            $ai_mail->SMTPSecure = ($ai_smtp_port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                            $ai_mail->setFrom($config['smtp']['from_email'], $config['smtp']['from_name'] . ' (AI Agent)'); // Indicate source
                        } else {
                             error_log("AI Agent Email Error: SMTP config missing. Cannot send. Check-in ID: {$check_in_id}");
                             throw new Exception("SMTP configuration is missing.");
                        }

                        $ai_mail->addAddress($ai_agent_email);
                        $ai_mail->isHTML(false); // Send as plain text
                        $ai_mail->Subject = $ai_email_subject;
                        $ai_mail->Body = $ai_email_body;
                        $ai_mail->send();
                        error_log("AI Agent email sent successfully to: " . $ai_agent_email . " for Check-in ID: {$check_in_id}");

                    } catch (Exception $e_ai) {
                        // Log the error if sending failed
                        error_log("Error sending AI Agent email to " . $ai_agent_email . " for Check-in ID: {$check_in_id}. Error: " . $e_ai->getMessage());
                        // Do not overwrite the main submission message, just log the failure.
                    }
                } else {
                     // Optional: Log why the AI email wasn't sent if needed for debugging
                     if ($ai_agent_enabled == 1 && !empty($client_email) && $allow_email_collection == 1) {
                         if (empty($ai_agent_email) || !filter_var($ai_agent_email, FILTER_VALIDATE_EMAIL)) {
                             error_log("AI Agent email NOT sent for Check-in ID {$check_in_id}: AI Agent email address missing or invalid.");
                         } elseif (empty($ai_agent_message_template)) {
                             error_log("AI Agent email NOT sent for Check-in ID {$check_in_id}: AI Agent message template missing.");
                         }
                     }
                }
            } catch (Exception $e_fetch) {
                 error_log("Error fetching AI Agent configuration for Check-in ID {$check_in_id}: " . $e_fetch->getMessage());
                 // Non-fatal error, proceed without AI email
            }
            // --- END: AI Agent Email Notification Logic ---

            // --- Send Email Notification ---
 // --- BEGIN REPLACEMENT: NEW Staff Notifier Email Block ---

            // Check if staff notification is enabled for the site AND a specific staff member was selected/found
            if ($allow_notifier && $notified_staff_id && isset($staff_notifiers[$notified_staff_id])) {

                // Check if SMTP settings are available in the global config
                if ($config && isset($config['smtp'])) {
                    $notifier = $staff_notifiers[$notified_staff_id];
                    $notifier_email = $notifier['staff_email'];
                    $notifier_name = $notifier['staff_name'];

                    // Validate the notifier's email address
                    if (filter_var($notifier_email, FILTER_VALIDATE_EMAIL)) {
                        // Proceed only if the email is valid
                        // Ensure PHPMailer class is available (adjust path if needed, or use Composer autoload)
                        // require_once 'vendor/phpmailer/phpmailer/src/Exception.php';
                        // require_once 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
                        // require_once 'vendor/phpmailer/phpmailer/src/SMTP.php';
                        // Note: If using Composer's autoload.php, these requires are likely not needed here.

                        // TO THIS:
                        $mail = new PHPMailer(true);

                        try {
                            // --- Server settings ---
                            $mail->isSMTP();
                            $mail->Host = $config['smtp']['host'];
                            $mail->SMTPAuth = true;
                            $mail->Username = $config['smtp']['username'];
                            $mail->Password = $config['smtp']['password'];
                            $smtp_port = $config['smtp']['port'] ?? 587;
                            $mail->Port = (int)$smtp_port;
                            $mail->SMTPSecure = ($smtp_port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                            // --- Recipient ---
                            // Set From address using config values
                            $from_email = $config['smtp']['from_email'] ?? 'noreply@example.com'; // Provide a fallback
                            $from_name = $config['smtp']['from_name'] ?? 'Check-In System';
                            $mail->setFrom($from_email, $from_name);

                            // Add the specific staff notifier as the primary recipient
                            $mail->addAddress($notifier_email, $notifier_name);

                            // --- BEGIN REVISED Content for Staff Notifier ---
                $mail->isHTML(true); // Use HTML format
                // Keep subject specific to staff notification? Or revert to generic? Let's keep it specific for now.
                $mail->Subject = 'Staff Notification: Client Check-in at ' . htmlspecialchars($site_name);

                // Build the detailed email body again
                $emailBody = "<h2>Staff Notification</h2>";
                $emailBody .= "<p>A client has checked in up front and wanted you to know:</p>"; // New introductory phrase
                $emailBody .= "<hr>"; // Separator
                $emailBody .= "<p><strong>Site:</strong> " . htmlspecialchars($site_name) . "</p>";
                $emailBody .= "<p><strong>Name:</strong> " . htmlspecialchars($first_name) . " " . htmlspecialchars($last_name) . "</p>";
                $emailBody .= "<p><strong>Time:</strong> " . htmlspecialchars($check_in_time) . "</p>";

                // Include Client Email if collected and allowed
                if ($allow_email_collection && !empty($client_email)) {
                     $emailBody .= "<p><strong>Client Email:</strong> " . htmlspecialchars($client_email) . "</p>";
                } else {
                     $emailBody .= "<p><strong>Client Email:</strong> Not Provided / Not Collected</p>";
                }

                // Include Question Responses (Requires $question_answers and $assigned_questions to be available)
                if (!empty($question_answers)) {
                    $emailBody .= "<h3>Responses:</h3><ul>";
                    // Build mapping from column name (q_...) to question text
                    // Ensure $assigned_questions is populated before this email block!
                    $col_to_text_map = [];
                    if (isset($assigned_questions) && is_array($assigned_questions)) {
                         // Ensure sanitize_title_to_base_name function is available!
                         if (!function_exists('sanitize_title_to_base_name')) {
                              error_log("CRITICAL: sanitize_title_to_base_name function missing in checkin.php email generation.");
                              // Add fallback or skip question details
                         } else {
                              foreach ($assigned_questions as $q_data) {
                                   $base_name = sanitize_title_to_base_name($q_data['question_title']);
                                   if (!empty($base_name)) {
                                       $col_name = 'q_' . $base_name;
                                       $col_to_text_map[$col_name] = $q_data['question_text'];
                                   }
                              }
                         }
                    } else {
                         error_log("WARNING: \$assigned_questions variable not available for email generation in checkin.php.");
                         // Consider adding a note to the email body
                    }

                    // Loop through answers and add them using the mapped text
                    foreach($question_answers as $col_name => $answer){
                        $question_text = $col_to_text_map[$col_name] ?? "Question (" . htmlspecialchars($col_name) . ")"; // Fallback if text mapping failed
                        $emailBody .= "<li><strong>" . htmlspecialchars($question_text) . ":</strong> " . htmlspecialchars($answer) . "</li>";
                    }
                    $emailBody .= "</ul>";
                } else {
                     $emailBody .= "<p>No specific question responses recorded for this check-in.</p>";
                }

                // Add Check-in ID for reference
                if (isset($check_in_id) && $check_in_id) {
                     $emailBody .= "<p>(Check-in Record ID: " . htmlspecialchars($check_in_id) . ")</p>";
                }

                // Assign to PHPMailer body
                $mail->Body = $emailBody;

                // Create a slightly more detailed AltBody (plain text)
                $altBody = "Staff Notification\n\nA client has checked in up front and wanted you to know:\n";
                $altBody .= "--------------------\n";
                $altBody .= "Site: " . $site_name . "\n";
                $altBody .= "Name: " . $first_name . " " . $last_name . "\n";
                $altBody .= "Time: " . $check_in_time . "\n";
                $altBody .= "Client Email: " . ($allow_email_collection && !empty($client_email) ? $client_email : 'Not Provided / Not Collected') . "\n";
                if (!empty($question_answers)) {
                    $altBody .= "\nResponses:\n";
                     // Simplified plain text for answers
                     foreach($question_answers as $col_name => $answer){
                          $question_text = $col_to_text_map[$col_name] ?? "Question (" . $col_name . ")";
                          $altBody .= "- " . $question_text . ": " . $answer . "\n";
                     }
                } else {
                     $altBody .= "\nNo specific question responses recorded.\n";
                }
                $altBody .= "\n(Check-in Record ID: " . ($check_in_id ?? 'N/A') . ")";
                $mail->AltBody = $altBody;

                // --- END REVISED Content for Staff Notifier ---
                            // --- Send the email ---
                            $mail->send();
                            // Optional: Log success for this specific notification
                            error_log("Staff notification email sent successfully to {$notifier_email} for check-in ID: {$check_in_id}");

                        } catch (Exception $e) {
                            // Log error if sending this specific notification failed
                            error_log("Staff Notification Email Send Error to {$notifier_email} for check-in ID {$check_in_id}: {$mail->ErrorInfo}");
                            // Optional: Modify the main user message or type if desired, e.g.:
                            // if ($message_type === 'success') { $message_type = 'warning'; $submission_message .= ' (Staff notification failed)'; }
                        }
                    } else {
                        // Log warning if the selected notifier has an invalid email
                        error_log("Staff Notification Email Skipped: Invalid notifier email '{$notifier_email}' for staff ID {$notified_staff_id}. Check-in ID: {$check_in_id}");
                    }
                } else {
                    // Log warning if SMTP settings are missing for the notifier email
                    error_log("Staff Notification Email Skipped: SMTP config missing or invalid. Check-in ID: {$check_in_id}");
                }
            } // --- END REPLACEMENT: NEW Staff Notifier Email Block ---

         } else { // saveCheckin returned false
             $submission_message = "Error saving check-in. Please try again or contact support. Ref: SAVE_FAIL"; $message_type = 'error'; $_SESSION['form_data'] = $_POST; // Keep form data for retry
             // Detailed error logged within saveCheckin function
        }
    } // Closes if (empty($errors))
    else { // Validation errors
        $submission_message = "Please correct the following errors:<br>" . implode("<br>", $errors); $message_type = 'error'; $_SESSION['form_data'] = $_POST; // Keep form data to display errors
    } // Closes else for validation errors

     // Redirect back to checkin page to show message and clear POST
     $_SESSION['flash_message'] = $submission_message; $_SESSION['flash_type'] = $message_type;
     $redirect_url = "checkin.php"; if ($manual_site_id && $manual_site_id == $site_id) $redirect_url .= "?manual_site_id=" . $site_id;
     header("Location: " . $redirect_url); exit;

} // --- End POST Handling ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
<!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <title>Welcome - Check In - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" type="image/png" href="assets/img/flaviconlogo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Link main stylesheet -->
    <link rel="stylesheet" href="assets/css/main.css?v=<?php echo filemtime(__DIR__ . '/assets/css/main.css'); ?>">
    <link rel="stylesheet" href="assets/css/kiosk.css?v=<?php echo filemtime(__DIR__ . '/assets/css/kiosk.css'); ?>">
     <!-- Inline styles ONLY for Google Translate Widget -->
     <style type="text/css">
        /* Google Translate */
        #google_translate_element { font-size: 0.9em; }
        .goog-te-gadget-simple { border: 1px solid #d4d4d4; background-color: #f9f9f9; padding: 0.3em 0.6em; border-radius: 4px; opacity: 0.8; transition: opacity 0.3s ease; }
        .goog-te-gadget-simple:hover { opacity: 1.0; }
        .goog-te-gadget-icon { display: none !important; }
        .goog-te-menu-frame { box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3); border: 1px solid #d4d4d4; }
        body > .skiptranslate { display: none !important; }
        body { top: 0px !important; }
     </style>
</head>
<body class="checkin-page"> <!-- Add class for main.css targeting -->

    <!-- Google Translate Widget -->
     <!-- ================== NEW WRAPPER ================== -->
    <div class="page-wrapper-with-ads">

        <!-- Camera Column (Left) -->
        <div class="camera-column">
            <!-- QR Code Reader Placeholder -->
            <div id="qr-reader" class="my-3 mx-auto"></div>
            <div id="qr-reader-results" class="mt-3 text-center"></div>
        </div>

        <!-- Main Content Column (Center) -->
        <div class="main-content-column">
            <!-- Logo -->
            <div class="checkin-logo">
                <img src="assets/img/logo.jpg" alt="Arizona@Work Logo">
            </div>
            <!-- Form Container -->
            <div class="checkin-container" id="checkin-form-container">
                <h1>Welcome to Arizona@Work <?php echo htmlspecialchars($site_name); ?>!</h1>
                <h2>Please Check In</h2>

                <?php if (!empty($submission_message)): ?>
                    <div class="message-area message-<?php echo htmlspecialchars($message_type); ?>" id="submission-message">
                        <?php echo $submission_message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="checkin.php<?php echo $manual_site_id ? '?manual_site_id='.$manual_site_id : ''; ?>" id="checkin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name:</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name:</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <?php if (!empty($assigned_questions) || ($allow_notifier && !empty($staff_notifiers)) || $allow_email_collection): ?><hr><?php endif; ?>

                    <?php if (!empty($assigned_questions)): ?>
                        <?php foreach ($assigned_questions as $question):
                            $q_key = 'q_' . $question['id'];
                            $current_answer = $form_data[$q_key] ?? null; ?>
                <div class="inline-question-group"> <!-- Simplified: removed form-group, col-form-label from direct children -->
                    <label class="question-text-label" for="<?php echo $q_key; ?>_yes"><?php echo htmlspecialchars($question['question_text']); ?>:</label>
                    <div class="radio-options-wrapper">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="<?php echo $q_key; ?>" id="<?php echo $q_key; ?>_yes" value="yes" <?php echo (isset($client_answers[$question['id']]) && $client_answers[$question['id']] == 'yes' || $current_answer === 'YES' || $current_answer === 'yes') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo $q_key; ?>_yes">Yes</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="<?php echo $q_key; ?>" id="<?php echo $q_key; ?>_no" value="no" <?php echo (isset($client_answers[$question['id']]) && $client_answers[$question['id']] == 'no' || $current_answer === 'NO' || $current_answer === 'no') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo $q_key; ?>_no">No</label>
                        </div>
                    </div>
                </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($allow_notifier && !empty($staff_notifiers)): $selected_notifier = $form_data['notify_staff'] ?? ''; ?>
                         <?php if ($allow_email_collection): ?><hr><?php endif; ?>
                        <div class="form-group"><label for="notify_staff" class="form-label">Notify Staff Member (Optional):</label><div class="description">Select staff member if you need specific assistance.</div><select id="notify_staff" name="notify_staff" class="form-control"><option value="">-- No Specific Staff Needed --</option><?php foreach ($staff_notifiers as $id => $data): ?><option value="<?php echo $id; ?>" <?php echo ($selected_notifier == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($data['staff_name']); ?></option><?php endforeach; ?></select></div>
                    <?php endif; ?>

                    <?php if ($allow_email_collection): ?>
                         <hr><div class="form-group"><label for="collect_email" class="form-label"><?php echo htmlspecialchars($site_email_description); ?> (Optional)</label><input type="email" id="collect_email" name="collect_email" class="form-control" value="<?php echo htmlspecialchars($form_data['collect_email'] ?? ''); ?>" placeholder="your.email@example.com" pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$" title="Please enter a valid email address."></div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">Check In</button>
                </form>
            </div> <!-- End .checkin-container -->
        </div> <!-- END Main Content Column (Center) -->

        <!-- Ads Column (Right) -->
        <div class="ads-column-right">
            <!-- Ad 1 (formerly $left_ad) -->
            <div class="ad-content-wrapper mb-3"> <!-- Added mb-3 for spacing -->
                <?php if ($left_ad): ?>
                    <div class="ad-content">
                        <?php if ($left_ad['ad_type'] === 'text' && !empty($left_ad['ad_text'])): ?>
                            <?php echo nl2br(htmlspecialchars($left_ad['ad_text'])); ?>
                        <?php elseif ($left_ad['ad_type'] === 'image' && !empty($left_ad['image_path'])):
                            $image_url_1 = htmlspecialchars($left_ad['image_path']); // Use different var name
                            if (!filter_var($image_url_1, FILTER_VALIDATE_URL) && $image_url_1[0] !== '/' && strpos($image_url_1, '://') === false) {
                                $image_url_1 = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($image_url_1);
                            }
                        ?>
                            <img src="<?php echo $image_url_1; ?>" alt="<?php echo htmlspecialchars($left_ad['ad_title'] ?: 'Advertisement 1'); ?>">
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- <div class="ad-placeholder">Ad 1 Placeholder</div> -->
                <?php endif; ?>
            </div>

            <!-- Ad 2 (formerly $right_ad) -->
            <div class="ad-content-wrapper">
                <?php if ($right_ad): ?>
                    <div class="ad-content">
                        <?php if ($right_ad['ad_type'] === 'text' && !empty($right_ad['ad_text'])): ?>
                            <?php echo nl2br(htmlspecialchars($right_ad['ad_text'])); ?>
                        <?php elseif ($right_ad['ad_type'] === 'image' && !empty($right_ad['image_path'])):
                            $image_url_2 = htmlspecialchars($right_ad['image_path']); // Use different var name
                            if (!filter_var($image_url_2, FILTER_VALIDATE_URL) && $image_url_2[0] !== '/' && strpos($image_url_2, '://') === false) {
                                $image_url_2 = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($image_url_2);
                            }
                        ?>
                            <img src="<?php echo $image_url_2; ?>" alt="<?php echo htmlspecialchars($right_ad['ad_title'] ?: 'Advertisement 2'); ?>">
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- <div class="ad-placeholder">Ad 2 Placeholder</div> -->
                <?php endif; ?>
            </div>
        </div> <!-- END Ads Column (Right) -->

    </div> <!-- ================== END page-wrapper-with-ads ================== -->
    <!-- Footer (Optional, if you have one) -->
    <!-- <?php // require_once 'includes/footer.php'; ?> -->

    <!-- Include main.js if needed -->
    <!-- <script src="assets/js/main.js?v=<?php // echo filemtime('assets/js/main.js'); ?>"></script> -->

    <!-- Keep Essential JavaScript -->
    <script>
        // Auto-hide SUCCESS message and reload
        const successMessage = document.getElementById('submission-message');
        const messageType = <?php echo json_encode($message_type); ?>; // Use json_encode for safety

        if (successMessage && messageType === 'success') {
             successMessage.style.display = 'block'; // Ensure it's visible first
             successMessage.style.opacity = '1';
             setTimeout(() => {
                successMessage.style.transition = 'opacity 1s ease-out';
                successMessage.style.opacity = '0';
                setTimeout(() => {
                     // Reload logic after fade out
                     const currentUrl = new URL(window.location.href);
                     const manualSiteId = currentUrl.searchParams.get('manual_site_id');
                     let reloadUrl = 'checkin.php';
                     if (manualSiteId) {
                         reloadUrl += '?manual_site_id=' + encodeURIComponent(manualSiteId);
                     }
                     window.location.href = reloadUrl; // Reload the page
                 }, 1000); // Wait for fade out
             }, 4000); // Start fading after 4 seconds
        }

        // Inactivity Timeout specific to check-in page
        let inactivityTimerCheckin;
        const resetInactivityTimerCheckin = () => {
            clearTimeout(inactivityTimerCheckin);
            inactivityTimerCheckin = setTimeout(() => {
                 console.log("Checkin page inactive, reloading.");
                 // Reload logic (same as above)
                 const currentUrl = new URL(window.location.href);
                 const manualSiteId = currentUrl.searchParams.get('manual_site_id');
                 let reloadUrl = 'checkin.php';
                 if (manualSiteId) {
                     reloadUrl += '?manual_site_id=' + encodeURIComponent(manualSiteId);
                 }
                 window.location.href = reloadUrl;
            }, 90000); // 90 seconds (1.5 minutes)
        };

        // Attach inactivity listeners
        window.onload = resetInactivityTimerCheckin;
        ['mousemove', 'mousedown', 'keypress', 'keydown', 'scroll', 'touchstart', 'click'].forEach(event => {
            document.addEventListener(event, resetInactivityTimerCheckin, { passive: true });
        });
        /*
        // Function to dynamically center sticky sidebars (Removed as it caused initial misalignment)
        function centerStickySidebars() {
            const sidebars = document.querySelectorAll('.ad-sidebar');
            const viewportHeight = window.innerHeight;
            const minTopGap = 40; // Pixels - Adjust as needed

            console.log(`Viewport Height: ${viewportHeight}`);

            // === Add index parameter HERE ===
            sidebars.forEach((sidebar, index) => {
                if (window.getComputedStyle(sidebar).position === 'sticky') {
                    const contentElement = sidebar.querySelector('.ad-content');
                    const elementHeight = contentElement ? contentElement.offsetHeight : sidebar.offsetHeight;

                    console.log(`Sidebar ${index} Element Height: ${elementHeight}`);

                    let idealTop = (viewportHeight / 2) - (elementHeight / 2);
                    let finalTop = Math.max(minTopGap, idealTop);

                    // Apply the calculated top value with !important
                    sidebar.style.setProperty('top', finalTop + 'px'); // Use setProperty
                    console.log(`Sidebar ${index} Setting sticky top: ${finalTop}px !important (Ideal: ${idealTop.toFixed(1)}px)`); // Now index is defined

                } else {
                    sidebar.style.top = ''; // Reset if not sticky
                    console.log(`Sidebar ${index} Resetting top (not sticky)`); // index is defined here too
                }
            });
        }

        // Debounce function to limit resize calculations
        function debounce(func, wait = 150) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Run on initial load (Removed)
        // document.addEventListener('DOMContentLoaded', centerStickySidebars);

        // Re-run on window resize (debounced) (Removed)
        // window.addEventListener('resize', debounce(centerStickySidebars));
        */

    <!-- ============ End JavaScript for Sticky Sidebar Centering (Removed) ============ -->
</script>

<!-- Define Base URL Path for JavaScript -->
    <script>;window.APP_BASE_URL_PATH = '/public_html';</script>
<!-- QR Code Library -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <!-- Kiosk Specific JavaScript -->
    <script>
        // Define the base path for the application, crucial for AJAX requests in subdirectories.
        // This is specifically for XAMPP or similar environments where the project
        // might not be at the server's document root.
        window.APP_BASE_URL_PATH = '/public_html';
    </script>
    <script src="assets/js/kiosk.js?v=<?php echo filemtime(__DIR__ . '/assets/js/kiosk.js'); ?>"></script>
</body>
</html>