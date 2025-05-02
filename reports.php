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
require_once 'data_access/budget_data.php'; // Provides budget/allocation functions (including new ones)
require_once 'includes/data_access/grants_dal.php'; // Provides grant functions
require_once 'includes/data_access/department_data.php'; // Provides department functions
require_once 'data_access/vendor_data.php'; // Provides vendor functions
 
 // --- Role Check ---
 $allowedRoles = ['azwk_staff', 'outside_staff', 'director', 'administrator'];
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
    if (in_array($_SESSION['active_role'], ['azwk_staff', 'outside_staff'])) {
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
// error_log("Reports Debug - Filter ID: {$selected_site_id} - Active VALID question columns for data fetch (prefixed): " . print_r($report_question_columns, true));

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
            $results_per_page,        // Limit
            $offset,                  // Offset
            $report_question_columns  // Dynamic question columns to select
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

// --- Fetch Data for Allocation Report Filters ---
$allocation_fiscal_years = getDistinctFiscalYears($pdo);
$allocation_grants = getAllGrants($pdo);
$allocation_departments = getAllDepartments($pdo);
$allocation_user_budgets = getBudgetsForUser($pdo, $_SESSION['user_id'] ?? 0, $_SESSION['active_role'] ?? '');
$allocation_vendors = getActiveVendors($pdo);

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

            <!-- Bootstrap Tabs Navigation -->
            <ul class="nav nav-tabs mt-3" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="checkin-tab" data-toggle="tab" href="#checkin-reports" role="tab" aria-controls="checkin-reports" aria-selected="true">Check-in Reports</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="allocation-tab" data-toggle="tab" href="#allocation-reports" role="tab" aria-controls="allocation-reports" aria-selected="false">Allocation Reports</a>
                </li>
            </ul>
            <div class="tab-content" id="reportTabContent">
                <!-- Check-in Reports Tab Pane -->
                <div class="tab-pane fade show active" id="checkin-reports" role="tabpanel" aria-labelledby="checkin-tab">

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
                           <?php if ($total_records === 0) echo 'class="disabled" aria-disabled="true" title="No data to export"'; ?> >
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
                        <span id="custom-report-loading" class="d-none ms-2">
                            <i class="fas fa-spinner fa-spin"></i> Generating...
                        </span>
                    </div>
                </form>

                <!-- Area to display the generated report -->
                <div id="custom-report-output-area" class="content-section" style="margin-top: 20px; min-height: 100px; border: 1px dashed var(--color-gray-light); padding: 15px;">
                    <!-- Report will be loaded here via AJAX -->
                    <p class="text-center text-muted">Select options above and click "Generate Report".</p>
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
                    <table class="table table-striped table-hover">
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
                                    <td colspan="<?php echo $total_cols; ?>" class="text-center">No records found for the selected criteria.</td>
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


                </div> <!-- End Check-in Reports Tab Pane -->

                <!-- Allocation Reports Tab Pane -->
                <div class="tab-pane fade" id="allocation-reports" role="tabpanel" aria-labelledby="allocation-tab">
                    <div class="content-section">
                        <h2 class="section-title">Allocation Reports</h2>

                        <!-- Allocation Filters Section -->
                        <div class="row mb-3" id="allocation-filters">
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="alloc-filter-fy">Fiscal Year</label>
                                    <select class="form-control form-control-sm" id="alloc-filter-fy" name="alloc_filter_fy">
                                        <option value="">All Years</option>
                                        <?php foreach ($allocation_fiscal_years as $fy_start_date): ?>
                                            <?php
                                                // Attempt to determine the fiscal year label (e.g., "FY24-25")
                                                $start_year = date('Y', strtotime($fy_start_date));
                                                $start_month = date('n', strtotime($fy_start_date));
                                                $fy_label = "FY" . substr($start_year, -2);
                                                // Basic assumption: July 1st starts new FY
                                                if ($start_month >= 7) {
                                                    $fy_label .= "-" . substr($start_year + 1, -2);
                                                } else {
                                                    $fy_label = "FY" . substr($start_year - 1, -2) . "-" . substr($start_year, -2);
                                                }
                                            ?>
                                            <option value="<?php echo htmlspecialchars($fy_start_date); ?>">
                                                <?php echo htmlspecialchars($fy_label); ?> (Starts <?php echo htmlspecialchars($fy_start_date); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="alloc-filter-grant">Grant</label>
                                    <select class="form-control form-control-sm" id="alloc-filter-grant" name="alloc_filter_grant">
                                        <option value="">All Grants</option>
                                        <?php foreach ($allocation_grants as $grant): ?>
                                            <option value="<?php echo $grant['id']; ?>">
                                                <?php echo htmlspecialchars($grant['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="alloc-filter-department">Department</label>
                                    <select class="form-control form-control-sm" id="alloc-filter-department" name="alloc_filter_department">
                                        <option value="">All Departments</option>
                                        <?php foreach ($allocation_departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>">
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="alloc-filter-budget">Budget</label>
                                    <select class="form-control form-control-sm" id="alloc-filter-budget" name="alloc_filter_budget">
                                        <option value="">All Budgets</option>
                                        <?php foreach ($allocation_user_budgets as $budget): ?>
                                            <option value="<?php echo $budget['id']; ?>">
                                                <?php echo htmlspecialchars($budget['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="alloc-filter-vendor">Vendor</label>
                                    <select class="form-control form-control-sm" id="alloc-filter-vendor" name="alloc_filter_vendor">
                                        <option value="">All Vendors</option>
                                        <?php foreach ($allocation_vendors as $vendor): ?>
                                            <option value="<?php echo $vendor['id']; ?>">
                                                <?php echo htmlspecialchars($vendor['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                 <button type="button" id="apply-allocation-filters-btn" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply Filters</button>
                                 <button type="button" id="reset-allocation-filters-btn" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</button>
                            </div>
                        </div>

                        <!-- Allocation Report Table Container -->
                        <div id="allocation-report-table-container" class="mt-4">
                            <p class="text-center text-muted">Apply filters to view allocation report.</p>
                            <!-- Table will be loaded here via AJAX -->
                        </div>
                    </div>
                </div> <!-- End Allocation Reports Tab Pane -->

            </div> <!-- End Tab Content Wrapper -->


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
            outputArea.innerHTML = '<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading report data...</p>'; // Clear previous results

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
                 outputArea.innerHTML = '<p class="text-center text-danger">Please select at least one metric.</p>';
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
                outputArea.innerHTML = ''; // Clear previous content first

                let reportHtml = ''; // Variable to hold the base HTML

                // Handle different response types (string HTML or object with html)
                if (typeof data === 'string') {
                    console.log('Rendering HTML response.');
                    reportHtml = data;
                    outputArea.innerHTML = reportHtml; // Render base HTML
                } else if (typeof data === 'object' && data !== null) {
                    // Render base HTML if provided
                    if (data.html) {
                        console.log('Rendering HTML from data object.');
                        reportHtml = data.html;
                        outputArea.innerHTML = reportHtml; // Render base HTML
                    }

                    // Handle chart data if present
                    if (data.chartData && data.chartType) {
                         console.log('Rendering chart response.');
                         // Ensure HTML container exists if not already rendered
                         if (!outputArea.innerHTML && reportHtml) {
                             outputArea.innerHTML = reportHtml;
                         } else if (!outputArea.innerHTML && !reportHtml) {
                             console.warn("Chart data provided but no base HTML structure found in response. Chart may not render correctly.");
                             // Attempt to create a basic container if needed, or rely on chart logic
                             // outputArea.innerHTML = '<div id="chart-container"><canvas id="custom-report-chart"></canvas></div>';
                         }

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
                                 // Append error message instead of replacing entire output
                                 outputArea.innerHTML += `<p class="text-center text-danger">Error rendering chart. Check console.</p>`;
                             }
                         } else {
                             console.error('Canvas element #custom-report-chart not found in the response HTML.');
                             // Append error message
                             outputArea.innerHTML += '<p class="text-center text-danger">Error: Chart canvas element missing in response.</p>';
                         }
                    }

                    // *** NEW: Handle Dynamic Table Headers ***
                    // Check if dynamic headers are provided in the response object
                    if (data.dynamic_question_headers && Array.isArray(data.dynamic_question_headers)) {
                        console.log('Processing dynamic question headers...');
                        // Find the header row within the rendered HTML.
                        // Assumes the table has id="custom-report-table" and standard thead > tr structure.
                        const headerRow = outputArea.querySelector('#custom-report-table thead tr');

                        if (headerRow) {
                            console.log('Found header row:', headerRow);
                            // Clear any previously added dynamic headers (marked with class 'dynamic-th')
                            // This prevents duplication if the report is regenerated.
                            headerRow.querySelectorAll('.dynamic-th').forEach(th => th.remove());
                            console.log('Cleared previous dynamic headers.');

                            // Add new headers from the response data
                            data.dynamic_question_headers.forEach(header => {
                                // Basic validation of the header object
                                if (header && typeof header.title !== 'undefined') {
                                    const newTh = document.createElement('th');
                                    newTh.textContent = header.title; // Use textContent for safety (prevents XSS)
                                    newTh.classList.add('dynamic-th'); // Add class for identification and clearing
                                    headerRow.appendChild(newTh);
                                } else {
                                    console.warn('Skipping invalid header object:', header);
                                }
                            });
                            console.log(`Appended ${data.dynamic_question_headers.length} new dynamic headers.`);
                        } else {
                            // Warn if the expected table structure isn't found in the HTML response
                            console.warn('Could not find header row (#custom-report-table thead tr) in the rendered HTML. Dynamic headers not added.');
                        }
                    } else if (data.hasOwnProperty('dynamic_question_headers')) {
                        // Log if the property exists but isn't a valid array
                        console.log('dynamic_question_headers property found but is not a valid array or is empty. No dynamic headers added.');
                    }
                    // *** END NEW DYNAMIC HEADER LOGIC ***

                    // *** NEW: Populate Dynamic Answer Cells ***
                    // Check if check-in data and headers are available for populating cells
                    if (data.checkinData && Array.isArray(data.checkinData) &&
                        data.dynamic_question_headers && Array.isArray(data.dynamic_question_headers) &&
                        data.dynamic_question_headers.length > 0) {

                        console.log('Processing dynamic answer cells...');
                        // Find the table body within the rendered HTML.
                        // Assumes the table has id="custom-report-table" and standard tbody structure.
                        const tableBody = outputArea.querySelector('#custom-report-table tbody');

                        if (tableBody) {
                            const rows = tableBody.querySelectorAll('tr');
                            console.log(`Found ${rows.length} rows and ${data.checkinData.length} check-in records.`);

                            // Ensure the number of rows matches the number of data records
                            if (rows.length === data.checkinData.length) {
                                rows.forEach((row, rowIndex) => {
                                    const checkinRecord = data.checkinData[rowIndex];
                                    // Ensure the record has the dynamic_answers map
                                    if (checkinRecord && typeof checkinRecord.dynamic_answers === 'object' && checkinRecord.dynamic_answers !== null) {
                                        // Iterate through the headers to append cells in the correct order
                                        data.dynamic_question_headers.forEach(header => {
                                            if (header && typeof header.title !== 'undefined') {
                                                const questionTitle = header.title; // Assuming title is the key
                                                // Get the answer from the map, default to '--' if missing
                                                const answer = checkinRecord.dynamic_answers.hasOwnProperty(questionTitle)
                                                               ? checkinRecord.dynamic_answers[questionTitle]
                                                               : '--';

                                                const newTd = document.createElement('td');
                                                newTd.textContent = answer !== null && answer !== '' ? answer : '--'; // Use textContent for safety
                                                row.appendChild(newTd);
                                            }
                                        });
                                    } else {
                                        console.warn(`Skipping row ${rowIndex}: Missing or invalid dynamic_answers in checkinData.`);
                                        // Optionally add placeholder cells if answers are missing for a whole row
                                        data.dynamic_question_headers.forEach(() => {
                                             const newTd = document.createElement('td');
                                             newTd.textContent = '--';
                                             row.appendChild(newTd);
                                        });
                                    }
                                });
                                console.log('Finished appending dynamic answer cells.');
                            } else {
                                console.warn(`Mismatch between number of table rows (${rows.length}) and check-in data records (${data.checkinData.length}). Cannot reliably populate dynamic cells.`);
                                // Append warning to output area
                                outputArea.innerHTML += '<p class="text-center text-warning">Warning: Data mismatch prevented dynamic answer population.</p>';
                            }
                        } else {
                            console.warn('Could not find table body (#custom-report-table tbody) in the rendered HTML. Dynamic answer cells not added.');
                        }
                    } else if (data.hasOwnProperty('checkinData') || data.hasOwnProperty('dynamic_question_headers')) {
                         // Log if data exists but isn't the expected format or is empty
                         console.log('checkinData or dynamic_question_headers missing, empty, or invalid. No dynamic answer cells added.');
                    }
                    // *** END NEW DYNAMIC ANSWER CELL LOGIC ***

                } else {
                    // Handle cases where data is not a string or a recognized object structure
                    console.warn('Received unexpected data format:', data);
                    outputArea.innerHTML = '<p class="text-center text-warning">Received unexpected data format from server.</p>';
                }
            })
            .catch(error => {
                console.error('Error fetching/processing custom report:', error);
                outputArea.innerHTML = `<p class="text-center text-danger">Error generating report: ${error.message}. Please check console or server logs.</p>`;
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

// --- Allocation Report Logic ---
    const allocationTab = document.getElementById('allocation-tab');
    const allocationFiltersForm = document.getElementById('allocation-filters'); // Container for filters
    const applyAllocFiltersBtn = document.getElementById('apply-allocation-filters-btn');
    const resetAllocFiltersBtn = document.getElementById('reset-allocation-filters-btn');
    const allocationContainer = document.getElementById('allocation-report-table-container');

    // Helper function to format currency
    function formatCurrency(amount) {
        const num = parseFloat(amount);
        if (isNaN(num)) {
            return amount; // Return original if not a number or null/undefined
        }
        // Format as $1,234.56 - adjust locale/options as needed
        return num.toLocaleString('en-US', { style: 'currency', currency: 'USD' });
    }

    // Helper function to format date/time
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        try {
            const date = new Date(dateString);
            // Format as YYYY-MM-DD HH:MM (adjust locale/options as needed)
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            // Check if time is midnight (often indicates date only)
            if (hours === '00' && minutes === '00') {
                 return `${year}-${month}-${day}`; // Return only date if time is 00:00
            }
            return `${year}-${month}-${day} ${hours}:${minutes}`;
        } catch (e) {
            console.warn("Could not format date:", dateString, e);
            return dateString; // Return original if formatting fails
        }
    }

    async function loadAllocationReport(page = 1) {
        if (!allocationContainer) {
            console.error("Allocation report container not found.");
            return;
        }
        console.log(`Loading allocation report - Page: ${page}`);
        allocationContainer.innerHTML = '<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading allocation data...</p>';

        // Gather filter values
        const filters = {
            fy: document.getElementById('alloc-filter-fy')?.value || '',
            grant_id: document.getElementById('alloc-filter-grant')?.value || '', // Assuming value is grant_id
            department_id: document.getElementById('alloc-filter-department')?.value || '', // Assuming value is department_id
            budget_id: document.getElementById('alloc-filter-budget')?.value || '', // Assuming value is budget_id
            vendor_id: document.getElementById('alloc-filter-vendor')?.value || '' // Assuming value is vendor_id
        };

        // Prepare data for AJAX
        const formData = new FormData();
        formData.append('action', 'get_allocation_report_data');
        formData.append('page', page);
        // Append filters only if they have a value
        for (const key in filters) {
            if (filters[key]) {
                 formData.append(`filters[${key}]`, filters[key]);
            }
        }
         console.log("Sending allocation filters:", Object.fromEntries(formData)); // Log form data

        try {
            const response = await fetch('ajax_report_handler.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status} - ${errorText}`);
            }

            const result = await response.json();
            console.log("Allocation data received:", result);

            if (result.success) {
                renderAllocationTable(result.data, result.pagination);
            } else {
                throw new Error(result.message || "Failed to load allocation data.");
            }

        } catch (error) {
            console.error('Error fetching allocation report:', error);
            allocationContainer.innerHTML = `<p class="text-center text-danger">Error loading allocation report: ${error.message}</p>`;
        }
    }

    function renderAllocationTable(data, pagination) {
        if (!allocationContainer) return;
        allocationContainer.innerHTML = ''; // Clear loading/previous content

        if (!data || data.length === 0) {
            allocationContainer.innerHTML = '<p class="text-center text-muted">No allocation records found for the selected criteria.</p>';
            // Still render pagination controls if needed (e.g., to show "0 records")
             renderAllocationPagination(pagination);
            return;
        }

        // Create table structure
        const table = document.createElement('table');
        table.className = 'table table-bordered table-striped table-hover table-sm'; // Added table-sm

        // Table Header
        const thead = table.createTHead();
        const headerRow = thead.insertRow();
        // Define headers based on expected data fields from backend
        const headers = [
            { key: 'grant_name', label: 'Grant' },
            { key: 'budget_name', label: 'Budget' },
            { key: 'vendor_name', label: 'Vendor' },
            { key: 'client_name', label: 'Client Name' },
            { key: 'voucher_number', label: 'Voucher #' },
            { key: 'amount', label: 'Amount' },
            { key: 'transaction_date', label: 'Transaction Date' },
            { key: 'entered_by_name', label: 'Entered By' },
            { key: 'notes', label: 'Notes' }
        ];
        headers.forEach(header => {
            const th = document.createElement('th');
            th.textContent = header.label;
            headerRow.appendChild(th);
        });

        // Table Body
        const tbody = table.createTBody();
        data.forEach(record => {
            const row = tbody.insertRow();

            headers.forEach(header => {
                const cell = row.insertCell();
                let cellData = record[header.key] ?? 'N/A'; // Use nullish coalescing

                // Special handling for specific columns
                if (header.key === 'client_name') {
                     cellData = (record.client_first_name || record.client_last_name)
                               ? `${record.client_first_name || ''} ${record.client_last_name || ''}`.trim()
                               : 'N/A';
                } else if (header.key === 'amount') {
                    cellData = formatCurrency(record.amount);
                    cell.style.textAlign = 'right';
                } else if (header.key === 'transaction_date') {
                    cellData = formatDate(record.transaction_date);
                } else if (header.key === 'notes') {
                     cellData = record.notes || ''; // Show empty string instead of N/A for notes
                     cell.title = cellData; // Add title for potentially long notes
                     cell.style.maxWidth = '200px';
                     cell.style.overflow = 'hidden';
                     cell.style.textOverflow = 'ellipsis';
                     cell.style.whiteSpace = 'nowrap';
                }

                cell.textContent = cellData;
            });
        });

        allocationContainer.appendChild(table);

        // Pagination
        renderAllocationPagination(pagination);
    }

     function renderAllocationPagination(pagination) {
        // Remove existing pagination first
        const existingPagination = allocationContainer.querySelector('.allocation-pagination-controls');
        if (existingPagination) {
            existingPagination.remove();
        }
         const existingRecordCount = allocationContainer.querySelector('.allocation-record-count');
         if(existingRecordCount) {
             existingRecordCount.remove();
         }


        if (!allocationContainer || !pagination || pagination.total_records === undefined) {
             console.warn("Pagination data missing or invalid.");
             return; // Exit if pagination data is incomplete
        }

        const { current_page = 1, total_pages = 0, total_records = 0, results_per_page = 25 } = pagination; // Provide defaults

         // Record count display (always show, even if 0 records)
         const recordCountDiv = document.createElement('div');
         recordCountDiv.className = 'allocation-record-count mt-2'; // Separate class for count
         const startRecord = (total_records > 0) ? (current_page - 1) * results_per_page + 1 : 0;
         const endRecord = Math.min(startRecord + results_per_page - 1, total_records);
         recordCountDiv.textContent = `Showing ${startRecord} - ${endRecord} of ${total_records} records`;
         // Insert record count after the table (if table exists) or at the start of the container
         const tableElement = allocationContainer.querySelector('table');
         if (tableElement) {
             tableElement.insertAdjacentElement('afterend', recordCountDiv);
         } else {
             allocationContainer.prepend(recordCountDiv);
         }


        // Only render controls if more than one page
        if (total_pages <= 1) {
            return;
// Get references to all filter dropdowns
    const allocFilterFY = document.getElementById('alloc-filter-fy');
    const allocFilterGrant = document.getElementById('alloc-filter-grant');
    const allocFilterDept = document.getElementById('alloc-filter-department');
    const allocFilterBudget = document.getElementById('alloc-filter-budget');
    const allocFilterVendor = document.getElementById('alloc-filter-vendor');
    const allAllocFilters = [allocFilterFY, allocFilterGrant, allocFilterDept, allocFilterBudget, allocFilterVendor];

    // Add 'change' listener to each filter dropdown
    allAllocFilters.forEach(filterSelect => {
        if (filterSelect) {
            filterSelect.addEventListener('change', () => {
                console.log(`Filter changed: ${filterSelect.id}, New value: ${filterSelect.value}`);
                loadAllocationReport(1); // Reload data on any filter change, starting from page 1
            });
        } else {
             // Log if any filter element wasn't found, though they should exist based on HTML
             console.warn(`Allocation filter element not found for one of the dropdowns.`);
        }
    });
        }

        // Create footer container for controls
        const footerDiv = document.createElement('div');
        footerDiv.className = 'table-footer allocation-pagination-controls mt-2 d-flex justify-content-end align-items-center'; // Align controls to the right

        // Pagination controls container
        const paginationDiv = document.createElement('div');
        paginationDiv.className = 'table-pagination';

        // Previous Button
        const prevButton = document.createElement('button');
        prevButton.className = 'page-btn btn btn-sm btn-outline-secondary me-1';
        prevButton.innerHTML = '<i class="fas fa-chevron-left"></i>';
        prevButton.disabled = current_page <= 1;
        prevButton.setAttribute('aria-label', 'Previous Page');
        prevButton.dataset.page = current_page - 1;
        paginationDiv.appendChild(prevButton);

        // Page Number Display
        const pageInfo = document.createElement('span');
        pageInfo.className = 'mx-2 align-middle'; // Vertical alignment
        pageInfo.textContent = `Page ${current_page} of ${total_pages}`;
        paginationDiv.appendChild(pageInfo);

        // Next Button
        const nextButton = document.createElement('button');
        nextButton.className = 'page-btn btn btn-sm btn-outline-secondary ms-1';
        nextButton.innerHTML = '<i class="fas fa-chevron-right"></i>';
        nextButton.disabled = current_page >= total_pages;
        nextButton.setAttribute('aria-label', 'Next Page');
        nextButton.dataset.page = current_page + 1;
        paginationDiv.appendChild(nextButton);

        footerDiv.appendChild(paginationDiv);

        // Append controls after the record count
        recordCountDiv.insertAdjacentElement('afterend', footerDiv);
    }


    // --- Event Listeners for Allocation Report ---

    // Load data when Allocation tab is shown
    if (allocationTab && typeof $ !== 'undefined') { // Check if jQuery is available
        $('#allocation-tab').on('shown.bs.tab', function (e) {
            console.log("Allocation tab shown.");
            // Load data every time the tab is shown to reflect potential background changes
            loadAllocationReport(1);
        });
    } else if (allocationTab) {
         console.warn("jQuery not found, cannot bind Bootstrap 'shown.bs.tab' event. Allocation report might not load automatically on tab switch.");
         // Fallback: Add a simple click listener to the tab itself, though less ideal
         allocationTab.addEventListener('click', () => {
             // Basic check if it's not already active (might need refinement)
             if (!allocationTab.classList.contains('active')) {
                 setTimeout(() => loadAllocationReport(1), 0); // Load after potential tab switch completes
             }
         });
    }
     else {
        console.warn("Allocation tab element not found.");
    }

    // Apply filters button
    if (applyAllocFiltersBtn) {
        applyAllocFiltersBtn.addEventListener('click', () => {
            loadAllocationReport(1); // Load first page with new filters
        });
    } else {
        console.warn("Apply allocation filters button not found.");
    }

     // Reset filters button
     if (resetAllocFiltersBtn && allocationFiltersForm) {
         resetAllocFiltersBtn.addEventListener('click', () => {
             allocationFiltersForm.querySelectorAll('select').forEach(select => {
                 select.value = ''; // Reset to default 'All' option (value="")
             });
             loadAllocationReport(1); // Reload data with reset filters
         });
     } else {
         console.warn("Reset allocation filters button or form container not found.");
     }


    // Pagination click handler (using event delegation on the container)
    if (allocationContainer) {
        allocationContainer.addEventListener('click', function(event) {
            // Target buttons within the pagination controls specifically
            const targetButton = event.target.closest('.allocation-pagination-controls button[data-page]');
            if (targetButton && !targetButton.disabled) {
                const page = parseInt(targetButton.dataset.page, 10);
                if (!isNaN(page) && page > 0) {
                    loadAllocationReport(page);
                }
            }
        });
    }

    // --- End Allocation Report Logic ---
}); // --- END OF DOMContentLoaded ---
</script>