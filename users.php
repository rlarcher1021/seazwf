<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Server-Side Access Control ---
// Enforces access control at the very beginning of the script.
// Only allows users with 'director' or 'administrator' roles.
if (!isset($_SESSION['active_role']) || !in_array($_SESSION['active_role'], ['director', 'administrator'])) {
    header('Location: index.php');
    exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/*
 * File: users.php
 * Path: /users.php
 * Created: 2024-08-01 14:00:00 MST
 * Author: Robert Archer
 * Updated: 2025-04-05
 * Description: Administrator page/section for managing user accounts
 *              (Add, Edit, Activate/Deactivate, Reset Password, Delete).
 *              Processes user management actions.
 *              Intended to be displayed within the dashboard interface.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication & database connection
require_once 'includes/db_connect.php'; // Provides $pdo
require_once 'includes/auth.php';       // Ensures user is logged in, provides $_SESSION['active_role'] etc.
require_once 'includes/data_access/site_data.php'; // For getAllActiveSites
require_once 'includes/data_access/user_data.php'; // For user functions
require_once 'includes/data_access/department_data.php'; // For department functions


// --- Role Check: Only Administrators can access this page ---
if (!($_SESSION['active_role'] === 'administrator' || (isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1))) {
    $_SESSION['flash_message'] = "Access denied. Administrator privileges required.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Flash Message Handling (Retrieve and Clear) ---
$flash_message = $_SESSION['flash_message'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// --- Fetch Sites for Dropdowns ---
$sites_list = getAllActiveSites($pdo); // Use data access function
$site_fetch_error = ($sites_list === []) ? "Error loading site list for forms." : ''; // Check if function returned empty array (error)

// --- Fetch Departments for Dropdowns ---
$departments_list = getAllDepartments($pdo); // Fetch departments
$department_fetch_error = ($departments_list === []) ? "Error loading department list for forms." : ''; // Basic error check

// --- Handle Form Submissions (POST Requests) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_message'] = 'CSRF token validation failed. Request blocked.';
        $_SESSION['flash_type'] = 'error';
        error_log("CSRF token validation failed for users.php from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        // Redirect back to the main users list page
        header('Location: users.php');
        exit; // Stop processing immediately
    }
    // --- End CSRF Token Verification ---


    $action = $_POST['action'] ?? null;

    // Validate user_id early if the action requires it
    $user_id = null;
    if (in_array($action, ['edit_user', 'toggle_active', 'reset_password', 'delete_user'])) {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if ($user_id === false || $user_id <= 0) {
             $_SESSION['flash_message'] = "Invalid User ID specified for the action.";
             $_SESSION['flash_type'] = 'error';
             header('Location: users.php');
             exit;
        }
    }

    try { // <-- START: Main try block wrapping ALL actions

        // --- Add User Action ---
        if ($action === 'add_user') {
            // Sanitize and validate input
            $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS));
            $full_name = trim(filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_SPECIAL_CHARS));
            $email = trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL));
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_SPECIAL_CHARS);
            $site_id_input = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
            $site_id = ($site_id_input === false || $site_id_input <= 0) ? null : $site_id_input;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $job_title = trim(filter_input(INPUT_POST, 'job_title', FILTER_SANITIZE_SPECIAL_CHARS)); // Added job_title
            $department_id_input = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            // Convert false (from empty string or invalid int) or 0 to null
            $department_id = ($department_id_input === false || $department_id_input === 0) ? null : $department_id_input;

            // Perform validation checks
            $errors = [];
            if (empty($username)) $errors[] = "Username is required.";
            if (empty($full_name)) $errors[] = "Full Name is required.";
            if (empty($password)) $errors[] = "Password is required.";
            if ($password !== $confirm_password) $errors[] = "Passwords do not match.";
            if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long.";
            if (!in_array($role, ['kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'])) $errors[] = "Invalid role selected.";
            if (in_array($role, ['kiosk', 'azwk_staff', 'outside_staff']) && $site_id === null) {
                 $errors[] = "A valid Site must be assigned for Kiosk and Site Supervisor roles.";
            }
            if (!empty($email) && $email === false) { // Check if validation failed specifically for email format
                $errors[] = "The provided email address format is invalid.";
            }
            if (!empty($username)) {
                 // Use data access function to check username
                 if (isUsernameTaken($pdo, $username)) {
                     $errors[] = "Username '" . htmlspecialchars($username) . "' already exists.";
                 }
            }

            // Process if no validation errors
            if (empty($errors)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $actual_site_id = ($role === 'director' || $role === 'administrator') ? null : $site_id; // Nullify site for higher roles

                // Prepare user data array for the function
                $newUserData = [
                    'username' => $username,
                    'full_name' => $full_name,
                    'email' => $email ?: null,
                    'password_hash' => $password_hash,
                    'role' => $role,
                    'site_id' => $actual_site_id,
                    'is_active' => $is_active,
                    'job_title' => $job_title ?: null, // Added job_title
                    'department_id' => $department_id // Added department_id
                ];

                // Use data access function to add user
                $newUserId = addUser($pdo, $newUserData);

                if ($newUserId === false) {
                    // If addUser fails, it logs the error. Set a generic message here.
                    $errors[] = "A database error occurred while adding the user.";
                    // Jump to error handling block below
                }

                // Check again if errors occurred (including potential DB error from addUser)
                if (empty($errors)) {
                    $_SESSION['flash_message'] = "User '" . htmlspecialchars($username) . "' added successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: users.php');
                    exit;
                }

            } else { // Validation FAILED
                $_SESSION['flash_message'] = "Error adding user:<br>" . implode("<br>", $errors);
                $_SESSION['flash_type'] = 'error';
                $_SESSION['form_data'] = $_POST; // Store POST data to repopulate form
                header('Location: users.php?action=add'); // Redirect back to add form
                exit;
            }
        } // --- End of 'add_user' action ---


        // --- Edit User Action ---
        elseif ($action === 'edit_user' && $user_id) { // user_id validated above

            // --- Permission Check ---
            $target_user = getUserById($pdo, $user_id);
            if (!$target_user) {
                $_SESSION['flash_message'] = "Target user not found for editing.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            $can_perform_action = false;
            $session_role = $_SESSION['active_role'];
            $session_is_site_admin = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
            $session_site_id = $_SESSION['active_site_id'] ?? null;
            $target_role = $target_user['role'];
            $target_site_id = $target_user['site_id'];

            if ($session_role === 'administrator') {
                $can_perform_action = true;
            } elseif ($session_role === 'director') {
                // Assuming directors can manage all users for now
                $can_perform_action = true;
            } elseif ($session_is_site_admin && $session_site_id !== null) {
                if ($target_site_id === $session_site_id && $target_role !== 'administrator' && $target_role !== 'director') {
                    $can_perform_action = true;
                }
            }

            if (!$can_perform_action) {
                $_SESSION['flash_message'] = "Permission denied to edit the selected user.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }
            // --- End Permission Check ---

            // Sanitize and validate input
            $full_name = trim(filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_SPECIAL_CHARS));
            $email_edit = trim(filter_input(INPUT_POST, 'email_edit', FILTER_VALIDATE_EMAIL));
            $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_SPECIAL_CHARS);
            $site_id_input = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
            $site_id = ($site_id_input === false || $site_id_input <= 0) ? null : $site_id_input;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $job_title_edit = trim(filter_input(INPUT_POST, 'job_title_edit', FILTER_SANITIZE_SPECIAL_CHARS)); // Added job_title_edit
            $department_id_input_edit = filter_input(INPUT_POST, 'department_id_edit', FILTER_VALIDATE_INT);
            // Convert false (from empty string or invalid int) or 0 to null
            $department_id_edit = ($department_id_input_edit === false || $department_id_input_edit === 0) ? null : $department_id_input_edit;

            // Perform validation checks
            $errors = [];
            if (empty($full_name)) $errors[] = "Full Name is required.";
            if (!in_array($role, ['kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'])) $errors[] = "Invalid role selected.";
            if (in_array($role, ['kiosk', 'azwk_staff', 'outside_staff']) && $site_id === null) {
                 $errors[] = "A valid Site must be assigned for Kiosk and Site Supervisor roles.";
            }
            if (!empty($email_edit) && $email_edit === false) {
                 $errors[] = "The provided email address format is invalid.";
            }
             // Prevent admin from deactivating their own account in edit form
             if ($user_id === $_SESSION['user_id'] && $is_active === 0) {
                 $errors[] = "You cannot deactivate your own account via the edit form.";
             }

            // Process if no validation errors
            if (empty($errors)) {
                 $actual_site_id = ($role === 'director' || $role === 'administrator') ? null : $site_id;

                 // Prepare user data array for the function
                 $updateUserData = [
                     'full_name' => $full_name,
                     'email' => $email_edit ?: null,
                     'role' => $role,
                     'site_id' => $actual_site_id,
                     'is_active' => $is_active,
                     'job_title' => $job_title_edit ?: null, // Added job_title
                     'department_id' => $department_id_edit, // Added department_id
                     'is_site_admin' => isset($_POST['is_site_admin']) ? 1 : 0 // Restore site admin handling
                 ];

                 // Use data access function to update user
                 if (!updateUser($pdo, $user_id, $updateUserData)) {
                     // If updateUser fails, it logs the error. Set a generic message here.
                     $errors[] = "A database error occurred while updating the user.";
                     // Jump to error handling block below
                 }

                 // Check again if errors occurred (including potential DB error from updateUser)
                 if (empty($errors)) {
                     $_SESSION['flash_message'] = "User details updated successfully.";
                     $_SESSION['flash_type'] = 'success';
                     header('Location: users.php'); // Redirect to list after success
                     exit;
                 }

            } else { // Validation failed
                $_SESSION['flash_message'] = "Error updating user:<br>" . implode("<br>", $errors);
                $_SESSION['flash_type'] = 'error';
                // Redirect back to edit form
                header('Location: users.php?action=edit&user_id=' . $user_id);
                exit;
            }
        } // --- End of 'edit_user' action ---


        // --- Toggle Active Status Action ---
        elseif ($action === 'toggle_active' && $user_id) { // user_id validated above

             // Prevent self-toggle first
             if ($user_id === $_SESSION['user_id']) {
                  $_SESSION['flash_message'] = "You cannot toggle the active status of your own account.";
                  $_SESSION['flash_type'] = 'error';
                  header('Location: users.php');
                  exit;
             }

            // --- Permission Check ---
            $target_user = getUserById($pdo, $user_id);
            if (!$target_user) {
                $_SESSION['flash_message'] = "Target user not found for status toggle.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            $can_perform_action = false;
            $session_role = $_SESSION['active_role'];
            $session_is_site_admin = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
            $session_site_id = $_SESSION['active_site_id'] ?? null;
            $target_role = $target_user['role'];
            $target_site_id = $target_user['site_id'];

            if ($session_role === 'administrator') {
                $can_perform_action = true;
            } elseif ($session_role === 'director') {
                // Assuming directors can manage all users for now
                $can_perform_action = true;
            } elseif ($session_is_site_admin && $session_site_id !== null) {
                if ($target_site_id === $session_site_id && $target_role !== 'administrator' && $target_role !== 'director') {
                    $can_perform_action = true;
                }
            }

            if (!$can_perform_action) {
                $_SESSION['flash_message'] = "Permission denied to toggle status for the selected user.";
                  $_SESSION['flash_type'] = 'error';
                  header('Location: users.php');
                  exit;
             }

             // Use data access function to toggle status
             if (toggleUserActiveStatus($pdo, $user_id)) {
                 $_SESSION['flash_message'] = "User status updated successfully.";
                 $_SESSION['flash_type'] = 'success';
             } else {
                 // Function handles logging, check if user exists or DB error occurred
                 // We might check if user exists first to give a better message, but for now:
                 $_SESSION['flash_message'] = "Failed to update user status (User not found or DB error).";
                 $_SESSION['flash_type'] = 'error';
             }
             header('Location: users.php'); // Redirect back to list
             exit;
        } // --- End of 'toggle_active' action ---


         // --- Reset Password Action ---
        elseif ($action === 'reset_password' && $user_id) { // user_id validated above

            // --- Permission Check ---
            $target_user = getUserById($pdo, $user_id);
            if (!$target_user) {
                $_SESSION['flash_message'] = "Target user not found for password reset.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            $can_perform_action = false;
            $session_role = $_SESSION['active_role'];
            $session_is_site_admin = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
            $session_site_id = $_SESSION['active_site_id'] ?? null;
            $target_role = $target_user['role'];
            $target_site_id = $target_user['site_id'];

            if ($session_role === 'administrator') {
                $can_perform_action = true;
            } elseif ($session_role === 'director') {
                // Assuming directors can manage all users for now
                $can_perform_action = true;
            } elseif ($session_is_site_admin && $session_site_id !== null) {
                if ($target_site_id === $session_site_id && $target_role !== 'administrator' && $target_role !== 'director') {
                    $can_perform_action = true;
                }
            }

            if (!$can_perform_action) {
                $_SESSION['flash_message'] = "Permission denied to reset password for the selected user.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }
            // --- End Permission Check ---

            $new_password = $_POST['new_password'] ?? '';
            $confirm_new_password = $_POST['confirm_new_password'] ?? '';

            // Validation
            $errors = [];
            if (empty($new_password)) $errors[] = "New Password is required.";
            if ($new_password !== $confirm_new_password) $errors[] = "Passwords do not match.";
            if (strlen($new_password) < 8) $errors[] = "Password must be at least 8 characters long.";

            if (empty($errors)) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                // Use data access function to reset password
                if (!resetUserPassword($pdo, $user_id, $password_hash)) {
                    // Function logs error, set generic message
                     $errors[] = "A database error occurred while resetting the password.";
                     // Jump to error handling block below
                }

                // Check again for errors (including potential DB error)
                if (empty($errors)) {
                    $_SESSION['flash_message'] = "Password reset successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: users.php'); // Redirect to list
                    exit;
                }

            } else { // Validation failed
                 $_SESSION['flash_message'] = "Error resetting password:<br>" . implode("<br>", $errors);
                 $_SESSION['flash_type'] = 'error';
                 // Redirect back to reset form
                 header('Location: users.php?action=resetpw&user_id=' . $user_id);
                 exit;
            }
        } // --- End of 'reset_password' action ---


        // --- Delete User Action ---
        elseif ($action === 'delete_user' && $user_id) { // user_id validated above

            // Keep debugging logs for now until deletion is fully stable
            error_log("[DEBUG users.php] Entering delete_user action for user ID: " . $user_id);

            // 1. Prevent Self-Deletion (Highest Priority Check)
            if ($_SESSION['user_id'] === $user_id) {
                $_SESSION['flash_message'] = "You cannot delete your own account.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            // 2. Permission Check
            $target_user = getUserById($pdo, $user_id);
            if (!$target_user) {
                $_SESSION['flash_message'] = "Target user not found for deletion.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            $can_perform_action = false;
            $session_role = $_SESSION['active_role'];
            $session_is_site_admin = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
            $session_site_id = $_SESSION['active_site_id'] ?? null;
            $target_role = $target_user['role'];
            $target_site_id = $target_user['site_id'];

            if ($session_role === 'administrator') {
                $can_perform_action = true;
            } elseif ($session_role === 'director') {
                // Assuming directors can manage all users for now
                $can_perform_action = true;
            } elseif ($session_is_site_admin && $session_site_id !== null) {
                if ($target_site_id === $session_site_id && $target_role !== 'administrator' && $target_role !== 'director') {
                    $can_perform_action = true;
                }
            }

            if (!$can_perform_action) {
                $_SESSION['flash_message'] = "Permission denied to delete the selected user.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            // 3. Use data access function to delete user
            if (deleteUser($pdo, $user_id)) {
                $_SESSION['flash_message'] = "User deleted successfully.";
                $_SESSION['flash_type'] = 'success';
                error_log("[DEBUG users.php] deleteUser function returned true for user ID: " . $user_id);
            } else {
                // Function logs specific error, set generic message here
                $_SESSION['flash_message'] = "Failed to delete user (User not found or DB error).";
                $_SESSION['flash_type'] = 'error';
                error_log("[ERROR users.php] deleteUser function returned false for user ID: " . $user_id);
            }

            header('Location: users.php'); // Redirect after delete attempt
            exit;

        } // --- End of 'delete_user' action ---


    } // <-- END: Closing brace for the main try block (comes AFTER all actions)
    catch (PDOException $e) { // <-- START: Main catch block for ALL actions
         // Catch any DB errors from any action
         $_SESSION['flash_message'] = "Database error encountered: " . $e->getMessage();
         $_SESSION['flash_type'] = 'error';
         // Log more context if possible
         $error_user_id = $user_id ?? 'N/A'; // Use null coalescing for user_id
         $error_action = $action ?? 'unknown'; // Use null coalescing for action
         error_log("User Mgmt PDO Error: " . $e->getMessage() . " | Action: " . $error_action . " | User ID: " . $error_user_id);
         // Redirect back to the main list view on unexpected DB errors
         header('Location: users.php');
         exit;
    } // <-- END: Main catch block

} // --- End POST request handling ---


// --- Fetch User Data (Ensure this is AFTER POST handling) ---
// Use data access function to fetch users, passing session context
$session_role_for_fetch = $_SESSION['active_role'];
$session_site_id_for_fetch = $_SESSION['active_site_id'] ?? null;
$session_is_site_admin_for_fetch = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
$users = getAllUsersWithSiteNames($pdo, $session_role_for_fetch, $session_site_id_for_fetch, $session_is_site_admin_for_fetch);
$user_fetch_error = ($users === false) ? "Error fetching user list." : ''; // Check if function returned false (error logged in function)

// --- Handle Displaying Add/Edit/Reset Password Forms ---
$edit_user_data = null;
$show_reset_password_form = false;
$user_to_reset = null; // Initialize
$current_action = $_GET['action'] ?? 'list'; // Default to list view
$edit_user_id = null; // Initialize

// Only try to get user_id if needed for edit/resetpw
if ($current_action === 'edit' || $current_action === 'resetpw') {
    $edit_user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    if (!$edit_user_id) {
        // Set flash message if ID is invalid/missing for these actions
        // Check if a flash message isn't already set from POST handling
        if (!isset($_SESSION['flash_message'])) {
            $_SESSION['flash_message'] = "Invalid User ID specified for action.";
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: users.php'); // Redirect to list view
        exit;
    }
}

// Fetch data if editing
if ($current_action === 'edit' && $edit_user_id) {
    // Use data access function to fetch user data
    $edit_user_data = getUserById($pdo, $edit_user_id);
    if (!$edit_user_data) {
        // Function returns null on error or not found (error logged in function)
        if (!isset($_SESSION['flash_message'])) {
            $_SESSION['flash_message'] = "User not found for editing or database error occurred.";
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: users.php'); // Redirect to list view
        exit;
    }
}
// Check if user exists for reset password form
elseif ($current_action === 'resetpw' && $edit_user_id) {
    // Use data access function to get user details for reset form
    $user_to_reset = getUserDetailsForReset($pdo, $edit_user_id);
    if ($user_to_reset) {
        $show_reset_password_form = true;
    } else {
        // Function returns null on error or not found (error logged in function)
        if (!isset($_SESSION['flash_message'])) {
            $_SESSION['flash_message'] = "User not found for password reset or database error occurred.";
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: users.php'); // Redirect to list view
        exit;
    }
}

// --- Page Setup Continued ---
$pageTitle = "User Management"; // Set the page title for header.php

// --- Include Header ---
require_once 'includes/header.php';

?>

            <!-- Page Header -->
            <div class="header">
               <!-- <h1 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>-->
            </div>

             <!-- Display Flash Messages (Retrieve from session if set) -->
            <?php
                if (isset($_SESSION['flash_message_display'])) {
                    $flash_message = $_SESSION['flash_message_display'];
                    $flash_type = $_SESSION['flash_type_display'] ?? 'info';
                    unset($_SESSION['flash_message_display'], $_SESSION['flash_type_display']);
                }
            ?>
            <?php if ($flash_message): ?>
                <div class="message-area message-<?php echo htmlspecialchars($flash_type); ?>">
                    <?php echo htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8'); // Apply encoding ?>
                </div>
            <?php endif; ?>

             <!-- Display Site Fetching Error -->
             <?php if ($site_fetch_error): ?>
                <div class="message-area error-message"><?php echo htmlspecialchars($site_fetch_error); ?></div>
            <?php endif; ?>
             <!-- Display User Fetching Error -->
             <?php if ($user_fetch_error): ?>
                <div class="message-area error-message"><?php echo htmlspecialchars($user_fetch_error); ?></div>
            <?php endif; ?>
            <!-- Display Department Fetching Error -->
            <?php if ($department_fetch_error): ?>
               <div class="message-area error-message"><?php echo htmlspecialchars($department_fetch_error); ?></div>
            <?php endif; ?>

            <!-- User Management Content Section -->
            <div class="content-section">

                <!-- User List Table (Show only if action is 'list') -->
                <div class="table-container <?php if ($current_action !== 'list') echo 'd-none'; ?>">
                    <div class="table-header">
                        <h2 class="table-title">User Accounts</h2>
                        <div class="table-actions">
                            <a href="users.php?action=add" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Add User
                            </a>
                        </div>
                    </div>
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Assigned Site</th>
                                <th>Department</th> <!-- Added Department Column -->
                                <th>Last Login</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></td>
                                        <td>
                                            <?php
                                                $email_value = $user['email'] ?? '';
                                                if (!empty($email_value)) {
                                                    echo '<a href="mailto:' . htmlspecialchars($email_value) . '">' . htmlspecialchars($email_value) . '</a>';
                                                } else {
                                                    echo '<span class="text-muted">No Email</span>'; // Display 'No Email' dimmed
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))); ?></td>
                                        <td><?php
                                                if ($user['site_id'] !== null) { echo htmlspecialchars($user['site_name'] ?? 'Invalid Site ID'); }
                                                else if (in_array($user['role'], ['director', 'administrator'])) { echo 'All Sites'; }
                                                else { echo 'N/A'; }
                                            ?>
                                        </td>
                                        <td><?php echo isset($user['department_name']) ? htmlspecialchars($user['department_name']) : '<span class="text-muted">None</span>'; ?></td> <!-- Display Department Name -->
                                        <td><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                        <td><span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                        <td class="actions-cell">
                                            <!-- Edit Button -->
                                            <a href="users.php?action=edit&user_id=<?php echo $user['id']; ?>" class="btn btn-outline btn-sm" title="Edit User">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <!-- Reset Password Button -->
                                            <a href="users.php?action=resetpw&user_id=<?php echo $user['id']; ?>" class="btn btn-outline btn-sm" title="Reset Password">
                                                <i class="fas fa-key"></i>
                                            </a>
                                            <!-- Activate/Deactivate Button -->
                                            <?php if ($_SESSION['user_id'] != $user['id']): // Prevent toggling own status ?>
                                                <form method="POST" action="users.php" class="d-inline-block">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn btn-outline btn-sm" title="<?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                        <i class="fas <?php echo $user['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <!-- Delete Button (Triggers Modal) -->
                                            <?php if ($_SESSION['user_id'] != $user['id']): // Prevent deleting own account ?>
                                                <button type="button" class="btn btn-danger btn-sm delete-user-btn" title="Delete User"
                                                        data-toggle="modal" data-target="#deleteUserModal"
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="text-center">No users found.</td></tr> <!-- Updated colspan to 9 -->
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div> <!-- /.table-container -->

                <!-- Delete User Modal HTML -->
                <div class="modal fade" id="deleteUserModal" tabindex="-1" role="dialog" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
                  <div class="modal-dialog" role="document">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="deleteUserModalLabel">Confirm User Deletion</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                          <span aria-hidden="true">×</span>
                        </button>
                      </div>
                      <div class="modal-body">
                        Are you sure you want to delete user "<strong id="delete-user-username"></strong>"?
                        <br>This action is irreversible!
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <form method="POST" action="users.php" class="d-inline" id="deleteUserForm">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" id="delete-user-id-input" value="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <button type="submit" class="btn btn-danger">Confirm Delete</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
                <!-- End Delete User Modal HTML -->

                <!-- Add User Form (Shown when action=add) -->
                <?php if ($current_action === 'add'): ?>
                 <div class="mt-4 <?php if ($current_action !== 'add') echo 'd-none'; ?>" id="add-user-form">
                    <h3 class="settings-section-title">Add New User</h3>
                    <form method="POST" action="users.php">
                        <input type="hidden" name="action" value="add_user">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                         <?php $form_data = $_SESSION['form_data'] ?? []; unset($_SESSION['form_data']); // Get repopulation data ?>
                        <div class="settings-form">
                            <div class="mb-3">
                                <label for="add_username" class="form-label">Username:</label>
                                <input type="text" id="add_username" name="username" class="form-control" required value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>">
                            </div>
                             <div class="mb-3">
                                <label for="add_full_name" class="form-label">Full Name:</label>
                                <input type="text" id="add_full_name" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="add_user_email" class="form-label">Email Address:</label>
                                <input type="email" id="add_user_email" name="email" class="form-control" placeholder="user@example.com" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                                <p class="form-description">Optional. Used for notifications if needed.</p>
                            </div>
                            <div class="mb-3">
                               <label for="add_job_title" class="form-label">Job Title:</label>
                               <input type="text" id="add_job_title" name="job_title" class="form-control" value="<?php echo htmlspecialchars($form_data['job_title'] ?? ''); ?>">
                               <p class="form-description">Optional. User's professional title.</p>
                           </div>
                            <div class="form-group">
                                <label for="add_password" class="form-label">Password:</label>
                                <input type="password" id="add_password" name="password" class="form-control" required minlength="8">
                                <p class="form-description">Minimum 8 characters.</p>
                            </div>
                             <div class="form-group">
                                <label for="add_confirm_password" class="form-label">Confirm Password:</label>
                                <input type="password" id="add_confirm_password" name="confirm_password" class="form-control" required>
                            </div>
                             <div class="mb-3">
                                <label for="add_role" class="form-label">Role:</label>
                                <select id="add_role" name="role" class="form-select" required onchange="toggleSiteSelect(this.value, 'add_site_id_group')">
                                    <option value="">-- Select Role --</option>
                                    <option value="kiosk" <?php echo (($form_data['role'] ?? '') === 'kiosk') ? 'selected' : ''; ?>>Kiosk</option>
                                    <option value="azwk_staff" <?php echo (($form_data['role'] ?? '') === 'azwk_staff') ? 'selected' : ''; ?>>AZWK Staff</option>
                                    <option value="outside_staff" <?php echo (($form_data['role'] ?? '') === 'outside_staff') ? 'selected' : ''; ?>>Outside Staff</option>
                                    <option value="director" <?php echo (($form_data['role'] ?? '') === 'director') ? 'selected' : ''; ?>>Director</option>
                                    <option value="administrator" <?php echo (($form_data['role'] ?? '') === 'administrator') ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                            </div>
                             <div id="add_site_id_group" class="mb-3 <?php echo (in_array($form_data['role'] ?? '', ['kiosk', 'azwk_staff', 'outside_staff'])) ? '' : 'd-none'; ?>">
                                <label for="add_site_id" class="form-label">Assign to Site:</label>
                                <select id="add_site_id" name="site_id" class="form-select">
                                     <option value="">-- Select Site --</option>
                                     <?php if (!empty($sites_list)): ?>
                                         <?php foreach ($sites_list as $site): ?>
                                             <option value="<?php echo $site['id']; ?>" <?php echo (($form_data['site_id'] ?? '') == $site['id']) ? 'selected' : ''; ?>>
                                                 <?php echo htmlspecialchars($site['name']); ?>
                                             </option>
                                         <?php endforeach; ?>
                                     <?php else: ?>
                                         <option value="" disabled>No active sites available</option>
                                     <?php endif; ?>
                                </select>
                                <p class="form-description">Required for Kiosk and Site Supervisor roles.</p>
                             </div>
                             <!-- Department Dropdown (Admin Only - but whole page is admin only) -->
                             <div class="mb-3">
                                <label for="add_department_id" class="form-label">Department:</label>
                                <select id="add_department_id" name="department_id" class="form-select">
                                    <option value="">-- None --</option>
                                    <?php if (!empty($departments_list)): ?>
                                        <?php foreach ($departments_list as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" <?php echo (($form_data['department_id'] ?? null) == $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php elseif ($department_fetch_error): ?>
                                        <option value="" disabled>Error loading departments</option>
                                    <?php else: ?>
                                         <option value="" disabled>No departments available</option>
                                    <?php endif; ?>
                                </select>
                                <p class="form-description">Optional. Assign user to a department.</p>
                             </div>
                             <div class="form-check mb-3 grid-col-full-width">
                                 <input class="form-check-input" type="checkbox" name="is_active" value="1" id="add_is_active" <?php echo (!isset($form_data['action']) || !empty($form_data['is_active'])) ? 'checked' : ''; // Default checked on new form or if previously checked ?>>
                                 <label class="form-check-label" for="add_is_active">
                                     Active User
                                 </label>
                             </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add User</button>
                            <a href="users.php" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                 </div>
                <?php endif; ?>


                 <!-- Edit User Form (Shown when action=edit) -->
                <?php if ($current_action === 'edit' && $edit_user_data): ?>
                 <div class="mt-4 <?php if ($current_action !== 'edit') echo 'd-none'; ?>" id="edit-user-form">
                    <h3 class="settings-section-title">Edit User: <?php echo htmlspecialchars($edit_user_data['username']); ?></h3>
                    <form method="POST" action="users.php">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" value="<?php echo $edit_user_data['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <div class="settings-form">
                             <div class="mb-3">
                                <label for="edit_username" class="form-label">Username:</label>
                                <input type="text" id="edit_username" name="username_display" class="form-control" value="<?php echo htmlspecialchars($edit_user_data['username']); ?>" disabled>
                                 <p class="form-description">Username cannot be changed.</p>
                            </div>
                             <div class="mb-3">
                                <label for="edit_full_name" class="form-label">Full Name:</label>
                                <input type="text" id="edit_full_name" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($edit_user_data['full_name']); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="edit_user_email" class="form-label">Email Address:</label>
                                <input type="email" id="edit_user_email" name="email_edit" class="form-control" placeholder="user@example.com" value="<?php echo htmlspecialchars($edit_user_data['email'] ?? ''); // Use null coalescing ?>">
                                <p class="form-description">Optional. Used for notifications if needed.</p>
                            </div>
                            <div class="mb-3">
                               <label for="edit_job_title" class="form-label">Job Title:</label>
                               <input type="text" id="edit_job_title" name="job_title_edit" class="form-control" value="<?php echo htmlspecialchars($edit_user_data['job_title'] ?? ''); ?>">
                               <p class="form-description">Optional. User's professional title.</p>
                           </div>
                            <div class="mb-3">
                                <label for="edit_role" class="form-label">Role:</label>
                                <select id="edit_role" name="role" class="form-select" required onchange="toggleSiteSelect(this.value, 'edit_site_id_group')">
                                     <option value="">-- Select Role --</option>
                                     <option value="kiosk" <?php echo ($edit_user_data['role'] === 'kiosk') ? 'selected' : ''; ?>>Kiosk</option>
                                     <option value="azwk_staff" <?php echo ($edit_user_data['role'] === 'azwk_staff') ? 'selected' : ''; ?>>AZWK Staff</option>
                                     <option value="outside_staff" <?php echo ($edit_user_data['role'] === 'outside_staff') ? 'selected' : ''; ?>>Outside Staff</option>
                                     <option value="director" <?php echo ($edit_user_data['role'] === 'director') ? 'selected' : ''; ?>>Director</option>
                                     <option value="administrator" <?php echo ($edit_user_data['role'] === 'administrator') ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                            </div>
                             <div id="edit_site_id_group" class="mb-3 <?php echo (in_array($edit_user_data['role'], ['kiosk', 'azwk_staff', 'outside_staff'])) ? '' : 'd-none'; ?>">
                                <label for="edit_site_id" class="form-label">Assign to Site:</label>
                                <select id="edit_site_id" name="site_id" class="form-select">
                                     <option value="">-- Select Site --</option>
                                      <?php if (!empty($sites_list)): ?>
                                         <?php foreach ($sites_list as $site): ?>
                                             <option value="<?php echo $site['id']; ?>" <?php echo ($edit_user_data['site_id'] == $site['id']) ? 'selected' : ''; ?>>
                                                 <?php echo htmlspecialchars($site['name']); ?>
                                             </option>
                                         <?php endforeach; ?>
                                      <?php else: ?>
                                         <option value="" disabled>No active sites available</option>
                                      <?php endif; ?>
                                </select>
                                <p class="form-description">Required for Kiosk and Site Supervisor roles.</p>
                            </div>
                             <!-- Department Dropdown (Admin Only - but whole page is admin only) -->
                             <div class="mb-3">
                                <label for="edit_department_id" class="form-label">Department:</label>
                                <select id="edit_department_id" name="department_id_edit" class="form-select">
                                    <option value="">-- None --</option>
                                    <?php if (!empty($departments_list)): ?>
                                        <?php foreach ($departments_list as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" <?php echo (($edit_user_data['department_id'] ?? null) == $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                     <?php elseif ($department_fetch_error): ?>
                                        <option value="" disabled>Error loading departments</option>
                                     <?php else: ?>
                                         <option value="" disabled>No departments available</option>
                                    <?php endif; ?>
                                </select>
                                <p class="form-description">Optional. Assign user to a department.</p>
                            </div>
                            <!-- Site Administrator Checkbox (Restore) -->
                            <div class="form-check mb-3 grid-col-full-width">
                                <input class="form-check-input" type="checkbox" name="is_site_admin" value="1" id="edit_is_site_admin" <?php echo ($edit_user_data['is_site_admin'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_is_site_admin">
                                    Grant Site Administrator Privileges
                                </label>
                                <p class="form-description">Allows user to manage other users within their assigned site(s).</p>
                            </div>
                             <div class="form-check mb-3 grid-col-full-width">
                                 <input class="form-check-input" type="checkbox" name="is_active" value="1" id="edit_is_active" <?php echo ($edit_user_data['is_active'] == 1) ? 'checked' : ''; ?>>
                                 <label class="form-check-label" for="edit_is_active">
                                     Active User
                                 </label>
                             </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                            <a href="users.php" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                 </div>
                <?php endif; ?>


                 <!-- Reset Password Form (Shown when action=resetpw) -->
                <?php if ($current_action === 'resetpw' && $show_reset_password_form && isset($user_to_reset)): ?>
                 <div class="mt-4 <?php if ($current_action !== 'resetpw') echo 'd-none'; ?>" id="reset-password-form">
                     <h3 class="settings-section-title">Reset Password for: <?php echo htmlspecialchars($user_to_reset['username']); ?> (<?php echo htmlspecialchars($user_to_reset['full_name']); ?>)</h3>
                     <form method="POST" action="users.php">
                         <input type="hidden" name="action" value="reset_password">
                         <input type="hidden" name="user_id" value="<?php echo $edit_user_id; ?>">
                         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                         <div class="settings-form grid-template-cols-1fr"> <!-- Single column for password -->
                              <div class="form-group">
                                 <label for="reset_new_password" class="form-label">New Password:</label>
                                 <input type="password" id="reset_new_password" name="new_password" class="form-control" required minlength="8">
                                 <p class="form-description">Minimum 8 characters.</p>
                             </div>
                              <div class="form-group">
                                 <label for="reset_confirm_new_password" class="form-label">Confirm New Password:</label>
                                 <input type="password" id="reset_confirm_new_password" name="confirm_new_password" class="form-control" required>
                             </div>
                         </div>
                         <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Reset Password</button>
                            <a href="users.php" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                 </div>
                <?php endif; ?>


            </div> <!-- /.content-section -->

    <!-- JavaScript for Delete User Modal -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteUserButtons = document.querySelectorAll('.delete-user-btn');
            const deleteUserIdInput = document.getElementById('delete-user-id-input');
            const deleteUsernameSpan = document.getElementById('delete-user-username');
            const deleteUserModalElement = document.getElementById('deleteUserModal');

            if (!deleteUserModalElement) {
                console.error("Delete User Modal element (#deleteUserModal) not found.");
                return;
            }

            const deleteUserModal = $(deleteUserModalElement); // Use jQuery for Bootstrap modal interaction

            deleteUserButtons.forEach(button => {
                button.addEventListener('click', function(event) {
                    console.log("Delete button clicked!"); // DEBUG
                    const userId = this.dataset.userId;
                    const username = this.dataset.username;
                    console.log("User ID:", userId, "Username:", username); // DEBUG

                    if (deleteUserIdInput && deleteUsernameSpan) {
                        console.log("Setting user ID in hidden input:", userId); // DEBUG
                        deleteUsernameSpan.textContent = username;
                        deleteUserIdInput.value = userId;
                        console.log("Hidden input value after set:", deleteUserIdInput.value); // DEBUG

                        deleteUserModal.modal('show');
                    } else {
                        console.error("Modal elements (input or username span) not found.");
                    }
                });
            });

            deleteUserModal.on('hidden.bs.modal', function () {
                 if (deleteUserIdInput) {
                     console.log("Modal hidden, clearing hidden user ID input."); // DEBUG
                     deleteUserIdInput.value = '';
                 }
            });
        });
    </script>

    <!-- JavaScript for Role -> Site Select Toggle -->
    <script>
        function toggleSiteSelect(role, targetGroupId) {
            const group = document.getElementById(targetGroupId);
            if (group) {
                if (role === 'kiosk' || role === 'azwk_staff' || role === 'outside_staff') {
                    group.classList.remove('d-none'); // Use Bootstrap class
                } else {
                    group.classList.add('d-none'); // Use Bootstrap class
                    // Optionally clear the site selection when role doesn't need it
                    const selectElement = group.querySelector('select');
                    if (selectElement) {
                        selectElement.value = '';
                    }
                }
            }
        }
        // Initial calls on page load for potentially pre-filled forms
        document.addEventListener('DOMContentLoaded', function() {
             const addRoleSelect = document.getElementById('add_role');
             const editRoleSelect = document.getElementById('edit_role');
             if(addRoleSelect) { toggleSiteSelect(addRoleSelect.value, 'add_site_id_group'); }
             if(editRoleSelect) { toggleSiteSelect(editRoleSelect.value, 'edit_site_id_group'); }
        });
    </script>

<?php
// --- Include Footer ---
require_once 'includes/footer.php'; // Provides closing tags, includes Bootstrap JS etc.
?>
