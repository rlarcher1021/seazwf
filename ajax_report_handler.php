<?php
/*
 * File: ajax_report_handler.php
 * Path: /ajax_report_handler.php
 * Created: 2025-04-14
 * Description: Handles AJAX requests for the Custom Report Builder.
 */

// --- Essential Setup for AJAX Request ---
// Use __DIR__ for robust path resolution
if (session_status() === PHP_SESSION_NONE) {
    // Start session if not already started (assuming auth.php might not always start it in an AJAX context)
    session_start();
}
require_once __DIR__ . '/includes/db_connect.php'; // Provides $pdo
require_once __DIR__ . '/includes/auth.php';       // Need role checks, session data
require_once __DIR__ . '/includes/utils.php';      // Provides format_base_name_for_display, sanitize_title_to_base_name
require_once __DIR__ . '/includes/data_access/question_data.php'; // Provides getAllGlobalQuestionTitles
require_once __DIR__ . '/includes/data_access/checkin_data.php'; // Provides generateCustomReportData

// --- Response Helper Functions ---
function send_json_response($data, $success = true) {
    header('Content-Type: application/json');
    // Wrap the data in a standard structure
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $success ? '' : $data]);
    exit;
}
function send_html_response($html) {
    header('Content-Type: text/html');
    echo $html;
    exit;
}
 function send_error_response($message, $statusCode = 400, $isJson = false) {
     http_response_code($statusCode);
     if ($isJson) {
         send_json_response($message, false); // Use the JSON helper for JSON errors
     } else {
         header('Content-Type: text/plain'); // Keep it simple for non-JSON errors
         echo "Error: " . $message;
         // Log the detailed error server-side
         error_log("AJAX Handler Error: " . $message);
         exit;
     }
 }


// --- Basic Request Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    send_error_response("Invalid Request Method or Missing Action.", 400);
}

$action = $_POST['action'];

