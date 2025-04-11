<?php
/*
 * File: reports.php
 * Path: /reports.php
 * Created: 2024-08-01 13:00:00 MST
 * Author: Robert Archer
 * Updated: 2025-04-08 - Corrected dynamic column name handling for reports.
 * Description: Provides reporting capabilities for check-in data.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication & database connection
require_once 'includes/db_connect.php'; // Provides $pdo and helper functions
require_once 'includes/auth.php';       // Ensures user is logged in, provides $_SESSION['active_role'] etc.

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

try {
    if ($user_can_select_sites) {
        $stmt_sites = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
        $sites = $stmt_sites->fetchAll(PDO::FETCH_ASSOC);
    } elseif (isset($_SESSION['site_id']) && $_SESSION['site_id'] !== null) { // Use 'site_id' from session if consistent
        $stmt_sites = $pdo->prepare("SELECT id, name FROM sites WHERE id = :site_id AND is_active = TRUE");
        $stmt_sites->execute([':site_id' => $_SESSION['site_id']]);
        $sites = $stmt_sites->fetchAll(PDO::FETCH_ASSOC);
        if (empty($sites)) $report_error_message .= " Your assigned site is inactive or could not be found.";
    } else {
        if ($_SESSION['active_role'] == 'site_supervisor') $report_error_message .= " No site is assigned to your account.";
    }
} catch (PDOException $e) {
    error_log("Reports Error - Fetching sites: " . $e->getMessage());
    $report_error_message .= " Error loading site list.";
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

try {
    // Fetch the BASE question titles associated with active questions for the filtered sites
    $sql_q_report = "SELECT DISTINCT gq.question_title -- Use DISTINCT for safety
                     FROM global_questions gq
                     JOIN site_questions sq ON gq.id = sq.global_question_id
                     WHERE sq.is_active = TRUE"; // Only include active assignments

    $params_q_report = [];
    if ($selected_site_id !== 'all' && $selected_site_id !== null) {
        $sql_q_report .= " AND sq.site_id = :site_id_filter"; // Filter by site
        $params_q_report[':site_id_filter'] = $selected_site_id;
    }
     $sql_q_report .= " ORDER BY gq.question_title ASC"; // Add consistent ordering

    $stmt_q_report = $pdo->prepare($sql_q_report);
    $stmt_q_report->execute($params_q_report);
    // Fetch the BASE titles (e.g., 'needs_assistance', 'free_test')
    $report_base_titles = $stmt_q_report->fetchAll(PDO::FETCH_COLUMN);

   // --- Inside reports.php, loop processing fetched $report_base_titles ---
if ($report_base_titles) {
     foreach ($report_base_titles as $base_title) {
         if (!empty($base_title)) {
             // --- FIX THIS LINE ---
             // $sanitized_base = sanitize_question_title_for_column($base_title); // OLD NAME
             $sanitized_base = sanitize_title_to_base_name($base_title); // <<< NEW NAME

             if (!empty($sanitized_base)) {
                 $prefixed_col_name = 'q_' . $sanitized_base;
                 $formatted_label = format_base_name_for_display($sanitized_base); // Use the other new function

                 $report_question_columns[] = $prefixed_col_name;
                 $report_chart_labels[] = $formatted_label;
                 $report_column_to_label_map[$prefixed_col_name] = $formatted_label;
             } // else log warning
         }
     }
 }
// --- End Fix ---
 } catch (PDOException $e) {
     error_log("Reports Error - Fetching active question columns: " . $e->getMessage());
     $report_error_message .= " Error loading question list for report.";
 }

 // Prepare JSON for JavaScript Chart Labels
 $chart_labels_json = json_encode($report_chart_labels); // Use the formatted labels

 // Log the prefixed column names (useful for debugging data fetching)
 error_log("Reports Debug - Filter ID: {$selected_site_id} - Active question columns for data fetch (prefixed): " . print_r($report_question_columns, true));
 // Log the formatted labels (useful for debugging chart display)
 // error_log("Reports Debug - Formatted chart labels: " . print_r($report_chart_labels, true));

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
if ($selected_site_id !== null || ($selected_site_id === 'all' && $user_can_select_sites) ) { // Ensure 'all' is only valid for those allowed
    try {
        // Count Total Records
        $sql_count = "SELECT COUNT(ci.id) FROM check_ins ci $where_sql";
        $stmt_count = $pdo->prepare($sql_count);
        if ($stmt_count === false) throw new PDOException("Failed to prepare count query.");
        $stmt_count->execute($params_data);
        $total_records = (int) $stmt_count->fetchColumn();

        if ($total_records > 0) {
            $total_pages = ceil($total_records / $results_per_page);
            // Validate current page against total pages
            $current_page = max(1, min($current_page, $total_pages));
            $offset = ($current_page - 1) * $results_per_page;

            // Fetch Paginated Data including dynamic columns
            // --- FIX: Use the prefixed names from $report_question_columns ---
            $dynamic_select_sql = "";
            if (!empty($report_question_columns)) {
                 // Sanitize and wrap each prefixed column name in backticks
                 $safe_dynamic_cols = array_map(function($col) {
                     // Double check format just in case
                     if (preg_match('/^q_[a-zA-Z0-9_]+$/', $col)) {
                         return "`" . $col . "`";
                     }
                     return null; // Skip invalid names
                 }, $report_question_columns);
                 $safe_dynamic_cols = array_filter($safe_dynamic_cols); // Remove nulls
                 if (!empty($safe_dynamic_cols)) {
                     $dynamic_select_sql = ", " . implode(", ", $safe_dynamic_cols);
                 }
            }
            // --- END FIX ---

            $sql_data = "SELECT
                            ci.id, ci.first_name, ci.last_name, ci.check_in_time, ci.client_email,
                            s.name as site_name, sn.staff_name as notified_staff
                            {$dynamic_select_sql}
                        FROM
                            check_ins ci
                        JOIN
                            sites s ON ci.site_id = s.id
                        LEFT JOIN
                            staff_notifications sn ON ci.notified_staff_id = sn.id
                        {$where_sql}
                        ORDER BY ci.check_in_time DESC
                        LIMIT :limit OFFSET :offset";

            $stmt_data = $pdo->prepare($sql_data); // Line 182 (approx)
             if ($stmt_data === false) {
                 throw new PDOException("Failed to prepare main data query. SQL: {$sql_data}");
             }

            // Bind WHERE parameters
            foreach ($params_data as $key => &$val) {
                 $type = (strpos($key, 'site_id') !== false) ? PDO::PARAM_INT : PDO::PARAM_STR;
                 $stmt_data->bindParam($key, $val, $type); // Line 187 (approx) - Should work now
            }
            unset($val); // Unset reference

            // Bind LIMIT and OFFSET
            $stmt_data->bindValue(':limit', $results_per_page, PDO::PARAM_INT);
            $stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);

            if (!$stmt_data->execute()) {
                 throw new PDOException("Failed to execute main data query.");
            }
            $report_data = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

        } else { $report_data = []; $total_pages = 0; $current_page = 1; }

    } catch (PDOException $e) {
        error_log("Reports Error - Fetching data: " . $e->getMessage());
        $report_error_message .= " Error fetching report data.";
        $report_data = []; $total_records = 0; $total_pages = 0; $current_page = 1;
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

            <!-- Custom Report Builder Placeholder -->
             <?php if ($user_can_select_sites): // Show only to Admin/Director ?>
             <div class="content-section">
                 <h2 class="section-title">Custom Report Builder (Placeholder)</h2>
                  <div class="settings-form">
                        <div class="form-group"><label class="form-label">Metrics:</label><select class="form-control" multiple><option selected>Total Check-ins</option><option>Q: Needs Assistance</option></select></div>
                         <div class="form-group"><label class="form-label">Group By:</label><select class="form-control"><option>Day</option><option>Site</option></select></div>
                        <div class="form-group"><label class="form-label">Chart Type:</label><select class="form-control"><option>Data Table</option><option>Bar</option></select></div>
                  </div>
                   <div class="form-actions"><button class="btn btn-primary" onclick="alert('Generate Custom Report TBD');"><i class="fas fa-chart-bar"></i> Generate</button></div>
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
                                // Dynamically generate question headers using the map (prefixed name => base title)
                                if (!empty($report_question_map)) {
                                    foreach ($report_question_map as $prefixed_col => $base_title) {
                                        // Display formatted base title for header
                                        echo "<th>" . htmlspecialchars(ucwords(str_replace('_', ' ', $base_title))) . "</th>";
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
    console.log("Report page script loaded. Initializing charts (if applicable)..."); // <<< MOVED LOG HERE

    // --- Chart 1: Check-ins Over Time (Placeholder) ---
    const ctxTimeReport = document.getElementById('reportCheckinsChart');
    if (ctxTimeReport) {
         // --- TODO: Replace with actual PHP data aggregation for time chart ---
         const timeLabels = ['Day 1', 'Day 2', 'Day 3']; // Example
         const timeData = [5, 8, 3]; // Example
         // --- End TODO ---

         if (timeLabels.length > 0) {
            // console.log("Initializing Time Chart..."); // Optional: keep if you want more detail
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
            const ctx = ctxTimeReport.getContext('2d');
            ctx.textAlign = 'center';
            ctx.fillStyle = 'var(--color-gray)';
            ctx.fillText('No time data available for chart.', ctxTimeReport.canvas.width / 2, ctxTimeReport.canvas.height / 2);
         }
    }

    // --- Chart 2: Question Responses (Using Formatted Labels) ---
    const ctxQuestionsReport = document.getElementById('reportQuestionsChart');
    if(ctxQuestionsReport) {
         // --- Use the JSON variables generated by PHP ---
         const questionChartLabels = <?php echo $chart_labels_json ?? '[]'; ?>;
         const questionColumns = <?php echo json_encode($report_question_columns ?? '[]'); ?>;
         const reportData = <?php echo empty($report_data) ? '[]' : json_encode($report_data); ?>;

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
            // console.log("Initializing Questions Chart..."); // Optional: keep if you want more detail
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
            const ctx = ctxQuestionsReport.getContext('2d');
            ctx.textAlign = 'center';
            ctx.fillStyle = 'var(--color-gray)';
            ctx.fillText('No question data available for chart.', ctxQuestionsReport.canvas.width / 2, ctxQuestionsReport.canvas.height / 2);
         }
    }
});
</script>