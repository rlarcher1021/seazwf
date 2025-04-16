<?php
/*
 * File: notifications.php
 * Path: /notifications.php
 * Created: 2024-08-01 14:30:00 MST
 * Author: Robert Archer
 * Updated: 2025-04-08 - Added 30-day time limit to history.
 *
 * Description: Displays recent staff notifications (last 30 days) for the supervisor's active site context.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication
require_once 'includes/auth.php';       // Ensures user is logged in
require_once 'includes/db_connect.php'; // Provides $pdo
require_once 'includes/data_access/site_data.php';     // For getSiteNameById
require_once 'includes/data_access/notifier_data.php'; // For notification history functions

// --- Configuration ---
define('NOTIFICATION_HISTORY_DAYS', 30); // << SET TIME LIMIT HERE (in days)

// Get current ACTIVE role and ACTIVE site ID from session (set by auth/impersonation)
$currentUserRole = $_SESSION['active_role'] ?? null;
$activeSiteId = $_SESSION['active_site_id'] ?? null; // Use the active context

if ($currentUserRole === null) {
    error_log("Notifications Error: User role not found in session.");
    $_SESSION['flash_message'] = "Error: User session invalid.";
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// --- Role Check ---
if ($currentUserRole !== 'azwk_staff') {
    error_log("Notifications Access Denied: Role '{$currentUserRole}' attempted access.");
    $_SESSION['flash_message'] = "Access denied. Supervisor role required.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Site Check ---
if ($activeSiteId === null || !is_numeric($activeSiteId)) {
    error_log("Notifications Error: Supervisor '{$_SESSION['username']}' has no active_site_id set in session.");
    $pageTitle = "Error";
    require_once 'includes/header.php';
    echo "<div class='main-content'><div class='message-area message-error'>Error: Your account session does not have a specific site context assigned. Cannot display notification history.</div></div>";
    require_once 'includes/footer.php';
    exit;
}

// --- Page Setup ---
$pageTitle = "Notification History (Last " . NOTIFICATION_HISTORY_DAYS . " Days)"; // Update title
$site_id_to_query = $activeSiteId;
$site_name = 'Site ' . $site_id_to_query;

// --- Fetch Site Name ---
$fetched_site_name = getSiteNameById($pdo, $site_id_to_query);
if ($fetched_site_name !== null) {
    $site_name = $fetched_site_name;
} else {
    // Error logged within the function
    $site_name = 'Unknown Site (ID: ' . $site_id_to_query . ')';
}


// --- Fetch Notification History Data (with Pagination AND Time Limit) ---
$notification_history = [];
$notification_error = '';
$total_records = 0;
$limit = 20; // Records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Calculate the cutoff date/time
$date_limit_string = date('Y-m-d H:i:s', strtotime('-' . NOTIFICATION_HISTORY_DAYS . ' days'));

// Use data access functions to fetch count and data
$total_records_result = countRecentNotificationsForSite($pdo, $site_id_to_query, NOTIFICATION_HISTORY_DAYS);
$total_pages = 0;

if ($total_records_result === false) {
    $notification_error = "Error counting notification history."; // Error logged in function
    $total_records = 0;
} else {
    $total_records = $total_records_result;
    if ($total_records > 0) {
        $total_pages = ceil($total_records / $limit);
        $page = max(1, min($page, $total_pages)); // Recalculate page based on filtered total
        $offset = ($page - 1) * $limit;

        $notification_history_result = getRecentNotificationsForSite($pdo, $site_id_to_query, NOTIFICATION_HISTORY_DAYS, $limit, $offset);

        if ($notification_history_result === false) {
            $notification_error = "Error fetching notification history details."; // Error logged in function
            $notification_history = []; // Ensure it's an empty array on error
        } else {
            $notification_history = $notification_history_result;
        }
    } else {
         $notification_history = []; // No records found
    }
}


// --- Include Header ---
require_once 'includes/header.php';

?>

            <!-- Page Header -->
            <div class="header">
                <!--<h1 class="page-title">
                    <?php echo htmlspecialchars($pageTitle); ?>
                    <span style="font-weight: normal; font-size: 0.8em; color: var(--color-gray);">
                        (Site: <?php echo htmlspecialchars($site_name); ?>)
                    </span>
                 </h1>-->
            </div>

             <?php if ($notification_error): ?>
                <div class="message-area message-error"><?php echo htmlspecialchars($notification_error); ?></div>
            <?php endif; ?>


            <!-- Notification History Table Section -->
            <div class="content-section">
                <h2 class="section-title">Recent Staff Notifications</h2>
                 <p class="info-message" style="text-align: left; margin-bottom: 1rem; padding: 0.5rem 1rem; font-size: 0.9em;">
                     <i class="fas fa-info-circle"></i> Displaying notifications from the last <?php echo NOTIFICATION_HISTORY_DAYS; ?> days.
                 </p>

                 <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Client Name</th>
                                <th>Staff Notified</th>
                                <th>Time of Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($notification_history)): ?>
                                <?php foreach ($notification_history as $notification): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($notification['first_name'] . ' ' . $notification['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($notification['notified_staff_name']); ?></td>
                                        <td><?php echo date('Y-m-d h:i A', strtotime($notification['check_in_time'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php elseif (empty($notification_error)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center;">No notification history found for this site within the last <?php echo NOTIFICATION_HISTORY_DAYS; ?> days.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                     <?php if ($total_pages > 1): ?>
                     <div class="table-footer">
                         <div>Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_records; ?> total records within time limit)</div>
                         <div class="table-pagination">
                              <!-- Previous Button -->
                              <a href="?page=<?php echo max(1, $page - 1); ?>"
                                 class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>"
                                 <?php echo ($page <= 1) ? 'aria-disabled="true"' : ''; ?>>
                                 <i class="fas fa-chevron-left"></i>
                             </a>

                             <!-- Page Numbers (Simple Example) -->
                             <span style="padding: 0 10px;">Page <?php echo $page; ?></span>

                              <!-- Next Button -->
                              <a href="?page=<?php echo min($total_pages, $page + 1); ?>"
                                 class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>"
                                 <?php echo ($page >= $total_pages) ? 'aria-disabled="true"' : ''; ?>>
                                  <i class="fas fa-chevron-right"></i>
                              </a>
                         </div>
                     </div>
                     <?php elseif ($total_records > 0 && empty($notification_error)): ?>
                          <div class="table-footer">
                              <div><?php echo $total_records; ?> total records found within the last <?php echo NOTIFICATION_HISTORY_DAYS; ?> days.</div>
                          </div>
                     <?php endif; ?>

                 </div> <!-- /.table-container -->
            </div> <!-- /.content-section -->

<?php
// --- Include Footer ---
require_once 'includes/footer.php';
?>