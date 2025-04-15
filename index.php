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
        error_log("CSRF token validation failed for login (index.php) from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        // Redirect back to login page with a generic error
        header('Location: index.php?error=csrf_fail');
        exit; // Stop processing immediately
    }
    // --- End CSRF Token Verification ---


    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic validation
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        try {
            // Prepare SQL statement to prevent SQL injection
            $sql = "SELECT id, username, password_hash, role, site_id, full_name
                    FROM users
                    WHERE username = :username AND is_active = TRUE";
            $stmt = $pdo->prepare($sql);

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
                // Use null coalescing operator (??) in case real_site_id is null
                $_SESSION['active_role'] = $user['role'];
                $_SESSION['active_site_id'] = $user['site_id'] ?? null; // active_site_id can be null (All Sites) or an int

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
    <style>
        /* Basic styles directly from mockup_index.php.html for this file */
        /* Consider moving these to main.css or a dedicated login.css */
        body.login-page {
            font-family: 'Inter', sans-serif;
            background-color: #F4F5F7;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh; /* Use min-height for flexibility */
        }
        .login-container {
            background-color: #FFFFFF;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            width: 400px;
            padding: 30px;
            max-width: 90%;
            margin: 20px; /* Add some margin for smaller screens */
        }
        .login-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .login-logo img {
            max-width: 220px;
            height: auto;
        }
        .login-container h1 {
            color: #1E3A8A; /* --color-primary */
            font-size: 24px;
            text-align: center;
            margin-bottom: 30px;
        }
        .message-area {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
        .error-message {
            background-color: #FEE2E2;
            border: 1px solid #F87171;
            color: #B91C1C;
        }
        .info-message {
            background-color: #D1FAE5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #4B5563; /* --color-dark-gray */
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #D1D5DB; /* --color-border */
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .form-actions {
            margin-top: 30px;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            transition: background-color 0.2s;
        }
        .btn-primary {
            background-color: #FF6B35; /* --color-secondary */
            color: white;
        }
        .btn-primary:hover {
            background-color: #E85A29; /* Darker orange */
        }
        .login-footer {
            margin-top: 30px;
            text-align: center;
            font-size: 14px;
            color: #6B7280; /* --color-gray */
        }
        .google-translate-container {
            margin-bottom: 15px;
            min-height: 30px; /* Give space for widget */
        }
         #google_translate_element { padding: 5px 0; }
        .copyright {
            font-size: 12px;
            margin-top: 10px;
        }
        /* Remove 'Forgot Password' link style if not needed */
        /* .form-links { margin-top: 20px; text-align: center; } */
        /* .form-links a { color: #1E3A8A; text-decoration: none; } */
        /* .form-links a:hover { text-decoration: underline; } */
    </style>
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