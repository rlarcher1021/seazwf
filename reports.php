<?php
/*
 * File: reports.php
 * Path: /reports.php
 * Created: 2024-08-01 13:00:00 MST

 * Updated: 2025-04-08 - Corrected dynamic column name handling for reports.
 * Description: Provides reporting capabilities for check-in data.
 */
// AJAX Handler for Custom Report Builder has been moved to ajax_report_handler.php

// --- Normal Page Execution Starts Below ---
// Make sure session_start() and includes are called *again* here if they are not already outside the IF block
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // Must be called for the main page rendering
}
// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication & database connection
require_once 'includes/db_connect.php'; // Provides $pdo
require_once 'includes/auth.php';       // Ensures user is logged in, provides $_SESSION['active_role'] etc.
require_once 'includes/utils.php';      // Provides format_base_name_for_display, sanitize_title_to_base_name
require_once 'includes/data_access/site_data.php'; // Provides site fetching functions
require_once 'includes/data_access/question_data.php'; // Provides question fetching functions
require_once 'includes/data_access/checkin_data.php'; // Provides check-in fetching functions

// --- Role Check ---
$allowedRoles = ['site_supervisor', 'director', 'administrator'];
if (!isset($_SESSION['active_role']) || !in_array($_SESSION['active_role'], $allowedRoles)) {
    $_SESSION['flash_message'] = "Access Denied. You do not have permission to view reports."; // Use standard flash keys
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Page Setup ---
$pageTitle = "Reports";
$report_error_message = '';
$results_per_page = 25; // Configurable results per page

// --- Site Selection Logic ---
$sites = [];
$selected_site_id = null;
$user_can_select_sites = ($_SESSION['active_role'] === 'administrator' || $_SESSION['active_role'] === 'director'); // Corrected variable name

// Fetch sites using data access functions
if ($user_can_select_sites) {
    $sites = getAllActiveSites($pdo);
    if ($sites === []) { // Check explicitly for empty array which indicates potential error
        $report_error_message .= " Error loading site list.";
    }
} elseif (isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== null) {
    $site_data = getActiveSiteById($pdo, $_SESSION['active_site_id']);
    if ($site_data) {
        $sites = [$site_data]; // Put the single site into an array format
    } else {
        $sites = [];
        $report_error_message .= " Your assigned site is currently inactive or could not be found.";
    }
} else {
    $sites = [];
    if ($_SESSION['active_role'] == 'site_supervisor') {
        $report_error_message .= " No site is assigned to your account.";
    }
}

// Determine selected site ID from GET or defaults
$site_id_from_get = $_GET['site_id'] ?? null;

if ($site_id_from_get !== null) {
    if ($site_id_from_get === 'all' && $user_can_select_sites) {
        $selected_site_id = 'all';
    } elseif (is_numeric($site_id_from_get)) {
        $potential_site_id = intval($site_id_from_get);
        $is_valid_selection = false;
        // Check against available sites for the user
        if ($user_can_select_sites) { foreach ($sites as $site) { if ($site['id'] == $potential_site_id) {$is_valid_selection = true; break;} } }
        elseif (!empty($sites) && $sites[0]['id'] == $potential_site_id) { $is_valid_selection = true; } // Supervisor can only select their own site

        if ($is_valid_selection) {
            $selected_site_id = $potential_site_id;
        } else { // Fallback for invalid ID passed in GET
             $report_error_message .= " Invalid site selected in filter.";
             if ($user_can_select_sites) $selected_site_id = 'all';
             elseif (!empty($sites)) $selected_site_id = $sites[0]['id'];
             else $selected_site_id = null; // No valid site context
        }
    } else { // Fallback for invalid format (e.g., non-numeric other than 'all')
         $report_error_message .= " Invalid site filter format.";
         if ($user_can_select_sites) $selected_site_id = 'all';
         elseif (!empty($sites)) $selected_site_id = $sites[0]['id'];
         else $selected_site_id = null;
    }
} else { // Default if no GET param
    if ($user_can_select_sites) $selected_site_id = 'all';
    elseif (!empty($sites)) $selected_site_id = $sites[0]['id'];
    else $selected_site_id = null; // No site available/selected
}


// --- Date Range Filter Logic ---
$default_end_date = date('Y-m-d');
$default_start_date = date('Y-m-d', strtotime('-29 days')); // Default to last 30 days
$filter_start_date = $_GET['start_date'] ?? $default_start_date;
$filter_end_date = $_GET['end_date'] ?? $default_end_date;
// Basic validation for date format
$filter_start_date = preg_match("/^\d{4}-\d{2}-\d{2}$/", $filter_start_date) ? $filter_start_date : $default_start_date;
$filter_end_date = preg_match("/^\d{4}-\d{2}-\d{2}$/", $filter_end_date) ? $filter_end_date : $default_end_date;


// --- Fetch Active Questions & Columns Relevant to Filter ---
$report_question_columns = []; // Stores sanitized column names WITH prefix (q_...) for data access
$report_chart_labels = [];     // Stores FORMATTED labels (e.g., "Needs Assistance") for chart display
$report_column_to_label_map = []; // Optional: Map prefixed name -> formatted label if needed elsewhere

// Fetch active question base titles using data access function
$report_base_titles = getActiveQuestionTitles($pdo, $selected_site_id);

if ($report_base_titles === []) {
    if ($selected_site_id !== null) { // Avoid error if no site context
        $report_error_message .= " Error loading question details or no active questions found for report.";
    }
} else {
    foreach ($report_base_titles as $base_title) {
        if (!empty($base_title)) {
            $sanitized_base = sanitize_title_to_base_name($base_title); // Use utility function
            if (!empty($sanitized_base)) {
                $prefixed_col_name = 'q_' . $sanitized_base;
                // Validate column name format before adding
                if (preg_match('/^q_[a-z0-9_]+$/', $prefixed_col_name) && strlen($prefixed_col_name) <= 64) {
                    $formatted_label = format_base_name_for_display($sanitized_base); // Use utility function
                    $report_question_columns[] = $prefixed_col_name; // Store validated prefixed name
                    $report_chart_labels[] = $formatted_label; // Store formatted label for charts
                    $report_column_to_label_map[$prefixed_col_name] = $formatted_label; // Map for custom builder options
                } else {
                     error_log("Reports Warning: Generated prefixed column name '{$prefixed_col_name}' from base '{$base_title}' is invalid and was skipped.");
                }
            } else {
                 error_log("Reports Warning: Sanitized base title for '{$base_title}' resulted in empty string.");
            }
        }
    }
}

// Prepare JSON for JavaScript Chart Labels (using the formatted labels)
$chart_labels_json = json_encode($report_chart_labels);

// Log the *validated* prefixed column names (useful for debugging data fetching)
error_log("Reports Debug - Filter ID: {$selected_site_id} - Active VALID question columns for data fetch (prefixed): " . print_r($report_question_columns, true));

// --- Fetch Report Data ---
$report_data = [];
$total_records = 0;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_pages = 0;
$offset = ($current_page - 1) * $results_per_page;

// Build WHERE clause and params
$sql_where_clauses = [];
$params_data = [];
if ($selected_site_id !== null && $selected_site_id !== 'all') {
    $sql_where_clauses[] = "ci.site_id = :site_id_filter";
    $params_data[':site_id_filter'] = $selected_site_id;
}
if (!empty($filter_start_date) && ($start_timestamp = strtotime($filter_start_date)) !== false) {
    $sql_where_clauses[] = "ci.check_in_time >= :start_date";
    $params_data[':start_date'] = date('Y-m-d 00:00:00', $start_timestamp);
}
if (!empty($filter_end_date) && ($end_timestamp = strtotime($filter_end_date)) !== false) {
    $sql_where_clauses[] = "ci.check_in_time <= :end_date";
    $params_data[':end_date'] = date('Y-m-d 23:59:59', $end_timestamp);
}
$where_sql = !empty($sql_where_clauses) ? 'WHERE ' . implode(' AND ', $sql_where_clauses) : '';

// Only attempt fetch if a site context is valid
if ($selected_site_id !== null || ($selected_site_id === 'all' && $user_can_select_sites)) {
    // Use data access functions for count and data
    $total_records = getCheckinCountByFilters($pdo, $selected_site_id, $filter_start_date, $filter_end_date);

    if ($total_records > 0) {
        $total_pages = ceil($total_records / $results_per_page);
        // Validate current page against total pages
        $current_page = max(1, min($current_page, $total_pages));
        $offset = ($current_page - 1) * $results_per_page;

        // Fetch paginated data using the function, passing validated question columns
        $report_data = getCheckinsByFiltersPaginated(
            $pdo,
            $selected_site_id,
            $filter_start_date,
            $filter_end_date,
            $report_question_columns, // Pass the validated list
            $results_per_page,
            $offset
        );

        if ($report_data === []) { // Check if fetch failed within the function
             $report_error_message .= " Error fetching report data details.";
             // Reset pagination info if data fetch fails after count succeeded
             $total_records = 0; $total_pages = 0; $current_page = 1;
        }

    } else {
        // No records found based on filters
        $report_data = [];
        $total_pages = 0;
        $current_page = 1;
    }
} else {
     // Handle case where no valid site context exists (e.g., supervisor with no site)
     $report_data = []; $total_records = 0; $total_pages = 0; $current_page = 1;
     if (empty($report_error_message)) $report_error_message .= " Cannot fetch report data without a valid site context.";
}


// --- Prepare Data for Charts (Placeholder - adapt later) ---
$chart_labels_json = json_encode([]);
$chart_data_checkins_json = json_encode([]);
$chart_data_by_site_json = json_encode([]);
// Add logic here later to process $report_data or run separate aggregate queries for charts

// --- Include Header ---
require_once 'includes/header.php';
?>

            <!-- Page Header -->
            <div class="header">
               <!-- <h1 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>-->
                 <?php // Site selector dropdown ?>
                 <?php if ($user_can_select_sites || count($sites) > 0) : ?>
                 <div class="site-selector">
                    <label for="report-site-select">Site:</label>
                    <select id="report-site-select" name="site_id_selector" onchange="updateReportFilters()">
                        <?php if ($user_can_select_sites): ?>
                            <option value="all" <?php echo ($selected_site_id === 'all') ? 'selected' : ''; ?>>All Sites</option>
                        <?php endif; ?>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>" <?php echo ($selected_site_id == $site['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?>
                            </option>
                        <?php endforeach; ?>
                         <?php if (empty($sites) && !$user_can_select_sites): ?>
                              <option value="" disabled>No site assigned/available</option>
                          <?php endif; ?>
                    </select>
                </div>
                 <?php endif; ?>
            </div>

             <!-- Display Errors -->
             <?php if ($report_error_message): ?>
                <div class="message-area message-error"><?php echo htmlspecialchars($report_error_message); ?></div>
            <?php endif; ?>

            <!-- Report Filters Section -->
            <div class="content-section">
                <h2 class="section-title">Report Filters</h2>
                <form method="GET" action="reports.php" id="report-filter-form" class="filter-form">
                    <input type="hidden" name="site_id" id="filter_site_id" value="<?php echo htmlspecialchars($selected_site_id ?? 'all'); ?>">
                    <div class="form-group">
                        <label for="start_date" class="form-label">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date" class="form-label">End Date:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                     <div class="form-actions" style="grid-column: 1 / -1;"> <!-- Ensure actions span columns -->
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                        <?php
                           $export_params = http_build_query(array_filter([ // array_filter removes null/empty values
                               'site_id' => $selected_site_id,
                               'start_date' => $filter_start_date,
                               'end_date' => $filter_end_date
                           ]));
                        ?>
                        <a href="export_report.php?<?php echo $export_params; ?>"
                           class="btn btn-outline" target="_blank"
                           <?php if ($total_records === 0) echo 'style="pointer-events: none; opacity: 0.6;" title="No data to export"'; ?> >
                            <i class="fas fa-download"></i> Export to CSV
                        </a>
                    </div>
                </form>
            </div>

            <!-- Custom Report Builder Section -->
            <?php if ($user_can_select_sites): // Show only to Admin/Director ?>
            <div class="content-section" id="custom-report-builder">
                <h2 class="section-title">Custom Report Builder</h2>
                <form id="custom-report-form" class="settings-form" action="#" method="POST"> <!-- Use POST for AJAX, action="#" prevents default nav -->
                    <div class="form-group">
                        <label for="custom_metrics" class="form-label">Metrics:</label>
                        <select id="custom_metrics" name="metrics[]" class="form-control" multiple required size="5"> <!-- Added size attribute -->
                            <option value="total_checkins" selected>Total Check-ins</option>
                            <?php
                            // Dynamically populate with active questions for the current filter
                            // We use the $report_column_to_label_map created earlier which maps q_[base_name] => Formatted Label
                            if (!empty($report_column_to_label_map)) {
                                foreach ($report_column_to_label_map as $prefixed_col => $formatted_label) {
                                    // Value should be the *prefixed column name* for backend processing
                                    echo '<option value="' . htmlspecialchars($prefixed_col) . '">Q: ' . htmlspecialchars($formatted_label) . ' (Yes Count)</option>';
                                }
                            } else {
                                echo '<option value="" disabled>No questions active for current filter</option>';
                            }
                            ?>
                        </select>
                        <small class="form-text text-muted">Select one or more metrics (use Ctrl/Cmd to select multiple).</small>
                    </div>

                    <div class="form-group">
                        <label for="custom_group_by" class="form-label">Group By:</label>
                        <select id="custom_group_by" name="group_by" class="form-control" required>
                            <option value="none" selected>None (Overall Totals)</option>
                            <option value="day">Day</option>
                            <option value="week">Week</option>
                            <option value="month">Month</option>
                            <?php if ($selected_site_id === 'all'): ?>
                                <option value="site">Site</option>
                            <?php endif; ?>
                            <!-- <option value="question">Question (Compare Metrics)</option> <-- More complex, add later if needed -->
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="custom_output_type" class="form-label">Output Type:</label>
                        <select id="custom_output_type" name="output_type" class="form-control" required>
                            <option value="table" selected>Data Table</option>
                            <option value="bar">Bar Chart</option>
                            <option value="line">Line Chart</option>
                            <!-- Add Pie later if suitable -->
                        </select>
                         <small class="form-text text-muted">Line charts work best when grouping by time.</small>
                    </div>

                    <div class="form-actions" style="grid-column: 1 / -1;"> <!-- Ensure actions span columns -->
                        <button type="submit" id="generate-custom-report-btn" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i> Generate Report
                        </button>
                        <span id="custom-report-loading" style="display: none; margin-left: 10px;">
                            <i class="fas fa-spinner fa-spin"></i> Generating...
                        </span>
                    </div>
                </form>

                <!-- Area to display the generated report -->
                <div id="custom-report-output-area" class="content-section" style="margin-top: 20px; min-height: 100px; border: 1px dashed var(--color-gray-light); padding: 15px;">
                    <!-- Report will be loaded here via AJAX -->
                    <p style="text-align: center; color: var(--color-gray);">Select options above and click "Generate Report".</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Report Data Table Section -->
            <div class="content-section">
                 <h2 class="section-title">Check-in Data
                     <span style="font-weight: normal; font-size: 0.9em; color: var(--color-gray);">
                         (<?php echo htmlspecialchars($filter_start_date); ?> to <?php echo htmlspecialchars($filter_end_date); ?>)
                     </span>
                 </h2>

                 <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <?php if ($selected_site_id === 'all') echo '<th>Site</th>'; ?>
                                <th>Check-in Time</th>
                                <th>Client Email</th>
                                <th>Notified Staff</th>
                                <?php
                                // Dynamically generate question headers using the map (prefixed name => formatted label)
                                // Use $report_column_to_label_map which was populated alongside $report_question_columns
                                if (!empty($report_column_to_label_map)) {
                                    foreach ($report_column_to_label_map as $prefixed_col => $formatted_label) {
                                        echo "<th>" . htmlspecialchars($formatted_label) . "</th>";
                                    }
                                }
                                ?>
                                <!--<th>Actions</th>-->
                            </tr>
                        </thead>
                        <tbody>
                             <?php if (empty($report_data)): ?>
                                <tr>
                                     <?php
                                     // Calculate colspan dynamically
                                     $fixed_cols = 5; // ID, Name, Time, Email, Notified
                                     if ($selected_site_id === 'all') $fixed_cols++;
                                     $total_cols = $fixed_cols + count($report_question_columns);
                                     ?>
                                    <td colspan="<?php echo $total_cols; ?>" style="text-align: center;">No records found for the selected criteria.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                        <?php if ($selected_site_id === 'all'): ?>
                                            <td><?php echo htmlspecialchars($row['site_name']); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo date('Y-m-d H:i', strtotime($row['check_in_time'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['client_email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['notified_staff'] ?? 'N/A'); ?></td>
                                        <?php
                                        // Dynamically output question answers using the prefixed column names
                                        if (!empty($report_question_columns)) {
                                            foreach ($report_question_columns as $q_col_name) { // $q_col_name is now 'q_coffee' etc.
                                                // Check if column exists in the fetched row data using the prefixed name
                                                $answer = array_key_exists($q_col_name, $row) ? $row[$q_col_name] : null;
                                                echo "<td>" . htmlspecialchars($answer ?? '--') . "</td>";
                                            }
                                        }
                                        ?>
                                        <!--<td><button class="btn btn-outline btn-sm">View</button></td>-->
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                     <!-- Pagination -->
                     <?php if ($total_records > 0): ?>
                     <div class="table-footer">
                         <div>
                             <?php
                                $start_record = ($total_records > 0) ? $offset + 1 : 0;
                                $end_record = min($offset + $results_per_page, $total_records);
                                echo "Showing {$start_record} - {$end_record} of {$total_records} records";
                             ?>
                         </div>
                         <?php if ($total_pages > 1): ?>
                         <div class="table-pagination">
                               <?php
                                $query_params = array_filter([ 'site_id' => $selected_site_id, 'start_date' => $filter_start_date, 'end_date' => $filter_end_date ]);
                                $base_url = 'reports.php?' . http_build_query($query_params);
                               ?>
                              <a href="<?php echo $base_url . '&page=' . max(1, $current_page - 1); ?>" class="page-btn <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>" aria-label="Previous Page"><i class="fas fa-chevron-left"></i></a>
                              <span>Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                              <a href="<?php echo $base_url . '&page=' . min($total_pages, $current_page + 1); ?>" class="page-btn <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>" aria-label="Next Page"><i class="fas fa-chevron-right"></i></a>
                         </div>
                         <?php endif; ?>
                     </div>
                     <?php endif; ?>

                 </div> <!-- /.table-container -->
            </div> <!-- /.content-section -->


            <!-- Chart Placeholders Section -->
            <div class="content-section">
                <h2 class="section-title">Visualizations (Placeholder)</h2>
                 <div class="charts-row">
                    <div class="chart-container">
                        <div class="chart-header"><h2 class="chart-title">Check-ins Over Time</h2></div>
                        <canvas id="reportCheckinsChart"></canvas>
                    </div>
                    <div class="chart-container" id="reportSiteChartContainer">
                        <div class="chart-header"><h2 class="chart-title">Data by Question (Example)</h2></div>
                         <canvas id="reportQuestionsChart"></canvas>
                    </div>
                </div>
            </div>

    <!-- JavaScript for updating hidden site filter input -->
    <script>
        function updateReportFilters() {
            const siteSelect = document.getElementById('report-site-select');
            const hiddenSiteInput = document.getElementById('filter_site_id');
            if (siteSelect && hiddenSiteInput) {
                hiddenSiteInput.value = siteSelect.value;
                // Submit form automatically on dropdown change for immediate filtering
                 document.getElementById('report-filter-form').submit();
            } else if (hiddenSiteInput) {
                 // If no dropdown, ensure hidden input value reflects the current state (might be set by PHP)
                 console.log("No site selector dropdown found, hidden input value:", hiddenSiteInput.value);
            }
        }
         // Set hidden input on initial load based on dropdown (if present)
         document.addEventListener('DOMContentLoaded', function() {
             const siteSelect = document.getElementById('report-site-select');
             const hiddenSiteInput = document.getElementById('filter_site_id');
             if(siteSelect && hiddenSiteInput) {
                 hiddenSiteInput.value = siteSelect.value;
             }
         });
    </script>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<?php
// --- Include Footer ---
require_once 'includes/footer.php';
?>

<!-- Chart Initialization Script (Placeholder - Needs Real Data Integration) -->
<!-- Chart Initialization Script -->
<!-- Chart Initialization Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- SINGLE LOG AT THE TOP ---
    console.log("Report page script loaded. Initializing charts and custom report handler...");

    // --- Chart 1: Check-ins Over Time (Placeholder) ---
    const ctxTimeReport = document.getElementById('reportCheckinsChart');
    if (ctxTimeReport) {
        // --- TODO: Replace with actual PHP data aggregation for time chart ---
        const timeLabels = ['Day 1', 'Day 2', 'Day 3']; // Example
        const timeData = [5, 8, 3]; // Example
        // --- End TODO ---

        if (timeLabels.length > 0) {
           // console.log("Initializing Time Chart...");
           new Chart(ctxTimeReport, {
               type: 'line',
               data: {
                   labels: timeLabels,
                   datasets: [{
                       label: 'Check-ins',
                       data: timeData,
                       tension: 0.1,
                       borderColor: 'var(--color-primary)',
                       backgroundColor: 'rgba(30, 58, 138, 0.1)',
                       fill: true
                   }]
               },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true } }
                }
           });
        } else {
           // --- FIX for TypeError: Only get context and draw if canvas exists ---
           const ctx = ctxTimeReport.getContext('2d');
           if (ctx) { // Check if context was successfully obtained
                ctx.textAlign = 'center';
                ctx.fillStyle = 'var(--color-gray)';
                // Use canvas dimensions directly if available
                const canvasWidth = ctxTimeReport.width || 300; // Default width if needed
                const canvasHeight = ctxTimeReport.height || 150; // Default height
                ctx.fillText('No time data available for chart.', canvasWidth / 2, canvasHeight / 2);
           } else {
               console.warn("Could not get 2D context for reportCheckinsChart.");
           }
        }
    } else {
        console.warn("Canvas element #reportCheckinsChart not found.");
    }

    // --- Chart 2: Question Responses (Using Formatted Labels) ---
    const ctxQuestionsReport = document.getElementById('reportQuestionsChart');
    if(ctxQuestionsReport) {
        // --- Use the JSON variables generated by PHP (for main page table, not custom report) ---
        const questionChartLabels = <?php echo $chart_labels_json ?? '[]'; ?>; // From main page PHP
        const questionColumns = <?php echo json_encode($report_question_columns ?? '[]'); ?>; // From main page PHP
        const reportData = <?php echo empty($report_data) ? '[]' : json_encode($report_data); ?>; // From main page PHP

        let yesCounts = []; // Initialize JS array for counts

        // Calculate counts ONLY if there's data and columns to process
        if (reportData.length > 0 && questionColumns.length > 0 && questionChartLabels.length === questionColumns.length) {
            yesCounts = Array(questionColumns.length).fill(0); // Create array of zeros matching column count
            reportData.forEach(row => {
                questionColumns.forEach((colName, index) => {
                    if (row.hasOwnProperty(colName) && row[colName] === 'YES') {
                        if (index < yesCounts.length) {
                            yesCounts[index]++;
                        }
                    }
                });
            });
        } else if (questionColumns.length > 0) {
            yesCounts = Array(questionColumns.length).fill(0);
        }

        // Initialize the chart if there are labels generated from PHP
        if (questionChartLabels.length > 0) {
           // console.log("Initializing Questions Chart...");
            new Chart(ctxQuestionsReport, {
                type: 'bar',
                data: {
                    labels: questionChartLabels,
                    datasets: [{
                        label: 'Yes Answers',
                        data: yesCounts,
                        backgroundColor: 'rgba(255, 107, 53, 0.7)',
                        borderColor: 'var(--color-secondary)',
                        borderWidth: 1
                   }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                callback: function(value) {if (Number.isInteger(value)) {return value;}}
                            }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: { /* callbacks if needed */ }
                    }
                }
            });
        } else { // No labels
           // --- FIX for TypeError: Only get context and draw if canvas exists ---
           const ctx = ctxQuestionsReport.getContext('2d');
           if (ctx) { // Check if context was successfully obtained
                ctx.textAlign = 'center';
                ctx.fillStyle = 'var(--color-gray)';
                const canvasWidth = ctxQuestionsReport.width || 300; // Default width
                const canvasHeight = ctxQuestionsReport.height || 150; // Default height
                ctx.fillText('No question data available for chart.', canvasWidth / 2, canvasHeight / 2);
           } else {
               console.warn("Could not get 2D context for reportQuestionsChart.");
           }
        }
    } else {
         console.warn("Canvas element #reportQuestionsChart not found.");
    }


    // --- Custom Report Builder AJAX Handler ---
    // --- Moved INSIDE DOMContentLoaded ---
    const customReportForm = document.getElementById('custom-report-form');
    const generateBtn = document.getElementById('generate-custom-report-btn');
    const loadingIndicator = document.getElementById('custom-report-loading');
    const outputArea = document.getElementById('custom-report-output-area');

    if (customReportForm && generateBtn && loadingIndicator && outputArea) {
        // --- ADDED THE EVENT LISTENER WRAPPER ---
        customReportForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission
            console.log('Custom report form submitted.');

            // Show loading indicator
            loadingIndicator.style.display = 'inline-block';
            generateBtn.disabled = true;
            outputArea.innerHTML = '<p style="text-align: center; color: var(--color-gray);"><i class="fas fa-spinner fa-spin"></i> Loading report data...</p>'; // Clear previous results

            // --- Gather Filter Data ---
            const siteId = document.getElementById('filter_site_id')?.value || 'all';
            const startDate = document.getElementById('start_date')?.value || '';
            const endDate = document.getElementById('end_date')?.value || '';

            // --- Gather Builder Options ---
            const metricsSelect = document.getElementById('custom_metrics');
            const selectedMetrics = Array.from(metricsSelect.selectedOptions).map(option => option.value);
            const groupBy = document.getElementById('custom_group_by')?.value || 'none';
            const outputType = document.getElementById('custom_output_type')?.value || 'table';

            if (selectedMetrics.length === 0) {
                 outputArea.innerHTML = '<p style="text-align: center; color: red;">Please select at least one metric.</p>';
                 loadingIndicator.style.display = 'none';
                 generateBtn.disabled = false;
                 return;
            }

            // --- Prepare data for AJAX request ---
            // --- formData is now correctly defined INSIDE the event handler ---
            const formData = new FormData();
            formData.append('action', 'generate_custom_report');
            formData.append('site_id', siteId);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            selectedMetrics.forEach(metric => {
                formData.append('metrics[]', metric);
            });
            formData.append('group_by', groupBy);
            formData.append('output_type', outputType);

            console.log('Sending AJAX request with data:', {
                 action: 'generate_custom_report',
                 site_id: siteId, start_date: startDate, end_date: endDate,
                 metrics: selectedMetrics, group_by: groupBy, output_type: outputType
            });

            // --- Send AJAX Request ---
            fetch('ajax_report_handler.php', {
                method: 'POST',
                body: formData // formData is now defined in this scope
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                         throw new Error(`HTTP error! status: ${response.status} - ${text}`);
                    });
                }
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                } else {
                    return response.text();
                }
            })
            .then(data => {
                console.log('Received response from server.');
                outputArea.innerHTML = '';

                if (typeof data === 'string') {
                    console.log('Rendering HTML response.');
                    outputArea.innerHTML = data;
                } else if (typeof data === 'object' && data !== null && data.html && data.chartData && data.chartType) {
                    console.log('Rendering chart response.');
                    outputArea.innerHTML = data.html;
                    const canvasElement = outputArea.querySelector('#custom-report-chart');

                    if (canvasElement) {
                         const existingChart = Chart.getChart(canvasElement);
                         if (existingChart) {
                             existingChart.destroy();
                             console.log('Destroyed previous custom chart instance.');
                         }
                         console.log('Creating new chart:', data.chartType, 'with data:', data.chartData);
                         try {
                            new Chart(canvasElement, {
                                type: data.chartType,
                                data: data.chartData,
                                options: { /* ... chart options ... */
                                    responsive: true, maintainAspectRatio: false,
                                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1, callback: function(value) {if (Number.isInteger(value)) {return value;}} } } },
                                    plugins: { legend: { display: data.chartData.datasets && data.chartData.datasets.length > 1 }, tooltip: { enabled: true } }
                                }
                            });
                         } catch (chartError) {
                             console.error("Chart.js initialization error:", chartError);
                             outputArea.innerHTML = `<p style="text-align: center; color: red;">Error rendering chart. Check console.</p>`;
                         }
                    } else {
                        console.error('Canvas element #custom-report-chart not found in response HTML.');
                        outputArea.innerHTML = '<p style="text-align: center; color: red;">Error: Chart canvas element missing in response.</p>';
                    }
                } else {
                     console.warn('Received unexpected data format:', data);
                     outputArea.innerHTML = '<p style="text-align: center; color: orange;">Received unexpected data format from server.</p>';
                }
            })
            .catch(error => {
                console.error('Error fetching/processing custom report:', error);
                outputArea.innerHTML = `<p style="text-align: center; color: red;">Error generating report: ${error.message}. Please check console or server logs.</p>`;
            })
            .finally(() => {
                 loadingIndicator.style.display = 'none';
                 generateBtn.disabled = false;
                 console.log('AJAX request finished.');
            });
        }); // --- END OF addEventListener ---

    } else {
        console.warn("Custom report builder elements not found. JS functionality disabled.");
        if (!customReportForm) console.warn("Reason: custom-report-form not found.");
        if (!generateBtn) console.warn("Reason: generate-custom-report-btn not found.");
        if (!loadingIndicator) console.warn("Reason: custom-report-loading not found.");
        if (!outputArea) console.warn("Reason: custom-report-output-area not found.");
    }

}); // --- END OF DOMContentLoaded ---
</script>