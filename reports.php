<![CDATA[<?php
/*
 * File: reports.php
 * Path: /reports.php
 * Created: 2024-08-01 13:00:00 MST
 * 
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
<style>
#allocation-reports-pane .form-control-sm {
    height: auto;
    padding-top: 0.3rem;
    padding-bottom: 0.3rem;
    line-height: 1.6;
}
#custom_output_type { /* ID of the Output Type select element */
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important; /* Attempt to override default browser min-width */
    box-sizing: border-box !important;
}
#customReportSelectRow {
    display: flex;
    flex-wrap: wrap;
    flex-direction: row !important; /* Force row direction */
    margin-right: -5px; /* Typical Bootstrap .row/.form-row negative margin */
    margin-left: -5px;  /* Typical Bootstrap .row/.form-row negative margin */
}
</style>

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

            <!-- Custom Tab Navigation (Styled like Configurations) -->
            <!-- Standard Bootstrap Tab Navigation -->
            <ul class="nav nav-tabs" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="checkin-reports-tab" data-toggle="tab" href="#checkin-reports-pane" role="tab" aria-controls="checkin-reports-pane" aria-selected="true">Check-in Reports</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="allocations-reports-tab" data-toggle="tab" href="#allocation-reports-pane" role="tab" aria-controls="allocation-reports-pane" aria-selected="false">Allocations Reports</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="custom-report-builder-tab" data-toggle="tab" href="#custom-report-builder-pane" role="tab" aria-controls="custom-report-builder-pane" aria-selected="false">Custom Report Builder</a>
                </li>
            </ul>
            <div class="tab-content" id="reportTabContent">
                <!-- Check-in Reports Tab Pane (Filters) -->
                <div class="tab-pane fade show active" id="checkin-reports-pane" role="tabpanel" aria-labelledby="checkin-reports-tab">

                    <!-- Display Errors -->
             <?php if ($report_error_message): ?>
                <div class="message-area message-error"><?php echo htmlspecialchars($report_error_message); ?></div>
            <?php endif; ?>

            <!-- Report Filters Section -->
            <div class="content-section">
                <h2 class="section-title">Report Filters</h2>
                <form method="GET" action="reports.php" id="report-filter-form" class="filter-form">
                    <input type="hidden" name="site_id" id="filter_site_id" value="<?php echo htmlspecialchars($selected_site_id ?? 'all'); ?>">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="start_date" class="form-label">Start Date:</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="end_date" class="form-label">End Date:</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                        </div>
                    </div>
                     <div class="form-actions grid-col-full-width"> <!-- Ensure actions span columns -->
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


            <!-- Report Data Table Section -->
            <div class="content-section">
                 <h2 class="section-title">Check-in Data
                     <span class="font-weight-normal report-meta-text">
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
                                <th>Dynamic Answers</th> <!-- New column for dynamic answers -->
                                <!--<th>Actions</th>-->
                            </tr>
                        </thead>
                        <tbody>
                             <?php if (empty($report_data)): ?>
                                <tr>
                                     <?php
                                     // Calculate colspan dynamically
                                     $fixed_cols = 6; // ID, Name, Time, Email, Notified, Dynamic Answers
                                     if ($selected_site_id === 'all') $fixed_cols++;
                                     // $total_cols = $fixed_cols + count($report_question_columns); // Old calculation
                                     $total_cols = $fixed_cols; // New calculation
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
                                        <td><?php echo htmlspecialchars($row['notified_staff_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php
                                            $dynamic_answers_display = [];
                                            if (isset($row['dynamic_answers']) && is_array($row['dynamic_answers']) && !empty($row['dynamic_answers'])) {
                                                foreach ($row['dynamic_answers'] as $answer_item) {
                                                    if (isset($answer_item['question_text']) && isset($answer_item['answer_text'])) {
                                                        $dynamic_answers_display[] = '<strong>' . htmlspecialchars($answer_item['question_text']) . ':</strong> ' . htmlspecialchars($answer_item['answer_text']);
                                                    }
                                                }
                                                echo implode('<br>', $dynamic_answers_display);
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
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
        </div> <!-- End Check-in Reports Tab Pane (Now includes Filters & Data Table) -->

        <!-- Custom Report Builder Tab Pane -->
        <div class="tab-pane fade" id="custom-report-builder-pane" role="tabpanel" aria-labelledby="custom-report-builder-tab">
            <!-- Custom Report Builder Section -->
            <?php if ($user_can_select_sites): // Show only to Admin/Director ?>
            <div class="content-section" id="custom-report-builder">
                <h2 class="section-title">Custom Report Builder</h2>
                <form id="custom-report-form" class="settings-form" action="#" method="POST"> <!-- Use POST for AJAX, action="#" prevents default nav -->
                    <div class="form-row" id="customReportSelectRow">
                        <div class="col">
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
                        </div>

                        <div class="col">
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
                        </div>

                        <div class="col">
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
                        </div>
                    </div> <!-- Closing div for form-row -->

                    <div class="form-actions grid-col-full-width"> <!-- Ensure actions span columns -->
                        <button type="submit" id="generate-custom-report-btn" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i> Generate Report
                        </button>
                        <span id="custom-report-loading" class="d-none ms-2">
                            <i class="fas fa-spinner fa-spin"></i> Generating...
                        </span>
                    </div>
                </form>

                <!-- Area to display the generated report -->
                <div id="custom-report-output-area" class="content-section mt-4 p-3 custom-report-output">
                    <!-- Report will be loaded here via AJAX -->
                    <p class="text-center text-muted">Select options above and click "Generate Report".</p>
                </div>
            </div>
            <?php endif; ?>
        </div> <!-- End Custom Report Builder Tab Pane -->

        <!-- Allocations Reports Tab Pane -->
        <div class="tab-pane fade" id="allocation-reports-pane" role="tabpanel" aria-labelledby="allocations-reports-tab">
            <div class="content-section">
                <h2 class="section-title">Allocation Report Filters</h2>
                <form id="allocation-report-form" class="settings-form" action="#" method="POST">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="allocation_fiscal_year" class="form-label">Fiscal Year:</label>
                            <select id="allocation_fiscal_year" name="fiscal_year" class="form-control form-control-sm">
                                <option value="">All Years</option>
                                <?php foreach ($allocation_fiscal_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="allocation_grant_id" class="form-label">Grant:</label>
                            <select id="allocation_grant_id" name="grant_id" class="form-control form-control-sm">
                                <option value="">All Grants</option>
                                <?php foreach ($allocation_grants as $grant): ?>
                                    <option value="<?php echo htmlspecialchars($grant['id']); ?>"><?php echo htmlspecialchars($grant['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="allocation_department_id" class="form-label">Department:</label>
                            <select id="allocation_department_id" name="department_id" class="form-control form-control-sm">
                                <option value="">All Departments</option>
                                <?php foreach ($allocation_departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['id']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="form-group col-md-3">
                            <label for="allocation_budget_id" class="form-label">Budget:</label>
                            <select id="allocation_budget_id" name="budget_id" class="form-control form-control-sm">
                                <option value="">All Budgets</option>
                                <?php foreach ($allocation_user_budgets as $budget): ?>
                                    <option value="<?php echo htmlspecialchars($budget['id']); ?>"><?php echo htmlspecialchars($budget['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                     <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="allocation_vendor_id" class="form-label">Vendor:</label>
                            <select id="allocation_vendor_id" name="vendor_id" class="form-control form-control-sm">
                                <option value="">All Vendors</option>
                                <?php foreach ($allocation_vendors as $vendor): ?>
                                    <option value="<?php echo htmlspecialchars($vendor['id']); ?>"><?php echo htmlspecialchars($vendor['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="allocation_status" class="form-label">Status:</label>
                            <select id="allocation_status" name="status" class="form-control form-control-sm">
                                <option value="">All Statuses</option>
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Denied">Denied</option>
                                <option value="Paid">Paid</option>
                                <option value="Void">Void</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="allocation_start_date" class="form-label">Start Date:</label>
                            <input type="date" id="allocation_start_date" name="start_date" class="form-control form-control-sm">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="allocation_end_date" class="form-label">End Date:</label>
                            <input type="date" id="allocation_end_date" name="end_date" class="form-control form-control-sm">
                        </div>
                    </div>

                    <div class="form-actions grid-col-full-width">
                        <button type="submit" id="generate-allocation-report-btn" class="btn btn-primary">
                            <i class="fas fa-file-invoice-dollar"></i> Generate Allocation Report
                        </button>
                         <button type="button" id="export-allocation-report-btn" class="btn btn-outline">
                            <i class="fas fa-download"></i> Export Allocations
                        </button>
                        <span id="allocation-report-loading" class="d-none ms-2">
                            <i class="fas fa-spinner fa-spin"></i> Generating...
                        </span>
                    </div>
                </form>
            </div>

            <!-- Area to display the generated allocation report -->
            <div id="allocation-report-output-area" class="content-section mt-4 p-3 custom-report-output">
                <p class="text-center text-muted">Select filters and click "Generate Allocation Report".</p>
            </div>
        </div> <!-- End Allocations Reports Tab Pane -->


    </div> <!-- End Tab Content -->


<!-- Include Footer -->
<?php require_once 'includes/footer.php'; ?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// --- Global Variables for Report State ---
let currentCustomReportChart = null; // To hold the Chart.js instance for custom reports
let currentAllocationReportChart = null; // To hold the Chart.js instance for allocation reports

// --- Utility Functions ---
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

function displayMessage(areaId, message, type = 'info') {
    const area = document.getElementById(areaId);
    if (area) {
        area.innerHTML = `<div class="message-area message-${type} p-3">${message}</div>`;
    }
}

function clearOutputArea(areaId) {
    const area = document.getElementById(areaId);
    if (area) {
        area.innerHTML = '<p class="text-center text-muted">Report output will appear here.</p>'; // Reset to placeholder
    }
}

// --- Site Selector and Date Filter Update Logic ---
function updateReportFilters() {
    const siteSelect = document.getElementById('report-site-select');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const filterForm = document.getElementById('report-filter-form');
    const hiddenSiteIdInput = document.getElementById('filter_site_id'); // The hidden input in the main filter form

    if (siteSelect && hiddenSiteIdInput) {
        hiddenSiteIdInput.value = siteSelect.value; // Update the hidden input
    }

    // Construct URL with all relevant parameters
    let params = new URLSearchParams();
    if (siteSelect) params.append('site_id', siteSelect.value);
    if (startDateInput) params.append('start_date', startDateInput.value);
    if (endDateInput) params.append('end_date', endDateInput.value);

    // Preserve current tab if possible
    const activeTabLink = document.querySelector('#reportTabs .nav-link.active');
    if (activeTabLink) {
        params.append('active_tab', activeTabLink.getAttribute('href'));
    }
    
    // Redirect to update the page with new filters
    window.location.href = 'reports.php?' + params.toString();
}


// --- Custom Report Builder Logic ---
document.addEventListener('DOMContentLoaded', function() {
    const customReportForm = document.getElementById('custom-report-form');
    const generateBtn = document.getElementById('generate-custom-report-btn');
    const loadingSpinner = document.getElementById('custom-report-loading');
    const outputArea = document.getElementById('custom-report-output-area');

    // Site selector and date inputs for the main page filter
    const siteSelect = document.getElementById('report-site-select');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');

    if (customReportForm && generateBtn) {
        customReportForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (generateBtn.disabled) return;

            generateBtn.disabled = true;
            if(loadingSpinner) loadingSpinner.classList.remove('d-none');
            if(outputArea) outputArea.innerHTML = '<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading report data...</p>'; // Initial loading message

            const formData = new FormData(customReportForm);

            // Append site_id, start_date, and end_date from the main page filters
            // These are crucial for the AJAX handler to know the context of the custom report
            if (siteSelect) formData.append('site_id', siteSelect.value);
            if (startDateInput) formData.append('start_date', startDateInput.value);
            if (endDateInput) formData.append('end_date', endDateInput.value);
            
            // Log formData for debugging
            // console.log("Custom Report FormData being sent:");
            // for (let [key, value] of formData.entries()) {
            //     console.log(key, value);
            // }


            fetch('ajax_report_handler.php?action=generate_custom_report', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => { throw new Error('Network response was not ok: ' + text); });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    renderCustomReport(data.data.report_type || data.data.chartType, data.data, data.data.report_title, data.data.error_message);
                } else {
                    const errorMessage = data.error_message || 'An unknown error occurred while generating the report.';
                    displayMessage('custom-report-output-area', `Error: ${errorMessage}`, 'error');
                }
            })
            .catch(error => {
                console.error('Error generating custom report:', error);
                displayMessage('custom-report-output-area', `Request failed: ${error.message}. Please check console for details.`, 'error');
            })
            .finally(() => {
                generateBtn.disabled = false;
                if(loadingSpinner) loadingSpinner.classList.add('d-none');
            });
        });
    }
});

function renderCustomReport(type, reportDataContainer, title, userProvidedErrorMessage) { // Renamed errorMessage param
    const outputArea = document.getElementById('custom-report-output-area');
    if (!outputArea) return;

    outputArea.innerHTML = ''; // Clear previous content

    // Handle explicit error message from AJAX call if any
    if (userProvidedErrorMessage) {
        displayMessage('custom-report-output-area', `Error: ${userProvidedErrorMessage}`, 'error');
        // Optionally return if the error is fatal, or let it try to render what it can
    }

    // Handle 'nodata' type from backend first
    if (type === 'nodata') {
        if (reportDataContainer && reportDataContainer.html) {
            outputArea.innerHTML = reportDataContainer.html; // This HTML comes from the backend
        } else {
            // Fallback if html is missing for nodata type
            displayMessage('custom-report-output-area', 'No data available for the selected criteria.', 'info');
        }
        return;
    }

    // Determine actual title for the report
    let actualTitleText = 'Custom Report'; // Default title
    if (type === 'table') {
        actualTitleText = 'Custom Data Table';
    } else if (type === 'bar') {
        actualTitleText = 'Custom Bar Chart';
    } else if (type === 'line') {
        actualTitleText = 'Custom Line Chart';
    }
    
    const reportTitleElement = document.createElement('h3');
    reportTitleElement.className = 'report-output-title text-center mb-3';
    reportTitleElement.textContent = title || actualTitleText; // Use title from AJAX if provided, else default
    outputArea.appendChild(reportTitleElement);

    // Proceed to render based on type if not 'nodata'
    if (type === 'table') {
        if (reportDataContainer && typeof reportDataContainer.html === 'string') {
            // The title is already added, create a container for the HTML table and append it
            const tableContentDiv = document.createElement('div');
            tableContentDiv.innerHTML = reportDataContainer.html;
            outputArea.appendChild(tableContentDiv);
        } else {
            // If HTML is missing for table type (and not 'nodata')
            displayMessage('custom-report-output-area', 'Report data for table is missing or invalid.', 'error');
        }
    } else if (type === 'bar' || type === 'line') {
        if (reportDataContainer && reportDataContainer.chartData && reportDataContainer.chartData.labels) {
            const canvasContainer = document.createElement('div');
            canvasContainer.style.maxWidth = '800px';
            canvasContainer.style.margin = '0 auto';
            const canvas = document.createElement('canvas');
            canvas.id = 'customReportChartCanvas';
            canvasContainer.appendChild(canvas);
            outputArea.appendChild(canvasContainer);

            if (currentCustomReportChart) {
                currentCustomReportChart.destroy();
            }
            currentCustomReportChart = new Chart(canvas, {
                type: type,
                data: reportDataContainer.chartData, // Use .chartData from the container
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    },
                    plugins: {
                        legend: {
                            display: reportDataContainer.chartData.datasets && reportDataContainer.chartData.datasets.length > 1
                        },
                        title: { display: false } // Title is handled by reportTitleElement
                    }
                }
            });
        } else {
            // If chartData is missing for chart types (and not 'nodata')
            displayMessage('custom-report-output-area', 'Report data for chart is missing or invalid.', 'error');
        }
    } else {
        // Fallback for any other type that wasn't 'nodata', 'table', 'bar', or 'line'
        displayMessage('custom-report-output-area', 'Unsupported or unknown report type encountered.', 'error');
    }
}


function createHtmlTable(data) {
    if (!data || data.length === 0) {
        const p = document.createElement('p');
        p.textContent = 'No data to display in table.';
        return p;
    }

    const table = document.createElement('table');
    table.className = 'table table-striped table-bordered table-hover table-sm custom-report-table'; // Added table-sm for smaller padding

    // Create table header
    const thead = table.createTHead();
    const headerRow = thead.insertRow();
    // Assuming all objects in data array have the same keys for columns
    const headers = Object.keys(data[0]);
    headers.forEach(headerText => {
        const th = document.createElement('th');
        th.textContent = headerText.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()); // Format header
        headerRow.appendChild(th);
    });

    // Create table body
    const tbody = table.createTBody();
    data.forEach(rowData => {
        const row = tbody.insertRow();
        headers.forEach(header => {
            const cell = row.insertCell();
            cell.textContent = rowData[header] !== null && rowData[header] !== undefined ? rowData[header] : 'N/A';
        });
    });

    return table;
}


// --- Allocation Report Logic ---
document.addEventListener('DOMContentLoaded', function() {
    const allocationReportForm = document.getElementById('allocation-report-form');
    const generateAllocationBtn = document.getElementById('generate-allocation-report-btn');
    const exportAllocationBtn = document.getElementById('export-allocation-report-btn');
    const allocationLoadingSpinner = document.getElementById('allocation-report-loading');
    const allocationOutputArea = document.getElementById('allocation-report-output-area');

    if (allocationReportForm && generateAllocationBtn) {
        allocationReportForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (generateAllocationBtn.disabled) return;

            generateAllocationBtn.disabled = true;
            if (exportAllocationBtn) exportAllocationBtn.disabled = true;
            if (allocationLoadingSpinner) allocationLoadingSpinner.classList.remove('d-none');
            if (allocationOutputArea) allocationOutputArea.innerHTML = '<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading allocation report...</p>';

            const formData = new FormData(allocationReportForm);
            
            // Log formData for debugging
            console.log("Allocation Report FormData being sent:");
            for (let [key, value] of formData.entries()) {
                console.log(key, value);
            }
            const allocationReportUrl = 'ajax_report_handler.php?action=generate_allocation_report';
            console.log('Allocation Report Fetch URL:', allocationReportUrl);

            fetch(allocationReportUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => { throw new Error('Network response was not ok: ' + text); });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    renderAllocationReport(data.report_data, data.report_title, data.summary_stats, data.error_message);
                } else {
                    const errorMessage = data.error_message || 'An unknown error occurred while generating the allocation report.';
                    displayMessage('allocation-report-output-area', `Error: ${errorMessage}`, 'error');
                }
            })
            .catch(error => {
                console.error('Error generating allocation report:', error);
                displayMessage('allocation-report-output-area', `Request failed: ${error.message}. Please check console for details.`, 'error');
            })
            .finally(() => {
                generateAllocationBtn.disabled = false;
                if (exportAllocationBtn) exportAllocationBtn.disabled = false;
                if (allocationLoadingSpinner) allocationLoadingSpinner.classList.add('d-none');
            });
        });
    }
    
    if (exportAllocationBtn) {
        exportAllocationBtn.addEventListener('click', function() {
            const formData = new FormData(allocationReportForm);
            const queryString = new URLSearchParams(formData).toString();
            const exportUrl = `ajax_report_handler.php?action=export_allocation_report&${queryString}`;
            window.open(exportUrl, '_blank');
        });
    }
});

