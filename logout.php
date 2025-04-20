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

// --- Clear Client-Side Storage & Redirect ---

// Output JavaScript to clear sessionStorage and then redirect
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging Out...</title>
    <script>
        // Clear chat-related session storage items
        sessionStorage.removeItem('aiChatHistory');
        sessionStorage.removeItem('aiChatState');
        sessionStorage.removeItem('aiChatSize');

        // Redirect to the login page
        window.location.href = 'index.php?status=logged_out';
    </script>
</head>
<body>
    <p>Logging out, please wait...</p>
</body>
</html>
<?php
// Note: The PHP exit is no longer strictly necessary here as the script ends,
// but it's good practice if more code were potentially added later.
exit;
?>