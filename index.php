<?php
/*
 * File: index.php
 * Path: /index.php
 * Created: 2024-08-01 11:15:00 MST
 * Author: Robert Archer
 *
 * Description: Login page for the Arizona@Work Check-In System.
 *              Displays the login form and handles authentication logic.
 */

// --- Configuration and Initialization ---
// Start the session explicitly if it's not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- CSRF Token Generation ---
// Generate a CSRF token if one doesn't exist in the session
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Handle error during token generation (rare)
        error_log("Error generating CSRF token: " . $e->getMessage());
        // Display a generic error or halt execution if critical
        die('A critical security error occurred. Please try again later.');
    }
}


// Check if the user is already logged in, if so, redirect based on role
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'kiosk') {
        header('Location: checkin.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

// Include the database connection file ONLY when processing the form
// No need to connect just to display the form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_once 'includes/db_connect.php'; // Contains the $pdo object
}

$error_message = ''; // Variable to hold login error messages
$info_message = ''; // Variable to hold info messages (like logout confirmation)

// --- Handle Status/Reason Messages from Redirects ---
if (isset($_GET['reason']) && $_GET['reason'] == 'not_logged_in') {
    $error_message = 'You must be logged in to access that page.';
}
if (isset($_GET['status']) && $_GET['status'] == 'logged_out') {
    $info_message = 'You have been successfully logged out.';
}
if (isset($_GET['error']) && $_GET['error'] == 'invalid_credentials') {
    $error_message = 'Invalid username or password.';
}

// --- Process Login Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token validation failed. Please try logging in again.';
        $user_agent_debug = $_SERVER['HTTP_USER_AGENT'] ?? 'USER AGENT UNKNOWN'; // Keep UserAgent for failure log
        error_log("CSRF token validation failed for login (index.php) from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . " | UserAgent: {$user_agent_debug}");
        // Redirect back to login page with a generic error
        header('Location: index.php?error=csrf_fail');
        exit; // Stop processing immediately
    }
    // --- End CSRF Token Verification ---


    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? ''); // Trim password input

    // Basic validation
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        try {
            // Prepare SQL statement to prevent SQL injection - Added LEFT JOIN for department slug
            $sql = "SELECT u.id, u.username, u.password_hash, u.role, u.site_id, u.department_id, u.full_name, u.is_site_admin, d.slug AS department_slug
                    FROM users u
                    LEFT JOIN departments d ON u.department_id = d.id
                    WHERE LOWER(u.username) = LOWER(:username) AND u.is_active = TRUE AND u.deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            if (!$stmt) {
                error_log("Login Attempt: PDO::prepare failed for username='{$username}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                $error_message = 'Login query preparation failed. Please contact support.';
            } else {

            // Check if prepare failed
            if (!$stmt) {
                 error_log("Login Attempt: PDO::prepare failed for username='{$username}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                 $error_message = 'Login query preparation failed. Please contact support.';
            } else {
                // Bind parameters
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);

                // Execute the statement
                $stmt->execute();

                // Fetch the user record
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Verify user exists and password is correct
                if ($user && password_verify($password, $user['password_hash'])) {
                    // Password is correct!

                    // Regenerate session ID for security (prevents session fixation)
                    session_regenerate_id(true);

                    // --- Store user data in session variables ---
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name']; // Use full_name from DB

                    // Store the user's REAL role and site_id from the database
                    $_SESSION['real_role'] = $user['role'];
                    $_SESSION['real_site_id'] = $user['site_id']; // Will be NULL for Admin/Director

                    // Initialize the ACTIVE role and site_id (starts same as real)
                    $_SESSION['active_role'] = $user['role'];
                    $_SESSION['active_site_id'] = $user['site_id'] ?? null; // active_site_id can be null (All Sites) or an int

                    // Store department ID and the newly fetched department SLUG
                    $_SESSION['department_id'] = isset($user['department_id']) && $user['department_id'] !== null ? (int)$user['department_id'] : null;
                    $_SESSION['department_slug'] = $user['department_slug'] ?? null; // Store the slug, will be null if no department or no slug
                    $_SESSION['is_site_admin'] = isset($user['is_site_admin']) ? (int)$user['is_site_admin'] : 0; // Store site admin status (0 or 1)

                    $_SESSION['last_login'] = time(); // Store login time

                    // Update last_login timestamp in the database
                    try {
                        $updateSql = "UPDATE users SET last_login = NOW() WHERE id = :user_id";
                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->bindParam(':user_id', $user['id'], PDO::PARAM_INT);
                        $updateStmt->execute();
                    } catch (PDOException $e) {
                        // Log this error, but don't necessarily stop the login
                        error_log("Error updating last_login for user ID {$user['id']}: " . $e->getMessage());
                    }

                    // Redirect based on the user's REAL role for the initial destination
                    if ($_SESSION['real_role'] === 'kiosk') {
                        header('Location: checkin.php');
                    } else {
                        // Site Supervisor, Director, Administrator go to dashboard
                        header('Location: dashboard.php');
                    }
                    exit; // Stop script execution after redirect

                } else {
                    // Invalid username or password
                    $error_message = 'Invalid username or password.';
                    // Optional: Log failed login attempt here for security monitoring
                    // error_log("Failed login attempt for username: " . $username);
                }
            } // End of the 'else' block for successful prepare
            } // End of the 'else' block for successful prepare

        } catch (PDOException $e) {
            // Database error during login process
            $error_message = "An error occurred during login. Please try again later.";
            // Log the detailed error for the administrator
            error_log("Database Login Error: " . $e->getMessage());
        }
    }
     // Redirect back to index.php with error GET parameter if error occurred during POST
     if (!empty($error_message) && $error_message == 'Invalid username or password.') {
        header('Location: index.php?error=invalid_credentials');
        exit;
    }
}

// --- Display the HTML Login Page ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arizona@Work - Login</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/flaviconlogo.png">
    <!-- Google Fonts (Inter) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- We will need a specific CSS file or rules for the login page -->
    <link rel="stylesheet" href="assets/css/main.css"> <!-- Use main.css or create login.css -->
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <img src="assets/img/logo.jpg" alt="Arizona@Work Logo">
        </div>

        <div class="login-form-container">
            <h1>Check-In System Login</h1>

            <!-- Display Error Messages -->
            <?php if (!empty($error_message)): ?>
                <div class="message-area error-message">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Display Info Messages -->
            <?php if (!empty($info_message)): ?>
                <div class="message-area info-message">
                    <?php echo htmlspecialchars($info_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php" class="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required autocomplete="username" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>

                <!-- "Forgot Password?" Link Removed as per plan -->
                <!-- <div class="form-links">
                    <a href="#">Forgot Password?</a>
                </div> -->
            </form>
        </div>

        <div class="login-footer">
            <div class="google-translate-container">
                <!-- Google Translate Widget Placeholder -->
                <div id="google_translate_element"></div>
            </div>

<div class="client-links text-center mb-3">
                <p class="mb-0">Are you a client? <a href="client_login.php">Login here</a> or <a href="client_register.php">Register here</a>.</p>
            </div>
            <div class="copyright">
                © <?php echo date("Y"); ?> Arizona@Work - Southeastern Arizona. All Rights Reserved.
            </div>
        </div>
    </div>

    <!-- Google Translate initialization script -->
    <script type="text/javascript">
        function googleTranslateElementInit() {
           new google.translate.TranslateElement({
        pageLanguage: 'en',
        includedLanguages: 'en,es', // Add this line
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE // Optional: Keeps the layout simple
    }, 'google_translate_element');
        }
    </script>
    <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

</body>
</html>