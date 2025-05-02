<?php
// --- Client Session Handling ---
// Use the distinct session name established in client_login.php
if (session_status() == PHP_SESSION_NONE) {
    session_name("CLIENT_SESSION");
    session_start();
}

// --- Authentication Check ---
// Redirect to login if client is not logged in
// Redirect to login if client session data is not set
if (!isset($_SESSION['CLIENT_SESSION']) || !isset($_SESSION['CLIENT_SESSION']['client_id'])) {
    // Add a log entry to understand why the session might be missing
    error_log("Client session check failed in services.php. Session data: " . print_r($_SESSION, true));
    header("Location: ../client_login.php?error=session_expired");
    exit;
}

$client_id = $_SESSION['CLIENT_SESSION']['client_id'];
$username = $_SESSION['CLIENT_SESSION']['username'] ?? 'Client'; // Get username for potential future use

// No database connection needed for this placeholder page yet.
// No CSRF needed as there's no form submission yet.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - Arizona@Work</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <!-- Main Custom CSS -->
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        /* Basic styling consistent with profile.php */
        body { background-color: #f8f9fa; }
        .content-container { max-width: 700px; margin: 50px auto; padding: 30px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .page-header { text-align: center; margin-bottom: 25px; }
        .nav-links { margin-bottom: 20px; text-align: center; }
        .nav-links a { margin: 0 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="content-container">
            <div class="page-header">
                <h2>Client Portal</h2>
                <p>Arizona@Work</p>
            </div>

            <!-- Basic Navigation (Copied from profile.php, link updated) -->
            <div class="nav-links">
                <a href="services.php">Services</a> |
                <a href="profile.php">My Profile</a> |
                <a href="qr_code.php">My QR Code</a> |
                <a href="../logout.php?client=1">Logout</a> <!-- Assuming logout handles client logout -->
            </div>

            <hr>

            <!-- Page Specific Content -->
            <h1>Our Services</h1>
            <p>Information about the services offered by Arizona@Work will be available here soon.</p>

        </div>
    </div>

    <!-- Bootstrap JS and dependencies (Optional but good for consistency) -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js" integrity="sha384-oBqDVmMz9ATKxIep9tiCxS/Z9fNfEXiDAYTujMAeBAsjFuCZSmKbSSUnQlmh/jp3" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>