<?php
/*
 * File: dashboard.php
 * Path: /dashboard.php
 * Created: 2024-08-01 12:15:00 MST
 * Author: Robert Archer
 * Updated: 2025-04-10 - Corrected function calls, parse errors, label formatting, button JS.
 * Description: Main dashboard page for logged-in staff users.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication & database connection
require_once 'includes/db_connect.php'; // Provides $pdo and helper functions
require_once 'includes/auth.php';       // Ensures user is logged in, provides $_SESSION['active_role'] etc.

// --- Page Setup ---
$pageTitle = "Dashboard";
$dashboard_error = ''; // For displaying errors

// --- Site Selection Logic for DASHBOARD FILTER ---
$sites_for_dropdown = []; // Sites available in the dropdown
$site_filter_id = null;   // The site ID used to FILTER data ('all' or specific ID)
$user_can_select_sites = (isset($_SESSION['active_role']) && ($_SESSION['active_role'] === 'administrator' || $_SESSION['active_role'] === 'director'));

// Fetch sites available for the dropdown based on user's REAL role/permissions
try {
    if ($user_can_select_sites) {
        $stmt_sites = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
        $sites_for_dropdown = $stmt_sites->fetchAll(PDO::FETCH_ASSOC);
    } elseif (isset($_SESSION['site_id']) && $_SESSION['site_id'] !== null) { // Use original site_id for supervisor's own site context
        $stmt_sites = $pdo->prepare("SELECT id, name FROM sites WHERE id = :site_id AND is_active = TRUE");
        $stmt_sites->execute([':site_id' => $_SESSION['site_id']]);
        $sites_for_dropdown = $stmt_sites->fetchAll(PDO::FETCH_ASSOC);
        if (empty($sites_for_dropdown)) { $dashboard_error = "Your assigned site is currently inactive or could not be found."; }
    } else {
        if (isset($_SESSION['active_role']) && $_SESSION['active_role'] == 'site_supervisor') { $dashboard_error = "No site is assigned to your account."; }
    }
} catch (PDOException $e) {
    error_log("Dashboard Error - Fetching sites for dropdown: " . $e->getMessage());
    $dashboard_error = "Error loading site list.";
}

// Determine the site filter ID for THIS page request
if (isset($_GET['site_id'])) {
    if ($_GET['site_id'] === 'all' && $user_can_select_sites) { $site_filter_id = 'all'; }
    elseif (is_numeric($_GET['site_id'])) {
        $potential_site_id = intval($_GET['site_id']);
        $is_valid_selection = false;
        if ($user_can_select_sites) { foreach ($sites_for_dropdown as $site) { if ($site['id'] == $potential_site_id) {$is_valid_selection = true; break;} } }
        elseif (!empty($sites_for_dropdown) && $sites_for_dropdown[0]['id'] == $potential_site_id) { $is_valid_selection = true; } // Supervisor can only select their own site
        if ($is_valid_selection) { $site_filter_id = $potential_site_id; }
        else { // Fallback for invalid ID
             if ($user_can_select_sites) { $site_filter_id = 'all'; } elseif (!empty($sites_for_dropdown)) { $site_filter_id = $sites_for_dropdown[0]['id']; } else { $site_filter_id = null; }
             $dashboard_error .= " Invalid site selected in URL, showing default view.";
        }
    } else { // Fallback for invalid format
         if ($user_can_select_sites) { $site_filter_id = 'all'; } elseif (!empty($sites_for_dropdown)) { $site_filter_id = $sites_for_dropdown[0]['id']; } else { $site_filter_id = null; }
    }
} else { // Default if no GET param
    if ($user_can_select_sites) { $site_filter_id = 'all'; }
    elseif (!empty($sites_for_dropdown)) { $site_filter_id = $sites_for_dropdown[0]['id']; }
    else { $site_filter_id = null; }
}


// --- Fetch active question columns and map based on the determined site filter ---
$active_question_columns = []; // Stores prefixed names e.g., q_needs_assistance
$active_question_map = [];     // Maps prefixed_name => base_title
$active_chart_labels = [];     // Stores formatted labels e.g., Needs Assistance
try {
    $sql_q_cols = "SELECT DISTINCT gq.question_title -- Use DISTINCT for safety
                   FROM global_questions gq
                   JOIN site_questions sq ON gq.id = sq.global_question_id
                   WHERE sq.is_active = TRUE";
    $params_q_cols = [];
    if ($site_filter_id !== 'all' && $site_filter_id !== null) {
         $sql_q_cols .= " AND sq.site_id = :site_id_filter";
         $params_q_cols[':site_id_filter'] = $site_filter_id;
    }
     $sql_q_cols .= " ORDER BY gq.question_title ASC";

    $stmt_q_cols = $pdo->prepare($sql_q_cols);
    $stmt_q_cols->execute($params_q_cols);
    $question_base_titles = $stmt_q_cols->fetchAll(PDO::FETCH_COLUMN); // Fetch base titles

    if ($question_base_titles) {
         foreach ($question_base_titles as $base_title) {
             if (!empty($base_title)) {
                 // --- Corrected function call ---
                 $sanitized_base = sanitize_title_to_base_name($base_title); // Use new function
                 if (!empty($sanitized_base)) {
                     $prefixed_col_name = 'q_' . $sanitized_base;
                     $formatted_label = format_base_name_for_display($sanitized_base); // Format for display

                     $active_question_columns[] = $prefixed_col_name;
                     $active_question_map[$prefixed_col_name] = $base_title; // Map prefixed to base
                     $active_chart_labels[] = $formatted_label; // Store formatted label
                 } else { error_log("Dashboard Warning: Sanitized base title for '{$base_title}' resulted in empty string."); }
             }
         }
     }
 } catch (PDOException $e) {
     error_log("Dashboard Error - Fetching active question titles: " . $e->getMessage());
     $dashboard_error .= " Error loading question details.";
 }
 // Prepare JSON for chart JS
 $chart_labels_json = json_encode($active_chart_labels);
 $question_columns_json = json_encode($active_question_columns);
 // Log prefixed names used for data fetching
 error_log("Dashboard Debug - Filter ID: {$site_filter_id} - Active question columns for data fetch (prefixed): " . print_r($active_question_columns, true));


// --- Determine initial state for the dashboard manual check-in button ---
// JS will handle the click action based on the dropdown, so PHP just sets a default href
$dashboard_manual_checkin_href = '#';
// We no longer set modal attributes here; JS controls behavior
// $dashboard_manual_checkin_action_attr = ''; // Not needed


// --- Fetch Main Dashboard Data (Using $site_filter_id) ---
$todays_checkins = 0;
$checkins_last_hour = 0;
$dynamic_stat_1_value = 0;
$dynamic_stat_1_label = "Needs Assistance"; // Default label for stat card
$recent_checkins_list = [];

try {
    // Only fetch if a valid site filter is active
    if ($site_filter_id !== null || ($site_filter_id === 'all' && $user_can_select_sites)) {

        $params = []; // Params for data queries
        $base_sql_where_clause = "";
        if ($site_filter_id !== 'all') {
            $base_sql_where_clause = " WHERE ci.site_id = :site_id_filter ";
            $params[':site_id_filter'] = $site_filter_id;
        }

        // Stat 1: Today's Check-ins
        $sql_today = "SELECT COUNT(ci.id) FROM check_ins ci " . $base_sql_where_clause . ($base_sql_where_clause ? " AND " : " WHERE ") . " DATE(ci.check_in_time) = CURDATE()";
        $stmt_today = $pdo->prepare($sql_today);
        $stmt_today->execute($params);
        $todays_checkins = $stmt_today->fetchColumn() ?: 0;

        // Stat 2: Check-ins Last Hour
        $sql_last_hour = "SELECT COUNT(ci.id) FROM check_ins ci " . $base_sql_where_clause . ($base_sql_where_clause ? " AND " : " WHERE ") . " ci.check_in_time >= :one_hour_ago";
        $params_last_hour = $params;
        $params_last_hour[':one_hour_ago'] = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $stmt_last_hour = $pdo->prepare($sql_last_hour);
        $stmt_last_hour->execute($params_last_hour);
        $checkins_last_hour = $stmt_last_hour->fetchColumn() ?: 0;

        // --- Stat 3: Dynamic Stat ---
        // CONFIGURATION: Set the BASE name (e.g., 'needs_assistance') of the question to track here
        $dynamic_stat_base_name = 'needs_assistance';
        // CONFIGURATION: Set the label for the card
        $dynamic_stat_1_label = format_base_name_for_display($dynamic_stat_base_name) . " Today"; // Use formatted name
        $dynamic_column_name = 'q_' . sanitize_title_to_base_name($dynamic_stat_base_name); // Construct prefixed name

        $dynamic_stat_1_value = 'N/A'; // Default if not applicable

        // Check if the constructed column name is among the active ones for the current filter
        if (!empty($active_question_columns) && in_array($dynamic_column_name, $active_question_columns)) {
            try {
                 $sql_dynamic = "SELECT COUNT(ci.id) FROM check_ins ci "
                             . $base_sql_where_clause
                             . ($base_sql_where_clause ? " AND " : " WHERE ") . " DATE(ci.check_in_time) = CURDATE()"
                             . " AND `" . $dynamic_column_name . "` = 'YES'"; // Use safe column name

                 $stmt_dynamic = $pdo->prepare($sql_dynamic);
                 $stmt_dynamic->execute($params); // Use same params as other stats
                 $dynamic_stat_1_value = $stmt_dynamic->fetchColumn() ?: 0;
             } catch (PDOException $e) {
                 error_log("Dashboard Error - Fetching dynamic stat for column '{$dynamic_column_name}': " . $e->getMessage());
                 $dashboard_error .= " Error loading dynamic stat (" . htmlspecialchars($dynamic_stat_base_name) . ").";
                 $dynamic_stat_1_value = 'ERR';
            }
        } else {
            // If the column isn't active for this filter, display 0
            error_log("Dashboard Info: Configured dynamic column '{$dynamic_column_name}' not found among active columns for filter '{$site_filter_id}'. Setting stat to 0.");
            $dynamic_stat_1_value = 0;
        }
        // --- End Stat 3 ---

        // --- Recent Check-ins List ---
        // Dynamically build the SELECT part for active question columns
        $dynamic_select_sql = "";
        if (!empty($active_question_columns)) {
             $safe_dynamic_cols = array_map(function($col) {
                 if (preg_match('/^q_[a-zA-Z0-9_]+$/', $col)) return "`" . $col . "`";
                 return null;
             }, $active_question_columns);
             $safe_dynamic_cols = array_filter($safe_dynamic_cols);
             if (!empty($safe_dynamic_cols)) $dynamic_select_sql = ", " . implode(", ", $safe_dynamic_cols);
        }

        $sql_recent = "SELECT ci.id, ci.first_name, ci.last_name, ci.check_in_time, s.name as site_name" . $dynamic_select_sql . "
                       FROM check_ins ci
                       JOIN sites s ON ci.site_id = s.id ";
        $sql_recent .= ($site_filter_id !== 'all' ? " WHERE ci.site_id = :site_id_filter " : ""); // Add WHERE only if specific site
        $sql_recent .= " ORDER BY ci.check_in_time DESC LIMIT 5";

        $stmt_recent = $pdo->prepare($sql_recent);
        // Bind site filter parameter only if needed
        if ($site_filter_id !== 'all') {
            $stmt_recent->bindParam(':site_id_filter', $site_filter_id, PDO::PARAM_INT);
        }
        $stmt_recent->execute();
        $recent_checkins_list = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
        // --- End Recent Check-ins ---

    } // End if ($site_filter_id !== null ...)

} catch (PDOException $e) { // Catch errors during main data fetching
    error_log("Dashboard Error - Fetching data: " . $e->getMessage());
    $dashboard_error .= " Error loading dashboard data.";
}


// --- Include Header ---
require_once 'includes/header.php'; // Provides sidebar, opening tags

?>

            <!-- Page Header -->
            <div class="header">
                <!-- Site selector dropdown -->
                 <?php if ($user_can_select_sites || count($sites_for_dropdown) > 0) : ?>
                    <div class="site-selector">
                        <label for="site-select">Filter Site:</label>
                        <select id="site-select" name="site_id_selector" onchange="location = 'dashboard.php?site_id=' + this.value;">
                            <?php if ($user_can_select_sites): ?>
                                <option value="all" <?php echo ($site_filter_id === 'all') ? 'selected' : ''; ?>>All Sites</option>
                            <?php endif; ?>
                            <?php if (!empty($sites_for_dropdown)): ?>
                                <?php foreach ($sites_for_dropdown as $site): ?>
                                    <option value="<?php echo $site['id']; ?>" <?php echo ($site_filter_id == $site['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($site['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php elseif (!$user_can_select_sites): ?>
                                 <option value="" disabled>No site assigned or available</option>
                             <?php endif; ?>
                        </select>
                    </div>
                 <?php endif; ?>
            </div>

             <!-- Display Errors -->
            <?php if ($dashboard_error): ?>
                <div class="message-area message-error"><?php echo htmlspecialchars($dashboard_error); ?></div>
            <?php endif; ?>


            <!-- Main Dashboard Content Area -->
            <div id="dashboard-view">
                 <!-- Data Context Info -->
                 <p class="data-context-info" style="text-align: right; font-size: 0.9em; color: var(--color-gray); margin-bottom: 1rem;">
                     Displaying data for:
                     <strong>
                         <?php
                            if ($site_filter_id === 'all') { echo "All Sites"; }
                            elseif ($site_filter_id !== null) {
                                $display_site_name = 'Selected Site';
                                foreach($sites_for_dropdown as $s) { if ($s['id'] == $site_filter_id) {$display_site_name = $s['name']; break;}}
                                echo htmlspecialchars($display_site_name);
                            } else { echo "No Site Applicable"; }
                         ?>
                     </strong>
                 </p>

                <!-- Stats Cards Row -->
                <div class="cards-row">
                    <!-- Card 1: Today's Check-ins -->
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Today's Check-ins</h2><div class="card-icon"><i class="fas fa-users"></i></div></div>
                        <div class="card-body"><div class="stat-value"><?php echo number_format($todays_checkins); ?></div><div class="stat-label">Total check-ins today</div></div>
                    </div>
                    <!-- Card 2: Dynamic Stat -->
                    <div class="card">
                         <div class="card-header"><h2 class="card-title"><?php echo htmlspecialchars($dynamic_stat_1_label); ?></h2><div class="card-icon"><i class="fas fa-question-circle"></i></div></div>
                         <div class="card-body"><div class="stat-value"><?php echo ($dynamic_stat_1_value === 'N/A' || $dynamic_stat_1_value === 'ERR') ? $dynamic_stat_1_value : number_format($dynamic_stat_1_value); ?></div><div class="stat-label">Based on question responses today</div></div>
                    </div>
                    <!-- Card 3: Check-ins Last Hour -->
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Check-ins Last Hour</h2><div class="card-icon"><i class="fas fa-clock"></i></div></div>
                        <div class="card-body"><div class="stat-value"><?php echo number_format($checkins_last_hour); ?></div><div class="stat-label">In the previous 60 minutes</div></div>
                    </div>
                </div>

                <!-- Recent Check-ins Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h2 class="table-title">Recent Check-ins</h2>
                        <div class="table-actions">
                             <a href="reports.php?site_id=<?php echo urlencode($site_filter_id ?? 'all'); ?>" class="btn btn-outline"><i class="fas fa-filter"></i> View Full Report</a>
                             <!-- Dashboard Manual Check-in Button (href is '#', JS handles click) -->
                             <a href="#"
                                id="manual-checkin-link-dashboard"
                                class="btn btn-secondary manual-checkin-trigger">
                                <i class="fas fa-clipboard-check"></i> Manual Check-in
                            </a>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <?php if($site_filter_id === 'all') echo '<th>Site</th>'; ?>
                                <th>Time</th>
                                <th>Details Summary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                             <?php if (!empty($recent_checkins_list)): ?>
                                <?php foreach ($recent_checkins_list as $checkin):
                                     // --- Corrected Details Summary Logic ---
                                    $details_parts = [];
                                    if (isset($active_question_columns) && is_array($active_question_columns) && isset($active_question_map) && is_array($active_question_map)) {
                                        foreach ($active_question_columns as $prefixed_col) {
                                            if (isset($checkin[$prefixed_col]) && $checkin[$prefixed_col] === 'YES') {
                                                if (isset($active_question_map[$prefixed_col])) {
                                                    $base_title = $active_question_map[$prefixed_col];
                                                    $formatted_label = format_base_name_for_display($base_title);
                                                    $details_parts[] = $formatted_label;
                                                } else {
                                                    $fallback_label = ucwords(str_replace(['q_', '_'], ['', ' '], $prefixed_col));
                                                    $details_parts[] = $fallback_label;
                                                    error_log("Dashboard Warning: No base title found in map for column '{$prefixed_col}' in recent checkins summary.");
                                                }
                                            }
                                        }
                                    }
                                    $details_summary = !empty($details_parts) ? implode(', ', $details_parts) : 'N/A';
                                    // --- End Details Summary Logic ---
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($checkin['first_name'] . ' ' . $checkin['last_name']); ?></td>
                                        <?php if($site_filter_id === 'all'): // Use colon syntax for clarity ?>
                                            <td><?php echo htmlspecialchars($checkin['site_name']); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo date('h:i A', strtotime($checkin['check_in_time'])); ?></td>
                                        <td style="font-size: 0.85em;"><?php echo htmlspecialchars($details_summary); ?></td>
                                        <td><button class="btn btn-outline btn-sm" onclick="alert('View details TBD');"><i class="fas fa-eye"></i> View</button></td>
                                    </tr>
                                <?php endforeach; // Correctly ends foreach ?>
                            <?php else: ?>
                                <tr>
                                     <?php
                                     // Calculate colspan dynamically
                                     $fixed_cols_dash = 4; // Name, Time, Summary, Actions
                                     if ($site_filter_id === 'all') $fixed_cols_dash++;
                                     ?>
                                    <td colspan="<?php echo $fixed_cols_dash; ?>" style="text-align: center;">No recent check-ins found.</td>
                                </tr>
                            <?php endif; // Correctly ends if ?>
                        </tbody>
                    </table>
                </div> <!-- /.table-container -->

                <!-- Charts Row -->
                <div class="charts-row">
                    <div class="chart-container">
                        <div class="chart-header"><h2 class="chart-title">Check-ins Over Time</h2></div>
                        <div class="chart-placeholder">
                           <canvas id="checkinsChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-container">
                        <div class="chart-header"><h2 class="chart-title">Question Responses</h2></div>
                         <div class="chart-placeholder">
                           <canvas id="responsesChart"></canvas>
                        </div>
                    </div>
                </div> <!-- /.charts-row -->
            </div> <!-- /#dashboard-view -->

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script> <!-- Using specific v3 -->
<!-- JavaScript for updating links and charts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Consistent Manual Check-in Button Logic ---
    /*const siteSelector = document.getElementById('site-select');
    const manualCheckinButtons = document.querySelectorAll('.manual-checkin-trigger'); // Select BOTH buttons

    if (manualCheckinButtons.length > 0) {
        manualCheckinButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent default link behavior

                let selectedSiteIdValue = null;
                if (siteSelector) {
                    selectedSiteIdValue = siteSelector.value;
                } else {
                     // If no dropdown (e.g., supervisor view), rely on PHP to set correct direct href
                     console.log("Site selector dropdown not found for manual check-in button, relying on href.");
                     // Attempt to navigate using the button's existing href (should be checkin.php for supervisor)
                      const directHref = this.getAttribute('href');
                      if (directHref && directHref !== '#') {
                         window.location.href = directHref;
                      } else {
                         alert('Cannot determine site context.'); // Fallback alert
                      }
                     return; // Exit function
                }

                if (selectedSiteIdValue === 'all') {
                    // Show alert if 'All Sites' is selected
                    //alert('Please select a site from the dropdown to continue manual check in.');
                } else if (selectedSiteIdValue && selectedSiteIdValue !== 'all') {
                    // Navigate directly if a specific site is selected
                    const siteId = encodeURIComponent(selectedSiteIdValue);
                    window.location.href = `checkin.php?manual_site_id=${siteId}`;
                } else {
                    // Handle case where dropdown exists but has no value?
                    alert('Please select a site from the dropdown.');
                }
            });
        });
    }*/
    // --- End Consistent Manual Check-in Logic ---


    // --- Chart Initialization ---
    // Chart 1: Check-ins Over Time (Placeholder)
    const ctxTimeDash = document.getElementById('checkinsChart');
    if (ctxTimeDash) {
        const timeLabelsDash = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; // Example
        const timeDataDash = [12, 19, 3, 5, 2, 3, 7]; // Example
        if (timeLabelsDash.length > 0) {
            new Chart(ctxTimeDash, { type: 'line', data: { labels: timeLabelsDash, datasets: [{ label: 'Check-ins', data: timeDataDash, tension: 0.1, borderColor: 'var(--color-primary)', backgroundColor: 'rgba(30, 58, 138, 0.1)', fill: true }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } } });
        } else { /* No data message */ }
    }

    // Chart 2: Question Responses
    const ctxQuestionsDash = document.getElementById('responsesChart');
    if (ctxQuestionsDash) {
        // Use the data embedded by PHP
        const questionChartLabelsDash = <?php echo $chart_labels_json ?? '[]'; ?>;
        const questionColumnsDash = <?php echo $question_columns_json ?? '[]'; ?>;
        const reportDataDash = <?php echo empty($recent_checkins_list) ? '[]' : json_encode($recent_checkins_list); // Use recent checkins list for sample data source ?>;

        let yesCountsDash = [];
        if (reportDataDash.length > 0 && questionColumnsDash.length > 0 && questionChartLabelsDash.length === questionColumnsDash.length) {
            yesCountsDash = Array(questionColumnsDash.length).fill(0);
            reportDataDash.forEach(row => {
                questionColumnsDash.forEach((colName, index) => {
                    if (row.hasOwnProperty(colName) && row[colName] === 'YES') {
                        if (index < yesCountsDash.length) yesCountsDash[index]++;
                    }
                });
            });
        } else if (questionColumnsDash.length > 0) {
            yesCountsDash = Array(questionColumnsDash.length).fill(0);
        }

        if (questionChartLabelsDash.length > 0) {
            new Chart(ctxQuestionsDash, { type: 'pie', data: { labels: questionChartLabelsDash, datasets: [{ label: 'Yes Answers', data: yesCountsDash, backgroundColor: ['rgba(255, 107, 53, 0.7)','rgba(30, 58, 138, 0.7)','rgba(75, 192, 192, 0.7)','rgba(255, 205, 86, 0.7)','rgba(153, 102, 255, 0.7)'], hoverOffset: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } } } });
        } else { /* No data message */
             const ctx = ctxQuestionsDash.getContext('2d');
             ctx.textAlign = 'center'; ctx.fillStyle = 'var(--color-gray)';
             ctx.fillText('No question data available for chart.', ctxQuestionsDash.canvas.width / 2, ctxQuestionsDash.canvas.height / 2);
        }
    }
    // --- End Chart Initialization ---

}); // End DOMContentLoaded
</script>

<?php
// --- Include Footer ---
require_once 'includes/footer.php';
?>