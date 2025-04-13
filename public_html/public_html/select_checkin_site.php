<?php
/*
 * File: select_checkin_site.php
 * Path: /select_checkin_site.php
 * Created: 2024-08-04 [Timezone] // Adjust Date/Timezone
 * Author: [Your Name/Identifier] // Adjust Author
 *
 * Description: Allows Admin/Director users (in 'All Sites' mode) to select
 *              a specific site before proceeding to the manual check-in form.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/auth.php'; // Ensures user is logged in, sets roles etc.
require_once 'includes/db_connect.php'; // Provides $pdo

// --- Role & Context Check ---
// Only Admin/Director in 'All Sites' mode should be here.
// Redirect others to appropriate locations.
if (!isset($_SESSION['active_role']) || !in_array($_SESSION['active_role'], ['administrator', 'director'])) {
    // If a Supervisor somehow gets here, send them to their checkin page
    if(isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'site_supervisor' && isset($_SESSION['active_site_id'])) {
         header('Location: checkin.php');
         exit;
    }
    // Otherwise, deny access for other roles or invalid states
    $_SESSION['flash_message'] = "Access Denied.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}
// If Admin/Director *already* has a site selected (impersonating), send them directly to checkin
if (isset($_SESSION['active_site_id'])) {
    header('Location: checkin.php');
    exit;
}


// --- Fetch Active Sites ---
$sites_list = [];
$fetch_error = null;
try {
    // Ensure $pdo is available
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("Database connection is not available.");
    }
    $stmt_sites = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
    $sites_list = $stmt_sites->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Select Checkin Site Error - Fetching sites: " . $e->getMessage());
    $fetch_error = "Could not load the list of sites. Please try again later.";
} catch (Exception $e) {
    error_log("Select Checkin Site Error - General: " . $e->getMessage());
    $fetch_error = "An error occurred while loading site data.";
}

// --- Page Setup ---
$pageTitle = "Select Site for Manual Check-In"; // Variable name used by header.php
require_once 'includes/header.php'; // Include header

?>

            <!-- Page Specific Content -->
            <div class="content-section">
                <h2 class="section-title">Manual Check-In Site Selection</h2>

                <?php if ($fetch_error): ?>
                    <div class="message-area error-message"><?php echo htmlspecialchars($fetch_error); ?></div>
                <?php elseif (empty($sites_list)): ?>
                    <div class="message-area info-message">There are no active sites available to perform a manual check-in. Please activate a site in Configurations.</div>
                <?php else: ?>
                    <p style="margin-bottom: 1.5rem; font-size: 1.1em;">Please select the site where you wish to perform a manual check-in:</p>

                    <?php // Simple form, styles assumed from main.css ?>
                    <form method="GET" action="checkin.php" style="max-width: 450px; margin: auto; background: #f9f9f9; padding: 2rem; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label for="manual_site_id" class="form-label" style="font-weight: 600; margin-bottom: 0.75rem;">Select Site:</label>
                            <select name="manual_site_id" id="manual_site_id" class="form-control" required style="padding: 10px; font-size: 1.1em;">
                                <option value="">-- Choose a Site --</option>
                                <?php foreach ($sites_list as $site): ?>
                                    <option value="<?php echo $site['id']; ?>">
                                        <?php echo htmlspecialchars($site['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-actions" style="margin-top: 1.5rem; display: flex; justify-content: space-between; gap: 1rem;">
                            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">
                                <i class="fas fa-arrow-right"></i> Proceed to Check-In
                            </button>
                            <a href="dashboard.php" class="btn btn-outline" style="flex-grow: 1;">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>

            </div><!-- /.content-section -->

<?php
require_once 'includes/footer.php'; // Include footer
?>