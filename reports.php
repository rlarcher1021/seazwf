<?php
/*
 * File: reports.php
 * Path: /reports.php
 * Created: 2024-08-01 13:00:00 MST

 * Updated: 2025-04-08 - Corrected dynamic column name handling for reports.
 * Description: Provides reporting capabilities for check-in data.
 */
// --- AJAX Handler for Custom Report Builder ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_custom_report') {

    // --- Essential Setup for AJAX Request ---
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once 'includes/db_connect.php'; // Need $pdo and helpers
    require_once 'includes/auth.php';       // Need role checks, session data

    // --- Response Helper Function ---
    function send_json_response($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    function send_html_response($html) {
        header('Content-Type: text/html');
        echo $html;
        exit;
    }
     function send_error_response($message, $statusCode = 400) {
         http_response_code($statusCode);
         // Can send JSON error or simple text
         header('Content-Type: text/plain'); // Keep it simple for errors
         echo "Error: " . $message;
         // Log the detailed error server-side
         error_log("Custom Report AJAX Error: " . $message);
         exit;
     }


    // --- Permission Check ---
    $allowedRoles = ['director', 'administrator']; // Only Director/Admin can use custom builder
    if (!isset($_SESSION['active_role']) || !in_array($_SESSION['active_role'], $allowedRoles)) {
        send_error_response("Permission Denied.", 403);
    }

    // --- Input Validation & Sanitization ---
    $site_id = $_POST['site_id'] ?? 'all';
    $start_date_str = $_POST['start_date'] ?? null;
    $end_date_str = $_POST['end_date'] ?? null;
    $metrics = isset($_POST['metrics']) && is_array($_POST['metrics']) ? $_POST['metrics'] : [];
    $group_by = $_POST['group_by'] ?? 'none';
    $output_type = $_POST['output_type'] ?? 'table';

    // Validate Site ID
    if ($site_id !== 'all') {
        if (!is_numeric($site_id)) {
            send_error_response("Invalid Site ID format.");
        }
        $site_id = intval($site_id);
         // Optional: Further check if this site ID is valid/accessible by the user, though main page load does this.
         // For simplicity here, assume valid if numeric.
    } elseif ($_SESSION['active_role'] !== 'administrator' && $_SESSION['active_role'] !== 'director') {
         send_error_response("Insufficient permissions for 'All Sites'.", 403); // Should be caught by role check above, but double-check
    }


    // Validate Dates (basic format check)
    $default_end_date = date('Y-m-d');
    $default_start_date = date('Y-m-d', strtotime('-29 days'));
    $filter_start_date = preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date_str) ? $start_date_str : $default_start_date;
    $filter_end_date = preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date_str) ? $end_date_str : $default_end_date;

    // Validate Metrics
    if (empty($metrics)) {
        send_error_response("Please select at least one metric.");
    }
    $validated_metrics = [];
    $question_metric_columns = []; // Store only the q_... column names
     $metric_labels = []; // Map internal metric name -> User-friendly label

    // Fetch valid question base names for validation if needed (alternative to direct column check)
    try {
        $stmt_q = $pdo->query("SELECT question_title FROM global_questions"); // Get all base names
        $valid_base_names = $stmt_q->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
         send_error_response("Database error fetching question list.", 500);
    }

    foreach ($metrics as $metric) {
        if ($metric === 'total_checkins') {
            $validated_metrics[] = $metric;
             $metric_labels[$metric] = 'Total Check-ins';
        } elseif (strpos($metric, 'q_') === 0) {
             // Validate the q_... column name format and if the base name exists
             $base_name = substr($metric, 2); // Remove 'q_'
             if (preg_match('/^[a-zA-Z0-9_]+$/', $metric) && in_array($base_name, $valid_base_names)) {
                $validated_metrics[] = $metric;
                $question_metric_columns[] = $metric; // Keep track of question columns requested
                 // Generate label (requires the helper function)
                 if (function_exists('format_base_name_for_display')) {
                      $metric_labels[$metric] = 'Q: ' . format_base_name_for_display($base_name) . ' (Yes Count)';
                 } else {
                      $metric_labels[$metric] = $metric . ' (Yes Count)'; // Fallback label
                 }
             } else {
                  error_log("Custom Report Warning: Invalid or non-existent metric skipped: " . $metric);
                  // Optionally notify user which metric was skipped, or just ignore it silently
             }
        } else {
             error_log("Custom Report Warning: Invalid metric format skipped: " . $metric);
        }
    }
     if (empty($validated_metrics)) {
         send_error_response("No valid metrics selected or found.");
     }

    // Validate Group By
    $allowed_group_by = ['none', 'day', 'week', 'month'];
    if ($site_id === 'all') {
        $allowed_group_by[] = 'site'; // Only allow group by site if viewing all sites
    }
    if (!in_array($group_by, $allowed_group_by)) {
        send_error_response("Invalid Group By option selected.");
    }

    // Validate Output Type
    $allowed_output_types = ['table', 'bar', 'line'];
    if (!in_array($output_type, $allowed_output_types)) {
        send_error_response("Invalid Output Type selected.");
    }

    // --- Build SQL Query ---
    $select_clauses = [];
    $group_by_sql = "";
    $order_by_sql = "";
    $params = [];
    $grouping_column_alias = 'grouping_key'; // Consistent alias for the grouping column
     $grouping_label = 'Group'; // Default header/axis label

    // Setup Grouping
    switch ($group_by) {
        case 'day':
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m-%d') AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
             $grouping_label = 'Day';
            break;
        case 'week':
            // Using YEARWEEK ensures weeks don't mix across years
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%x-%v') AS {$grouping_column_alias}"; // e.g., 2024-45
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
             $grouping_label = 'Week';
            break;
        case 'month':
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m') AS {$grouping_column_alias}"; // e.g., 2024-11
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
             $grouping_label = 'Month';
            break;
        case 'site':
            // Assumes $site_id is 'all' (validated earlier)
            $select_clauses[] = "s.name AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY ci.site_id, s.name"; // Group by ID and Name
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
             $grouping_label = 'Site';
            break;
        case 'none':
        default:
             // No grouping column in SELECT or GROUP BY
             $grouping_column_alias = null; // Indicate no grouping
             $grouping_label = 'Overall'; // Label for the single result row/bar
            break;
    }

    // Setup Metrics in SELECT
    foreach ($validated_metrics as $metric) {
        if ($metric === 'total_checkins') {
            $select_clauses[] = "COUNT(ci.id) AS total_checkins";
        } elseif (in_array($metric, $question_metric_columns)) {
             // Safely use the validated column name. Alias it for clarity.
             $alias = $metric . '_yes_count'; // e.g., q_needs_help_yes_count
             $select_clauses[] = "SUM(CASE WHEN ci.`" . $metric . "` = 'YES' THEN 1 ELSE 0 END) AS `" . $alias . "`";
        }
    }

    // Setup WHERE Clause
    $where_clauses = [];
    if ($site_id !== 'all') {
        $where_clauses[] = "ci.site_id = :site_id";
        $params[':site_id'] = $site_id;
    }
    if ($filter_start_date) {
        $where_clauses[] = "ci.check_in_time >= :start_date";
        $params[':start_date'] = $filter_start_date . ' 00:00:00';
    }
    if ($filter_end_date) {
        $where_clauses[] = "ci.check_in_time <= :end_date";
        $params[':end_date'] = $filter_end_date . ' 23:59:59';
    }
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Final SQL Assembly
    $sql = "SELECT " . implode(', ', $select_clauses) . "
            FROM check_ins ci ";
            error_log("--- Custom Report Debug ---");
