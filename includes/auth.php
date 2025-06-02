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
            // It's not an allowed unauthenticated staff page

            // Check if a client session IS active.
            // This assumes the client session uses $_SESSION['client_id'].
            // The main session_start() earlier (lines 21-25) would have loaded an existing client session if the cookie was present.
            if (isset($_SESSION['client_id'])) {
                // Active client trying to access a non-client, staff-only page.
                // Redirect them to their portal. We do NOT destroy their session.
                // Path is relative to the web root, consistent with other redirects like "index.php".
                header("Location: client_portal/profile.php?reason=staff_page_access_denied");
                exit;
            }

             // No staff session AND no client session, so redirect to staff login (or client_login.php via index.php)
             session_unset(); // Ensure clean state before redirect
             session_destroy(); // This will destroy any session, including a potential partial/invalid one.
             header("Location: index.php?reason=not_logged_in");
             exit;
        }
        // If it IS an allowed unauthenticated page (like index.php), just continue without further checks.
    } else {
        // --- Staff User is Logged In - Perform Role-Based Access Control ---
        $role = $_SESSION['active_role'];
        $accessDenied = false;


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
            'save_checkin_answers_handler.php' => ['azwk_staff', 'director', 'administrator'], // Allow staff to save check-in answers
            'get_recent_checkins_handler.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // For dashboard AJAX refresh
            'get_client_details_handler.php' => ['azwk_staff', 'director', 'administrator'], // For client editor modal
            'update_client_details_handler.php' => ['azwk_staff', 'director', 'administrator'],
            // NOTE: index.php is intentionally NOT listed. Logged-in users might be redirected from index.php elsewhere.
        ];

        // Check access rules for the current page

        // Original check:
        if (array_key_exists($script_basename, $accessRules)) {
            if (!in_array($role, $accessRules[$script_basename])) {
                $accessDenied = true; // Role not allowed for this specific page
            } else { // Role IS allowed
            }
        } else { // Page not found by array_key_exists for $script_basename
            // The specific 'if' block for 'get_client_details_handler.php' has been removed.
            // The following condition, previously 'elseif', is now 'if'.
            if ($script_basename !== 'index.php') {
                 // For any OTHER script not in $accessRules (and not index.php)
                 $logUsername = isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown';
                 error_log("Access Attempt to Unlisted Staff Page: User '{$logUsername}' (Role: {$role}) to {$script_basename}");
                 $accessDenied = true;
            }
             // If it's index.php, script execution continues (assuming $accessDenied is false from this block's perspective).
        }

        // Kiosk specific restriction (Apply AFTER general rules)
        if (isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'kiosk' && !in_array($script_basename, ['checkin.php', 'logout.php', 'qr_checkin.php'])) {
             $accessDenied = true; // Kiosk trying to access disallowed page
        }

        // Site Admin Override: Allow access to specific pages regardless of primary role rules
        $siteAdminAllowedPages = ['users.php', 'configurations.php', 'client_editor.php'];
        if (isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1 && in_array($script_basename, $siteAdminAllowedPages)) {
            $accessDenied = false; // Explicitly grant access
        } else {
        }
        // Handle Access Denied
        if ($accessDenied) {
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

/**
 * Checks if a staff user is currently logged in.
 * Relies on session variables 'user_id' and 'active_role' being set.
 *
 * @return bool True if the user is considered logged in, false otherwise.
 */
function is_logged_in(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        // This should ideally not happen if auth.php is included correctly,
        // as it starts a session if one isn't active (for non-client pages).
        // However, as a safeguard:
        session_start();
    }
    return isset($_SESSION['user_id']) && isset($_SESSION['active_role']);
}

?>