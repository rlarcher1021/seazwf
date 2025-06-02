<?php
// File: client_portal/qr_code.php

// 1. Include dependencies
// auth.php is included first. It will attempt to start/resume AZWK_STAFF_SESSION.
// It also provides is_logged_in() and check_permission().
require_once '../includes/auth.php';
require '../includes/db_connect.php'; // For database access; changed from require_once to ensure $db is in global scope

$client_id_for_qr_display = null; // Actual client ID whose QR is being displayed
$client_qr_identifier = null;     // The QR identifier string
$is_staff_viewing = false;        // Flag to adapt UI for staff
$error_message = null;            // To store any error messages
// $page_title_info is used to build $_SESSION['viewing_client_name'] for staff
// and the HTML title defaults to "Your QR Code" if not staff.

// 2. Check for Staff Access FIRST
// is_logged_in() checks AZWK_STAFF_SESSION variables (e.g., $_SESSION['user_id'], $_SESSION['role'])
if (is_logged_in()) {
    $allowed_staff_roles = ['azwk_staff', 'director', 'administrator'];
    if (check_permission($allowed_staff_roles)) {
        if (isset($_GET['client_id']) && filter_var($_GET['client_id'], FILTER_VALIDATE_INT) && $_GET['client_id'] > 0) {
            $target_client_id_from_get = (int)$_GET['client_id'];

            $stmt_staff = $db->prepare("SELECT client_qr_identifier, first_name, last_name FROM clients WHERE id = ? AND deleted_at IS NULL");
            $stmt_staff->bindParam(1, $target_client_id_from_get, PDO::PARAM_INT);
            $stmt_staff->execute();
            if ($client_data_staff = $stmt_staff->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($client_data_staff['client_qr_identifier'])) {
                    $client_qr_identifier = $client_data_staff['client_qr_identifier'];
                    $client_id_for_qr_display = $target_client_id_from_get;
                    $is_staff_viewing = true;
                    $staff_view_client_name = htmlspecialchars($client_data_staff['first_name'] . ' ' . $client_data_staff['last_name']);
                    // These session variables are used by the existing HTML template
                    $_SESSION['viewing_client_name'] = $staff_view_client_name;
                    $_SESSION['viewing_client_id'] = $client_id_for_qr_display;
                } else {
                    $error_message = "Error: QR Code identifier not found for the specified client (ID: {$target_client_id_from_get}). The client may need to complete their profile.";
                }
            } else {
                $error_message = "Error: Client not found or access denied for Client ID {$target_client_id_from_get}.";
            }
            $stmt_staff->closeCursor();
        } else {
            $error_message = "Error: A valid Client ID must be provided for staff to view a QR code. Please use the link from the client management page.";
        }
    } else {
        $error_message = "Access Denied: Your staff role does not permit viewing client QR codes in this manner.";
        $is_staff_viewing = true; // Treat as a staff interaction that failed, to skip client logic.
    }
}

