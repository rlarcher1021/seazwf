<?php
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


// --- Role Check: Only Administrators can access this page ---
if ($_SESSION['active_role'] !== 'administrator') {
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
                    'job_title' => $job_title ?: null // Added job_title
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
            // Sanitize and validate input
            $full_name = trim(filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_SPECIAL_CHARS));
            $email_edit = trim(filter_input(INPUT_POST, 'email_edit', FILTER_VALIDATE_EMAIL));
            $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_SPECIAL_CHARS);
            $site_id_input = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
            $site_id = ($site_id_input === false || $site_id_input <= 0) ? null : $site_id_input;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $job_title_edit = trim(filter_input(INPUT_POST, 'job_title_edit', FILTER_SANITIZE_SPECIAL_CHARS)); // Added job_title_edit

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
                     'job_title' => $job_title_edit ?: null // Added job_title
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
             // Prevent admin from deactivating themselves via this toggle
             if ($user_id === $_SESSION['user_id']) {
                  $_SESSION['flash_message'] = "You cannot toggle the active status of your own account.";
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

            // 1. Authorization Check
            if ($_SESSION['active_role'] !== 'administrator') {
                $_SESSION['flash_message'] = "Insufficient permissions to delete users.";
                $_SESSION['flash_type'] = 'error';
                header('Location: users.php');
                exit;
            }

            // 2. Prevent Self-Deletion
            if ($_SESSION['user_id'] === $user_id) {
                $_SESSION['flash_message'] = "You cannot delete your own administrator account.";
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
// Use data access function to fetch users
$users = getAllUsersWithSiteNames($pdo);
$user_fetch_error = ($users === []) ? "Error fetching user list." : ''; // Check if function returned empty (error logged in function)

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
                    <?php echo $flash_message; // Allow potential <br> from errors ?>
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

            <!-- User Management Content Section -->
            <div class="content-section">

                <!-- User List Table (Show only if action is 'list') -->
                <div class="table-container <?php if ($current_action !== 'list') echo 'hidden'; ?>">
                    <div class="table-header">
                        <h2 class="table-title">User Accounts</h2>
                        <div class="table-actions">
                            <a href="users.php?action=add" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Add User
                            </a>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Assigned Site</th>
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
                                                    echo '<span style="color: #888;">No Email</span>'; // Display 'No Email' dimmed
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
                                                <form method="POST" action="users.php" style="display: inline-block;">
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
                                                <button type="button" class="btn btn-outline btn-sm delete-user-btn" style="color: var(--color-trend-down);" title="Delete User"
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
                                <tr><td colspan="8" style="text-align: center;">No users found.</td></tr>
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
                        <form method="POST" action="users.php" style="display:inline;" id="deleteUserForm">
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
                 <div class="admin-form-container <?php if ($current_action !== 'add') echo 'hidden'; ?>" id="add-user-form">
                    <h3 class="settings-section-title">Add New User</h3>
                    <form method="POST" action="users.php">
                        <input type="hidden" name="action" value="add_user">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                         <?php $form_data = $_SESSION['form_data'] ?? []; unset($_SESSION['form_data']); // Get repopulation data ?>
                        <div class="settings-form">
                            <div class="form-group">
                                <label for="add_username" class="form-label">Username:</label>
                                <input type="text" id="add_username" name="username" class="form-control" required value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>">
                            </div>
                             <div class="form-group">
                                <label for="add_full_name" class="form-label">Full Name:</label>
                                <input type="text" id="add_full_name" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="add_user_email" class="form-label">Email Address:</label>
                                <input type="email" id="add_user_email" name="email" class="form-control" placeholder="user@example.com" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                                <p class="form-description">Optional. Used for notifications if needed.</p>
                            </div>
                            <div class="form-group">
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
                             <div class="form-group">
                                <label for="add_role" class="form-label">Role:</label>
                                <select id="add_role" name="role" class="form-control" required onchange="toggleSiteSelect(this.value, 'add_site_id_group')">
                                    <option value="">-- Select Role --</option>
                                    <option value="kiosk" <?php echo (($form_data['role'] ?? '') === 'kiosk') ? 'selected' : ''; ?>>Kiosk</option>
                                    <option value="azwk_staff" <?php echo (($form_data['role'] ?? '') === 'azwk_staff') ? 'selected' : ''; ?>>AZWK Staff</option>
                                    <option value="outside_staff" <?php echo (($form_data['role'] ?? '') === 'outside_staff') ? 'selected' : ''; ?>>Outside Staff</option>
                                    <option value="director" <?php echo (($form_data['role'] ?? '') === 'director') ? 'selected' : ''; ?>>Director</option>
                                    <option value="administrator" <?php echo (($form_data['role'] ?? '') === 'administrator') ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                            </div>
                             <div class="form-group" id="add_site_id_group" style="display: <?php echo (in_array($form_data['role'] ?? '', ['kiosk', 'azwk_staff', 'outside_staff'])) ? 'block' : 'none'; ?>;">
                                <label for="add_site_id" class="form-label">Assign to Site:</label>
                                <select id="add_site_id" name="site_id" class="form-control">
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
                             <div class="form-group" style="grid-column: 1 / -1;">
                                 <label class="form-label">
                                     <input type="checkbox" name="is_active" value="1" <?php echo (!isset($form_data['action']) || !empty($form_data['is_active'])) ? 'checked' : ''; // Default checked on new form or if previously checked ?>> Active User
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
                 <div class="admin-form-container <?php if ($current_action !== 'edit') echo 'hidden'; ?>" id="edit-user-form">
                    <h3 class="settings-section-title">Edit User: <?php echo htmlspecialchars($edit_user_data['username']); ?></h3>
                    <form method="POST" action="users.php">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" value="<?php echo $edit_user_data['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <div class="settings-form">
                             <div class="form-group">
                                <label for="edit_username" class="form-label">Username:</label>
                                <input type="text" id="edit_username" name="username_display" class="form-control" value="<?php echo htmlspecialchars($edit_user_data['username']); ?>" disabled>
                                 <p class="form-description">Username cannot be changed.</p>
                            </div>
                             <div class="form-group">
                                <label for="edit_full_name" class="form-label">Full Name:</label>
                                <input type="text" id="edit_full_name" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($edit_user_data['full_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="edit_user_email" class="form-label">Email Address:</label>
                                <input type="email" id="edit_user_email" name="email_edit" class="form-control" placeholder="user@example.com" value="<?php echo htmlspecialchars($edit_user_data['email'] ?? ''); // Use null coalescing ?>">
                                <p class="form-description">Optional. Used for notifications if needed.</p>
                            </div>
                            <div class="form-group">
                               <label for="edit_job_title" class="form-label">Job Title:</label>
                               <input type="text" id="edit_job_title" name="job_title_edit" class="form-control" value="<?php echo htmlspecialchars($edit_user_data['job_title'] ?? ''); ?>">
                               <p class="form-description">Optional. User's professional title.</p>
                           </div>
                            <div class="form-group">
                                <label for="edit_role" class="form-label">Role:</label>
                                <select id="edit_role" name="role" class="form-control" required onchange="toggleSiteSelect(this.value, 'edit_site_id_group')">
                                     <option value="">-- Select Role --</option>
                                     <option value="kiosk" <?php echo ($edit_user_data['role'] === 'kiosk') ? 'selected' : ''; ?>>Kiosk</option>
                                     <option value="azwk_staff" <?php echo ($edit_user_data['role'] === 'azwk_staff') ? 'selected' : ''; ?>>AZWK Staff</option>
                                     <option value="outside_staff" <?php echo ($edit_user_data['role'] === 'outside_staff') ? 'selected' : ''; ?>>Outside Staff</option>
                                     <option value="director" <?php echo ($edit_user_data['role'] === 'director') ? 'selected' : ''; ?>>Director</option>
                                     <option value="administrator" <?php echo ($edit_user_data['role'] === 'administrator') ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                            </div>
                             <div class="form-group" id="edit_site_id_group" style="display: <?php echo (in_array($edit_user_data['role'], ['kiosk', 'azwk_staff', 'outside_staff'])) ? 'block' : 'none'; ?>;">
                                <label for="edit_site_id" class="form-label">Assign to Site:</label>
                                <select id="edit_site_id" name="site_id" class="form-control">
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
                            <div class="form-group" style="grid-column: 1 / -1;">
                                 <label class="form-label">
                                     <input type="checkbox" name="is_active" value="1" <?php echo ($edit_user_data['is_active'] == 1) ? 'checked' : ''; ?>> Active User
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
                 <div class="admin-form-container <?php if ($current_action !== 'resetpw') echo 'hidden'; ?>" id="reset-password-form">
                     <h3 class="settings-section-title">Reset Password for: <?php echo htmlspecialchars($user_to_reset['username']); ?> (<?php echo htmlspecialchars($user_to_reset['full_name']); ?>)</h3>
                     <form method="POST" action="users.php">
                         <input type="hidden" name="action" value="reset_password">
                         <input type="hidden" name="user_id" value="<?php echo $edit_user_id; ?>">
                         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                         <div class="settings-form" style="grid-template-columns: 1fr;"> <!-- Single column for password -->
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
                    group.style.display = 'block';
                } else {
                    group.style.display = 'none';
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
    <!-- Simple CSS for hiding forms and spacing action buttons -->
    <style>
        .hidden { display: none; }
        .admin-form-container { margin-top: 20px; } /* Add some space above forms */
        .actions-cell form, .actions-cell button, .actions-cell a { margin-right: 5px; display: inline-block; vertical-align: middle;} /* Inline actions */
        .actions-cell form:last-child, .actions-cell button:last-child, .actions-cell a:last-child { margin-right: 0; }
    </style>

<?php
// --- Include Footer ---
require_once 'includes/footer.php'; // Provides closing tags, includes Bootstrap JS etc.
?>