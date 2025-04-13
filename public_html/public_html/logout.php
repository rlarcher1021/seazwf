<?php
/*
 * File: logout.php
 * Path: /logout.php
 * Created: 2024-08-01 11:00:00 MST
 * Author: Robert Archer
 *
 * Description: Destroys the user's session (logs them out) and redirects
 *              to the login page.
 */

// Ensure session is started so we can access session functions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Destroy the Session ---

// 1. Unset all session variables
$_SESSION = array();

// 2. If session cookies are used, delete the session cookie
// This is generally recommended for a thorough logout.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, // Set expiry date in the past
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finally, destroy the session data on the server
session_destroy();

// --- Redirect to Login Page ---
// Redirect the user back to the main login page.
// Add a parameter to indicate successful logout (optional).
header('Location: index.php?status=logged_out');
exit; // Important: Stop script execution after redirect

?>