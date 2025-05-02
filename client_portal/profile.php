<?php
// --- Client Session Handling ---
// Use the distinct session name established in client_login.php
if (session_status() == PHP_SESSION_NONE) {
    session_name("CLIENT_SESSION");
    session_start();
}

// --- Authentication Check ---
// Redirect to login if client is not logged in
// Redirect to login if client session data is not set
if (!isset($_SESSION['CLIENT_SESSION']) || !isset($_SESSION['CLIENT_SESSION']['client_id'])) {
    // Add a log entry to understand why the session might be missing
    error_log("Client session check failed in profile.php. Session data: " . print_r($_SESSION, true));
    header("Location: ../client_login.php?error=session_expired");
    exit;
}

$client_id = $_SESSION['CLIENT_SESSION']['client_id'];

// --- CSRF Protection ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Database Connection & Data Access Includes ---
require_once '../includes/db_connect.php'; // Assumes $pdo is available here
require_once '../includes/data_access/site_data.php';
require_once '../includes/data_access/question_data.php';
require_once '../includes/data_access/client_data.php'; // Contains getClientAnswers, saveClientAnswers

// --- Variable Initialization ---
$error_message = '';
$success_message = '';
$client_data = []; // To store fetched client data
$form_values = [ // To store submitted/fetched values for repopulation
    'first_name' => '',
    'last_name' => '',
    'email_preference_jobs' => 0
];
$username = $_SESSION['CLIENT_SESSION']['username'] ?? 'N/A'; // Get username from session if available
$email = ''; // Will be fetched from DB
$site_name = 'N/A'; // To store fetched site name
$global_questions = []; // To store fetched global questions
$client_answers = []; // To store fetched client answers

// --- Form Submission Logic (Update) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Invalid request token. Please try submitting the form again.";
    } else {
        // --- Input Retrieval and Sanitization ---
        $form_values['first_name'] = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING) ?? '');
        $form_values['last_name'] = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING) ?? '');
        $form_values['email_preference_jobs'] = isset($_POST['email_preference_jobs']) ? 1 : 0;
        // Dynamic question answers will be processed after basic profile update

        // --- Server-Side Validation ---
        if (empty($form_values['first_name'])) {
            $error_message .= "First Name is required.<br>";
        }
        if (empty($form_values['last_name'])) {
            $error_message .= "Last Name is required.<br>";
        }

        if (empty($error_message)) {
            $pdo->beginTransaction(); // Start transaction
            try {
                // --- 1. Update Basic Client Info ---
                $sql_client = "UPDATE clients SET
                                   first_name = :first_name,
                                   last_name = :last_name,
                                   email_preference_jobs = :email_preference_jobs,
                                   updated_at = NOW()
                               WHERE id = :client_id";

                $stmt_client = $pdo->prepare($sql_client);
                if (!$stmt_client) { throw new Exception("Failed to prepare client update statement."); }

                $stmt_client->bindParam(':first_name', $form_values['first_name'], PDO::PARAM_STR);
                $stmt_client->bindParam(':last_name', $form_values['last_name'], PDO::PARAM_STR);
                $stmt_client->bindParam(':email_preference_jobs', $form_values['email_preference_jobs'], PDO::PARAM_INT);
                $stmt_client->bindParam(':client_id', $client_id, PDO::PARAM_INT);

                $client_update_success = $stmt_client->execute();

                if ($client_update_success) {
                    // --- 2. Process and Save Dynamic Answers ---
                    $answers_to_save = [];
                    // Fetch all global questions to know what to look for in POST
                    // Re-fetch here inside transaction to ensure we have the latest question list if needed, though unlikely to change mid-request
                    $posted_questions = function_exists('getAllGlobalQuestions') ? getAllGlobalQuestions($pdo) : [];
                    foreach ($posted_questions as $question) {
                        $question_id = $question['id'];
                        $post_key = 'question_' . $question_id;
                        if (isset($_POST[$post_key])) {
                            // Basic validation: Expecting 'Yes' or 'No'
                            $answer = filter_input(INPUT_POST, $post_key, FILTER_SANITIZE_STRING);
                            if ($answer === 'Yes' || $answer === 'No') {
                                $answers_to_save[$question_id] = $answer;
                            } else {
                                // Treat invalid/unexpected answers as 'No'
                                $answers_to_save[$question_id] = 'No';
                                error_log("Invalid answer received for question ID {$question_id} for client {$client_id}. Defaulting to 'No'.");
                            }
                        } else {
                            // Treat missing answers as 'No' for Yes/No questions.
                            $answers_to_save[$question_id] = 'No';
                        }
                    }

                    $answers_save_success = function_exists('saveClientAnswers') ? saveClientAnswers($pdo, $client_id, $answers_to_save) : false;

                    if ($answers_save_success) {
                        $pdo->commit(); // Both updates successful
                        $success_message = "Profile and answers updated successfully!";

                        // Update session first name if changed
                        // Update session first name if changed
                        if (isset($_SESSION['CLIENT_SESSION']['first_name']) && $_SESSION['CLIENT_SESSION']['first_name'] !== $form_values['first_name']) {
                             $_SESSION['CLIENT_SESSION']['first_name'] = $form_values['first_name'];
                        }
                        // Regenerate CSRF token after successful submission
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $csrf_token = $_SESSION['csrf_token'];
                    } else {
                        $pdo->rollBack(); // Rollback if answers failed
                        $error_message = "Failed to update answers. Profile changes were not saved.";
                        if (!function_exists('saveClientAnswers')) {
                             error_log("saveClientAnswers function does not exist.");
                             $error_message .= " (System error: save function missing)";
                        }
                    }
                } else {
                    $pdo->rollBack(); // Rollback if client update failed
                    $error_message = "Failed to update profile. Please try again.";
                }

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack(); // Rollback on PDO exception
                error_log("Database error during client profile update (PDO) for client ID {$client_id}: " . $e->getMessage());
                $error_message = "An error occurred while updating your profile. Please try again later.";
            } catch (Exception $e) { // Catch other potential errors like prepare failure
                 if ($pdo->inTransaction()) $pdo->rollBack(); // Rollback on general exception
                 error_log("General error during client profile update for client ID {$client_id}: " . $e->getMessage());
                 $error_message = "An error occurred while updating your profile. Please try again later.";
            }
        }
    }
}

