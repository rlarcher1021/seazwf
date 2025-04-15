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
$configPath = __DIR__ . '/config.ini';
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
if ($manual_site_id && in_array($current_role, ['administrator', 'director', 'site_supervisor'])) {
    // Use data access function to get site details
    $site_details = getActiveSiteDetailsById($pdo, $manual_site_id);
    if ($site_details) {
        $site_id_for_checkin = (int)$site_details['id'];
        $site_name = $site_details['name'];
        if (!empty($site_details['email_collection_desc'])) {
            $site_email_description = $site_details['email_collection_desc'];
        }
        error_log("Checkin: Using manual_site_id {$site_id_for_checkin} for role {$current_role}");
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
            error_log("Checkin: Using session_site_id {$site_id_for_checkin} for role {$current_role}");
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
    echo '<div class="message-area message-error" style="margin:20px; padding: 20px; max-width: 600px; margin-left: auto; margin-right:auto; background-color: #fff;">'; // Style error box similar to checkin container
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
    error_log("[DEBUG checkin.php] Site {$site_id} Config Flags: AllowEmail=" . ($allow_email_collection ? '1' : '0') . ", AllowNotifier=" . ($allow_notifier ? '1' : '0'));

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
    error_log("Checkin Ad Fetch - Site ID {$site_id} - Left Ad: " . ($left_ad ? $left_ad['ad_title'] ?? $left_ad['ad_type'] : 'None') . ", Right Ad: " . ($right_ad ? $right_ad['ad_title'] ?? $right_ad['ad_type'] : 'None'));


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
    $question_answers = [];
    foreach ($assigned_questions as $question) {
        $q_key = 'q_' . $question['global_question_id'];
        $answer = filter_input(INPUT_POST, $q_key, FILTER_SANITIZE_SPECIAL_CHARS);
        $question_title_base = $question['question_title'];

        if (empty($question_title_base)) {
            error_log("Checkin Warning: Global Question ID {$question['global_question_id']} missing title/base_name for site {$site_id}.");
            continue;
        }
        $db_column_name = 'q_' . sanitize_title_to_base_name($question_title_base);

        if ($answer === 'YES' || $answer === 'NO') {
            $question_answers[$db_column_name] = $answer;
        } else {
            $errors[] = "Please answer the question: \"" . htmlspecialchars($question['question_text']) . "\"";
        }
    }
    error_log("[DEBUG checkin.php POST] Prepared Question answers array FINAL: " . print_r($question_answers, true));

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

    // If no validation errors, proceed to save
    if (empty($errors)) {
        // Prepare data array for the saveCheckin function
        $checkin_data_to_save = [
            'site_id' => $site_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'check_in_time' => $check_in_time, // Use defined time
            'notified_staff_id' => $notified_staff_id,
            'client_email' => $client_email,
            'question_answers' => $question_answers
        ];

        // Call the data access function to save the check-in
        $check_in_id = saveCheckin($pdo, $checkin_data_to_save);

        if ($check_in_id !== false) {
            // --- Send Email Notification ---
            if ($config && isset($config['smtp'])) {
                $mail = new PHPMailer(true);
                try {
                    // Server settings
                    $mail->isSMTP(); $mail->Host = $config['smtp']['host']; $mail->SMTPAuth = true;
                    $mail->Username = $config['smtp']['username']; $mail->Password = $config['smtp']['password'];
                    $smtp_port = $config['smtp']['port'] ?? 587; $mail->Port = (int)$smtp_port;
                    $mail->SMTPSecure = ($smtp_port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

                    // Recipients
                    $mail->setFrom($config['smtp']['from_email'], $config['smtp']['from_name']);
                    $primary_recipient = $config['smtp']['primary_recipient'] ?? null;
                    if ($primary_recipient && filter_var($primary_recipient, FILTER_VALIDATE_EMAIL)) {
                         $mail->addAddress($primary_recipient, 'Check-In Admin');
                    } else { error_log("Checkin Email Error: Primary recipient '{$primary_recipient}' invalid or missing in config. Check-in ID: {$check_in_id}"); }

                    // CC Notifier
                    if ($allow_notifier && $notified_staff_id && isset($staff_notifiers[$notified_staff_id])) {
                        $notifier_email = $staff_notifiers[$notified_staff_id]['staff_email'];
                        $notifier_name = $staff_notifiers[$notified_staff_id]['staff_name'];
                        if (filter_var($notifier_email, FILTER_VALIDATE_EMAIL)) {
                            $mail->addCC($notifier_email, $notifier_name);
                        } else { error_log("Checkin Email Warning: Invalid notifier email for ID {$notified_staff_id}: '{$notifier_email}'. Check-in ID: {$check_in_id}"); }
                    }
                    // CC Client
                    if ($allow_email_collection && !empty($client_email)) {
                        $mail->addCC($client_email, $first_name . ' ' . $last_name);
                    }

                    // Content
                    $mail->isHTML(true); $mail->Subject = 'New Client Check-In at ' . htmlspecialchars($site_name);
                    $emailBody = "<h2>New Client Check-In</h2><p><strong>Site:</strong> " . htmlspecialchars($site_name) . "</p><p><strong>Name:</strong> " . htmlspecialchars($first_name) . " " . htmlspecialchars($last_name) . "</p><p><strong>Time:</strong> " . htmlspecialchars($check_in_time) . "</p>";
                    if ($allow_email_collection && !empty($client_email)) $emailBody .= "<p><strong>Client Email:</strong> " . htmlspecialchars($client_email) . "</p>";
                    if (!empty($question_answers)) {
                        $emailBody .= "<h3>Responses:</h3><ul>";
                        $col_to_text_map = [];
                        foreach ($assigned_questions as $q_data) {
                            $base_name = sanitize_title_to_base_name($q_data['question_title']);
                            if (!empty($base_name)) {
                                $col_name = 'q_' . $base_name;
                                $col_to_text_map[$col_name] = $q_data['question_text'];
                            }
                        }
                        foreach($question_answers as $col_name => $answer){
                            $question_text = $col_to_text_map[$col_name] ?? "Question ({$col_name})";
                            $emailBody .= "<li><strong>" . htmlspecialchars($question_text) . ":</strong> " . htmlspecialchars($answer) . "</li>";
                        }
                        $emailBody .= "</ul>";
                    }
                     if ($allow_notifier && $notified_staff_id && isset($staff_notifiers[$notified_staff_id])) $emailBody .= "<p><strong>Staff Notified:</strong> " . htmlspecialchars($staff_notifiers[$notified_staff_id]['staff_name']) . "</p>";

                    $mail->Body = $emailBody;
                    $mail->AltBody = strip_tags(str_replace(['<p>', '</li>', '</ul>', '<h2>', '<h3>'], ["\n", "\n", "\n", "\n\n", "\n"], $emailBody));
                    $mail->send();
                    $submission_message = "Check-in successful! Thank you."; $message_type = 'success';
                } catch (Exception $e) { error_log("Checkin Email Send Error: {$mail->ErrorInfo}. Check-in ID: {$check_in_id}"); $submission_message = "Check-in successful, but notification failed."; $message_type = 'warning'; }
            } else { error_log("Checkin Email Skipped: SMTP config missing or invalid. Check-in ID: {$check_in_id}"); $submission_message = "Check-in successful! (Email offline)."; $message_type = 'warning'; }
            // Clear form data only on full success
            $_SESSION['form_data'] = [];

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
    <title>Welcome - Check In - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" type="image/png" href="assets/img/flaviconlogo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Link main stylesheet -->
    <link rel="stylesheet" href="assets/css/main.css?v=<?php echo filemtime(__DIR__ . '/assets/css/main.css'); ?>">
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

        <!-- == NEW LEFT AD SIDEBAR == -->
        <div class="ad-sidebar ad-left">
            <?php if ($left_ad): ?>
                <div class="ad-content">
                    <?php if ($left_ad['ad_type'] === 'text' && !empty($left_ad['ad_text'])): ?>
                        <?php echo nl2br(htmlspecialchars($left_ad['ad_text'])); // Display text ad, convert newlines ?>
                    <?php elseif ($left_ad['ad_type'] === 'image' && !empty($left_ad['image_path'])):
                        $image_url = htmlspecialchars($left_ad['image_path']);
                        // Basic path correction (adjust if needed)
                        if (!filter_var($image_url, FILTER_VALIDATE_URL) && $image_url[0] !== '/' && strpos($image_url, '://') === false) {
                            $image_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($image_url); // Use constant
                        }
                    ?>
                        <img src="<?php echo $image_url; ?>" alt="<?php echo htmlspecialchars($left_ad['ad_title'] ?: 'Advertisement'); ?>">
                    <?php endif; ?>
                </div>
            <?php else: ?>
                 <!-- Optional: Placeholder if no ad -->
                 <!-- <div class="ad-placeholder"></div> -->
            <?php endif; ?>
        </div>
        <!-- == END LEFT AD SIDEBAR == -->


        <!-- == NEW MAIN CONTENT COLUMN WRAPPER == -->
        <div class="main-content-column">

            <!-- Existing Logo (Now inside central column) -->
            <div class="checkin-logo">
                <img src="assets/img/logo.jpg" alt="Arizona@Work Logo">
            </div>

            <!-- Existing Form Container (Now inside central column) -->
            <div class="checkin-container" id="checkin-form-container">
                <h1>Welcome to Arizona@Work <?php echo htmlspecialchars($site_name); ?>!</h1>
                <h2>Please Check In</h2>

                <!-- Existing Message Area -->
                <?php if (!empty($submission_message)): ?>
                    <div class="message-area message-<?php echo htmlspecialchars($message_type); ?>" id="submission-message">
                        <?php echo $submission_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Existing Form -->
                <form method="POST" action="checkin.php<?php echo $manual_site_id ? '?manual_site_id='.$manual_site_id : ''; ?>" id="checkin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <!-- ... (ALL your existing form fields: first name, last name, questions, email, notifier, button) ... -->
                     <!-- Fixed Fields -->
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

                    <!-- Separator -->
                    <?php if (!empty($assigned_questions) || ($allow_notifier && !empty($staff_notifiers)) || $allow_email_collection): ?><hr><?php endif; ?>

                    <!-- Dynamic Questions -->
                    <?php if (!empty($assigned_questions)): ?>
                        <?php foreach ($assigned_questions as $question):
                            $q_key = 'q_' . $question['global_question_id']; $current_answer = $form_data[$q_key] ?? null; ?>
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars($question['question_text']); ?>:</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="<?php echo $q_key; ?>" value="YES" <?php echo ($current_answer === 'YES') ? 'checked' : ''; ?> required> Yes</label>
                                    <label><input type="radio" name="<?php echo $q_key; ?>" value="NO" <?php echo ($current_answer === 'NO') ? 'checked' : ''; ?> required> No</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Optional Notifier -->
                    <?php if ($allow_notifier && !empty($staff_notifiers)): $selected_notifier = $form_data['notify_staff'] ?? ''; ?>
                         <?php if ($allow_email_collection): ?><hr><?php endif; ?>
                        <div class="form-group"><label for="notify_staff" class="form-label">Notify Staff Member (Optional):</label><div class="description">Select staff member if you need specific assistance.</div><select id="notify_staff" name="notify_staff" class="form-control"><option value="">-- No Specific Staff Needed --</option><?php foreach ($staff_notifiers as $id => $data): ?><option value="<?php echo $id; ?>" <?php echo ($selected_notifier == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($data['staff_name']); ?></option><?php endforeach; ?></select></div>
                    <?php endif; ?>

                     <!-- Optional Email -->
                    <?php if ($allow_email_collection): ?>
                         <hr><div class="form-group"><label for="collect_email" class="form-label"><?php echo htmlspecialchars($site_email_description); ?> (Optional)</label><input type="email" id="collect_email" name="collect_email" class="form-control" value="<?php echo htmlspecialchars($form_data['collect_email'] ?? ''); ?>" placeholder="your.email@example.com" pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$" title="Please enter a valid email address."></div>
                    <?php endif; ?>

                    <!-- Submission Button -->
                    <button type="submit" class="btn btn-primary">Check In</button>
                </form>
            </div> <!-- End .checkin-container -->

        </div> <!-- == END MAIN CONTENT COLUMN WRAPPER == -->


        <!-- == NEW RIGHT AD SIDEBAR == -->
        <div class="ad-sidebar ad-right">
             <?php if ($right_ad): ?>
                <div class="ad-content">
                    <?php if ($right_ad['ad_type'] === 'text' && !empty($right_ad['ad_text'])): ?>
                        <?php echo nl2br(htmlspecialchars($right_ad['ad_text'])); ?>
                    <?php elseif ($right_ad['ad_type'] === 'image' && !empty($right_ad['image_path'])):
                        $image_url = htmlspecialchars($right_ad['image_path']);
                        // Basic path correction (adjust if needed)
                        if (!filter_var($image_url, FILTER_VALIDATE_URL) && $image_url[0] !== '/' && strpos($image_url, '://') === false) {
                            $image_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($image_url); // Use constant
                        }
                    ?>
                        <img src="<?php echo $image_url; ?>" alt="<?php echo htmlspecialchars($right_ad['ad_title'] ?: 'Advertisement'); ?>">
                    <?php endif; ?>
                </div>
            <?php else: ?>
                 <!-- Optional: Placeholder if no ad -->
                 <!-- <div class="ad-placeholder"></div> -->
            <?php endif; ?>
        </div>
        <!-- == END RIGHT AD SIDEBAR == -->

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
                    sidebar.style.top = '';
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

        // Run on initial load
        document.addEventListener('DOMContentLoaded', centerStickySidebars);

        // Re-run on window resize (debounced)
        window.addEventListener('resize', debounce(centerStickySidebars));


    <!-- ============ End JavaScript for Sticky Sidebar Centering ============ -->
    </script>

</body>
</html>