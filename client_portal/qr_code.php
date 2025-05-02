<?php
// --- Client Session Handling ---
// Use the distinct session name established in client_login.php
if (session_status() == PHP_SESSION_NONE) {
    session_name("CLIENT_SESSION");
    session_start();
}

// --- Authentication Check ---
// Redirect to login if client is not logged in (using the correct session key)
if (!isset($_SESSION['CLIENT_SESSION']) || !isset($_SESSION['CLIENT_SESSION']['client_id'])) {
    // Redirect to the client login page, which is one level up from the client_portal directory
    header("Location: ../client_login.php?error=session_required");
    exit;
}

// --- Get Client Data from Session ---
// Proceed only if authenticated (using the correct session key)
$client_id = $_SESSION['CLIENT_SESSION']['client_id'];
// Attempt to get the QR identifier from the session data array (using the correct session key and identifier key)
$client_qr_identifier = $_SESSION['CLIENT_SESSION']['qr_identifier'] ?? null;

// Add a check to ensure the QR identifier exists in the session
if (empty($client_qr_identifier)) {
    // Handle the case where the identifier is missing, maybe redirect or show an error
    // For now, let's redirect back to profile or show an error message.
    // Redirecting to profile might be better as they are logged in.
    // Or display an error directly on this page. Let's display an error for simplicity.
    die("Error: QR Code identifier not found in your session. Please contact support.");
    // Alternatively, redirect:
    // header("Location: profile.php?error=qr_id_missing");
    // exit;
}

// Include necessary files *after* session check
require_once '../includes/db_connect.php'; // For potential future use, though not strictly needed for QR display if identifier is in session
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
    <title>Your QR Code - Arizona@Work</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Optional: Add custom CSS if needed -->
    <!-- <link rel="stylesheet" href="/assets/css/client_portal.css"> -->
    <style>
        body { padding-top: 56px; /* Adjust based on navbar height */ }
        .qr-container { text-align: center; margin-top: 30px; }
        .qr-code-img { max-width: 100%; height: auto; border: 1px solid #dee2e6; padding: 10px; }
    </style>
</head>
<body>

    <!-- Basic Navbar (Replace with include if header.php is created later) -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <a class="navbar-brand" href="/public_html/client_portal/dashboard.php">AZ@Work Client Portal</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/public_html/client_portal/services.php">Services</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/public_html/client_portal/profile.php">Profile</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="/public_html/client_portal/qr_code.php">QR Code <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../logout.php?client=1">Logout</a> <!-- Link to client logout -->
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="qr-container">
            <h1 class="mb-4">Your Check-In QR Code</h1>

            <?php // Note: $errorMessage is not defined in the current logic, the script dies on error. ?>
            <?php /* if ($errorMessage): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php */ ?>
            <?php if ($qr_code_data_uri): // Corrected variable name ?>
                <div class="card">
                    <div class="card-body">
                        <p class="card-text">Scan this QR code at any Arizona@Work kiosk to quickly check in for services.</p>
                        <img src="<?php echo $qr_code_data_uri; // Corrected variable name ?>" alt="Your Check-In QR Code" class="qr-code-img">
                        <p class="mt-3"><strong>Keep this code secure.</strong></p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Fallback if something unexpected happened but no specific error was caught -->
                <div class="alert alert-warning" role="alert">
                    Could not display QR code at this time. Please try again later or contact support.
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Basic Footer (Replace with include if footer.php is created later) -->
    <footer class="footer mt-auto py-3 bg-light fixed-bottom">
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