// --- Data Fetching Logic (View) ---
// Always fetch current data to display, even after POST success/error
try {
    // Fetch client details including site_id
    $sql_fetch_client = "SELECT username, email, first_name, last_name, email_preference_jobs, site_id
                         FROM clients
                         WHERE id = :client_id AND deleted_at IS NULL";
    $stmt_fetch_client = $pdo->prepare($sql_fetch_client);
    if (!$stmt_fetch_client) { throw new Exception("Failed to prepare client fetch statement."); }
    $stmt_fetch_client->bindParam(':client_id', $client_id, PDO::PARAM_INT);
    $stmt_fetch_client->execute();
    $client_data = $stmt_fetch_client->fetch(PDO::FETCH_ASSOC);

    if ($client_data) {
        // Fetch site name using site_id from client data
        if (isset($client_data['site_id']) && $client_data['site_id']) {
            // Ensure getSiteNameById exists and handles errors/null site_id gracefully
            if (function_exists('getSiteNameById')) {
                $site_name = getSiteNameById($pdo, $client_data['site_id']);
                if (!$site_name) { // Check if getSiteNameById returned null or false
                    $site_name = 'Site Not Found';
                }
            } else {
                $site_name = 'Error: getSiteNameById missing';
            }
        } else {
            $site_name = 'N/A'; // Handle case where site_id might be null or 0
        }

        // Fetch all global questions
        $global_questions = function_exists('getAllGlobalQuestions') ? getAllGlobalQuestions($pdo) : [];
        if (empty($global_questions) && function_exists('getAllGlobalQuestions')) {
             error_log("getAllGlobalQuestions returned empty for profile page client ID: {$client_id}.");
        }

        // Fetch existing answers for this client
        $client_answers = function_exists('getClientAnswers') ? getClientAnswers($pdo, $client_id) : [];
        // No error log needed if empty, it's normal for new clients

        // Populate form values with fetched data ONLY if not a POST request OR if POST had errors
        if ($_SERVER["REQUEST_METHOD"] != "POST" || !empty($error_message)) {
             $form_values = [
                'first_name' => $client_data['first_name'] ?? '',
                'last_name' => $client_data['last_name'] ?? '',
                'email_preference_jobs' => $client_data['email_preference_jobs'] ?? 0
            ];
        }
        // Always update email and username display from fetched data
        $username = $client_data['username'] ?? $username; // Prefer DB username if available
        $email = $client_data['email'] ?? 'N/A';

    } else {
        // Should not happen if session is valid, but handle defensively
        $error_message = "Could not retrieve client data. Please log out and log back in.";
        // Optionally destroy session and redirect
        // session_destroy();
        // header("Location: ../client_login.php?error=data_fetch_failed");
        // exit;
    }

} catch (PDOException $e) {
    error_log("Database error fetching client profile data (PDO) for client ID {$client_id}: " . $e->getMessage());
    $error_message = "An error occurred while retrieving your profile data. Please try again later.";
} catch (Exception $e) { // Catch other potential errors like prepare failure
     error_log("General error fetching client profile data for client ID {$client_id}: " . $e->getMessage());
     $error_message = "An error occurred while retrieving your profile data. Please try again later.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Arizona@Work</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .profile-container { max-width: 700px; margin: 50px auto; padding: 30px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .profile-header { text-align: center; margin-bottom: 25px; }
        .form-group label { font-weight: bold; }
        .btn-update { background-color: #007bff; border-color: #007bff; color: white; }
        .nav-links { margin-bottom: 20px; text-align: center; }
        .nav-links a { margin: 0 10px; }
        .readonly-field { background-color: #e9ecef; opacity: 1; }
        .question-block { margin-bottom: 1.5rem; border: 1px solid #dee2e6; padding: 1rem; border-radius: .25rem; }
        .question-block label:first-child { display: block; margin-bottom: .5rem; } /* Style question text */
    </style>
</head>
<body>
    <div class="container">
        <div class="profile-container">
            <div class="profile-header">
                <h2>My Profile</h2>
                <p>Arizona@Work Client Portal</p>
            </div>

            <!-- Basic Navigation -->
            <div class="nav-links">
                <a href="services.php">Services</a> |
                <a href="profile.php">My Profile</a> |
                <a href="qr_code.php">My QR Code</a> |
                <a href="../logout.php?client=1">Logout</a> <!-- Assuming logout handles client logout -->
            </div>

            <!-- Feedback Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); // May contain HTML <br> ?>
                </div>
            <?php endif; ?>

            <?php if ($client_data): // Only show form if data was fetched ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <!-- Non-Editable Fields -->
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="username">Username</label>
                        <input type="text" class="form-control readonly-field" id="username" value="<?php echo htmlspecialchars($username); ?>" readonly>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="email">Email</label>
                        <input type="email" class="form-control readonly-field" id="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
                        <small class="form-text text-muted">Email cannot be changed here.</small>
                    </div>
                </div>

                 <!-- Primary Site Display -->
                <div class="form-group">
                    <label for="site_name">Primary Site</label>
                    <input type="text" class="form-control readonly-field" id="site_name" value="<?php echo htmlspecialchars($site_name); ?>" readonly>
                    <small class="form-text text-muted">Your primary site cannot be changed here.</small>
                </div>


                <hr>

                <!-- Editable Fields -->
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="first_name">First Name</label>
                        <input type="text" class="form-control <?php echo (!empty($error_message) && empty($form_values['first_name'])) ? 'is-invalid' : ''; ?>" id="first_name" name="first_name" value="<?php echo htmlspecialchars($form_values['first_name']); ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="last_name">Last Name</label>
                        <input type="text" class="form-control <?php echo (!empty($error_message) && empty($form_values['last_name'])) ? 'is-invalid' : ''; ?>" id="last_name" name="last_name" value="<?php echo htmlspecialchars($form_values['last_name']); ?>" required>
                    </div>
                </div>

                <div class="form-group form-check">
                    <input type="checkbox" class="form-check-input" id="email_preference_jobs" name="email_preference_jobs" value="1" <?php echo ($form_values['email_preference_jobs'] == 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="email_preference_jobs">Opt-in to receive emails about job fairs and events</label>
                </div>

                <hr>
                <h5>Questions</h5>
                <p><small>Please answer the following questions to help us provide the best service.</small></p>

                <?php if (!empty($global_questions)): ?>
                    <?php foreach ($global_questions as $question):
                        $question_id = $question['id'];
                        $input_name = 'question_' . $question_id;
                        // Default to 'No' if not set in $client_answers or if value is not 'Yes'
                        $current_answer = isset($client_answers[$question_id]) && $client_answers[$question_id] === 'Yes' ? 'Yes' : 'No';
                    ?>
                    <div class="form-group question-block">
                        <label><?php echo htmlspecialchars($question['question_text']); ?></label>
                        <div> <!-- Wrapper div for inline radio buttons -->
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="<?php echo $input_name; ?>_yes" value="Yes" <?php echo ($current_answer === 'Yes') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="<?php echo $input_name; ?>_yes">Yes</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="<?php echo $input_name; ?>_no" value="No" <?php echo ($current_answer === 'No') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="<?php echo $input_name; ?>_no">No</label>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">No questions are currently configured.</div>
                <?php endif; ?>

                <hr>

                <button type="submit" class="btn btn-update btn-block">Update Profile</button>
            </form>
            <?php endif; // End check for $client_data ?>

        </div>
    </div>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js" integrity="sha384-oBqDVmMz9ATKxIep9tiCxS/Z9fNfEXiDAYTujMAeBAsjFuCZSmKbSSUnQlmh/jp3" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>