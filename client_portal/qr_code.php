<?php
// File: client_portal/qr_code.php

// Initialize variables
$client_id_for_qr_display = null;
$client_qr_identifier = null;
$is_staff_viewing = false; // Assume client view by default unless client_id is in GET
$error_message = null;
$client_data_staff = null; // For staff view, to hold client's name for title

// Determine context (staff or client) based on GET parameter
if (isset($_GET['client_id']) && filter_var($_GET['client_id'], FILTER_VALIDATE_INT) && $_GET['client_id'] > 0) {
    // ----- STAFF PATH -----
    $is_staff_viewing = true;
    // For staff, auth.php must be included first to establish staff session and check permissions.
    require_once '../includes/auth.php'; // This will handle session_start for staff if not already active.
    require '../includes/db_connect.php';

    if (is_logged_in()) { // is_logged_in() from auth.php checks staff session
        $allowed_staff_roles = ['azwk_staff', 'director', 'administrator'];
        if (check_permission($allowed_staff_roles)) {
            $target_client_id_from_get = (int)$_GET['client_id'];
            $stmt_staff_select = $db->prepare("SELECT client_qr_identifier, first_name, last_name FROM clients WHERE id = ? AND deleted_at IS NULL");
            $stmt_staff_select->bindParam(1, $target_client_id_from_get, PDO::PARAM_INT);
            $stmt_staff_select->execute();
            $client_data_staff = $stmt_staff_select->fetch(PDO::FETCH_ASSOC); // Used for page title

            if ($client_data_staff && !empty($client_data_staff['client_qr_identifier'])) {
                $client_qr_identifier = $client_data_staff['client_qr_identifier'];
                $client_id_for_qr_display = $target_client_id_from_get;
            } elseif ($client_data_staff) { // Client exists but no QR ID
                $error_message = "Error: QR Code identifier not found for the specified client (ID: {$target_client_id_from_get}). The client may need to complete their profile.";
            } else { // Client not found
                $error_message = "Error: Client not found or access denied for Client ID {$target_client_id_from_get}.";
            }
            $stmt_staff_select->closeCursor();
        } else { // Staff logged in, but no permission
            $error_message = "Access Denied: Your staff role does not permit viewing client QR codes.";
        }
    } else { // Staff not logged in (is_logged_in() returned false)
        // Redirect to staff login page, as client_id was in URL implying staff intent.
        header("Location: ../index.php?reason=login_required_staff_qr_view_attempt");
        exit;
    }
} else {
    // ----- CLIENT PATH -----
    // No client_id in GET, so this is a client viewing their own QR code.
    $is_staff_viewing = false; // Explicitly set for clarity

    // For clients, manage CLIENT_SESSION explicitly BEFORE including auth.php.
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== 'CLIENT_SESSION') {
            // An unexpected session is active (e.g. PHPSESSID from a previous hit, or staff session).
            // Close it cleanly.
            error_log("qr_code.php (Client Path): Closing active session '" . session_name() . "' to switch to CLIENT_SESSION.");
            session_write_close(); // Important: commit and close before changing name
            session_name('CLIENT_SESSION'); // Set name for the new/resumed client session
            session_start(); // Start/resume CLIENT_SESSION
        } elseif (session_id() === '') {
            // This means session_name() is 'CLIENT_SESSION' but session_start() hasn't been called yet in this request.
            session_start();
        }
        // If session is active and name is already CLIENT_SESSION and session_id() is not empty, it's already running.
    } else { // PHP_SESSION_NONE
        session_name('CLIENT_SESSION');
        session_start();
    }
    
    // Now that CLIENT_SESSION is (theoretically) established, include auth.php.
    // auth.php should detect it's a client-facing page (due to path) and NOT interfere with CLIENT_SESSION.
    require_once '../includes/auth.php'; 
    require '../includes/db_connect.php'; 

    if (isset($_SESSION['client_id'])) {
        $client_id_for_qr_display = $_SESSION['client_id'];
        // Fetch QR identifier if not in session or if it's empty
        if (empty($_SESSION['qr_identifier'])) {
            $stmt_client_qr_fetch = $db->prepare("SELECT client_qr_identifier FROM clients WHERE id = ? AND deleted_at IS NULL");
            $stmt_client_qr_fetch->bindParam(1, $client_id_for_qr_display, PDO::PARAM_INT);
            $stmt_client_qr_fetch->execute();
            if ($client_qr_data_fetch = $stmt_client_qr_fetch->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($client_qr_data_fetch['client_qr_identifier'])) {
                    $client_qr_identifier = $client_qr_data_fetch['client_qr_identifier'];
                    $_SESSION['qr_identifier'] = $client_qr_identifier; 
                } else {
                    $error_message = "Error: QR Code identifier not found in your profile. Please complete your profile or contact support.";
                }
            } else {
                $error_message = "Error: Could not retrieve your client details. Your session may be invalid. Please try logging in again.";
                unset($_SESSION['client_id']); 
                unset($_SESSION['qr_identifier']);
                header("Location: ../client_login.php?error=invalid_client_session_data_qr_v4");
                exit;
            }
            $stmt_client_qr_fetch->closeCursor();
        } else {
             $client_qr_identifier = $_SESSION['qr_identifier'];
        }
    } else {
        // Client is not logged in (no client_id in CLIENT_SESSION)
        header("Location: ../client_login.php?error=client_not_logged_in_qr_v4");
        exit;
    }
}

