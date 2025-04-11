<?php
/*
 * File: export_report.php
 * Path: /export_report.php
 * Created: 2024-08-01 15:00:00 MST // Adjust if needed
 * Author: Robert Archer
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

// --- Role Check ---
$allowedRoles = ['site_supervisor', 'director', 'administrator'];
// *** Use session variable ***
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
// This logic needs to determine the EFFECTIVE site filter for the query,
// mirroring reports.php's handling of the passed 'site_id' param based on the user's role.
$effective_site_id_for_query = null; // null means 'all' (for roles that can), specific ID otherwise

if ($_SESSION['active_role'] === 'site_supervisor') {
    // Supervisor MUST export their own site. Ignore any passed site_id.
    if ($user_assigned_site_id === null) {
        http_response_code(400);
        die("Export Error: Your user account is not assigned to a specific site.");
    }
    $effective_site_id_for_query = $user_assigned_site_id;
    // Optional: Check if the passed filter_site_id matches, just for sanity? Not strictly needed.

} elseif ($can_access_all_sites) {
    // Admin/Director: Respect the passed site_id filter
    if ($filter_site_id === 'all' || empty($filter_site_id)) {
        $effective_site_id_for_query = null; // Explicitly null for 'all sites' query
    } elseif (is_numeric($filter_site_id)) {
         // Validate the passed numeric site ID exists and is active
         try {
             $stmt_check_site = $pdo->prepare("SELECT id FROM sites WHERE id = :site_id AND is_active = TRUE");
             $stmt_check_site->execute([':site_id' => $filter_site_id]);
             if ($stmt_check_site->fetch()) {
                  $effective_site_id_for_query = (int)$filter_site_id;
             } else {
                  http_response_code(400);
                  die("Export Error: The selected site ID ('" . htmlspecialchars($filter_site_id) . "') is invalid or inactive.");
             }
         } catch (PDOException $e) {
             error_log("Export Error - Site check failed: " . $e->getMessage());
             http_response_code(500);
             die("Export Error: Could not validate the selected site.");
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

// --- Fetch Data for Export (No Pagination) ---
$export_data = [];
$report_error = '';

try {
    // *** Use the exact same WHERE clause building logic as reports.php ***
    $sql_where_clauses = [];
    $params = []; // Parameters for prepared statement

    // Site filtering - Use $effective_site_id_for_query determined above
    if ($effective_site_id_for_query !== null) {
        $sql_where_clauses[] = "ci.site_id = :site_id";
        $params[':site_id'] = $effective_site_id_for_query;
    }
    // If $effective_site_id_for_query is null, no site clause is added (fetches all sites)

    // Date filtering - Use validated $filter_start_date and $filter_end_date
    if (($start_timestamp = strtotime($filter_start_date)) !== false) {
        $sql_where_clauses[] = "ci.check_in_time >= :start_date";
        $params[':start_date'] = date('Y-m-d 00:00:00', $start_timestamp);
    }
    if (($end_timestamp = strtotime($filter_end_date)) !== false) {
        $sql_where_clauses[] = "ci.check_in_time <= :end_date";
        $params[':end_date'] = date('Y-m-d 23:59:59', $end_timestamp);
    }

    $where_sql = !empty($sql_where_clauses) ? 'WHERE ' . implode(' AND ', $sql_where_clauses) : '';

    // Fetch ALL matching data - NO LIMIT/OFFSET
    $sql_data = "SELECT
                    ci.id as CheckinID,
                    s.name as SiteName,
                    ci.first_name as FirstName,
                    ci.last_name as LastName,
                    ci.check_in_time as CheckinTime,
                    ci.additional_data as AdditionalDataJSON,
                    sn.staff_name as NotifiedStaff
                 FROM check_ins ci
                 JOIN sites s ON ci.site_id = s.id
                 LEFT JOIN staff_notifications sn ON ci.notified_staff_id = sn.id "
                 . $where_sql . "
                 ORDER BY ci.check_in_time ASC"; // Order chronologically for export

    $stmt_data = $pdo->prepare($sql_data);

    // Bind parameters
    // No need to bind by reference here since we execute immediately
    // PDO can often detect types automatically, but explicit is safer
    foreach ($params as $key => $val) {
        $type = PDO::PARAM_STR;
        if ($key === ':site_id') {
            $type = PDO::PARAM_INT;
        }
        $stmt_data->bindValue($key, $val, $type);
    }

    $stmt_data->execute();
    // Fetch row by row to potentially handle large exports better, though fetchAll is fine for moderate data
    // $export_data = $stmt_data->fetchAll(PDO::FETCH_ASSOC); // Old way

} catch (PDOException $e) {
     $report_error = "Database Error fetching export data: " . $e->getMessage();
     error_log($report_error);
     http_response_code(500); // Internal Server Error
     die("An error occurred while generating the report data. Please check server logs or contact support.");
}

// --- Generate CSV Output ---

// Define filename using validated dates and effective site context
$site_name_part = 'UnknownSite'; // Default
if ($effective_site_id_for_query === null) {
    $site_name_part = 'AllSites';
} else {
    // Fetch the actual site name for the filename (optional but nice)
    try {
        $stmt_site_name = $pdo->prepare("SELECT name FROM sites WHERE id = :id");
        $stmt_site_name->execute([':id' => $effective_site_id_for_query]);
        $site_info = $stmt_site_name->fetch(PDO::FETCH_ASSOC);
        if ($site_info && !empty($site_info['name'])) {
             // Sanitize site name for filename (remove spaces, special chars)
             $site_name_part = preg_replace('/[^A-Za-z0-9_\-]/', '', $site_info['name']);
        } else {
             $site_name_part = 'Site' . $effective_site_id_for_query;
        }
    } catch (PDOException $e) {
         error_log("Export Filename Error - fetching site name failed: " . $e->getMessage());
         $site_name_part = 'Site' . $effective_site_id_for_query; // Fallback
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
    'Collected Email', // Extracted from JSON
    // Add specific question headers HERE if you fetch them
    'Additional Data (JSON)' // Raw JSON as fallback/detail
];

// --- Dynamically add Question Headers (Optional but Recommended) ---
$question_headers = [];
$question_map = []; // Map ID to text for ordering columns later
if ($effective_site_id_for_query !== null) { // Fetch questions for a specific site
    try {
        $stmt_q = $pdo->prepare("SELECT id, question_text FROM questions WHERE site_id = :site_id AND is_active = TRUE ORDER BY display_order ASC");
        $stmt_q->execute([':site_id' => $effective_site_id_for_query]);
        while ($q_row = $stmt_q->fetch(PDO::FETCH_ASSOC)) {
            $header_text = 'Q: ' . trim(preg_replace('/\s+/', ' ', $q_row['question_text'])); // Clean up text slightly
            $question_headers[] = $header_text;
            $question_map[$q_row['id']] = $header_text; // Store ID -> Header mapping
        }
    } catch (PDOException $e) {
        error_log("Export Warning: Failed to fetch question headers for site $effective_site_id_for_query - " . $e->getMessage());
        // Proceed without specific question columns if fetching fails
    }
}
// Add the dynamic question headers BEFORE the raw JSON column
array_splice($headers, 7, 0, $question_headers); // Insert question headers at index 7

// Write the final header row
fputcsv($output, $headers);


// --- Write Data Rows (Fetch row-by-row) ---
try {
    // Re-execute the statement to reset the pointer if needed, or just use the existing $stmt_data
    // $stmt_data->execute(); // Needed if fetchAll was used before, NOT needed if we fetch now

    while ($row = $stmt_data->fetch(PDO::FETCH_ASSOC)) { // Fetch one row at a time
        $csv_row_map = []; // Use associative array temporarily for easier column placement

        // Basic data
        $csv_row_map['Checkin ID'] = $row['CheckinID'];
        $csv_row_map['Site Name'] = $row['SiteName'];
        $csv_row_map['First Name'] = $row['FirstName'];
        $csv_row_map['Last Name'] = $row['LastName'];
        $csv_row_map['Check-in Timestamp'] = $row['CheckinTime'];
        $csv_row_map['Notified Staff'] = $row['NotifiedStaff'] ?? '';

        // Parse JSON
        $collected_email = '';
        $answers = [];
        if (!empty($row['AdditionalDataJSON'])) {
            $additional_data = json_decode($row['AdditionalDataJSON'], true);
            if (is_array($additional_data)) {
                 $collected_email = $additional_data['collected_email'] ?? '';
                 // Store answers keyed by question ID
                 foreach ($additional_data as $key => $value) {
                     if (is_numeric($key)) { // Assuming keys are numeric question IDs
                         $answers[$key] = $value;
                     }
                 }
            }
        }
        $csv_row_map['Collected Email'] = $collected_email;
        $csv_row_map['Additional Data (JSON)'] = $row['AdditionalDataJSON'] ?? '';

        // Add answers to the map based on the fetched question headers
        foreach ($question_map as $q_id => $q_header) {
             $csv_row_map[$q_header] = $answers[$q_id] ?? ''; // Use mapped answer or empty string
        }

        // Build the final CSV row IN THE ORDER OF THE HEADERS
        $final_csv_row = [];
        foreach ($headers as $header) {
            $final_csv_row[] = $csv_row_map[$header] ?? ''; // Ensure order matches $headers
        }

        // Write the row
        fputcsv($output, $final_csv_row);
    }

} catch (PDOException $e) {
     // Log error if fetching rows fails after headers sent
     error_log("Export Error: Failed fetching/writing rows - " . $e->getMessage());
     // Can't send HTTP error codes now, browser will likely show failed download
} finally {
    // Close output stream
    if ($output) {
        fclose($output);
    }
}

exit; // Ensure no other output interferes