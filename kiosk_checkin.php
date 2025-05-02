<?php
session_start();
require_once 'includes/db_connect.php'; // Assuming db connection is needed for header/footer or utils
require_once 'includes/auth.php';       // Handles authentication logic
require_once 'includes/utils.php';      // For CSRF token generation
require_once 'includes/data_access/question_data.php'; // For fetching site questions

// --- Authentication Check ---
// Ensure the user is logged in and has the 'kiosk' role
// Note: auth.php uses isset($_SESSION['user_id']) for login check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'kiosk') {
    // Redirect to login page or an unauthorized page
    error_log('Unauthorized access attempt to kiosk_checkin.php by user ID: ' . ($_SESSION['user_id'] ?? 'Not Logged In')); // Use error_log
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- CSRF Token ---
$csrf_token = generateCsrfToken(); // Correct function name from utils.php

// --- Page Variables ---
$page_title = "Kiosk Check-In";
$current_page = "kiosk_checkin"; // For potential use in header active state

// --- Include Header ---
// Add meta tag for CSRF token to be accessible by JS
$additional_meta_tags = '<meta name="csrf-token" content="' . htmlspecialchars($csrf_token) . '">';
include_once 'includes/header.php';

// --- Fetch Site-Specific Questions ---
$kiosk_site_id = $_SESSION['user_site_id'] ?? null; // Assuming site ID is stored in session
$site_questions = [];
if ($kiosk_site_id && isset($pdo)) { // Ensure PDO connection exists
    try {
        $site_questions = getActiveQuestionsForSite($pdo, $kiosk_site_id);
    } catch (Exception $e) {
        error_log("Error fetching site questions for kiosk site ID {$kiosk_site_id}: " . $e->getMessage());
        // Optionally display an error message to the user or handle gracefully
        $site_questions = []; // Ensure it's an empty array on error
    }
} else {
     error_log("Kiosk Checkin: Could not determine kiosk site ID from session or PDO connection not available.");
}


// --- Define ENUM options (based on schema description and common usage) ---
// $veteran_options = ['Yes', 'No']; // No longer needed here
// $age_options = ['18-24', '25-34', '35-44', '45-54', '55+']; // No longer needed here
// $interviewing_options = ['Yes', 'No']; // No longer needed here

?>

<div class="container mt-4">
    <h1 class="text-center mb-4"><?php echo htmlspecialchars($page_title); ?></h1>

    <div class="row">
        <!-- QR Code Scanner Section -->
        <div class="col-md-6 border-right">
            <h2 class="text-center mb-3">Scan QR Code</h2>
            <div class="d-flex justify-content-center mb-3">
                <div id="qr-reader" style="width: 300px; height: 300px;"></div>
            </div>
            <div id="qr-reader-results" class="text-center font-weight-bold mt-3" style="min-height: 50px;">
                <!-- Scan results will be displayed here -->
                Please align the QR code within the frame.
            </div>
        </div>

        <!-- Manual Check-in Section -->
        <div class="col-md-6">
            <h2 class="text-center mb-3">Manual Check-in</h2>
            <p class="text-center text-muted">If the client does not have a QR code, please enter their details below.</p>

            <?php
            // Display success/error messages from manual form submission (if redirected back)
            if (isset($_SESSION['message'])) {
                echo '<div class="alert alert-' . $_SESSION['message_type'] . ' alert-dismissible fade show" role="alert">' .
                     htmlspecialchars($_SESSION['message']) .
                     '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' .
                     '</div>';
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
            }
            ?>

            <form id="manual-checkin-form" action="kiosk_manual_handler.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" class="form-control form-control-lg" id="first_name" name="first_name" required>
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" class="form-control form-control-lg" id="last_name" name="last_name" required>
                </div>

                <div class="form-group">
                    <label for="client_email">Email Address</label>
                    <input type="email" class="form-control form-control-lg" id="client_email" name="client_email" required>
                    <small class="form-text text-muted">Used for follow-up and potential AI services enrollment.</small>
                </div>

                <!-- Dynamic Site Questions -->
                <?php if (!empty($site_questions)): ?>
                    <hr>
                    <p class="font-weight-bold">Please answer the following questions:</p>
                    <?php foreach ($site_questions as $question):
                        $question_id = htmlspecialchars($question['id']);
                        $question_text = htmlspecialchars($question['question_text']);
                        $input_name = "question[{$question_id}]";
                    ?>
                        <div class="form-group mb-3">
                            <label class="mb-1"><?php echo $question_text; ?></label>
                            <div class="ml-3"> <!-- Indent options slightly -->
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="question_<?php echo $question_id; ?>_yes" value="Yes" required>
                                    <label class="form-check-label" for="question_<?php echo $question_id; ?>_yes">
                                        Yes
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="question_<?php echo $question_id; ?>_no" value="No" required>
                                    <label class="form-check-label" for="question_<?php echo $question_id; ?>_no">
                                        No
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (isset($kiosk_site_id)): ?>
                    <!-- Optional: Message if no questions are configured for the site -->
                    <!-- <p class="text-muted">No additional questions configured for this location.</p> -->
                <?php else: ?>
                    <!-- Optional: Error message if site ID couldn't be determined -->
                     <p class="text-danger font-weight-bold">Error: Could not load site-specific questions. Please contact support.</p>
                <?php endif; ?>
                <!-- End Dynamic Site Questions -->

                <button type="submit" class="btn btn-primary btn-lg btn-block mt-4">Submit Manual Check-in</button>
            </form>
        </div>
    </div>
</div>

<!-- Include html5-qrcode library from CDN -->
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<!-- Include Footer -->
<?php include_once 'includes/footer.php'; ?>

<!-- Include the custom Kiosk JavaScript -->
<script src="assets/js/kiosk.js"></script>

</body>
</html>