// 4. Final check for QR identifier before proceeding to QR generation (common to both paths if no error yet)
if (empty($client_qr_identifier) && empty($error_message)) {
    if ($is_staff_viewing) {
        $error_message = "An unexpected error occurred: Client QR identifier is missing for staff view after initial checks.";
    } else {
        $error_message = "An unexpected error occurred: Your QR identifier is missing after initial checks. Please try again or contact support.";
    }
}

require_once '../vendor/autoload.php'; // For QR code library

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Label\Font\NotoSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

$qr_code_data_uri = null; // Initialize
if (!empty($client_qr_identifier) && empty($error_message)) {
    $checkin_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/kiosk/qr_checkin.php?cid=" . urlencode($client_qr_identifier);
    try {
        $qr_code_result = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
            ->data($checkin_url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh())
            ->size(300)
            ->margin(10)
            ->roundBlockSizeMode(new Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin())
            ->labelText('Scan for Check-In')
            ->labelFont(new NotoSans(16))
            ->labelAlignment(new Endroid\QrCode\Label\Alignment\LabelAlignmentCenter())
            ->validateResult(false)
            ->build();
        $qr_code_data_uri = $qr_code_result->getDataUri();
    } catch (Exception $e) {
        error_log("QR Code Generation Error for client ID {$client_id_for_qr_display}: " . $e->getMessage());
        $error_message = "Error generating QR code. Please try again. " . $e->getMessage(); // Show error to user
    }
} elseif (empty($error_message)) { // If QR identifier is empty but no other error was set
    $error_message = "QR Code cannot be generated because the client identifier is missing.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php 
        if ($is_staff_viewing && $client_data_staff) {
            echo htmlspecialchars(trim($client_data_staff['first_name'] . ' ' . $client_data_staff['last_name'])) . "'s QR Code";
        } elseif (!$is_staff_viewing && isset($_SESSION['client_first_name']) && isset($_SESSION['client_last_name'])) {
            echo htmlspecialchars(trim($_SESSION['client_first_name'] . ' ' . $_SESSION['client_last_name'])) . "'s QR Code";
        } elseif (!$is_staff_viewing) {
            echo "Your QR Code";
        } else {
            echo "Client QR Code";
        }
    ?> - Arizona@Work</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { padding-top: 70px; padding-bottom: 70px; }
        .qr-container { text-align: center; margin-top: 30px; }
        .qr-code-img { max-width: 100%; width: 280px; height: auto; border: 1px solid #dee2e6; padding: 10px; background-color: #fff; }
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

    <?php if (!$is_staff_viewing): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <a class="navbar-brand" href="profile.php">AZ@Work Client Portal</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item"><a class="nav-link" href="services.php">Services</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php">Profile</a></li>
                <li class="nav-item active"><a class="nav-link" href="qr_code.php">QR Code <span class="sr-only">(current)</span></a></li>
                <li class="nav-item"><a class="nav-link" href="../logout.php?client=1">Logout</a></li>
            </ul>
        </div>
    </nav>
    <?php else: // Staff viewing ?>
    <nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">Staff View: Client QR Code</a>
            <button class="btn btn-sm btn-outline-secondary ml-auto d-print-none" onclick="window.close();">Close</button>
        </div>
    </nav>
    <?php endif; ?>

    <div class="container">
        <div class="qr-container">
            <?php
            $page_main_heading = "Client QR Code"; // Default
            if ($is_staff_viewing && $client_data_staff) {
                $page_main_heading = htmlspecialchars(trim($client_data_staff['first_name'] . ' ' . $client_data_staff['last_name'])) . "'s QR Code";
            } elseif (!$is_staff_viewing && isset($_SESSION['client_first_name']) && isset($_SESSION['client_last_name'])) {
                $page_main_heading = htmlspecialchars(trim($_SESSION['client_first_name'] . ' ' . $_SESSION['client_last_name'])) . "'s QR Code";
            } elseif (!$is_staff_viewing) {
                 $page_main_heading = "Your Check-In QR Code";
            }
            // Fallback for staff if name isn't available but client_id is
            if ($is_staff_viewing && !$client_data_staff && isset($client_id_for_qr_display)) {
                 $page_main_heading = "Client QR Code (ID: " . htmlspecialchars($client_id_for_qr_display) . ")";
            }
            ?>
            <h1 class="mb-1"><?php echo $page_main_heading; ?></h1>
            <?php if ($is_staff_viewing && isset($client_id_for_qr_display)): ?>
                <p class="text-muted mb-4">Client ID: <?php echo htmlspecialchars($client_id_for_qr_display); ?></p>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php elseif ($qr_code_data_uri): // Check $qr_code_data_uri directly as it's null if generation failed ?>
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
            <?php else: // Fallback if $qr_code_data_uri is null and no specific $error_message was set (should be rare now) ?>
                <div class="alert alert-warning" role="alert">
                    Could not display QR code. Ensure your profile is complete or contact support if the issue persists.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer mt-auto py-3 bg-light fixed-bottom d-print-none">
        <div class="container text-center">
            <span class="text-muted">&copy; <?php echo date("Y"); ?> Arizona@Work. All rights reserved.</span>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>