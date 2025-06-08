<?php
/*
 * File: dashboard.php
 * Path: /dashboard.php
 * Created: 2024-08-01 12:15:00 MST
 * 
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
// error_log("Dashboard Debug - Filter ID: {$site_filter_id} - Active VALID question columns for data fetch (prefixed): " . print_r($active_question_columns, true));


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
                                 class="btn btn-secondary manual-checkin-trigger"
                                 data-toggle="modal" data-target="#selectSiteModal">
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
                        <tbody id="recent-checkins-tbody">
                             <?php if (!empty($recent_checkins_list)): ?>
                                 <?php foreach ($recent_checkins_list as $checkin):
                                     // --- New Details Summary Logic using dynamic_answers ---
                                     $details_parts = [];
                                     if (isset($checkin['dynamic_answers']) && is_array($checkin['dynamic_answers'])) {
                                         foreach ($checkin['dynamic_answers'] as $answer_item) {
                                             if (isset($answer_item['question_text']) && isset($answer_item['answer_text'])) {
                                                 // For brevity, we might only show 'YES' answers or a summary
                                                 // For now, let's list them if the answer is not empty or 'NO' (assuming 'YES'/'NO' or free text)
                                                 if (!empty($answer_item['answer_text']) && strtoupper($answer_item['answer_text']) !== 'NO') {
                                                     $details_parts[] = htmlspecialchars($answer_item['question_text']) . ': ' . htmlspecialchars($answer_item['answer_text']);
                                                 }
                                             }
                                         }
                                     }
                                     $details_summary = !empty($details_parts) ? implode('<br>', $details_parts) : 'N/A';
                                     // --- End New Details Summary Logic ---
                                 ?>
                                     <?php
                                     $row_class = '';
                                     if (isset($checkin['missing_data_status'])) {
                                         if ($checkin['missing_data_status'] === 'complete') {
                                             $row_class = 'table-success';
                                         } elseif ($checkin['missing_data_status'] === 'missing') {
                                             $row_class = 'table-danger';
                                         }
                                     }
                                     ?>
                                     <tr class="<?php echo $row_class; ?>">
                                         <td><?php echo htmlspecialchars($checkin['first_name'] . ' ' . $checkin['last_name']); ?></td>
                                         <?php if($site_filter_id === 'all'): // Use colon syntax for clarity ?>
                                             <td><?php echo htmlspecialchars($checkin['site_name']); ?></td>
                                         <?php endif; ?>
                                         <td><?php echo date('h:i A', strtotime($checkin['check_in_time'])); ?></td>
                                         <td class="small"><?php echo $details_summary; // Already HTML escaped in loop or is 'N/A' ?></td>
                                         <td><button type="button" class="btn btn-outline btn-sm view-checkin-details" data-toggle="modal" data-target="#checkinDetailsModal" data-checkin-id="<?php echo $checkin['id']; ?>"><i class="fas fa-eye"></i> View</button></td>
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
                <div class="charts-row row"> <!-- Added 'row' class -->
                    <div class="chart-container col-md-6"> <!-- Added 'col-md-6' class -->
                        <div class="chart-header"><h2 class="chart-title">Check-ins Over Time</h2></div>
                        <div class="chart-placeholder" style="height: 500px;">
                           <canvas id="checkinsChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-container col-md-6"> <!-- Added 'col-md-6' class -->
                        <div class="chart-header d-flex justify-content-between align-items-center">
                            <h2 class="chart-title mb-0">Question Responses</h2>
                            <div class="chart-controls d-flex align-items-center">
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
                        </div>
                         <div class="chart-placeholder">
                           <canvas id="responsesChart"></canvas>
                        </div>
                    </div>
                </div> <!-- /.charts-row -->
            </div> <!-- /#dashboard-view -->

<!-- Include Modals -->
<?php require_once 'includes/modals/checkin_details_modal.php'; ?>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script> <!-- Using specific v3 -->
<!-- JavaScript for updating links and charts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Consistent Manual Check-in Button Logic ---
    let siteSelector = document.getElementById('site-select'); // Changed const to let
    const manualCheckinButtons = document.querySelectorAll('.manual-checkin-trigger'); // Select BOTH buttons

    if (manualCheckinButtons.length > 0) {
        manualCheckinButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent default link behavior
                console.log("Manual Check-in button clicked.");

                let selectedSiteIdValue = null;
                if (siteSelector) {
                    selectedSiteIdValue = siteSelector.value;
                }

                // If a specific site is selected in the dropdown, navigate directly
                if (selectedSiteIdValue && selectedSiteIdValue !== 'all') {
                    console.log("Site selected in dropdown: Specific Site ID", selectedSiteIdValue, ". Navigating directly.");
                    const siteId = encodeURIComponent(selectedSiteIdValue);
                    window.location.href = `checkin.php?manual_site_id=${siteId}`;
                } else {
                    // If 'All Sites' is selected, or no site selector dropdown exists (e.g. supervisor view),
                    // or if the dropdown has no value, show the site selection modal.
                    console.log("No specific site selected in dropdown, or dropdown not present. Triggering site selection modal.");
                    // Check if jQuery and Bootstrap modal function are available
                    if (typeof $ !== 'undefined' && typeof $('#selectSiteModal').modal === 'function') {
                        $('#selectSiteModal').modal('show');
                    } else {
                        console.error("jQuery or Bootstrap modal function not available. Cannot show modal.");
                        // Fallback alert if modal cannot be shown
                        alert('Site selection is required. Please select a site.');
                    }
                }
            });
        });
    }
    // --- End Consistent Manual Check-in Logic ---

    // --- Function to attach event listeners to "View" buttons ---
    function attachViewDetailsListeners() {
        const viewDetailsButtons = document.querySelectorAll('#recent-checkins-tbody .view-checkin-details');
        viewDetailsButtons.forEach(button => {
            // Remove any existing listener to prevent duplicates if re-attaching
            button.removeEventListener('click', handleViewDetailsClick);
            button.addEventListener('click', handleViewDetailsClick);
        });
    }

    function handleViewDetailsClick() {
        const checkinId = this.dataset.checkinId;
        if (checkinId) {
            if (typeof openCheckinDetailsModal === 'function') {
                openCheckinDetailsModal(checkinId);
            } else {
                console.error('Error: openCheckinDetailsModal function not found.');
                alert('Error: Could not load check-in details function.');
            }
        } else {
            console.error('Error: data-checkin-id attribute not found on button.');
        }
    }
    // Initial attachment
    attachViewDetailsListeners();
    // --- End "View" button listener logic ---

    // --- AJAX Auto-Refresh for Recent Check-ins Table ---
    const recentCheckinsTbody = document.getElementById('recent-checkins-tbody');
    const siteSelectForRefresh = document.getElementById('site-select');

    async function fetchAndUpdateRecentCheckins() {
        if (!recentCheckinsTbody) return;

        let currentSiteId = '<?php echo $site_filter_id ?? "all"; ?>'; // Default to PHP's initial filter
        if (siteSelectForRefresh) {
            currentSiteId = siteSelectForRefresh.value;
        }
        
        console.log(`Refreshing recent check-ins for site_id: ${currentSiteId}`);

        try {
            const response = await fetch(`ajax_handlers/get_recent_checkins_handler.php?site_id=${encodeURIComponent(currentSiteId)}`);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const newTableRowsHtml = await response.text();
            recentCheckinsTbody.innerHTML = newTableRowsHtml;
            attachViewDetailsListeners(); // Re-attach listeners to new content
            console.log("Recent check-ins table updated.");
        } catch (error) {
            console.error("Error fetching recent check-ins:", error);
            // Optionally, display an error message in the table
            recentCheckinsTbody.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading recent check-ins.</td></tr>';
        }
    }

    // Set interval to refresh every 30 seconds (30000 milliseconds)
    setInterval(fetchAndUpdateRecentCheckins, 30000);
    // --- End AJAX Auto-Refresh ---

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
    // const questionResponseSelector = document.getElementById('questionResponseSelector'); // Removed
    // siteSelector is declared at line 372: let siteSelector = document.getElementById('site-select');
    let responsesChartInstance = null;
    let fullQuestionData = {}; // This will store the raw data from AJAX: { "Question Text 1": { "Answer A": countA, ... } }

    // Function to display messages on the chart canvas
    function displayChartMessage(canvasContext, message) {
        if (!canvasContext) {
            console.warn("displayChartMessage: canvasContext is null");
            return;
        }
        const canvas = canvasContext.canvas;
        canvasContext.clearRect(0, 0, canvas.width, canvas.height);
        canvasContext.save();
        canvasContext.textAlign = 'center';
        canvasContext.textBaseline = 'middle';
        canvasContext.fillStyle = 'var(--color-gray)';
        canvasContext.fillText(message, canvas.width / 2, canvas.height / 2);
        canvasContext.restore();
    }

    // Function to fetch and update the responses chart
    async function updateResponsesChart() { // selectedQuestionText parameter removed
        if (!ctxQuestionsDash || !responsesChartInstance) {
            console.warn("Chart context or instance not ready for updateResponsesChart");
            return;
        }

        const currentSiteSelector = document.getElementById('site-select'); // Always get the latest reference
        const selectedTimeframe = timeframeSelector ? timeframeSelector.value : 'today';
        const selectedSiteId = currentSiteSelector ? currentSiteSelector.value : '<?php echo $site_filter_id ?? "all"; ?>';

        // If no site is selected (e.g. initial load for a user who must select one, or error), display message.
        // Allow 'all' to proceed to fetch data.
        if (selectedSiteId === null || selectedSiteId === '') {
            let message = 'Select a site to view question responses.';
            console.log("Dashboard chart: " + message);
            displayChartMessage(ctxQuestionsDash.getContext('2d'), message);
            fullQuestionData = {}; // Clear any old data
            if (responsesChartInstance.options && responsesChartInstance.options.plugins && responsesChartInstance.options.plugins.title) {
                responsesChartInstance.options.plugins.title.text = message;
            }
            responsesChartInstance.data.labels = [];
            responsesChartInstance.data.datasets[0].data = [];
            responsesChartInstance.update();
            return;
        }

        // No longer selecting a question from a dropdown, will always use the first from fetched data.
        // The logic to use an existing selectedQuestionText or fullQuestionData is removed.
        // We will always fetch.

        console.log(`Fetching responses data for site: ${selectedSiteId}, timeframe: ${selectedTimeframe}`);
        displayChartMessage(ctxQuestionsDash.getContext('2d'), 'Loading data...');
        // Removed: if(questionResponseSelector) questionResponseSelector.innerHTML = '<option value="">Loading Questions...</option>';

        try {
            const formData = new FormData();
            formData.append('site_id', selectedSiteId);
            formData.append('time_frame', selectedTimeframe);

            const response = await fetch('ajax_report_handler.php?action=get_question_responses_data', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            console.log('Question Responses AJAX Result:', result);

            fullQuestionData = {}; // Reset before populating
            if (result.success && result.data && typeof result.data === 'object' && result.data !== null) {
                const dataForChart = result.data;
                const chartLabels = [];
                const chartDataPoints = [];

                // Check if dataForChart is not empty and is an object
                if (dataForChart && typeof dataForChart === 'object' && Object.keys(dataForChart).length > 0) {
                    for (const questionText in dataForChart) {
                        // Ensure it's a direct property and not from prototype chain
                        if (Object.prototype.hasOwnProperty.call(dataForChart, questionText)) {
                            const answerObject = dataForChart[questionText];
                            chartLabels.push(questionText);
                            
                            // Get "Yes" count for the current question
                            const yesCount = (answerObject && typeof answerObject.Yes !== 'undefined') ? Number(answerObject.Yes) : 0;
                            chartDataPoints.push(yesCount);
                        }
                    }

                    responsesChartInstance.data.labels = chartLabels;
                    responsesChartInstance.data.datasets[0].data = chartDataPoints;
                    if (responsesChartInstance.options.plugins.title) {
                        responsesChartInstance.options.plugins.title.text = 'Overall Question Responses Summary';
                        responsesChartInstance.options.plugins.title.display = true;
                    }
                    console.log("Responses chart updated with overall summary.");

                } else { // Handles cases where result.data is empty {}
                    displayChartMessage(ctxQuestionsDash.getContext('2d'), 'No question response data found for this period.');
                    if (responsesChartInstance.options.plugins.title) {
                        responsesChartInstance.options.plugins.title.text = 'No question response data found';
                        responsesChartInstance.options.plugins.title.display = true;
                    }
                    responsesChartInstance.data.labels = [];
                    responsesChartInstance.data.datasets[0].data = [];
                    console.log("No question data available to display for overall summary.");
                }
            } else {
                // Removed: if(questionResponseSelector) questionResponseSelector.innerHTML = '<option value="">Error Loading Questions</option>';
                const errorMessage = result.message || 'Failed to fetch chart data or data is empty/invalid.';
                displayChartMessage(ctxQuestionsDash.getContext('2d'), errorMessage);
                if (responsesChartInstance.options.plugins.title) {
                    responsesChartInstance.options.plugins.title.text = 'No question response data found';
                    responsesChartInstance.options.plugins.title.display = true;
                }
                responsesChartInstance.data.labels = [];
                responsesChartInstance.data.datasets[0].data = [];
                console.error(errorMessage, result);
            }
        } catch (error) {
            // Removed: if(questionResponseSelector) questionResponseSelector.innerHTML = '<option value="">Error Loading Questions</option>';
            console.error('Error in updateResponsesChart:', error);
            displayChartMessage(ctxQuestionsDash.getContext('2d'), 'Error loading chart data.');
            if (responsesChartInstance.options.plugins.title) {
                responsesChartInstance.options.plugins.title.text = 'Error loading data';
            }
            responsesChartInstance.data.labels = [];
            responsesChartInstance.data.datasets[0].data = [];
        } finally {
            if(responsesChartInstance) responsesChartInstance.update();
        }
    }

    if (ctxQuestionsDash) {
        responsesChartInstance = new Chart(ctxQuestionsDash, {
            type: 'pie',
            data: {
                labels: [],
                datasets: [{
                    label: 'Responses',
                    data: [],
                    backgroundColor: ['rgba(255, 107, 53, 0.7)','rgba(30, 58, 138, 0.7)','rgba(75, 192, 192, 0.7)','rgba(255, 205, 86, 0.7)','rgba(153, 102, 255, 0.7)', 'rgba(255, 159, 64, 0.7)', 'rgba(54, 162, 235, 0.7)'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Loading Question Responses...'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed !== null && typeof context.parsed !== 'undefined') {
                                    label += context.parsed;
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });

        if (timeframeSelector) {
            timeframeSelector.addEventListener('change', () => updateResponsesChart());
        }
        // Removed: questionResponseSelector event listener
        
        // siteSelector (declared at line 372) is used for page reloads,
        // so its 'change' event directly reloads the page.
        // The initial chart update is handled below.

        const initialSiteSelector = document.getElementById('site-select'); // Get current reference for initial load
        const initialSiteId = initialSiteSelector ? initialSiteSelector.value : '<?php echo $site_filter_id ?? "all"; ?>';

        // Allow 'all' to trigger an initial data load attempt
        if (initialSiteId !== null && initialSiteId !== '') { // If 'all' or a specific site ID is present
             updateResponsesChart(); // Call without arguments
        } else { // Only if initialSiteId is truly null or empty (should be rare with current site filter logic)
            let initialMessage = 'Select a site to view question responses.';
            displayChartMessage(ctxQuestionsDash.getContext('2d'), initialMessage);
            // Removed: if(questionResponseSelector) questionResponseSelector.innerHTML = ...
            if (responsesChartInstance && responsesChartInstance.options.plugins.title) {
                responsesChartInstance.options.plugins.title.text = initialMessage;
                responsesChartInstance.update();
            }
        }
    } else {
        console.warn("ctxQuestionsDash not found, chart cannot be initialized.");
    }
});
</script>
<script>
// Define the flag if it doesn't exist
if (typeof window.dashboardManualCheckinInitialized === 'undefined') {
    window.dashboardManualCheckinInitialized = false;
}

document.addEventListener('DOMContentLoaded', function() {
    // Check the flag
    if (window.dashboardManualCheckinInitialized) {
        console.log('Dashboard manual check-in script already initialized. Skipping.'); // Optional log
        return;
    }

    // Original script content starts here (with existing console logs)
    const dashboardManualCheckinButton = document.getElementById('manual-checkin-link-dashboard');
    if (dashboardManualCheckinButton) {
        console.log('Attempting to attach click listener to dashboardManualCheckinButton.');
        dashboardManualCheckinButton.addEventListener('click', function(event) {
            console.log('Dashboard manual check-in button clicked, preventDefault called.');
            event.preventDefault();
        });
    } else {
        console.error('Dashboard manual check-in button (manual-checkin-link-dashboard) not found.');
    }

    const siteModal = document.getElementById('selectSiteModal');
    const dashboardButtonToBlur = document.getElementById('manual-checkin-link-dashboard');

    if (siteModal && dashboardButtonToBlur) {
        // Using jQuery for Bootstrap event handling as it's often used with Bootstrap
        // If jQuery is not available or preferred, this would need to be vanilla JS
        // For now, assume jQuery is available as Bootstrap v4 often uses it.
        $('#selectSiteModal').on('hidden.bs.modal', function () {
            console.log('selectSiteModal hidden, attempting to blur dashboardManualCheckinButton via setTimeout.'); // Modified log
            setTimeout(function() {
                dashboardButtonToBlur.blur();
                console.log('Blurred dashboardManualCheckinButton after timeout.'); // New log
            }, 0); // Using a 0ms timeout
        });
    } else {
        if (!siteModal) console.error('Modal with ID selectSiteModal not found.');
        if (!dashboardButtonToBlur) console.error('Button with ID manual-checkin-link-dashboard not found for blur.');
    }
    // End of original script content

    // Set the flag to true after successful initialization
    console.log('Dashboard manual check-in script initialized.'); // Optional log
    window.dashboardManualCheckinInitialized = true;
});
</script>

<?php
// --- Include Footer ---
require_once 'includes/footer.php'; // Provides closing tags, modals
?>