function renderAllocationReport(data, title, summaryStats, errorMessage) {
    const outputArea = document.getElementById('allocation-report-output-area');
    if (!outputArea) return;

    outputArea.innerHTML = ''; // Clear previous content

    if (errorMessage) {
        displayMessage('allocation-report-output-area', `Note: ${errorMessage}`, 'warning');
    }

    if (!data || data.length === 0) {
        displayMessage('allocation-report-output-area', 'No allocation data available for the selected criteria.', 'info');
        return;
    }

    const reportTitleElement = document.createElement('h3');
    reportTitleElement.className = 'report-output-title text-center mb-3';
    reportTitleElement.textContent = title || 'Allocation Report';
    outputArea.appendChild(reportTitleElement);

    // Display Summary Statistics (if available)
    if (summaryStats && Object.keys(summaryStats).length > 0) {
        const summaryDiv = document.createElement('div');
        summaryDiv.className = 'allocation-summary-stats mb-3 p-3 border rounded bg-light';
        let summaryHtml = '<h4 class="mb-2">Summary Statistics</h4><dl class="row mb-0">';
        for (const [key, value] of Object.entries(summaryStats)) {
            const formattedKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            const formattedValue = (typeof value === 'number' && key.toLowerCase().includes('amount')) 
                                 ? `$${parseFloat(value).toFixed(2)}` 
                                 : value;
            summaryHtml += `<dt class="col-sm-4">${formattedKey}:</dt><dd class="col-sm-8">${formattedValue}</dd>`;
        }
        summaryHtml += '</dl>';
        summaryDiv.innerHTML = summaryHtml;
        outputArea.appendChild(summaryDiv);
    }
    
    // Display Data Table
    outputArea.appendChild(createHtmlTable(data)); // Re-use the same table creation function
}


// --- Tab Persistence Logic ---
document.addEventListener('DOMContentLoaded', function () {
    // Function to activate a tab
    function activateTab(tabId) {
        if (tabId) {
            const tabLink = document.querySelector(`.nav-tabs a[href="${tabId}"]`);
            if (tabLink) {
                // Using Bootstrap's JavaScript to show the tab
                // Ensure jQuery and Bootstrap JS are loaded if using Bootstrap v4
                $(tabLink).tab('show');
            }
        }
    }

    // Check for active_tab in URL on page load
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabFromUrl = urlParams.get('active_tab');
    if (activeTabFromUrl) {
        activateTab(activeTabFromUrl);
    }

    // Store active tab in localStorage when a tab is shown
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        localStorage.setItem('activeReportTab', $(e.target).attr('href'));
    });

    // On page load, if no URL param, try to load from localStorage
    if (!activeTabFromUrl) {
        const activeTabFromStorage = localStorage.getItem('activeReportTab');
        if (activeTabFromStorage) {
            activateTab(activeTabFromStorage);
        }
    }
});


</script>
]]>