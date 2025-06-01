<?php
// --- Include Staff Auth and DB Connect FIRST ---
// auth.php will handle staff session start if not a client page,
// but we need its functions (is_logged_in, check_permission)
require_once '../includes/auth.php'; // Provides is_logged_in() and check_permission()
require_once '../includes/db_connect.php'; // For database access

// --- Client Session Handling (Attempt) ---
// Use the distinct session name established in client_login.php
if (session_status() == PHP_SESSION_NONE) {
    // If auth.php (for staff) didn't start a session, and we are here,
    // it's likely a direct access or client context. Start client session.
    session_name("CLIENT_SESSION");
    session_start();
} else {
    // A session is already started. If it's a staff session, CLIENT_SESSION might not be set.
    // If it's already a CLIENT_SESSION, session_name() would have been called before.
    // This logic assumes auth.php handles its own session_name if it starts one.
    // If current session is default staff and we need CLIENT_SESSION, this needs care.
    // For now, assume if a session exists, it's either the one we want or staff.
}

$client_id = null;
$client_qr_identifier = null;
$is_staff_viewing = false;
$error_message = null;

// --- Authentication and Data Retrieval Logic ---

// 1. Check for Logged-in Client
if (isset($_SESSION['CLIENT_SESSION']) && isset($_SESSION['CLIENT_SESSION']['client_id'])) {
    $client_id = $_SESSION['CLIENT_SESSION']['client_id'];
    $client_qr_identifier = $_SESSION['CLIENT_SESSION']['qr_identifier'] ?? null;

    if (empty($client_qr_identifier)) {
        $error_message = "Error: QR Code identifier not found in your client session. Please contact support.";
    }
}
// 2. If Not Client, Check for Logged-in Staff
// The is_logged_in() function from auth.php checks for staff session variables like $_SESSION['user_id'] and $_SESSION['active_role']
elseif (is_logged_in()) {
    $allowed_staff_roles = ['azwk_staff', 'director', 'administrator'];
    if (check_permission($allowed_staff_roles)) {
        if (isset($_GET['client_id']) && filter_var($_GET['client_id'], FILTER_VALIDATE_INT)) {
            $target_client_id = (int)$_GET['client_id'];

            // Fetch client_qr_identifier from the database for the target_client_id
            $stmt = $db->prepare("SELECT client_qr_identifier, first_name, last_name FROM clients WHERE id = ? AND deleted_at IS NULL");
            $stmt->bind_param("i", $target_client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($client_data = $result->fetch_assoc()) {
                $client_qr_identifier = $client_data['client_qr_identifier'];
                $client_id = $target_client_id; // Set client_id for context, though it's the target
                $is_staff_viewing = true;
                // Store client name for display if staff is viewing
                $_SESSION['viewing_client_name'] = htmlspecialchars($client_data['first_name'] . ' ' . $client_data['last_name']);

                if (empty($client_qr_identifier)) {
                    $error_message = "Error: QR Code identifier not found for the specified client (ID: {$target_client_id}).";
                }
            } else {
                $error_message = "Error: Client not found or no QR identifier available for Client ID {$target_client_id}.";
            }
            $stmt->close();
        } else {
            $error_message = "Error: Client ID not specified or invalid for staff view.";
        }
    } else {
        // Staff role not permitted to view QR codes this way.
        // This case should ideally be prevented by the calling page (client_editor.php)
        // but as a fallback:
        $error_message = "Access Denied: Your staff role does not permit viewing client QR codes directly.";
        // Potentially redirect or show a more generic error to avoid info leakage.
        // For now, we'll let it fall through to the main error display.
    }
}
// 3. If Neither Client nor Authorized Staff
else {
    // No client session, and no staff session, or staff session without permission.
    // Redirect to the client login page as a default fallback.
    header("Location: ../client_login.php?error=session_required_or_permission_denied");
    exit;
}

// If there was an error during data retrieval, display it and stop.
if ($error_message) {
    // Display error and exit.
    // We can make this nicer later, for now, a simple die.
    // Consider a proper error display within the HTML structure.
    // For now, let's set a session flash message if possible and redirect or die.
    // Since we might be in client context, set a generic session var for error.
    $_SESSION['qr_page_error'] = $error_message;
    // Redirecting to profile might be an option if client was logged in but had an issue.
    // If staff had an issue, redirecting them back to client_editor might be better.
    // For simplicity, we'll display the error on this page if we reach the HTML part.
    // The die() below will prevent HTML rendering if error is critical.
    if (empty($client_qr_identifier)) { // Critical error if QR ID is still missing
         // We will display this error within the HTML structure below.
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
    error_log("QR Code Generation Error for client ID {$client_id}: " . $e->getMessage());
    $qr_code_data_uri = null; // Handle error case
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_staff_viewing && isset($_SESSION['viewing_client_name']) ? htmlspecialchars($_SESSION['viewing_client_name']) . "'s QR Code" : "Your QR Code"; ?> - Arizona@Work</title>
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
            <?php if ($is_staff_viewing && isset($_SESSION['viewing_client_name'])): ?>
                <h1 class="mb-1">QR Code for <?php echo $_SESSION['viewing_client_name']; ?></h1>
                <p class="text-muted mb-4">Client ID: <?php echo htmlspecialchars($client_id); ?></p>
            <?php else: ?>
                <h1 class="mb-4">Your Check-In QR Code</h1>
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