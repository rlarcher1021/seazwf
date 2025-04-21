<?php
/*
 * File: dashboard.php
 * Path: /dashboard.php
 * Created: 2024-08-01 12:15:00 MST

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
require_once 'includes/utils.php';      // General utility functions like sanitizers
require_once 'includes/data_access/site_data.php'; // Functions for site data
require_once 'includes/data_access/question_data.php'; // Functions for question data
// Moved checkin_data include closer to usage below

// --- Page Setup ---
$pageTitle = "Dashboard";
$dashboard_error = ''; // For displaying errors

// --- Site Selection Logic for DASHBOARD FILTER ---
$sites_for_dropdown = []; // Sites available in the dropdown
$site_filter_id = null;   // The site ID used to FILTER data ('all' or specific ID)
$user_can_select_sites = (isset($_SESSION['active_role']) && ($_SESSION['active_role'] === 'administrator' || $_SESSION['active_role'] === 'director'));

// Fetch sites available for the dropdown using data access functions
if ($user_can_select_sites) {
    $sites_for_dropdown = getAllActiveSites($pdo);
    if ($sites_for_dropdown === []) { // Check explicitly for empty array which indicates potential error in function
        $dashboard_error = "Error loading site list.";
    }
} elseif (isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== null) {
    $site_data = getActiveSiteById($pdo, $_SESSION['active_site_id']);
    if ($site_data) {
        $sites_for_dropdown = [$site_data]; // Put the single site into an array format
    } else {
        $sites_for_dropdown = [];
        $dashboard_error = "Your assigned site is currently inactive or could not be found.";
    }
} else {
    $sites_for_dropdown = [];
    if (isset($_SESSION['active_role']) && in_array($_SESSION['active_role'], ['azwk_staff', 'outside_staff'])) {
        $dashboard_error = "No site is assigned to your account.";
    }
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
$active_question_map = [];     // Maps prefixed_name => original_title (from DB)
$active_chart_labels = [];     // Stores formatted labels e.g., Needs Assistance

// Use the new data access function
$question_base_titles = getActiveQuestionTitles($pdo, $site_filter_id);

if ($question_base_titles === []) {
    // Function might have returned empty due to error or no questions found
    if ($site_filter_id !== null) { // Avoid error message if no site context exists
         $dashboard_error .= " Error loading question details or no active questions found for this filter.";
    }
} else {
    foreach ($question_base_titles as $base_title) {
        if (!empty($base_title)) {
            // Use utility functions (already required)
            $sanitized_base = sanitize_title_to_base_name($base_title);
            if (!empty($sanitized_base)) {
                $prefixed_col_name = 'q_' . $sanitized_base;
                // Validate column name format before adding (important for security later)
                 if (preg_match('/^q_[a-z0-9_]+$/', $prefixed_col_name) && strlen($prefixed_col_name) <= 64) {
                    $formatted_label = format_base_name_for_display($sanitized_base); // Format for display

                    $active_question_columns[] = $prefixed_col_name;
                    $active_question_map[$prefixed_col_name] = $base_title; // Map prefixed to original base title
                    $active_chart_labels[] = $formatted_label; // Store formatted label
                 } else {
                     error_log("Dashboard Warning: Generated prefixed column name '{$prefixed_col_name}' from base '{$base_title}' is invalid and was skipped.");
                 }
            } else {
                error_log("Dashboard Warning: Sanitized base title for '{$base_title}' resulted in empty string.");
            }
        }
    }
}

// Prepare JSON for chart JS
$chart_labels_json = json_encode($active_chart_labels);
$question_columns_json = json_encode($active_question_columns); // Contains only validated columns now
// Log prefixed names used for data fetching
error_log("Dashboard Debug - Filter ID: {$site_filter_id} - Active VALID question columns for data fetch (prefixed): " . print_r($active_question_columns, true));


// --- Determine initial state for the dashboard manual check-in button ---
// JS will handle the click action based on the dropdown, so PHP just sets a default href
$dashboard_manual_checkin_href = '#';
// We no longer set modal attributes here; JS controls behavior
// $dashboard_manual_checkin_action_attr = ''; // Not needed


// Moved require_once here to ensure it's loaded right before use
require_once 'includes/data_access/checkin_data.php'; // Functions for check-in data

// --- Fetch Main Dashboard Data (Using $site_filter_id) ---
$todays_checkins = 0;
$checkins_last_hour = 0;
$checkins_last_7_days = 0; // Initialize new variable
$recent_checkins_list = [];

// Only fetch if a valid site filter context exists
if ($site_filter_id !== null || ($site_filter_id === 'all' && $user_can_select_sites)) {

    // Stat 1: Today's Check-ins
    $todays_checkins = getTodaysCheckinCount($pdo, $site_filter_id);

    // Stat 2: Check-ins Last Hour
    $checkins_last_hour = getLastHourCheckinCount($pdo, $site_filter_id);

    // Stat 3: Check-ins Last 7 Days
    $checkins_last_7_days = getLastSevenDaysCheckinCount($pdo, $site_filter_id);

    // --- Recent Check-ins List ---
    // Pass the validated $active_question_columns to the function
    $recent_checkins_list = getRecentCheckins($pdo, $site_filter_id, $active_question_columns, 5);
    // --- End Recent Check-ins ---

    // --- Check-ins Over Time Data ---
    $daily_checkin_counts_raw = getDailyCheckinCountsLast7Days($pdo, $site_filter_id);
    $chart_time_labels = [];
    $chart_time_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date_obj = new DateTime("-{$i} days");
        $date_key = $date_obj->format('Y-m-d');
        $chart_time_labels[] = $date_obj->format('M j'); // e.g., Apr 16
        $chart_time_data[] = $daily_checkin_counts_raw[$date_key] ?? 0; // Use count if exists, else 0
    }
    $chart_time_labels_json = json_encode($chart_time_labels);
    $chart_time_data_json = json_encode($chart_time_data);
    // --- End Check-ins Over Time Data ---

} else {
    // If $site_filter_id is null (e.g., supervisor with no site assigned), stats remain at default 0/[]
    $dashboard_error .= " Cannot load dashboard data without a valid site context.";
    // Initialize chart data arrays as empty JSON for JS safety
    $chart_time_labels_json = '[]';
    $chart_time_data_json = '[]';
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
                 <p class="data-context-info" class="text-end small text-muted mb-3">
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
                    <!-- Card 2: Check-ins Last 7 Days -->
                    <div class="card">
                         <div class="card-header"><h2 class="card-title">Check-ins Last 7 Days</h2><div class="card-icon"><i class="fas fa-calendar-week"></i></div></div>
                         <div class="card-body"><div class="stat-value"><?php echo number_format($checkins_last_7_days); ?></div><div class="stat-label">Total check-ins in the past week</div></div>
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
                    <table class="table table-striped table-hover">
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
                                        <td class="small"><?php echo htmlspecialchars($details_summary); ?></td>
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
                                    <td colspan="<?php echo $fixed_cols_dash; ?>" class="text-center">No recent check-ins found.</td>
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
                        <div class="chart-header" class="d-flex justify-content-between align-items-center">
                            <h2 class="chart-title" class="mb-0">Question Responses</h2>
                            <div class="chart-timeframe-selector">
                                <label for="responses-timeframe" class="small me-1">Timeframe:</label>
                                <select id="responses-timeframe" name="responses_timeframe" class="form-select form-select-sm">
                                    <option value="today" selected>Today</option>
                                    <option value="last_7_days">Last 7 Days</option>
                                    <option value="last_30_days">Last 30 Days</option>
                                    <option value="last_365_days">Last Year</option>
                                </select>
                            </div>
                        </div>
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
        // Use data embedded by PHP
        const timeLabelsDash = <?php echo $chart_time_labels_json ?? '[]'; ?>;
        const timeDataDash = <?php echo $chart_time_data_json ?? '[]'; ?>;

        if (timeLabelsDash.length > 0 && timeDataDash.length > 0) {
            new Chart(ctxTimeDash, { type: 'line', data: { labels: timeLabelsDash, datasets: [{ label: 'Check-ins', data: timeDataDash, tension: 0.1, borderColor: 'var(--color-primary)', backgroundColor: 'rgba(30, 58, 138, 0.1)', fill: true }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, suggestedMax: Math.max(...timeDataDash) + 1 } } } }); // Added suggestedMax
        } else {
             const ctx = ctxTimeDash.getContext('2d');
             ctx.textAlign = 'center'; ctx.fillStyle = 'var(--color-gray)';
             ctx.fillText('No check-in data available for chart.', ctxTimeDash.canvas.width / 2, ctxTimeDash.canvas.height / 2);
        }
    }

    // Chart 2: Question Responses (Dynamic Update)
    const ctxQuestionsDash = document.getElementById('responsesChart');
    const timeframeSelector = document.getElementById('responses-timeframe');
    const siteSelector = document.getElementById('site-select'); // Get site selector
    let responsesChartInstance = null; // Variable to hold the chart instance

    // Function to display messages on the chart canvas
    function displayChartMessage(canvasContext, message) {
        const canvas = canvasContext.canvas;
        canvasContext.clearRect(0, 0, canvas.width, canvas.height); // Clear previous drawing
        canvasContext.save();
        canvasContext.textAlign = 'center';
        canvasContext.textBaseline = 'middle';
        canvasContext.fillStyle = 'var(--color-gray)';
        canvasContext.fillText(message, canvas.width / 2, canvas.height / 2);
        canvasContext.restore();
    }

    // Function to fetch and update the responses chart
    async function updateResponsesChart() {
        if (!ctxQuestionsDash || !responsesChartInstance) return; // Ensure canvas and chart instance exist

        const selectedTimeframe = timeframeSelector ? timeframeSelector.value : 'today';
        // Get site_id carefully, considering it might not exist or be 'all'
        const selectedSiteId = siteSelector ? siteSelector.value : '<?php echo $site_filter_id ?? "all"; ?>'; // Use PHP value as fallback

        // If no valid site context (null from PHP or empty string from selector), display message and exit
        if (selectedSiteId === null || selectedSiteId === '') {
             console.log("No valid site selected. Cannot update responses chart.");
             displayChartMessage(ctxQuestionsDash.getContext('2d'), 'Select a site to view question responses.');
             return;
        }

        console.log(`Updating responses chart for site: ${selectedSiteId}, timeframe: ${selectedTimeframe}`);
        displayChartMessage(ctxQuestionsDash.getContext('2d'), 'Loading data...'); // Show loading message

        try {
            const formData = new FormData();
            formData.append('action', 'get_question_responses_data');
            formData.append('site_id', selectedSiteId);
            formData.append('time_frame', selectedTimeframe);

            const response = await fetch('ajax_report_handler.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.data) {
                if (result.data.labels && result.data.labels.length > 0) {
                    // Update chart data
                    responsesChartInstance.data.labels = result.data.labels;
                    responsesChartInstance.data.datasets[0].data = result.data.data;
                    responsesChartInstance.update();
                    console.log("Responses chart updated successfully.");
                } else {
                    // No data returned, display message
                    displayChartMessage(ctxQuestionsDash.getContext('2d'), 'No question response data found for this period.');
                    // Clear existing data
                    responsesChartInstance.data.labels = [];
                    responsesChartInstance.data.datasets[0].data = [];
                    responsesChartInstance.update();
                }
            } else {
                throw new Error(result.message || 'Failed to fetch chart data.');
            }

        } catch (error) {
            console.error('Error updating responses chart:', error);
            displayChartMessage(ctxQuestionsDash.getContext('2d'), 'Error loading chart data.');
            // Optionally clear data on error
             responsesChartInstance.data.labels = [];
             responsesChartInstance.data.datasets[0].data = [];
             responsesChartInstance.update();
        }
    }

    if (ctxQuestionsDash) {
        // Initial chart setup with empty data
        const initialLabels = <?php echo $chart_labels_json ?? '[]'; ?>; // Use PHP labels for initial structure if available
        responsesChartInstance = new Chart(ctxQuestionsDash, {
            type: 'pie',
            data: {
                labels: initialLabels, // Start with labels if known, data loaded async
                datasets: [{
                    label: 'Yes Answers',
                    data: [], // Start with empty data
                    backgroundColor: ['rgba(255, 107, 53, 0.7)','rgba(30, 58, 138, 0.7)','rgba(75, 192, 192, 0.7)','rgba(255, 205, 86, 0.7)','rgba(153, 102, 255, 0.7)'], // Add more colors if needed
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                animation: {
                    duration: 500 // Optional: add a small animation duration
                }
            }
        });

        // Add event listener to the timeframe selector
        if (timeframeSelector) {
            timeframeSelector.addEventListener('change', updateResponsesChart);
        } else {
             console.error("Timeframe selector #responses-timeframe not found.");
        }

        // Add event listener to the site selector as well, to refresh chart on site change
         if (siteSelector) {
             // The site selector reloads the page, so we don't need a JS listener here.
             // The initial load handles the site change.
         }

        // Initial data load
        // Use setTimeout to ensure the chart is fully rendered before the first data load attempt
        setTimeout(updateResponsesChart, 100);

    } else {
        console.error("Canvas element #responsesChart not found.");
    }
    // --- End Chart Initialization ---

}); // End DOMContentLoaded
</script>
<script>
// --- Specific Handler for Dashboard Button ---
document.addEventListener('DOMContentLoaded', function() {
    const dashboardButton = document.getElementById('manual-checkin-link-dashboard');
    const siteSelectorForButton = document.getElementById('site-select'); // Check if dropdown exists

    if (dashboardButton) {
        console.log("Attaching specific listener to #manual-checkin-link-dashboard");
        dashboardButton.addEventListener('click', function(event) {
            event.preventDefault(); // Stop default '#' navigation
            console.log("Dashboard button clicked!");

            let siteValue = 'all'; // Default assumption
            if (siteSelectorForButton) { // Check if dropdown exists on this page load
                siteValue = siteSelectorForButton.value;
                console.log("Dropdown value:", siteValue);
            } else {
                 console.log("No site dropdown found, assuming direct action needed (shouldn't happen for Admin/Director triggering this ideally).");
                 // If an Admin/Director somehow clicks this WITHOUT the dropdown,
                 // perhaps we should still force the modal? Or rely on header.php setting href?
                 // Forcing modal for safety if dropdown missing for Admin/Director:
                  const currentUserRoleCheck = '<?php echo $_SESSION['active_role'] ?? ''; ?>';
                  if(currentUserRoleCheck === 'administrator' || currentUserRoleCheck === 'director'){
                     console.log("Forcing modal trigger because dropdown is missing for Admin/Director.");
                      $('#selectSiteModal').modal('show');
                      return; // Stop further processing
                  } else {
                      // If not admin/director and no dropdown, try the href like before
                       const directHrefCheck = this.getAttribute('href');
                       if (directHrefCheck && directHrefCheck !== '#') {
                           window.location.href = directHrefCheck;
                       } else {
                           alert('Cannot determine site context.');
                       }
                       return; // Stop further processing
                  }
            }


            if (siteValue === 'all') {
                console.log("Site is 'all', attempting to show modal manually.");
                // Directly call Bootstrap's modal function using jQuery
                $('#selectSiteModal').modal('show');
            } else if (siteValue && siteValue !== 'all' && siteValue !== '') {
                // Navigate if specific site selected
                const siteId = encodeURIComponent(siteValue);
                console.log("Navigating to checkin.php?manual_site_id=", siteId);
                window.location.href = `checkin.php?manual_site_id=${siteId}`;
            } else {
                 // Handle case like '-- Select --' if that's an option
                 alert('Please select a specific site from the dropdown.');
            }
        });
    } else {
        console.log("Dashboard button #manual-checkin-link-dashboard not found.");
    }
});
</script>
<?php
// --- Include Footer ---
require_once 'includes/footer.php';
?>