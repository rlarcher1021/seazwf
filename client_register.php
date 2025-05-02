<![CDATA[<?php
/*
 * File: client_register.php
 * Path: /client_register.php
 * Created: 2025-04-29
 * Author: Roo (AI Assistant)
 * Description: Public-facing client registration page.
 */

// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include necessary files
require_once __DIR__ . '/includes/db_connect.php'; // Provides $pdo
require_once __DIR__ . '/includes/utils.php';      // Provides CSRF, flash messages, etc.
require_once __DIR__ . '/includes/email_utils.php'; // Provides sendTransactionalEmail()
require_once __DIR__ . '/includes/data_access/site_data.php'; // Provides getAllActiveSites()
require_once __DIR__ . '/includes/data_access/question_data.php'; // Provides getAllGlobalQuestions()
require_once __DIR__ . '/includes/data_access/client_data.php'; // Provides saveClientAnswers()

// Verify $pdo exists
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("FATAL: PDO connection object not established in client_register.php");
    // Display a user-friendly error without revealing details
    die("A critical error occurred during setup. Please try again later or contact support.");
}

// Generate or retrieve CSRF token
$csrf_token = generateCsrfToken();

// Define ENUM options for dropdowns based on schema
$veteran_options = ['Yes', 'No', 'Decline to Answer'];
$age_options = ['16-18', '19-24', '25-44', '45-54', '55-64', '65+', 'Decline to Answer'];
$interviewing_options = ['Yes', 'No', 'Decline to Answer'];

// Initialize variables
$errors = [];
$input_data = [
    'username' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
    'site_id' => null, // Added site_id
    'email_preference_jobs' => 0, // Default to 0 (unchecked)
    'question' => [], // Added for dynamic questions
];
$registration_success = false;

// --- Fetch data needed for the form (Sites and Questions) ---
$sites = [];
// $questions = []; // Questions are now loaded dynamically via AJAX
try {
    $sites = getAllActiveSites($pdo);
    if ($sites === false) { // Check if the function indicated an error
        error_log("Error fetching active sites for registration form.");
        $sites = []; // Ensure it's an array
        $errors['form_load'] = "Could not load site information. Please try again later.";
    }

    // Removed fetching global questions here - will be done via AJAX
    // $questions = getAllGlobalQuestions($pdo);
    // if ($questions === false) { // Check if the function indicated an error
    //     error_log("Error fetching global questions for registration form.");
    //     $questions = []; // Ensure it's an array
    //     $errors['form_load'] = "Could not load registration questions. Please try again later.";
    // }
} catch (PDOException $e) {
    error_log("Database error fetching site data for registration form: " . $e->getMessage());
    $sites = []; // Ensure it's an array on exception
    // $questions = []; // Ensure it's an array on exception
    $errors['form_load'] = "A database error occurred while loading site data. Please try again later.";
}
// --- End Fetch data ---


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errors['csrf'] = 'Invalid request. Please try submitting the form again.';
        // Log this potential attack
        error_log("CSRF token validation failed for client registration attempt.");
    } else {
        // 2. Retrieve and Sanitize Input Data
        $input_data['username'] = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
        $input_data['email'] = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $input_data['first_name'] = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING));
        $input_data['last_name'] = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING));
        $password = $_POST['password'] ?? ''; // Don't sanitize password itself yet
        $password_confirm = $_POST['password_confirm'] ?? '';
        $input_data['email_preference_jobs'] = isset($_POST['email_preference_jobs']) ? 1 : 0;
        $input_data['site_id'] = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
        // Sanitize dynamic question answers (assuming 'Yes'/'No')
        $submitted_questions = $_POST['question'] ?? [];
        $input_data['question'] = []; // Reset before populating
        if (is_array($submitted_questions)) {
            foreach ($submitted_questions as $qid => $answer) {
                 $clean_qid = filter_var($qid, FILTER_VALIDATE_INT);
                 $clean_answer = filter_var(trim($answer), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
                 // Basic validation for answer format (allow only 'Yes' or 'No')
                 if ($clean_qid && in_array($clean_answer, ['Yes', 'No'])) {
                     $input_data['question'][$clean_qid] = $clean_answer;
                 } elseif ($clean_qid) {
                     // Store invalid answer attempt to show error, but don't use it for saving
                     $input_data['question'][$clean_qid] = 'INVALID_ANSWER_PROVIDED';
                 }
            }
        }


        // 3. Server-Side Validation
        if (empty($input_data['username'])) { $errors['username'] = 'Username is required.'; }
        if (empty($input_data['email'])) { $errors['email'] = 'Email is required.'; }
        elseif (!filter_var($input_data['email'], FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Invalid email format.'; }
        if (empty($password)) { $errors['password'] = 'Password is required.'; }
        if (empty($password_confirm)) { $errors['password_confirm'] = 'Password confirmation is required.'; }
        elseif ($password !== $password_confirm) { $errors['password_confirm'] = 'Passwords do not match.'; }
        if (empty($input_data['first_name'])) { $errors['first_name'] = 'First name is required.'; }
        if (empty($input_data['last_name'])) { $errors['last_name'] = 'Last name is required.'; }

        if (empty($input_data['site_id'])) {
            $errors['site_id'] = 'Primary Site selection is required.';
        } else {
            // Verify the selected site_id is actually one of the active sites fetched
            $valid_site_selected = false;
            foreach ($sites as $site) {
                if ($site['id'] == $input_data['site_id']) {
                    $valid_site_selected = true;
                    break;
                }
            }
            if (!$valid_site_selected) {
                $errors['site_id'] = 'Invalid site selected.';
                // Log this potential manipulation attempt
                error_log("Client registration attempt with invalid site_id: " . $input_data['site_id']);
            }
        }

        // Validate dynamic questions - fetch expected questions for the *submitted* site_id
        // Only do this if site_id itself is valid
        if ($valid_site_selected && !isset($errors['site_id'])) {
            try {
                $expected_questions = getActiveQuestionsForSite($pdo, $input_data['site_id']);
                if ($expected_questions === false) {
                    $errors['questions_load'] = 'Could not verify required questions for the selected site. Please try again.';
                    error_log("Failed to fetch questions for validation for site_id: " . $input_data['site_id']);
                } elseif (is_array($expected_questions)) {
foreach ($expected_questions as $question) {
    // Use the correct key 'global_question_id' returned by getActiveQuestionsForSite
    $qid = $question['id'];
                        if (!isset($input_data['question'][$qid])) {
                            $errors['question_' . $qid] = 'An answer is required for: ' . $question['question_text'];
                        } elseif ($input_data['question'][$qid] === 'INVALID_ANSWER_PROVIDED') {
                             $errors['question_' . $qid] = 'Invalid answer provided (must be Yes or No) for: ' . $question['question_text'];
                             // Remove the invalid marker so it doesn't get saved
                             unset($input_data['question'][$qid]);
                        }
                    }
                }
            } catch (PDOException $e) {
                $errors['questions_load'] = 'Database error validating questions. Please try again.';
                error_log("PDOException fetching questions for validation: " . $e->getMessage());
            }
        }


        // Check for existing username/email only if other basic validation passes
        if (empty($errors)) {
            try {
                // Check username
                $stmt_check = $pdo->prepare("SELECT id FROM clients WHERE username = :username AND deleted_at IS NULL");
                $stmt_check->execute([':username' => $input_data['username']]);
                if ($stmt_check->fetch()) {
                    $errors['username'] = 'Username is already taken.';
                }
                $stmt_check->closeCursor(); // Close cursor before next query

                // Check email
                $stmt_check = $pdo->prepare("SELECT id FROM clients WHERE email = :email AND deleted_at IS NULL");
                $stmt_check->execute([':email' => $input_data['email']]);
                if ($stmt_check->fetch()) {
                    $errors['email'] = 'Email address is already registered.';
                }
                $stmt_check->closeCursor();

            } catch (PDOException $e) {
                error_log("Database error during client registration check: " . $e->getMessage());
                $errors['db'] = 'An error occurred while checking existing user data. Please try again.';
            }
        }

        // 4. Process Registration if No Errors
        if (empty($errors)) {
            try {
                // Generate unique QR identifier (simple hex string)
                $client_qr_identifier = bin2hex(random_bytes(16)); // 32 characters hex

                // Hash the password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                if ($password_hash === false) {
                    throw new Exception("Password hashing failed.");
                }

                // Prepare INSERT statement
                $sql = "INSERT INTO clients (
                            username, email, password_hash, first_name, last_name, site_id,
                            client_qr_identifier, email_preference_jobs, created_at
                        ) VALUES (
                            :username, :email, :password_hash, :first_name, :last_name, :site_id,
                            :client_qr_identifier, :email_preference_jobs, NOW()
                        )";
                $stmt = $pdo->prepare($sql);

                // Bind parameters
                $stmt->bindParam(':username', $input_data['username']);
                $stmt->bindParam(':email', $input_data['email']);
                $stmt->bindParam(':password_hash', $password_hash);
                $stmt->bindParam(':first_name', $input_data['first_name']);
                $stmt->bindParam(':last_name', $input_data['last_name']);
                $stmt->bindParam(':site_id', $input_data['site_id'], PDO::PARAM_INT); // Bind site_id
                $stmt->bindParam(':client_qr_identifier', $client_qr_identifier);
                $stmt->bindParam(':email_preference_jobs', $input_data['email_preference_jobs'], PDO::PARAM_INT);


                // Execute the statement
                if ($stmt->execute()) {
                    $new_client_id = $pdo->lastInsertId(); // Get the new client ID

                    // --- Save Dynamic Answers ---
                    $answers_to_save = $input_data['question']; // Use the validated answers
                    if (!empty($answers_to_save)) {
                        if (!saveClientAnswers($pdo, (int)$new_client_id, $answers_to_save)) {
                            // Log the error, but don't necessarily fail the whole registration
                            // as the client record itself was created.
                            error_log("Client Registration Warning: Client ID {$new_client_id} created, but failed to save dynamic answers.");
                            // Optionally set a less severe flash message?
                            // set_flash_message('register_warning', 'Account created, but there was an issue saving some details. Please review your profile.', 'warning');
                        }
                    }
                    // --- End Save Dynamic Answers ---

                    $registration_success = true;

                    // --- Send Welcome Email ---
                    $email_recipient = $input_data['email'];
                    $email_recipient_name = $input_data['first_name'];
                    $email_subject = "Welcome to Arizona@Work Check-In!";
                    $login_url = 'https://seazwf.com/client_login.php'; // Use production URL and correct login path
                    $qr_code_url = 'https://seazwf.com/client_portal/qr_code.php'; // Use production URL

$encoded_username = htmlspecialchars($input_data['username'], ENT_QUOTES, 'UTF-8');
                    $email_htmlBody = <<<HTML
                    <p>Hello {$email_recipient_name},</p>
                    <p>Welcome to the Arizona@Work Check-In System! Your registration was successful.</p>
                    <p>Your username is: <strong>{$encoded_username}</strong></p>
                    <p>You can log in to your account here: <a href="{$login_url}">Login Page</a></p>
                    <p>You can also access your personal QR code for quick check-ins here: <a href="{$qr_code_url}">Your QR Code</a></p>
                    <p>We look forward to assisting you!</p>
                    <p>Sincerely,<br>The Arizona@Work Team</p>
                    HTML;

                    if (!sendTransactionalEmail($email_recipient, $email_recipient_name, $email_subject, $email_htmlBody)) {
                        error_log("Failed to send welcome email to: " . $email_recipient . " during client registration.");
                    }
                    // --- End Send Welcome Email ---

                    // Set flash message for success (will be displayed after potential redirect or on page reload)
                    set_flash_message('register_success', 'Registration successful! You can now log in.', 'success');
                    // Redirect to login page upon successful registration
                    header('Location: client_login.php');
                    exit; // Important: Stop script execution after sending the header
                    // Success message below is now unreachable due to exit, which is intended.
                } else {
                    throw new PDOException("Failed to execute registration insert statement.");
                }

            } catch (PDOException | Exception $e) {
                error_log("Error during client registration processing: " . $e->getMessage());
                $errors['db'] = 'An unexpected error occurred during registration. Please try again later.';
                // Ensure password hash isn't accidentally exposed in logs if Exception was from hashing
                if (isset($password_hash)) unset($password_hash);
            }
        } else {
             // Set a general error flash message if specific errors occurred
             set_flash_message('register_error', 'Please correct the errors below and try again.', 'danger');
        }
    } // End CSRF check else block
} // End POST request handling

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Registration - AZ@Work Check-In</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/flaviconlogo.png">
    <!-- FontAwesome (Optional, but useful for icons) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom Styles (Optional) -->
    <style>
        body { background-color: #f8f9fa; }
        .register-container { max-width: 600px; margin: 50px auto; padding: 30px; background-color: #fff; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .form-group label { font-weight: 600; }
        .is-invalid { border-color: #dc3545; }
        .invalid-feedback { display: block; color: #dc3545; font-size: 0.875em; }
        .optional-label { font-weight: normal; font-style: italic; color: #6c757d; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-container">
            <div class="text-center mb-4">
                <img src="assets/img/logo.jpg" alt="Arizona@Work Logo" style="max-height: 70px;">
                <h2 class="mt-3">Client Registration</h2>
            </div>

            <?php
            // Display flash messages if any
            display_flash_messages('register_success', 'success');
            display_flash_messages('register_error', 'danger');
            if (isset($errors['db'])) { // Display general DB error if set
                echo "<div class='alert alert-danger'>" . htmlspecialchars($errors['db']) . "</div>";
            }
            if (isset($errors['csrf'])) { // Display CSRF error if set
                echo "<div class='alert alert-danger'>" . htmlspecialchars($errors['csrf']) . "</div>";
            }
            ?>

            <?php if ($registration_success): ?>
                <div class="alert alert-success text-center">
                    <h4>Registration Successful!</h4>
                    <p>Your account has been created. You can now proceed to the login page.</p>
                    <a href="index.php" class="btn btn-primary">Go to Login</a>
                </div>
            <?php else: ?>
                <form action="client_register.php" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <!-- Account Information -->
                    <h5>Account Information</h5>
                    <hr>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="username">Username</label>
                            <input type="text" class="form-control <?php echo isset($errors['username']) ? 'is-invalid' : ''; ?>" id="username" name="username" value="<?php echo htmlspecialchars($input_data['username']); ?>" required>
                            <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?php echo $errors['username']; ?></div><?php endif; ?>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="email">Email Address</label>
                            <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" id="email" name="email" value="<?php echo htmlspecialchars($input_data['email']); ?>" required>
                            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?php echo $errors['email']; ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="password">Password</label>
                            <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" id="password" name="password" required>
                            <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?php echo $errors['password']; ?></div><?php endif; ?>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="password_confirm">Confirm Password</label>
                            <input type="password" class="form-control <?php echo isset($errors['password_confirm']) ? 'is-invalid' : ''; ?>" id="password_confirm" name="password_confirm" required>
                            <?php if (isset($errors['password_confirm'])): ?><div class="invalid-feedback"><?php echo $errors['password_confirm']; ?></div><?php endif; ?>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <h5 class="mt-4">Personal Information</h5>
                    <hr>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="first_name">First Name</label>
                            <input type="text" class="form-control <?php echo isset($errors['first_name']) ? 'is-invalid' : ''; ?>" id="first_name" name="first_name" value="<?php echo htmlspecialchars($input_data['first_name']); ?>" required>
                            <?php if (isset($errors['first_name'])): ?><div class="invalid-feedback"><?php echo $errors['first_name']; ?></div><?php endif; ?>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="last_name">Last Name</label>
                            <input type="text" class="form-control <?php echo isset($errors['last_name']) ? 'is-invalid' : ''; ?>" id="last_name" name="last_name" value="<?php echo htmlspecialchars($input_data['last_name']); ?>" required>
                            <?php if (isset($errors['last_name'])): ?><div class="invalid-feedback"><?php echo $errors['last_name']; ?></div><?php endif; ?>
                        </div>
                    </div>

                    <!-- Site Selection -->
                    <h5 class="mt-4">Site Selection</h5>
                    <hr>
                    <div class="form-group">
                        <label for="site_id">Primary Site <span class="text-danger">*</span></label>
                        <select id="site_id" name="site_id" class="form-control <?php echo isset($errors['site_id']) ? 'is-invalid' : ''; ?>" required>
                            <option value="" <?php echo empty($input_data['site_id']) ? 'selected' : ''; ?>>-- Select Your Primary Site --</option>
                            <?php if (!empty($sites)): ?>
                                <?php foreach ($sites as $site): ?>
                                    <option value="<?php echo htmlspecialchars($site['id']); ?>" <?php echo (!empty($input_data['site_id']) && $input_data['site_id'] == $site['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($site['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No sites available</option>
                            <?php endif; ?>
                        </select>
                        <?php if (isset($errors['site_id'])): ?><div class="invalid-feedback"><?php echo $errors['site_id']; ?></div><?php endif; ?>
                        <?php if (isset($errors['form_load'])): ?><div class="alert alert-warning mt-2"><?php echo htmlspecialchars($errors['form_load']); ?></div><?php endif; // Display form load errors here ?>
                    </div>


                    <!-- Preferences & Dynamic Questions -->
                    <h5 class="mt-4">Preferences & Questions</h5>
                    <hr>
                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" id="email_preference_jobs" name="email_preference_jobs" value="1" <?php echo !empty($input_data['email_preference_jobs']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="email_preference_jobs">Receive email notifications about relevant jobs and events?</label>
                    </div>

                    <!-- Placeholder for dynamically loaded questions -->
                    <div id="dynamic-questions-container" class="mt-3">
                        <!-- Questions will be loaded here via JavaScript -->
                        <?php
                        // Display validation errors for questions if they exist from POST submission
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
                            foreach ($errors as $key => $message) {
                                if (strpos($key, 'question_') === 0) {
                                    // Attempt to find the question text if possible (might not be available if load failed)
                                    // This part is tricky as questions aren't pre-loaded anymore.
                                    // We'll just display the error message directly.
                                    echo '<div class="alert alert-danger mt-2" role="alert">' . htmlspecialchars($message) . '</div>';
                                }
                            }
                            // Display question load error if it occurred during validation
                            if (isset($errors['questions_load'])) {
                                echo '<div class="alert alert-danger mt-2" role="alert">' . htmlspecialchars($errors['questions_load']) . '</div>';
                            }
                        }
                        ?>
                    </div>
                    <!-- End Placeholder -->

                    <?php /* if (!empty($questions)): ?> // Old static loading removed
                        <?php foreach ($questions as $question):
                            $qid = $question['id'];
                            $error_key = 'question_' . $qid;
                            // Get submitted answer, handling potential invalid submission state
                            $current_answer = null;
                            if (isset($input_data['question'][$qid]) && $input_data['question'][$qid] !== 'INVALID_ANSWER_PROVIDED') {
                                $current_answer = $input_data['question'][$qid];
                            }
                        ?>
                            <div class="form-group mt-3 <?php echo isset($errors[$error_key]) ? 'border border-danger p-2 rounded' : ''; ?>">
                                <label><?php echo htmlspecialchars($question['question_text']); ?> <span class="text-danger">*</span></label>
                                <div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input <?php echo isset($errors[$error_key]) ? 'is-invalid' : ''; ?>" type="radio" name="question[<?php echo $qid; ?>]" id="q_<?php echo $qid; ?>_yes" value="Yes" <?php echo ($current_answer === 'Yes') ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="q_<?php echo $qid; ?>_yes">Yes</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input <?php echo isset($errors[$error_key]) ? 'is-invalid' : ''; ?>" type="radio" name="question[<?php echo $qid; ?>]" id="q_<?php echo $qid; ?>_no" value="No" <?php echo ($current_answer === 'No') ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="q_<?php echo $qid; ?>_no">No</label>
                                    </div>
                                </div>
                                <?php if (isset($errors[$error_key])): ?><div class="invalid-feedback d-block"><?php echo $errors[$error_key]; ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?> */ ?>
                    <?php /* elseif (empty($errors['form_load'])): // Only show this if questions aren't empty due to a load error ?>
                         <p class="text-muted">No additional questions at this time.</p>
                    <?php endif; */ ?>


                    <button type="submit" class="btn btn-primary btn-block mt-4">Register</button>

                    <div class="text-center mt-3">
                        <p>Already have an account? <a href="client_login.php">Log In</a></p>
                    </div>
                </form>
            <?php endif; // End check for registration success ?>

        </div> <!-- /register-container -->
    </div> <!-- /container -->

    <!-- Minimal Footer -->
    <footer class="text-center p-3 small text-muted mt-5">
        © <?php echo date("Y"); ?> Arizona@Work - Southeastern Arizona. All Rights Reserved.
    </footer>

    <!-- Bootstrap JS Dependencies -->
    <!-- Use full jQuery for AJAX -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

    <script>
        $(document).ready(function() {
            const questionsContainer = $('#dynamic-questions-container');
            const siteSelect = $('#site_id');

            siteSelect.on('change', function() {
                const siteId = $(this).val();
                questionsContainer.html(''); // Clear previous questions

                if (siteId) {
                    questionsContainer.html('<p class="text-muted">Loading questions...</p>'); // Loading indicator

                    $.ajax({
                        url: 'ajax_handlers/get_site_questions.php', // Endpoint to fetch questions
                        type: 'GET',
                        data: { site_id: siteId },
                        dataType: 'json',
                        success: function(response) {
                            questionsContainer.html(''); // Clear loading indicator
                            if (response.success && response.questions.length > 0) {
                                $.each(response.questions, function(index, question) {
                                    const qid = question.id; // Use 'id' from response
                                    const qtext = question.question_text; // Use 'question_text'
                                    const errorKey = 'question_' + qid;
                                    // Check if there was a validation error for this question from a previous POST
                                    const hasError = <?php echo json_encode(isset($errors) ? $errors : []); ?>[errorKey] !== undefined;
                                    const invalidClass = hasError ? 'is-invalid' : '';
                                    const errorMsg = hasError ? <?php echo json_encode(isset($errors) ? $errors : []); ?>[errorKey] : '';
                                    // Get previously submitted answer if available (from POST data)
                                    const submittedAnswers = <?php echo json_encode($input_data['question'] ?? []); ?>;
                                    const currentAnswer = submittedAnswers[qid] || null;

                                    const questionHtml = `
                                        <div class="form-group mt-3 ${hasError ? 'border border-danger p-2 rounded' : ''}">
                                            <label>${$('<textarea />').html(qtext).text()} <span class="text-danger">*</span></label> <!-- Decode HTML entities -->
                                            <div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input ${invalidClass}" type="radio" name="question[${qid}]" id="q_${qid}_yes" value="Yes" ${currentAnswer === 'Yes' ? 'checked' : ''} required>
                                                    <label class="form-check-label" for="q_${qid}_yes">Yes</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input ${invalidClass}" type="radio" name="question[${qid}]" id="q_${qid}_no" value="No" ${currentAnswer === 'No' ? 'checked' : ''} required>
                                                    <label class="form-check-label" for="q_${qid}_no">No</label>
                                                </div>
                                            </div>
                                            ${hasError ? '<div class="invalid-feedback d-block">' + $('<div/>').text(errorMsg).html() + '</div>' : ''} <!-- Sanitize error message -->
                                        </div>
                                    `;
                                    questionsContainer.append(questionHtml);
                                });
                            } else if (response.success && response.questions.length === 0) {
                                questionsContainer.html('<p class="text-muted">No specific questions for this site.</p>');
                            } else {
                                questionsContainer.html('<p class="text-danger">Error loading questions. Please try selecting the site again.</p>');
                                console.error("Error fetching questions:", response.message || 'Unknown error');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            questionsContainer.html('<p class="text-danger">Failed to load questions due to a network or server error. Please try again.</p>');
                            console.error("AJAX Error:", textStatus, errorThrown);
                        }
                    });
                }
            });

            // Trigger change event on page load if a site is already selected (e.g., after form submission with errors)
            if (siteSelect.val()) {
                siteSelect.trigger('change');
            }
        });
    </script>

</body>
</html>
]]>