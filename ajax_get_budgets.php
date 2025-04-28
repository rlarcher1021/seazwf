<?php
// Ensure session is started at the very beginning for AJAX requests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Capture Session Vars Immediately ---
// Capture required session variables right after starting the session,
// before any includes might interfere. Use 'active_role' based on logs.
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserRole = isset($_SESSION['active_role']) ? strtolower($_SESSION['active_role']) : null;
// --- End Capture ---

// Set header to return JSON
header('Content-Type: application/json');

// Include necessary files
require_once 'includes/auth.php'; // auth.php will perform its checks using the already loaded session
require_once 'includes/db_connect.php';
require_once 'includes/data_access/budgets_dal.php';
require_once 'includes/data_access/finance_access_data.php'; // Needed for finance role

// Basic security check is handled by includes/auth.php.
// If execution reaches here, assume $currentUserId and $currentUserRole were valid initially.
// Check if they are still valid *after* includes, just in case auth.php did modify them (though it shouldn't).
if ($currentUserId === null || $currentUserRole === null) {
     // This case should ideally not be reached if auth.php works correctly.
     // If it is reached, it means auth.php might have cleared the session despite it being valid initially.
     error_log("AJAX Session Error: Session variables became null after includes. UserID: " . ($_SESSION['user_id'] ?? 'Not Set') . ", Role: " . ($_SESSION['active_role'] ?? 'Not Set'));
     echo json_encode(['success' => false, 'message' => 'Session validation failed unexpectedly after includes.', 'budgets' => []]);
     exit;
}

$financeAccessibleDeptIds = [];

// Get filter parameters from GET request
$fiscal_year_start = filter_input(INPUT_GET, 'fiscal_year_start', FILTER_SANITIZE_SPECIAL_CHARS); // Expects YYYY-MM-DD
$grant_id = filter_input(INPUT_GET, 'grant_id', FILTER_VALIDATE_INT);
$department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);

$budgets = [];
$message = '';
$success = false;

try {
    // Fetch accessible departments if the user is Finance
    if ($currentUserRole === 'finance') { // Use lowercase comparison
        $financeAccessibleDeptIds = getAccessibleDepartmentIdsForFinanceUser($pdo, $currentUserId);
        // The getBudgetsForUser function handles the case where financeAccessibleDeptIds is empty.
    }

    // Prepare filters for the DAL function
    $budgetFilterParams = [];
    if ($fiscal_year_start) {
        // Extract year from YYYY-MM-DD for the DAL function if it expects just the year
        $yearDate = DateTime::createFromFormat('Y-m-d', $fiscal_year_start);
        if ($yearDate) {
            // Assuming getBudgetsForUser uses 'fiscal_year' key expecting the year number
            $budgetFilterParams['fiscal_year'] = $yearDate->format('Y');
        }
    }
    if ($grant_id) {
        $budgetFilterParams['grant_id'] = $grant_id;
    }
    // Department filter is handled within getBudgetsForUser based on role and financeAccessibleDeptIds
    if ($department_id) {
         $budgetFilterParams['department_id'] = $department_id; // Pass it along, DAL will check access
    }


    // Fetch budgets based on user role and filters
    $budgets = getBudgetsForUser(
        $pdo,
        $currentUserId,
        $currentUserRole,
        $financeAccessibleDeptIds,
        $budgetFilterParams
    );

    $success = true; // Assume success if no exception

    // Prepend the "All Budgets" option if budgets were successfully fetched
    if ($success && !empty($budgets)) { // Only add if there are actual budgets to select from
        array_unshift($budgets, ['id' => 'all', 'name' => '-- All Budgets --']);
    } elseif ($success) {
        // If successful but no budgets found for the filters, maybe still add "All" if desired?
        // For now, only adding if specific budgets exist. Add an empty "No budgets found" option?
        // Let's add "All" even if empty, allows viewing across all accessible budgets regardless of other filters.
         array_unshift($budgets, ['id' => 'all', 'name' => '-- All Budgets --']);
         // Also add a default "Select Budget"
         array_unshift($budgets, ['id' => '', 'name' => '-- Select Budget --']);
    }


} catch (Exception $e) {
    error_log("Error in ajax_get_budgets.php: " . $e->getMessage());
    $message = 'An error occurred while fetching budgets.';
    $success = false;
}

// Return JSON response
echo json_encode([
    'success' => $success,
    'message' => $message,
    'budgets' => $budgets // Array of budget objects (id, name, budget_type, etc.)
]);

exit;
?>