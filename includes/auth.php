<?php
/*
 * File: auth.php
 * Path: /includes/auth.php
 * ... (header comment) ...
 */

// --- Determine if it's a client-related page FIRST ---
// Use SCRIPT_NAME as it reflects the initially requested script, even if included.
$script_path = $_SERVER['SCRIPT_NAME'];
$script_basename = basename($script_path);
$is_client_login_or_register = in_array($script_basename, ['client_login.php', 'client_register.php']);
$is_client_portal_page = strpos($script_path, '/client_portal/') !== false;
$is_client_facing_page = $is_client_login_or_register || $is_client_portal_page;

// --- Staff Session Handling and Authentication ---
// ONLY run staff session start and auth checks if it's NOT a client-facing page
if (!$is_client_facing_page) {

    // --- Conditionally Start Default Session ---
    // Only start the default session if a session isn't already active
    if (session_status() === PHP_SESSION_NONE) {
        // No session_name() here, assume default staff session
        session_start();
    }

    // --- Staff Authentication & Authorization Logic ---
    $staff_allowed_unauthenticated = ['index.php']; // Staff pages accessible without login

    // Check if staff session variables are set
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role'])) {
        // If session vars aren't set, check if the current page is one that requires login
        if (!in_array($script_basename, $staff_allowed_unauthenticated)) {
             // It's not an allowed unauthenticated page, so redirect to login
             session_unset(); // Ensure clean state before redirect
             session_destroy();
             header("Location: index.php?reason=not_logged_in");
             exit;
        }
        // If it IS an allowed unauthenticated page (like index.php), just continue without further checks.
    } else {
        // --- Staff User is Logged In - Perform Role-Based Access Control ---
        $role = $_SESSION['active_role'];
        $accessDenied = false;
        // [AUTH DEBUG] Start of staff auth block


        // Define roles allowed for each STAFF page
        $accessRules = [
            'checkin.php' => ['kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'],
            'select_checkin_site.php' => ['director', 'administrator'],
            'dashboard.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'reports.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'export_report.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'notifications.php' => ['azwk_staff'],
            'configurations.php' => ['administrator'],
            'users.php' => ['administrator'],
            'alerts.php' => ['administrator'],
            'logout.php' => ['kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'],
            'forum_index.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'view_category.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'view_topic.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'create_topic.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'ajax_report_handler.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'account.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
            'budget_settings.php' => ['director', 'administrator'],
            'budgets.php' => ['director', 'azwk_staff', 'finance', 'administrator'],
            'ajax_get_budgets.php' => ['director', 'azwk_staff', 'finance', 'administrator'],
            'ajax_allocation_handler.php' => ['director', 'azwk_staff', 'finance', 'administrator'],
            'vendor_handler.php' => ['director', 'administrator'], // Assuming this is AJAX for budget_settings
            'qr_checkin.php' => ['kiosk'], // Allow kiosk role for QR check-in AJAX handler
            'client_editor.php' => ['director', 'administrator'], // Added access for Director and Administrator
            'api_keys_handler.php' => ['administrator'], // Corrected: Use basename for AJAX handler access rule
            // NOTE: index.php is intentionally NOT listed. Logged-in users might be redirected from index.php elsewhere.
        ];

        // Check access rules for the current page
        if (array_key_exists($script_basename, $accessRules)) {
            if (!in_array($role, $accessRules[$script_basename])) {
                $accessDenied = true; // Role not allowed for this specific page
                // [AUTH DEBUG] Inside $accessRules check (fail)
            } else { // Role IS allowed
                 // [AUTH DEBUG] Inside $accessRules check (pass)
            }
        } else {
            // Page is not listed in access rules. Deny access unless it's index.php (which handles its own logic).
            if ($script_basename !== 'index.php') {
                 // Attempt to safely get username for logging
                 $logUsername = isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown';
                 error_log("Access Attempt to Unlisted Staff Page: User '{$logUsername}' (Role: {$role}) to {$script_basename}");
                 $accessDenied = true;
                 // [AUTH DEBUG] Inside else (page not listed)
            }
             // If it's index.php, allow script execution to continue (index.php might redirect based on role).
        }

        // Kiosk specific restriction (Apply AFTER general rules)
        if (isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'kiosk' && !in_array($script_basename, ['checkin.php', 'logout.php', 'qr_checkin.php'])) {
             $accessDenied = true; // Kiosk trying to access disallowed page
        }

        // [AUTH DEBUG] Before Site Admin override check
        // Site Admin Override: Allow access to specific pages regardless of primary role rules
        $siteAdminAllowedPages = ['users.php', 'configurations.php', 'client_editor.php'];
        // [AUTH DEBUG] Inside Site Admin override check
        if (isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1 && in_array($script_basename, $siteAdminAllowedPages)) {
            $accessDenied = false; // Explicitly grant access
        } else {
        }
        // [AUTH DEBUG] After Site Admin override check
        // Handle Access Denied
        if ($accessDenied) {
            // [AUTH DEBUG] Inside final if ($accessDenied)
            $username = $_SESSION['username'] ?? 'Unknown'; // Handle case where username might not be set
            // Determine redirect target based on role and situation - MUST HAPPEN BEFORE LOGGING IT
            $redirectTarget = 'index.php?reason=access_denied'; // Default redirect
            if (isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'kiosk') {
                 $redirectTarget = 'checkin.php'; // Force kiosk back to checkin page
            } elseif (in_array($role, ['azwk_staff', 'outside_staff', 'director', 'administrator', 'finance'])) {
                 // Redirect most staff roles to dashboard on denial
                 $redirectTarget = 'dashboard.php?reason=access_denied';
            }
            // Note: If role is somehow invalid or not in the list above, they go to index.php

            error_log("Access Denied: User '{$username}' (Active Role: {$role}) attempted to access {$script_basename}");


            // Set flash message ONLY if redirecting to dashboard (avoid showing on login page)
            if (strpos($redirectTarget, 'dashboard.php') !== false) {
                $displayRole = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
                $displayPage = htmlspecialchars($script_basename, ENT_QUOTES, 'UTF-8');
                if (function_exists('set_flash_message')) {
                     set_flash_message('auth_error', "Access Denied: Your current role ({$displayRole}) does not permit access to {$displayPage}.", 'error');
                } else {
                    // Fallback if utils isn't included before auth (less likely now)
                    $_SESSION['flash_message'] = "Access Denied: Your current role ({$displayRole}) does not permit access to {$displayPage}.";
                    $_SESSION['flash_type'] = 'error';
                }
            }

            header("Location: " . $redirectTarget);
            exit;
        }
        // If access is not denied, script execution continues for the staff page.
    } // End else (user is logged in)

} // End if (!$is_client_facing_page)


/**
 * Checks if the current user's active role has permission based on an allowed list.
 * Ensures session is started and active_role is set.
 *
 * @param array $allowedRoles An array of role strings that are allowed. Case-insensitive comparison.
 * @return bool True if the user's role is in the allowed list, false otherwise.
 */
function check_permission(array $allowedRoles): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['active_role'])) {
        // If no role is set in session, they don't have permission.
        return false;
    }

    $userRole = strtolower($_SESSION['active_role']); // Ensure case-insensitive comparison

    // Convert allowed roles to lowercase for comparison
    $allowedRolesLower = array_map('strtolower', $allowedRoles);

    return in_array($userRole, $allowedRolesLower);
}

?>