// 3. If NOT Staff Viewing (i.e., $is_staff_viewing is still false)
//    Then, it's a client attempting to view their own QR code.
if (!$is_staff_viewing) {
    // This is the client view path. We need to ensure CLIENT_SESSION is active.
    if (session_status() === PHP_SESSION_ACTIVE) {
        // A session is already active.
        if (session_name() !== 'CLIENT_SESSION') {
            // It's the wrong session (e.g., PHPSESSID or AZWK_STAFF_SESSION).
            // We need to close it and start CLIENT_SESSION.
            error_log("qr_code.php: Client context - Closing active session '" . session_name() . "' to switch to CLIENT_SESSION.");
            session_write_close(); // Close the interfering session

            // Now, status should be PHP_SESSION_NONE, so we can set name and start.
            session_name('CLIENT_SESSION');
            session_start();
        } else {
            // Session is active and is already CLIENT_SESSION. Good to go.
            // session_start() might have already been called if it was resumed,
            // but calling it again if already active is safe and ensures it's loaded.
            if (session_status() === PHP_SESSION_ACTIVE && !isset($_SESSION['client_id'])) {
                 // If CLIENT_SESSION is active but client_id is not set, it might be an old/empty session.
                 // This can happen if session_start() was called but didn't load data.
                 // Forcing a restart of the session might help, but can be risky.
                 // For now, let the check for $_SESSION['client_id'] below handle redirect.
            }
        }
    } else { // PHP_SESSION_NONE
        // No session active, so we can set the name and start CLIENT_SESSION.
        session_name('CLIENT_SESSION');
        session_start();
    }

    if (isset($_SESSION['client_id'])) {
        $client_id_for_qr_display = $_SESSION['client_id'];
        if (isset($_SESSION['qr_identifier']) && !empty($_SESSION['qr_identifier'])) {
            $client_qr_identifier = $_SESSION['qr_identifier'];
        } else {
            $stmt_client = $db->prepare("SELECT client_qr_identifier FROM clients WHERE id = ? AND deleted_at IS NULL");
            $stmt_client->bindParam(1, $client_id_for_qr_display, PDO::PARAM_INT);
            $stmt_client->execute();
            if ($client_data_client = $stmt_client->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($client_data_client['client_qr_identifier'])) {
                    $client_qr_identifier = $client_data_client['client_qr_identifier'];
                    $_SESSION['qr_identifier'] = $client_qr_identifier;
                } else {
                    $error_message = "Error: QR Code identifier not found in your profile. Please complete your profile or contact support.";
                }
            } else {
                $error_message = "Error: Could not retrieve your client details. Please try logging in again.";
                unset($_SESSION['client_id']); // Force re-login
                unset($_SESSION['qr_identifier']);
            }
            $stmt_client->closeCursor();
        }
    } else {
        header("Location: ../client_login.php?error=session_expired_or_not_logged_in_qr");
        exit;
    }
}

// 4. Final check for QR identifier before proceeding to QR generation
if (empty($client_qr_identifier) && empty($error_message)) {
    if ($is_staff_viewing) {
        // This case should ideally be covered by specific errors in staff logic
        $error_message = "An unexpected error occurred: Client QR identifier is missing for staff view after initial checks.";
    } else {
        // This case should ideally be covered by specific errors in client logic
        $error_message = "An unexpected error occurred: Your QR identifier is missing after initial checks. Please try again or contact support.";
    }
}
// Note: The original lines 86-101 for general error display are implicitly covered.
// If $error_message is set, the HTML part will show it.
// If $client_qr_identifier is still empty, the HTML part has a fallback.


require_once '../vendor/autoload.php'; // For QR code library

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Label\Font\NotoSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

// --- Generate QR Code ---
// Construct the URL for the QR code check-in
// Assumes kiosk/qr_checkin.php is at the root level
$checkin_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/kiosk/qr_checkin.php?cid=" . urlencode($client_qr_identifier);

