<?php
/*
 * File: export_report.php
 * Path: /export_report.php
 * Created: 2024-08-01 15:00:00 MST // Adjust if needed
 * Author: Robert Archer
 * Updated: 2025-04-14 - Refactored DB logic to data access layer.
 *
 * Description: Generates and forces download of a CSV report based on
 *              site and date range filters passed via GET parameters.
 *              Uses active session role and site context.
 */

// --- Initialization and Includes ---
// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication - user must be logged in and session vars set
require_once 'includes/auth.php'; // Ensures user is logged in, sets $_SESSION['active_role'], $_SESSION['active_site_id'] etc.
require_once 'includes/db_connect.php'; // Provides $pdo
require_once 'includes/data_access/site_data.php';     // For site functions
require_once 'includes/data_access/question_data.php'; // For question functions
require_once 'includes/data_access/checkin_data.php';  // For check-in functions

// --- Role Check ---
$allowedRoles = ['azwk_staff', 'outside_staff', 'director', 'administrator'];
if (!isset($_SESSION['active_role']) || !in_array($_SESSION['active_role'], $allowedRoles)) {
    // Prevent direct access or invalid role state
    http_response_code(403); // Forbidden
    die("Access Denied: You do not have permission to export reports.");
}

// --- Get and Validate Filter Parameters ---
$filter_site_id = $_GET['site_id'] ?? null; // From the URL link
$filter_start_date = $_GET['start_date'] ?? null;
$filter_end_date = $_GET['end_date'] ?? null;

// Determine user capabilities based on SESSION role
$can_access_all_sites = ($_SESSION['active_role'] === 'administrator' || $_SESSION['active_role'] === 'director');
$user_assigned_site_id = $_SESSION['active_site_id'] ?? null; // User's actual assigned site (for supervisor check)

// --- Site ID Validation ---
$effective_site_id_for_query = null; // null means 'all' (for roles that can), specific ID otherwise

if (in_array($_SESSION['active_role'], ['azwk_staff', 'outside_staff'])) {
    // Supervisor MUST export their own site. Ignore any passed site_id.
    if ($user_assigned_site_id === null) {
        http_response_code(400);
        die("Export Error: Your user account is not assigned to a specific site.");
    }
    $effective_site_id_for_query = $user_assigned_site_id;

} elseif ($can_access_all_sites) {
    // Admin/Director: Respect the passed site_id filter
    if ($filter_site_id === 'all' || empty($filter_site_id)) {
        $effective_site_id_for_query = null; // Explicitly null for 'all sites' query
    } elseif (is_numeric($filter_site_id)) {
         // Use data access function to validate site
         // Use getActiveSiteById which returns null if site is inactive or not found
         // Note: The initial require_once at the top of the file should be sufficient,
         // but we keep this logic structure after the failed isActiveSite attempts.
         $site_details = getActiveSiteById($pdo, (int)$filter_site_id);
         if ($site_details !== null) {
              $effective_site_id_for_query = (int)$filter_site_id; // Site is valid and active
         } else {
              // Site not found, inactive, or DB error during lookup
              http_response_code(400);
              die("Export Error: The selected site ID ('" . htmlspecialchars($filter_site_id) . "') is invalid, inactive, or could not be verified.");
         }
    } else {
        // Invalid non-numeric, non-'all' site_id passed
        http_response_code(400);
        die("Export Error: Invalid site parameter provided.");
    }
} else {
     // Should not happen if role check above is correct, but belts and braces...
     http_response_code(403);
     die("Access Denied.");
}


// --- Date Validation (Stricter) ---
if (empty($filter_start_date) || !preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $filter_start_date)) {
     http_response_code(400);
     die("Export Error: Invalid or missing start date parameter.");
}
if (empty($filter_end_date) || !preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $filter_end_date)) {
     http_response_code(400);
     die("Export Error: Invalid or missing end date parameter.");
}
// Ensure start date is not after end date (optional but good)
if (strtotime($filter_start_date) > strtotime($filter_end_date)) {
    http_response_code(400);
     die("Export Error: Start date cannot be after end date.");
}

// --- Fetch Active Questions for Headers and Data Query ---
require_once 'includes/utils.php'; // Need sanitize_title_to_base_name, format_base_name_for_display

$export_question_columns = []; // Stores sanitized column names WITH prefix (q_...) for data access
$export_column_to_label_map = []; // Map prefixed name -> formatted label for headers

// Fetch active question base titles using data access function based on the *effective* site ID for the query
$export_base_titles = getActiveQuestionTitles($pdo, $effective_site_id_for_query); // Use null for 'all'

