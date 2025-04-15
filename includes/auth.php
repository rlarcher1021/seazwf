<?php
/*
 * File: auth.php
 * Path: /includes/auth.php
 * ... (header comment) ...
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- CSRF Token Generation ---
// Generate a CSRF token if one doesn't exist for the session
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Handle error during random_bytes generation (rare)
        error_log("CSRF token generation failed: " . $e->getMessage());
        // Depending on security policy, might want to die() or redirect here
        // For now, log and continue, but the token will be empty, failing validation later.
    }
}
// --- End CSRF Token Generation ---


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
        'checkin.php' => ['kiosk', 'site_supervisor', 'director', 'administrator'], // ALL roles can access checkin
        'select_checkin_site.php' => ['director', 'administrator'], // ONLY Director/Admin
        'dashboard.php' => ['site_supervisor', 'director', 'administrator'],
        'reports.php' => ['site_supervisor', 'director', 'administrator'],
        'export_report.php' => ['site_supervisor', 'director', 'administrator'], // Match reports access
        'notifications.php' => ['site_supervisor'],
        'configurations.php' => ['administrator'],
        'users.php' => ['administrator'],
        'alerts.php' => ['administrator'],
        'logout.php' => ['kiosk', 'site_supervisor', 'director', 'administrator'] // ALL logged in roles
        // Add other specific pages here if needed
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
        if (in_array($role, ['site_supervisor', 'director', 'administrator'])) {
             $redirectTarget = 'dashboard.php?reason=access_denied';
        }
        // Avoid setting session message if maybe redirecting to login? Check target.
        if (strpos($redirectTarget, 'dashboard.php') !== false) {
            $_SESSION['flash_message'] = "Access Denied: Your current role ({$role}) does not permit access to {$currentPage}.";
            $_SESSION['flash_type'] = 'error';
        }
        header("Location: " . $redirectTarget);
        exit;
    }

} // End check for authenticated pages
?>