// --- Action: Generate Custom Report ---
if ($action === 'generate_custom_report') {

    // --- Permission Check (Moved inside the action block) ---
    $allowedRoles = ['director', 'administrator']; // Only Director/Admin can use custom builder
    if (!isset($_SESSION['active_role']) || !in_array($_SESSION['active_role'], $allowedRoles)) {
        send_error_response("Permission Denied for Custom Report.", 403); // More specific error
    }
    // --- End Permission Check ---


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

    // Fetch valid question base names using data access function
    $valid_base_names = getAllGlobalQuestionTitles($pdo);
    if ($valid_base_names === []) { // Check if function returned empty array (could be error or none found)
        // Decide if this is a fatal error for the custom report builder
        // For now, allow proceeding but validation below will skip question metrics
         error_log("Custom Report AJAX Warning: Could not fetch global question titles for validation.");
         $valid_base_names = []; // Ensure it's an empty array if fetch failed
    }

    foreach ($metrics as $metric) {
        if ($metric === 'total_checkins') {
            $validated_metrics[] = $metric;
             $metric_labels[$metric] = 'Total Check-ins';
        } elseif (strpos($metric, 'q_') === 0) {
             // Validate the q_... column name format and if the base name exists
             $base_name = substr($metric, 2); // Remove 'q_'
             // Validate format AND existence using fetched base names AND utility function
             if (preg_match('/^q_[a-z0-9_]+$/', $metric) && strlen($metric) <= 64) {
                 // Check if the base name derived from the metric exists in our fetched list
                 $derived_base_name = substr($metric, 2);
                 if (in_array($derived_base_name, $valid_base_names)) {
                     $validated_metrics[] = $metric;
                     $question_metric_columns[] = $metric; // Keep track of validated question columns
                     // Generate label using utility function (already required)
                     $metric_labels[$metric] = 'Q: ' . format_base_name_for_display($derived_base_name) . ' (Yes Count)';
                 } else {
                      error_log("Custom Report Warning: Metric '{$metric}' skipped because base name '{$derived_base_name}' not found in global questions.");
                 }
             } else {
                  error_log("Custom Report Warning: Invalid metric format skipped: " . $metric);
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
    // NOTE: The original code built the SQL here but then called a data access function
    // `generateCustomReportData` which likely rebuilds or executes a similar query.
    // For this refactoring, we keep the call to the data access function as it was.
    // The SQL building logic (lines 140-223 in original) is technically redundant if
    // `generateCustomReportData` handles it all, but we keep it for context unless
    // we know for sure the function ignores these specific SQL parts.
    // If `generateCustomReportData` *does* use these parts, they should remain.
    // If it *doesn't*, they could be removed for cleanup, but that's beyond this refactor scope.

    $select_clauses = []; // Kept for context, may not be used by generateCustomReportData
    $group_by_sql = "";   // Kept for context
    $order_by_sql = "";   // Kept for context
    $params = [];         // Kept for context
    $grouping_column_alias = 'grouping_key'; // Consistent alias for the grouping column
     $grouping_label = 'Group'; // Default header/axis label

    // Setup Grouping (Kept for context, may influence generateCustomReportData logic)
    switch ($group_by) {
        case 'day':
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m-%d') AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
             $grouping_label = 'Day';
            break;
        case 'week':
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
            $select_clauses[] = "s.name AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY ci.site_id, s.name"; // Group by ID and Name
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
             $grouping_label = 'Site';
            break;
        case 'none':
        default:
             $grouping_column_alias = null; // Indicate no grouping
             $grouping_label = 'Overall'; // Label for the single result row/bar
            break;
    }

    // Setup Metrics in SELECT (Kept for context)
    foreach ($validated_metrics as $metric) {
        if ($metric === 'total_checkins') {
            $select_clauses[] = "COUNT(ci.id) AS total_checkins";
        } elseif (in_array($metric, $question_metric_columns)) {
             $alias = $metric . '_yes_count'; // e.g., q_needs_help_yes_count
             $select_clauses[] = "SUM(CASE WHEN ci.`" . $metric . "` = 'YES' THEN 1 ELSE 0 END) AS `" . $alias . "`";
        }
    }

    // Setup WHERE Clause (Kept for context)
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

    // Final SQL Assembly (Kept for context)
    $sql = "SELECT " . implode(', ', $select_clauses) . "
            FROM check_ins ci ";
    // Add JOIN only if grouping by site
    if ($group_by === 'site') {
         $sql .= " JOIN sites s ON ci.site_id = s.id ";
    }
     $sql .= $where_sql . " "
           . $group_by_sql . " "
           . $order_by_sql;

    // Log the SQL that *would* be built here, for comparison/debugging with the function
    error_log("--- Custom Report Handler Debug (Context SQL) ---");
    error_log("Context SQL: " . $sql);
    error_log("Context SQL Parameters: " . print_r($params, true));


    // --- Execute Query using Data Access Function ---
    $grouping_column_alias_out = null; // Variable to receive the alias used
    $results = generateCustomReportData(
        $pdo,
        $validated_metrics,
        $question_metric_columns,
        $group_by,
        $site_id, // Use the validated $site_id ('all' or int)
        $filter_start_date,
        $filter_end_date,
        $grouping_column_alias_out // Pass by reference
    );

    // Update the grouping alias based on the output parameter from the function
    $grouping_column_alias = $grouping_column_alias_out;

    if ($results === null) { // Check for failure from the function
        send_error_response("Database query failed executing custom report.", 500);
    }
    error_log("Custom Report Handler - Grouping Alias Used: " . ($grouping_column_alias ?? 'none'));
    error_log("Custom Report Handler - Raw DB Results: " . print_r($results, true));

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
        // Send the JSON directly without the 'success/data' wrapper
        header('Content-Type: application/json');
        echo json_encode($responseJson);
        exit;
    }

    // Should not reach here if output type is valid
    send_error_response("An unexpected error occurred processing the report type.", 500);

// --- Action: Get Question Responses Data for Dashboard Chart ---
} elseif ($action === 'get_question_responses_data') {

    // --- Permission Check (Allow any logged-in user, but validate site access) ---
    if (!isset($_SESSION['user_id'])) {
        send_error_response("Authentication required.", 401, true); // Send JSON error
    }

    // --- Input Validation ---
    $site_id_param = $_POST['site_id'] ?? null;
    $time_frame = $_POST['time_frame'] ?? 'today';
    $user_role = $_SESSION['active_role'] ?? null;
    $user_site_id = $_SESSION['active_site_id'] ?? null;

    // Validate Time Frame
    $allowed_time_frames = ['today', 'last_7_days', 'last_30_days', 'last_365_days'];
    if (!in_array($time_frame, $allowed_time_frames)) {
        send_error_response("Invalid time frame specified.", 400, true);
    }

    // Validate Site ID Access
    $validated_site_id = null;
    if ($user_role === 'administrator' || $user_role === 'director') {
        if ($site_id_param === 'all') {
            $validated_site_id = 'all';
        } elseif (is_numeric($site_id_param)) {
            $validated_site_id = intval($site_id_param);
            // Optional: Check if site exists, but dashboard load usually handles this
        } else {
            send_error_response("Invalid site ID for your role.", 400, true);
        }
    } elseif (in_array($user_role, ['azwk_staff', 'outside_staff'])) {
        if ($user_site_id === null) {
            send_error_response("No site assigned to your account.", 403, true);
        }
        // Supervisor can only request their own site ID
        if ($site_id_param !== null && is_numeric($site_id_param) && intval($site_id_param) === $user_site_id) {
             $validated_site_id = $user_site_id;
        } else {
             // If the dropdown somehow sent a different ID, force it to the user's assigned site or error out
             error_log("Supervisor requested site {$site_id_param} but is assigned to {$user_site_id}. Forcing to assigned site.");
             $validated_site_id = $user_site_id;
             // Alternatively, send an error:
             // send_error_response("You can only view data for your assigned site.", 403, true);
        }
    } else {
        // Other roles (if any) shouldn't access this
        send_error_response("Permission Denied.", 403, true);
    }

    if ($validated_site_id === null && $validated_site_id !== 'all') {
         send_error_response("Could not determine a valid site context.", 400, true);
    }

    // --- Fetch Data using Data Access Function ---
    $chart_data = getAggregatedQuestionResponses($pdo, $validated_site_id, $time_frame);

    if ($chart_data === null) {
        // Function indicated an error during fetch
        send_error_response("Failed to retrieve question response data.", 500, true);
    }

    // --- Send Response ---
    send_json_response($chart_data); // Send the data structure {labels: [...], data: [...]}

} else {
    // --- Unknown Action ---
    send_error_response("Unknown action specified.", 400);
}

?>