error_log("Generated SQL: " . $sql);
error_log("SQL Parameters: " . print_r($params, true));
    // Add JOIN only if grouping by site
    if ($group_by === 'site') {
         $sql .= " JOIN sites s ON ci.site_id = s.id ";
    }
     $sql .= $where_sql . " "
           . $group_by_sql . " "
           . $order_by_sql;

    // --- Execute Query ---
    try {
        $stmt = $pdo->prepare($sql);
         // Bind parameters carefully based on type
         foreach ($params as $key => &$val) { // Use reference needed for bindParam/bindValue scope
             if ($key === ':site_id') {
                 $stmt->bindValue($key, $val, PDO::PARAM_INT);
             } else {
                 $stmt->bindValue($key, $val, PDO::PARAM_STR);
             }
         }
         unset($val); // Break the reference

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Raw DB Results: " . print_r($results, true));
        
    } catch (PDOException $e) {
        error_log("Custom Report SQL Error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . print_r($params, true));
        send_error_response("Database query failed executing custom report.", 500);
    }

    // --- Format and Send Response ---
    if (empty($results)) {
         // Send back a 'no results' message suitable for the container
         send_html_response('<p style="text-align: center; color: var(--color-gray);">No data found matching the selected criteria.</p>');
    }

    // ** Output: Table **
    if ($output_type === 'table') {
        $html = '<div class="table-container custom-report-table">'; // Add specific class
        $html .= '<table>';
        $html .= '<thead><tr>';
        if ($grouping_column_alias) {
             $html .= '<th>' . htmlspecialchars($grouping_label) . '</th>'; // Header for grouping column
        } else {
             // If no grouping, we might still need a conceptual first column label
             $html .= '<th>' . htmlspecialchars($grouping_label) . '</th>';
        }
        foreach ($validated_metrics as $metric) {
             $label = $metric_labels[$metric] ?? $metric; // Use generated label
            $html .= '<th>' . htmlspecialchars($label) . '</th>';
        }
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($results as $row) {
            $html .= '<tr>';
            // Display grouping key (or 'Overall' if none)
             if ($grouping_column_alias) {
                $html .= '<td>' . htmlspecialchars($row[$grouping_column_alias] ?? 'N/A') . '</td>';
             } else {
                 $html .= '<td>' . htmlspecialchars($grouping_label) . '</td>'; // Single row case
             }
            // Display metric values
            foreach ($validated_metrics as $metric) {
                $value_key = $metric;
                if (in_array($metric, $question_metric_columns)) {
                     $value_key = $metric . '_yes_count'; // Use the alias from the SELECT clause
                }
                 // Ensure the key exists and format the number nicely
                 $display_value = isset($row[$value_key]) ? number_format($row[$value_key]) : '0';
                $html .= '<td>' . htmlspecialchars($display_value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
        send_html_response($html);
    }

    // ** Output: Chart (Bar or Line) **
    elseif ($output_type === 'bar' || $output_type === 'line') {
        $labels = [];
        $datasets_data = []; // Prepare data structure for datasets

         // Initialize data arrays for each metric
         foreach($validated_metrics as $metric) {
             $value_key = $metric;
             if (in_array($metric, $question_metric_columns)) {
                 $value_key = $metric . '_yes_count';
             }
             $datasets_data[$metric] = [
                 'label' => $metric_labels[$metric] ?? $metric,
                 'data' => [],
                 'value_key' => $value_key // Store the key to look up in results
             ];
         }

        // Populate labels and dataset data
        foreach ($results as $row) {
            // Add label (grouping key or 'Overall')
            if ($grouping_column_alias) {
                 $labels[] = $row[$grouping_column_alias] ?? 'N/A';
            } elseif (empty($labels)) { // Only add 'Overall' once if no grouping
                 $labels[] = $grouping_label;
            }

            // Add data for each metric for this label
            foreach ($validated_metrics as $metric) {
                 $key_to_lookup = $datasets_data[$metric]['value_key'];
                 // Add the numeric value (or 0 if null/missing)
                 $datasets_data[$metric]['data'][] = isset($row[$key_to_lookup]) ? (int)$row[$key_to_lookup] : 0;
            }
        }

        // Define some basic colors (can be expanded)
         $chart_colors = [
             ['border' => 'rgba(30, 58, 138, 1)', 'bg' => 'rgba(30, 58, 138, 0.7)'],   // Primary Blue
             ['border' => 'rgba(255, 107, 53, 1)', 'bg' => 'rgba(255, 107, 53, 0.7)'],   // Secondary Orange
             ['border' => 'rgba(34, 197, 94, 1)', 'bg' => 'rgba(34, 197, 94, 0.7)'],   // Green
             ['border' => 'rgba(234, 179, 8, 1)',  'bg' => 'rgba(234, 179, 8, 0.7)'],   // Yellow
             ['border' => 'rgba(139, 92, 246, 1)', 'bg' => 'rgba(139, 92, 246, 0.7)'],  // Purple
             ['border' => 'rgba(236, 72, 153, 1)', 'bg' => 'rgba(236, 72, 153, 0.7)'],  // Pink
         ];
         $color_index = 0;

        // Finalize datasets array for Chart.js
        $chart_datasets = [];
        foreach ($datasets_data as $metric_data) {
             $color = $chart_colors[$color_index % count($chart_colors)]; // Cycle through colors
             $chart_datasets[] = [
                 'label' => $metric_data['label'],
                 'data' => $metric_data['data'],
                 'backgroundColor' => $color['bg'],
                 'borderColor' => $color['border'],
                 'borderWidth' => 1,
                 'tension' => ($output_type === 'line' ? 0.1 : 0) // Add slight tension for line charts
             ];
             $color_index++;
        }


        // Prepare JSON Response for Chart
        $responseJson = [
            'html' => '<canvas id="custom-report-chart" style="max-height: 400px; width: 100%;"></canvas>', // Canvas HTML
            'chartType' => $output_type, // 'bar' or 'line'
            'chartData' => [
                'labels' => $labels,
                'datasets' => $chart_datasets
            ]
        ];
        send_json_response($responseJson);
    }

    // Should not reach here if output type is valid
    send_error_response("An unexpected error occurred processing the report type.", 500);

} // --- END of AJAX Handler Block ---

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
            fetch('reports.php', {
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