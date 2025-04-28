<?php
/*
 * File: auth.php
 * Path: /includes/auth.php
 * ... (header comment) ...
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$allowedUnauthenticated = ['index.php'];

if (!in_array($currentPage, $allowedUnauthenticated)) {
    // Check if user_id and active_role are set
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role'])) {
        session_unset(); session_destroy();
        header("Location: index.php?reason=not_logged_in"); exit;
    }

    $role = $_SESSION['active_role'];
    $accessDenied = false;

    // Define roles allowed for each page
    $accessRules = [
        'checkin.php' => ['kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'], // ALL roles can access checkin
        'select_checkin_site.php' => ['director', 'administrator'], // ONLY Director/Admin
        'dashboard.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
        'reports.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'],
        'export_report.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // Match reports access
        'notifications.php' => ['azwk_staff'], // outside_staff intentionally excluded
        'configurations.php' => ['administrator'],
        'users.php' => ['administrator'],
        'alerts.php' => ['administrator'],
        'logout.php' => ['kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'], // ALL logged in roles
        'forum_index.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // Forum category list
        'view_category.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // View topics in a category
        'view_topic.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // View posts in a topic
        'create_topic.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // Create new topic form/handler
        'ajax_report_handler.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // AJAX endpoint for reports/dashboard charts
        'account.php' => ['azwk_staff', 'outside_staff', 'director', 'administrator'], // User account self-management (exclude kiosk)
        // Add other specific pages here if needed
        // Budget Feature Pages (Consolidated under budget_settings.php now)
        // 'grant_management.php' => ['director', 'administrator'], // Removed
        // 'budget_setup.php' => ['director', 'administrator'], // Removed
        'budget_settings.php' => ['director', 'administrator'], // NEW Consolidated Settings Page
        'budgets.php' => ['director', 'azwk_staff', 'finance', 'administrator'], // Budget Allocation Management
        'ajax_get_budgets.php' => ['director', 'azwk_staff', 'finance', 'administrator'], // AJAX for budgets page
        'ajax_allocation_handler.php' => ['director', 'azwk_staff', 'finance', 'administrator'], // AJAX for budgets page
        'vendor_handler.php' => ['director', 'administrator'], // AJAX for vendor CRUD (Key changed to basename)
        // Add AJAX handler for vendor CRUD if needed later
    ];

    // Check if the current page has a rule defined
    if (array_key_exists($currentPage, $accessRules)) {
        // Check if the user's role is in the allowed list for this page
        if (!in_array($role, $accessRules[$currentPage])) {
            $accessDenied = true;
        }
        // If role IS allowed by the rule, $accessDenied remains false
    } else {
        // Page not explicitly listed in rules - deny access for safety
        error_log("Access Attempt to Unlisted Page: User '{$_SESSION['username']}' (Role: {$role}) to {$currentPage}");
        $accessDenied = true;
    }


    // Kiosk specific restriction (Keep this!)
     if (isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'kiosk' && !in_array($currentPage, ['checkin.php', 'logout.php'])) {
          // Even if allowed by rules above, force Kiosk ONLY to checkin/logout
          header("Location: checkin.php");
          exit;
     }


    if ($accessDenied) {
        error_log("Access Denied: User '{$_SESSION['username']}' (Active Role: {$role}) attempted to access {$currentPage}");
        // Redirect logic (keep existing)
        $redirectTarget = 'index.php?reason=access_denied';
        if (in_array($role, ['azwk_staff', 'outside_staff', 'director', 'administrator', 'finance'])) { // Added finance here
             $redirectTarget = 'dashboard.php?reason=access_denied';
        }
        // Avoid setting session message if maybe redirecting to login? Check target.
        if (strpos($redirectTarget, 'dashboard.php') !== false) {
            // Use the flash message system from utils.php if available
            if (function_exists('set_flash_message')) {
                 set_flash_message('auth_error', "Access Denied: Your current role ({$role}) does not permit access to {$currentPage}.", 'error');
            } else {
                // Fallback if utils isn't included before auth (should be)
                $_SESSION['flash_message'] = "Access Denied: Your current role ({$role}) does not permit access to {$currentPage}.";
                $_SESSION['flash_type'] = 'error';
            }
        }
        header("Location: " . $redirectTarget);
        exit;
    }

} // End check for authenticated pages


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