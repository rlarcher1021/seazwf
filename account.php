<?php
// account.php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/data_access/user_data.php';
require_once 'includes/utils.php'; // For generateCsrfToken, verifyCsrfToken, set_flash_message, display_flash_messages

// Start session if not already started (assuming auth.php might handle this, but ensure it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Access Control: Ensure user is logged in (Check if user_id is set in session)
// auth.php handles redirection for most pages, but this is an extra check specifically for account.php
if (!isset($_SESSION['user_id'])) {
    // If auth.php didn't already redirect, ensure they go to login
    header('Location: index.php?reason=session_expired');
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['active_role']; // Use 'active_role' consistent with auth.php

// 2. Access Control: Deny access for Kiosk role
if ($current_user_role === 'kiosk') {
    // Option 1: Redirect
    // header('Location: dashboard.php?error=access_denied');
    // exit;

    // Option 2: Show message on this page (requires header/footer includes below)
    $pageTitle = "Access Denied";
    include 'includes/header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>Access Denied: Your role does not permit access to this page.</div></div>";
    include 'includes/footer.php';
    exit;
}

// Initialize variables for messages and form data
$profile_success_message = '';
$profile_error_message = '';
$password_success_message = '';
$password_error_message = '';
$user_data = null;

// 3. Fetch Current User Data (for pre-populating forms)
$user_data = getUserById($pdo, $current_user_id);

if (!$user_data) {
    // Handle case where user data couldn't be fetched (e.g., user deleted after login)
    // Destroy the session and redirect to login
    session_unset();
    session_destroy();
    header('Location: index.php?error=user_not_found');
    exit;
}

// Pre-populate form fields (use fetched data or empty strings if not set)
$current_full_name = htmlspecialchars($user_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
$current_job_title = htmlspecialchars($user_data['job_title'] ?? '', ENT_QUOTES, 'UTF-8');


// 4. POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF Token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // Handle CSRF failure - log it, show generic error, maybe redirect
        error_log("CSRF token validation failed for user ID: " . $current_user_id);
        // Set a general error message or redirect
        set_flash_message('error', 'Security token invalid. Please try again.');
        header('Location: account.php'); // Redirect back to the form
        exit;
    }

    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // --- Handle Profile Update ---
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $jobTitle = trim($_POST['job_title'] ?? ''); // Keep as potentially empty/null

            // Basic validation (e.g., full name cannot be empty)
            if (empty($fullName)) {
                 set_flash_message('profile_error', 'Full Name cannot be empty.');
            } else {
                // Call data access function
                $success = updateUserProfile($pdo, $current_user_id, $fullName, $jobTitle);

                if ($success) {
                    set_flash_message('profile_success', 'Profile updated successfully.');
                    // Refresh user data to show updated values
                    $user_data = getUserById($pdo, $current_user_id);
                    $current_full_name = htmlspecialchars($user_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $current_job_title = htmlspecialchars($user_data['job_title'] ?? '', ENT_QUOTES, 'UTF-8');
                    // Update session if full name is stored there (optional)
                    // $_SESSION['full_name'] = $fullName;
                } else {
                    set_flash_message('profile_error', 'Failed to update profile. Please try again.');
                }
            }
             // Redirect back to account page to show messages and prevent resubmission
             header('Location: account.php');
             exit;
        }

        // --- Handle Password Change ---
        elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            // 1. Validate Current Password
            if (empty($currentPassword) || !verifyUserPassword($pdo, $current_user_id, $currentPassword)) {
                 set_flash_message('password_error', 'Incorrect current password.');
            }
            // 2. Validate New Password Match
            elseif ($newPassword !== $confirmPassword) {
                 set_flash_message('password_error', 'New passwords do not match.');
            }
            // 3. Validate Password Complexity (Server-side)
            // Regex: >= 10 chars, 1 uppercase, 1 lowercase, 1 number, 1 special char
            elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_+={}\[\]:;\"\'<>,.?~\\\\\/\\-])[A-Za-z\d!@#$%^&*()_+={}\[\]:;\"\'<>,.?~\\\\\/\\-]{10,}$/', $newPassword)) {
                 set_flash_message('password_error', 'New password does not meet complexity requirements. Must be at least 10 characters long and include uppercase, lowercase, number, and special character (!@#$%^&*).');
            }
            // 4. All Validations Pass - Update Password
            else {
                $success = updateUserPassword($pdo, $current_user_id, $newPassword);
                if ($success) {
                    set_flash_message('password_success', 'Password changed successfully.');
                    // Optionally: Force re-login after password change for security
                    // logout();
                    // header('Location: index.php?message=password_changed_login_again');
                    // exit;
                } else {
                    set_flash_message('password_error', 'Failed to change password. Please try again.');
                }
            }
             // Redirect back to account page to show messages and prevent resubmission
             header('Location: account.php');
             exit;
        }
    }
}

// Generate a new CSRF token for the forms
$csrf_token = generateCsrfToken();

// Set Page Title
$pageTitle = "My Account";

// Include Header
include 'includes/header.php';
?>

<div class="container mt-4">
    <h1><?php echo $pageTitle; ?></h1>
    <hr>

    <!-- Display Flash Messages -->
    <?php display_flash_messages('profile_error', 'danger'); ?>
    <?php display_flash_messages('profile_success', 'success'); ?>

    <!-- Section 1: Update Profile -->
    <section class="mb-5">
        <h2>Update Profile</h2>
        <form method="POST" action="account.php">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="mb-3">
                <label for="full_name" class="form-label">Full Name</label>
                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo $current_full_name; ?>" required>
            </div>

            <div class="mb-3">
                <label for="job_title" class="form-label">Job Title</label>
                <input type="text" class="form-control" id="job_title" name="job_title" value="<?php echo $current_job_title; ?>">
                <div class="form-text">Optional. Your professional title.</div>
            </div>

             <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled readonly>
                 <div class="form-text">Username cannot be changed.</div>
            </div>

             <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled readonly>
                 <div class="form-text">Email cannot be changed here. Contact an administrator if needed.</div>
            </div>


            <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
    </section>

    <hr>

     <!-- Display Flash Messages -->
    <?php display_flash_messages('password_error', 'danger'); ?>
    <?php display_flash_messages('password_success', 'success'); ?>

    <!-- Section 2: Change Password -->
    <section>
        <h2>Change Password</h2>
        <form method="POST" action="account.php">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="mb-3">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password" class="form-control" id="current_password" name="current_password" required>
            </div>

            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required aria-describedby="passwordHelp">
                 <div id="passwordHelp" class="form-text">
                    Must be at least 10 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character (!@#$%^&*).
                </div>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
    </section>

</div><!-- /.container -->

<?php
// Include Footer
include 'includes/footer.php';
?>