if ($export_base_titles === false) { // Check for DB error
    error_log("Export Error: Failed to fetch active question titles for site filter '$effective_site_id_for_query'.");
    // Proceed without question columns, but log the error
} elseif (is_array($export_base_titles)) {
    foreach ($export_base_titles as $base_title) {
        if (!empty($base_title)) {
            $sanitized_base = sanitize_title_to_base_name($base_title);
            if (!empty($sanitized_base)) {
                $prefixed_col_name = 'q_' . $sanitized_base;
                // Validate column name format before adding
                if (preg_match('/^q_[a-z0-9_]+$/', $prefixed_col_name) && strlen($prefixed_col_name) <= 64) {
                    $formatted_label = format_base_name_for_display($sanitized_base);
                    $export_question_columns[] = $prefixed_col_name; // Store validated prefixed name for query
                    $export_column_to_label_map[$prefixed_col_name] = $formatted_label; // Map for headers
                } else {
                     error_log("Export Warning: Generated prefixed column name '{$prefixed_col_name}' from base '{$base_title}' is invalid and was skipped.");
                }
            }
        }
    }
}
error_log("Export Debug - Filter ID: {$effective_site_id_for_query} - Active VALID question columns for export data fetch: " . print_r($export_question_columns, true));


// --- Fetch Data for Export (No Pagination) ---
// Prepare date parameters for the function
$start_date_param = date('Y-m-d 00:00:00', strtotime($filter_start_date));
$end_date_param = date('Y-m-d 23:59:59', strtotime($filter_end_date));

// Use data access function to fetch all data for export, passing the dynamic columns
$export_data = getCheckinDataForExport($pdo, $effective_site_id_for_query, $start_date_param, $end_date_param, $export_question_columns);
$report_error = ''; // Reset error

if ($export_data === false) {
    // Error logged within the function
    $report_error = "Database Error fetching export data.";
    http_response_code(500); // Internal Server Error
    die("An error occurred while generating the report data. Please check server logs or contact support.");
}
// Note: $export_data now holds all rows, the CSV loop below will iterate over this array.

// --- Generate CSV Output ---

// Define filename using validated dates and effective site context
$site_name_part = 'UnknownSite'; // Default
if ($effective_site_id_for_query === null) {
    $site_name_part = 'AllSites';
} else {
    // Use data access function to fetch site name
    $fetched_site_name = getSiteNameById($pdo, $effective_site_id_for_query);
    if ($fetched_site_name !== null) {
        // Sanitize site name for filename
        $site_name_part = preg_replace('/[^A-Za-z0-9_\-]/', '', $fetched_site_name);
    } else {
        // Error logged in function, use fallback
        $site_name_part = 'Site' . $effective_site_id_for_query;
    }
}
$filename = "CheckinReport_" . $site_name_part . "_" . $filter_start_date . "_to_" . $filter_end_date . ".csv";

// Set Headers to trigger download - MUST be before any output
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache'); // Prevent caching
header('Expires: 0'); // Prevent caching

// Open output stream
$output = fopen('php://output', 'w');
if ($output === false) {
     // Log this error server-side as the headers might already be sent partially
     error_log("Export Error: Failed to open php://output stream.");
     http_response_code(500);
     // Avoid echoing here if headers might be sent, the browser might show a partial download error
     exit;
}

// --- Write Header Row ---
$headers = [
    'Checkin ID',
    'Site Name',
    'First Name',
    'Last Name',
    'Check-in Timestamp',
    'Notified Staff',
    'Client Email' // Now fetched directly
    // Dynamic question headers will be added here
];

// --- Dynamically add Question Headers ---
$dynamic_question_headers = [];
if (!empty($export_column_to_label_map)) {
    foreach ($export_column_to_label_map as $prefixed_col => $formatted_label) {
        // Use the formatted label for the header
        $dynamic_question_headers[] = $formatted_label;
    }
    // Add the dynamic question headers to the main header array
    $headers = array_merge($headers, $dynamic_question_headers);
}


// Write the final header row
fputcsv($output, $headers);


// --- Write Data Rows ---
// Iterate over the fetched data array
if ($export_data !== false && !empty($export_data)) {
    foreach ($export_data as $row) {
        // Build the row data directly in the order of the headers
        $final_csv_row = [
            $row['CheckinID'] ?? '',
            $row['SiteName'] ?? '',
            $row['FirstName'] ?? '',
            $row['LastName'] ?? '',
            $row['CheckinTime'] ?? '',
            $row['NotifiedStaff'] ?? '',
            $row['ClientEmail'] ?? '' // Use directly fetched email
        ];

        // Add dynamic question answers
        if (!empty($export_question_columns)) {
            foreach ($export_question_columns as $q_col_name) { // $q_col_name is 'q_age', 'q_interviewing' etc.
                // Append the answer for this column, defaulting to empty string if not present
                $final_csv_row[] = $row[$q_col_name] ?? '';
            }
        }

        // Write the row
        fputcsv($output, $final_csv_row);

    } // End foreach loop
} // End if ($export_data)

// Close output stream
if ($output) {
    fclose($output);
}

exit; // Ensure no other output interferes

?>