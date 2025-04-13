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
if ($currentUserRole !== 'site_supervisor') {
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
try {
    $stmt_site = $pdo->prepare("SELECT name FROM sites WHERE id = :site_id");
    $stmt_site->bindParam(':site_id', $site_id_to_query, PDO::PARAM_INT);
    $stmt_site->execute();
    $site_data = $stmt_site->fetch();
    if ($site_data) {
        $site_name = $site_data['name'];
    } else {
        error_log("Notifications Warning: Could not fetch name for active site ID: {$site_id_to_query}");
        $site_name = 'Unknown Site (ID: ' . $site_id_to_query . ')';
    }
} catch (PDOException $e) {
    error_log("Notifications Error - Fetching site name for ID {$site_id_to_query}: " . $e->getMessage());
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

try {
    // Base query filtering by site, notification presence, AND date
    $base_sql = "FROM check_ins ci
                 JOIN staff_notifications sn ON ci.notified_staff_id = sn.id
                 WHERE ci.site_id = :site_id
                   AND ci.notified_staff_id IS NOT NULL
                   AND ci.check_in_time >= :start_date_limit"; // <<< ADDED DATE CONDITION

    // Count total records for pagination (WITH date filter)
    $sql_count = "SELECT COUNT(*) " . $base_sql;
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->bindParam(':site_id', $site_id_to_query, PDO::PARAM_INT);
    $stmt_count->bindParam(':start_date_limit', $date_limit_string, PDO::PARAM_STR); // <<< BIND DATE LIMIT
    $stmt_count->execute();
    $total_records = (int) $stmt_count->fetchColumn();

    $total_pages = 0; // Initialize
    if ($total_records > 0) {
        $total_pages = ceil($total_records / $limit);
        $page = max(1, min($page, $total_pages)); // Recalculate page based on filtered total
        $offset = ($page - 1) * $limit;

        // Fetch paginated notification history data (WITH date filter)
        $sql_data = "SELECT ci.first_name, ci.last_name, ci.check_in_time, sn.staff_name AS notified_staff_name
                     " . $base_sql . "
                     ORDER BY ci.check_in_time DESC
                     LIMIT :limit OFFSET :offset";

        $stmt_data = $pdo->prepare($sql_data);
        $stmt_data->bindParam(':site_id', $site_id_to_query, PDO::PARAM_INT);
        $stmt_data->bindParam(':start_date_limit', $date_limit_string, PDO::PARAM_STR); // <<< BIND DATE LIMIT
        $stmt_data->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt_data->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt_data->execute();
        $notification_history = $stmt_data->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $notification_error = "Error fetching notification history."; // Keep generic
    error_log("Notifications PDOException Fetching History for site {$site_id_to_query} with date limit: " . $e->getMessage());
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