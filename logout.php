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

// --- Determine Logout Type (Client vs Staff) ---
$is_client_logout = isset($_GET['client']) && $_GET['client'] == '1';
$redirect_url = '';

// --- Session Handling ---
// Use specific session name for clients
if ($is_client_logout) {
    session_name("CLIENT_SESSION");
    $redirect_url = 'client_login.php?status=logged_out';
} else {
    // Use default session name for staff (or if not specified)
    // session_name("DEFAULT_SESSION_NAME"); // Uncomment and set if staff uses a different name
    $redirect_url = 'index.php?status=logged_out'; // Staff redirect
}

// Ensure session is started *after* potentially setting the name
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Destroy the Session ---

// 1. Unset all session variables for the *current* session
$_SESSION = array();

// 2. If session cookies are used, delete the session cookie for the *current* session name
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    // Use the correct session name (either default or CLIENT_SESSION)
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finally, destroy the session data on the server
session_destroy();

// --- Redirect using PHP header ---
// Clear any previously sent headers
// Note: This might not be strictly necessary if output buffering is on, but good practice.
if (headers_sent()) {
    // If headers are already sent, fallback to JS (though ideally this shouldn't happen)
    echo "<script>window.location.href='{$redirect_url}';</script>";
} else {
    header("Location: " . $redirect_url);
}
exit; // Ensure script stops execution after redirect
?>