$qr_code_result = null;
try {
    $qr_code_result = Builder::create()
        ->writer(new PngWriter())
        ->writerOptions([])
        ->data($checkin_url)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(new Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh()) // Use FQN
        ->size(300)
        ->margin(10)
        ->roundBlockSizeMode(new Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin()) // Use FQN
        // ->logoPath(__DIR__.'/../assets/img/logo.png') // Optional: Add logo if needed
        // ->logoResizeToWidth(50)
        // ->logoPunchoutBackground(true)
        ->labelText('Scan for Check-In')
        ->labelFont(new NotoSans(16)) // Smaller font size
        ->labelAlignment(new Endroid\QrCode\Label\Alignment\LabelAlignmentCenter()) // Use FQN (Assuming Center alignment class)
        ->validateResult(false) // Set to true if you want exceptions on invalid results
        ->build();

    // Generate data URI for embedding directly in HTML
    $qr_code_data_uri = $qr_code_result->getDataUri();

} catch (Exception $e) {
    error_log("QR Code Generation Error for client ID {$client_id_for_qr_display}: " . $e->getMessage());
    $qr_code_data_uri = null; // Handle error case
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_staff_viewing && isset($client_data_staff['first_name']) && isset($client_data_staff['last_name']) ? htmlspecialchars(trim($client_data_staff['first_name'] . ' ' . $client_data_staff['last_name'])) . "'s QR Code" : "Your QR Code"; ?> - Arizona@Work</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Optional: Add custom CSS if needed -->
    <!-- <link rel="stylesheet" href="/assets/css/client_portal.css"> -->
    <style>
        body { padding-top: 70px; /* Adjust based on navbar height */ padding-bottom: 70px; /* Adjust for footer */ }
        .qr-container { text-align: center; margin-top: 30px; }
        .qr-code-img { max-width: 100%; width: 280px; /* Fixed width for consistency */ height: auto; border: 1px solid #dee2e6; padding: 10px; background-color: #fff; }
        .print-button { margin-top: 20px; }
        @media print {
            body { padding-top: 0; padding-bottom: 0; }
            .navbar, .footer, .print-button, .breadcrumb { display: none !important; }
            .qr-container { margin-top: 0; }
            .card { border: none; box-shadow: none; }
        }
    </style>
</head>
<body>

    <!-- Navbar: Show client navbar if client is viewing, or a minimal staff header if staff is viewing -->
    <?php if (!$is_staff_viewing): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <a class="navbar-brand" href="profile.php">AZ@Work Client Portal</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="services.php">Services</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="profile.php">Profile</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="qr_code.php">QR Code <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../logout.php?client=1">Logout</a>
                </li>
            </ul>
        </div>
    </nav>
    <?php else: ?>
    <!-- Minimal header for staff view, perhaps just a way to go back or print -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">Staff View: Client QR Code</a>
            <button class="btn btn-sm btn-outline-secondary ml-auto d-print-none" onclick="window.close();">Close</button>
        </div>
    </nav>
    <?php endif; ?>


    <!-- Main Content -->
    <div class="container">
        <div class="qr-container">
            <?php
            if ($is_staff_viewing && isset($client_data_staff['first_name']) && isset($client_data_staff['last_name'])) {
                $page_main_heading = htmlspecialchars(trim($client_data_staff['first_name'] . ' ' . $client_data_staff['last_name'])) . "'s QR Code";
            } elseif (!$is_staff_viewing && isset($_SESSION['client_first_name']) && isset($_SESSION['client_last_name'])) { // Assuming client self-view might have these in session
                $page_main_heading = htmlspecialchars(trim($_SESSION['client_first_name'] . ' ' . $_SESSION['client_last_name'])) . "'s QR Code";
            } elseif (!$is_staff_viewing) { // Client self-view fallback
                 $page_main_heading = "Your Check-In QR Code";
            } else { // Fallback for staff if name isn't available
                $page_main_heading = "Client QR Code";
                if (isset($client_id_for_qr_display)) {
                     $page_main_heading .= " (ID: " . htmlspecialchars($client_id_for_qr_display) . ")";
                }
            }
            ?>
            <h1 class="mb-1"><?php echo $page_main_heading; ?></h1>
            <?php if ($is_staff_viewing && isset($client_id_for_qr_display)): ?>
                <p class="text-muted mb-4">Client ID: <?php echo htmlspecialchars($client_id_for_qr_display); // Keep showing Client ID for staff ?></p>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php elseif ($qr_code_data_uri && !empty($client_qr_identifier)): ?>
                <div class="card">
                    <div class="card-body">
                        <p class="card-text">Scan this QR code at any Arizona@Work kiosk to quickly check in.</p>
                        <img src="<?php echo $qr_code_data_uri; ?>" alt="Client Check-In QR Code" class="qr-code-img img-fluid">
                        <?php if (!$is_staff_viewing): ?>
                        <p class="mt-3"><strong>Keep this code secure.</strong></p>
                        <?php endif; ?>
                    </div>
                </div>
                <button class="btn btn-primary print-button d-print-none" onclick="window.print();">Print QR Code</button>
            <?php else: ?>
                <div class="alert alert-warning" role="alert">
                    Could not display QR code at this time. The QR identifier might be missing or an unexpected error occurred. Please try again later or contact support.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light fixed-bottom d-print-none">
        <div class="container text-center">
            <span class="text-muted">&copy; <?php echo date("Y"); ?> Arizona@Work. All rights reserved.</span>
        </div>
    </footer>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>