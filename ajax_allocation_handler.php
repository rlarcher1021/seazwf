<?php
// Set header to return JSON
header('Content-Type: application/json');

// Start session and include necessary files
require_once 'includes/auth.php'; // Handles session start and ensures user is logged in
require_once 'includes/db_connect.php';
require_once 'includes/data_access/budgets_dal.php'; // Contains getBudgetsForUser()
require_once 'includes/data_access/budget_allocations_dal.php'; // Contains allocation CRUD functions (getAllocationById, addAllocation, updateAllocation, softDeleteAllocation)
require_once 'data_access/budget_data.php'; // Contains getAllocationsByBudgetList and other budget/allocation functions

require_once 'data_access/vendor_data.php'; // Corrected path - Contains doesVendorRequireClientName
require_once 'includes/data_access/finance_access_data.php'; // Needed for finance role
require_once 'includes/data_access/user_data.php'; // Needed for getUserById
require_once 'includes/data_access/department_data.php'; // Needed for getDepartmentById
require_once 'includes/utils.php'; // For CSRF check etc.

// --- Initial Setup & Security ---

// 1. Check Authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role'])) { // Check active_role instead of user_role
    echo json_encode(['success' => false, 'message' => 'Authentication required (Active role not set).']); // Modified message for clarity
    exit;
}
$currentUserId = $_SESSION['user_id'];
$currentUserRole = $_SESSION['active_role']; // Use active_role consistently

// Determine if the user is acting in a finance capacity *before* fetching accessible departments
$isFinanceStaff = false;
$currentUserDeptSlug = null; // Initialize department slug
$currentUserDeptId = null; // Initialize department ID

if ($currentUserRole === 'azwk_staff' || $currentUserRole === 'director') { // Also fetch for director if needed later
    try {
        $userData = getUserById($pdo, $currentUserId);
        $currentUserDeptId = $userData['department_id'] ?? null;
        if ($currentUserDeptId) {
            $deptData = getDepartmentById($pdo, $currentUserDeptId);
            if (isset($deptData['slug'])) {
                $currentUserDeptSlug = strtolower($deptData['slug']); // Store lowercase slug
                if ($currentUserRole === 'azwk_staff' && $currentUserDeptSlug === 'finance') {
                    $isFinanceStaff = true; // Still useful as a flag
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching user department details in ajax_allocation_handler: " . $e->getMessage());
        // Proceed cautiously, permissions might be affected
    }
}
// Note: $isFinanceStaff is now reliably set based on the fetched slug for azwk_staff role.

// 2. Define Finance Accessible Departments (Needed for getBudgetsForUser call later)
$financeAccessibleDeptIds = []; // Initialize as empty array to satisfy type hint

// This logic is specific to the old finance_department_access table and is NOT used for v1.30 permissions,
// but the variable might still be expected by older function signatures like getBudgetsForUser.
// We keep it defined but likely empty unless getBudgetsForUser is updated.
// If getBudgetsForUser *still* needs the actual accessible IDs for finance staff for filtering,
// uncomment and potentially adapt this block. For now, assume passing an empty array is sufficient.
/*
if ($isFinanceStaff) {
    try {
        // If getBudgetsForUser still relies on this for filtering *which* budgets finance staff see,
        // this might need to be uncommented or the DAL function updated.
        $financeAccessibleDeptIds = getAccessibleDepartmentIdsForFinanceUser($pdo, $currentUserId);
    } catch (Exception $e) {
        error_log("Error fetching finance accessible departments in ajax_allocation_handler for user {$currentUserId}: " . $e->getMessage());
        // Don't exit, just proceed with an empty array, but log the error.
        $financeAccessibleDeptIds = [];
    }
}
*/

// 3. Determine Action and Method
$action = null;
$requestData = [];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    $requestData = $_GET;
} elseif ($method === 'POST' && isset($_POST['action'])) {
    // 4. CSRF Check for POST requests
    if (!isset($_POST['csrf_token']) || empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request (CSRF token mismatch). Please refresh the page and try again.']);
        exit;
    }
    $action = $_POST['action'];
    $requestData = $_POST;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or action not specified.']);
    exit;
}

// --- Action Handling ---

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

try {
    switch ($action) {
        // --- GET ALLOCATIONS ---
        case 'get':
            if ($method !== 'GET') { throw new Exception('Invalid method for get action.'); }

            $budgetId = filter_var($requestData['budget_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$budgetId) { throw new Exception('Valid Budget ID is required.'); }

            // **Permission Check**: Can the current user view this budget?
            // We need to fetch the budget details to check ownership/department access.
            $budgetCheckFilters = ['budget_id_for_check' => $budgetId]; // Use a distinct filter key if needed by getBudgetsForUser
            $allowedBudgets = getBudgetsForUser($pdo, $currentUserId, $currentUserRole, $financeAccessibleDeptIds, $budgetCheckFilters);

            $canView = false;
            foreach($allowedBudgets as $b) {
                if ($b['id'] == $budgetId) {
                    $canView = true;
                    break;
                }
            }

            if (!$canView) {
                 throw new Exception('Permission denied to view allocations for this budget.');
            }

            // Fetch allocations if permission granted
            $allocations = getAllocationsByBudget($pdo, $budgetId); // Use new function name
            // Note: The new getAllocationsByBudget already joins user tables for names

            $response = ['success' => true, 'allocations' => $allocations];
            break;

        // --- ADD ALLOCATION ---
        case 'add':
             if ($method !== 'POST') { throw new Exception('Invalid method for add action.'); }

             // --- Validation for Add ---
             $budgetId = filter_var($requestData['budget_id'] ?? null, FILTER_VALIDATE_INT);
             $vendorId = filter_var($requestData['vendor_id'] ?? null, FILTER_VALIDATE_INT);
             $clientName = isset($requestData['client_name']) ? trim($requestData['client_name']) : null;
             $transactionDate = $requestData['transaction_date'] ?? null; // Basic check

             if (!$budgetId) { throw new Exception('Budget ID is required.'); }
             if (!$vendorId) { throw new Exception('Vendor is required.'); }
             if (!$transactionDate) { throw new Exception('Transaction Date is required.'); }
             // Add other essential field checks as needed

             // --- Permission Check for Add ---
             $budgetDetails = getBudgetById($pdo, $budgetId); // Assumes this function exists
             if (!$budgetDetails) { throw new Exception('Target budget not found.'); }
             $budgetType = $budgetDetails['budget_type'];
             $budgetDeptId = $budgetDetails['department_id'];
             $budgetOwnerId = $budgetDetails['user_id']; // Relevant for Staff budgets

             $canAdd = false;
             $roleLower = strtolower($currentUserRole);
             // $currentUserDeptSlug is fetched and lowercased earlier
             // $isFinanceStaff flag is also set earlier

             // Living Plan v1.30 Add Rules:
             // azwk_staff (AZ@Work Dept) -> ONLY on assigned 'Staff' budgets.
             // azwk_staff (Finance Dept) -> ONLY on 'Admin' budgets.
             // Director -> ONLY on 'Staff' budgets.

             if ($roleLower === 'azwk_staff') {
                 if ($isFinanceStaff) { // User is in Finance Department
                     if ($budgetType === 'Admin') {
                         $canAdd = true;
                     }
                 } else { // User is in an AZ@Work Department
                     if ($budgetType === 'Staff' && $budgetOwnerId == $currentUserId) {
                         $canAdd = true;
                     }
                 }
             } elseif ($roleLower === 'director') {
                 if ($budgetType === 'Staff') {
                     $canAdd = true;
                 }
             }
             // No separate 'finance' role check needed. Administrator role might need adding if applicable.

             if (!$canAdd) {
                 // Provide a more specific error message if possible
                 error_log("Add Permission Denied: User={$currentUserId}, Role={$roleLower}, DeptSlug={$currentUserDeptSlug}, BudgetType={$budgetType}, BudgetOwner={$budgetOwnerId}");
                 throw new Exception('Permission denied to add allocations to this budget type/assignment.');
             }
             // --- End Permission Check ---


             // Conditional Client Name Server-Side Validation
             $requiresClientName = doesVendorRequireClientName($pdo, $vendorId);
             if ($requiresClientName === null) {
                 throw new Exception('Invalid or inactive vendor selected.');
             }
             if ($requiresClientName && empty($clientName)) {
                 throw new Exception('Client Name is required for the selected vendor.');
             }
             // Ensure client_name is null if not required (handled in DAL addAllocation now)

             // Prepare data - ensure created_by_user_id is set
             $addData = $requestData;
             $addData['created_by_user_id'] = $currentUserId; // Ensure creator is tracked

             // Call DAL function (pass the prepared data array)
             $newAllocationId = addAllocation($pdo, $addData); // Use new function

             if ($newAllocationId) {
                 $response = ['success' => true, 'message' => 'Allocation added successfully.', 'new_id' => $newAllocationId];
             } else {
                 throw new Exception('Failed to add allocation. Please check data and try again.');
             }
             break;

        // --- EDIT ALLOCATION ---
        case 'edit':
             if ($method !== 'POST') { throw new Exception('Invalid method for edit action.'); }

             $allocationId = filter_var($requestData['allocation_id'] ?? null, FILTER_VALIDATE_INT);
             if (!$allocationId) { throw new Exception('Allocation ID is required.'); }

             // --- Fetch Existing Data & Budget Info for Permissions ---
             $existingAllocation = getBudgetAllocationById($pdo, $allocationId);
             if (!$existingAllocation) { throw new Exception('Allocation not found or already deleted.'); }

             $budgetId = $existingAllocation['budget_id'];
             $budgetDetails = getBudgetById($pdo, $budgetId);
             if (!$budgetDetails) { throw new Exception('Associated budget not found.'); }
             $budgetType = $budgetDetails['budget_type'];
             $budgetDeptId = $budgetDetails['department_id'];
             $budgetOwnerId = $budgetDetails['user_id'];

             // --- Permission Check for Edit Access (Overall - can they open the modal?) ---
             $canAccess = false;
             $roleLower = strtolower($currentUserRole);
             // $currentUserDeptSlug and $isFinanceStaff are available

             // Living Plan v1.30 Edit Access Rules (who can even attempt to edit):
             // Director -> 'Staff' budgets
             // azwk_staff (AZ@Work Dept) -> Assigned 'Staff' budgets
             // azwk_staff (Finance Dept) -> 'Staff' OR 'Admin' budgets

             if ($roleLower === 'director') {
                 if ($budgetType === 'Staff') {
                     $canAccess = true;
                 }
                 // Director cannot edit 'Admin' budgets per v1.30 (line 44)
             } elseif ($roleLower === 'azwk_staff') {
                 if ($isFinanceStaff) { // User is in Finance Department
                     // Can edit both Staff and Admin budgets (field restrictions apply later)
                     if ($budgetType === 'Staff' || $budgetType === 'Admin') {
                         $canAccess = true;
                     }
                 } else { // User is in an AZ@Work Department
                     if ($budgetType === 'Staff' && $budgetOwnerId == $currentUserId) {
                         $canAccess = true; // Can edit their assigned Staff budgets
                     }
                 }
             }
             // No separate 'finance' role check

             if (!$canAccess) {
                 error_log("Edit Access Denied: User={$currentUserId}, Role={$roleLower}, DeptSlug={$currentUserDeptSlug}, BudgetType={$budgetType}, BudgetOwner={$budgetOwnerId}");
                 throw new Exception('Permission denied to edit allocations for this budget type/assignment.');
             }
             // --- End Permission Check ---

             // --- Field-Level Edit Enforcement ---
             $allowedFields = [];
             $staffSideFields = ['transaction_date', 'vendor_id', 'client_name', 'voucher_number', 'enrollment_date', 'class_start_date', 'purchase_date', 'payment_status', 'program_explanation', 'funding_dw', 'funding_dw_admin', 'funding_dw_sus', 'funding_adult', 'funding_adult_admin', 'funding_adult_sus', 'funding_rr', 'funding_h1b', 'funding_youth_is', 'funding_youth_os', 'funding_youth_admin'];
             $financeFields = ['fin_voucher_received', 'fin_accrual_date', 'fin_obligated_date', 'fin_comments', 'fin_expense_code'];
             $allFields = array_merge($staffSideFields, $financeFields); // Combine for convenience

             // Living Plan v1.30 Field Editability Rules:
             // Director (Editing 'Staff' Budget): Staff fields editable / fin_* fields read-only.
             // azwk_staff (AZ@Work Dept) (Editing assigned 'Staff' Budget): Staff fields editable / fin_* fields read-only.
             // azwk_staff (Finance Dept) (Editing 'Staff' Budget): Staff fields read-only / fin_* fields editable.
             // azwk_staff (Finance Dept) (Editing 'Admin' Budget): ALL fields editable.

             if ($roleLower === 'director') {
                 if ($budgetType === 'Staff') {
                     $allowedFields = $staffSideFields;
                     // Director Void Check: Allow 'payment_status' if they are submitting 'Void'
                     if (!(isset($requestData['payment_status']) && $requestData['payment_status'] === 'Void')) {
                         // If not voiding, remove payment_status from allowed fields for director editing staff budget?
                         // Plan v1.30 implies staff fields are editable, which includes payment_status. Let's allow it unless voiding.
                         // Re-check: Plan line 81 says staff fields editable. Let's keep payment_status allowed for non-void edits too.
                     }
                 }
                 // Director cannot edit Admin budgets at all (handled by $canAccess check earlier)
             } elseif ($roleLower === 'azwk_staff') {
                 if ($isFinanceStaff) { // User is in Finance Department
                     if ($budgetType === 'Staff') {
                         $allowedFields = $financeFields; // Finance staff edits fin_* fields on Staff budgets
                     } elseif ($budgetType === 'Admin') {
                         $allowedFields = $allFields; // Finance staff edits ALL fields on Admin budgets
                     }
                 } else { // User is in an AZ@Work Department
                     if ($budgetType === 'Staff' && $budgetOwnerId == $currentUserId) {
                         $allowedFields = $staffSideFields; // AZ@Work staff edits staff fields on their assigned Staff budgets
                     }
                 }
             }

             // --- Void Permission Check (applies specifically to payment_status field) ---
             if (isset($requestData['payment_status']) && $requestData['payment_status'] === 'Void') {
                 if ($roleLower !== 'director') {
                     // If a non-director tries to submit 'Void', remove it from the data to be processed.
                     // We don't need to unset from $allowedFields, just from the incoming data.
                     unset($requestData['payment_status']); // Prevent non-director voiding
                     error_log("User {$currentUserId} (Role {$roleLower}) attempted to VOID allocation {$allocationId} - Permission Denied.");
                 } else {
                     // Director is submitting 'Void'. Ensure 'payment_status' is in allowedFields if it wasn't already.
                     // (It should be via staffSideFields, but double-check)
                     if (!in_array('payment_status', $allowedFields)) {
                         $allowedFields[] = 'payment_status'; // Explicitly allow for Director voiding
                     }
                     error_log("Director {$currentUserId} is VOIDING allocation {$allocationId}.");
                 }
             }

             // Filter the submitted data to include only allowed fields
             $updateData = [];
             foreach ($allowedFields as $field) {
                 if (isset($requestData[$field])) {
                     $updateData[$field] = $requestData[$field];
                 }
             }

             // Always include the user performing the update
             $updateData['updated_by_user_id'] = $currentUserId;
             // Include fin_processed_by_user_id and fin_processed_at if finance user edited finance fields
             // Set finance processing details ONLY if azwk_staff in Finance Dept edited finance fields
             if ($isFinanceStaff && !empty(array_intersect(array_keys($updateData), $financeFields))) {
                 $updateData['fin_processed_by_user_id'] = $currentUserId;
                 $updateData['fin_processed_at'] = date('Y-m-d H:i:s'); // Set timestamp on process
             }


             // --- Validation for Edit (after filtering) ---
             $vendorId = filter_var($updateData['vendor_id'] ?? $existingAllocation['vendor_id'] ?? null, FILTER_VALIDATE_INT); // Use existing if not submitted/allowed
             $clientName = isset($updateData['client_name']) ? trim($updateData['client_name']) : ($existingAllocation['client_name'] ?? null); // Use existing if not submitted/allowed

             // Conditional Client Name Server-Side Validation (using potentially updated vendor)
             if ($vendorId !== null) {
                 $requiresClientName = doesVendorRequireClientName($pdo, $vendorId);
                 if ($requiresClientName === null) {
                     throw new Exception('Invalid or inactive vendor selected/associated.');
                 }
                 // Enforce only if the user *could* have edited the client name
                 if ($requiresClientName && empty($clientName) && in_array('client_name', $allowedFields)) {
                     throw new Exception('Client Name is required for the selected vendor.');
                 }
             }
             // Ensure client_name is null if not required (handled in DAL updateAllocation now)


             // Call DAL function with FILTERED data
             // The DAL function needs to be robust enough to handle partial updates.
             // Pass the necessary data AND context to the DAL function.
             // Even though primary permission checks are done here, the DAL function
             // might have internal logic or logging that uses role/dept context.
             // The DAL function signature requires: $pdo, $allocationId, $updateData, $currentUserId, $currentUserRole, [$financeAccessibleDeptIds], [$isFinanceStaff]
             $success = updateBudgetAllocation(
                 $pdo,
                 $allocationId,
                 $updateData,
                 $currentUserId,          // Pass User ID
                 $currentUserRole,        // Pass User Role
                 $financeAccessibleDeptIds, // Pass (potentially empty) array
                 $isFinanceStaff          // Pass finance staff flag
             );

             if ($success) {
                 $response = ['success' => true, 'message' => 'Allocation updated successfully.'];
             } else {
                 // Log the failed update data for debugging
                 error_log("Failed to update allocation {$allocationId}. Data: " . print_r($updateData, true));
                 throw new Exception('Failed to update allocation. Please check data or contact support.');
             }
             break;

         // --- DELETE ALLOCATION ---
         case 'delete':
              if ($method !== 'POST') { throw new Exception('Invalid method for delete action.'); }

              $allocationId = filter_var($requestData['allocation_id'] ?? null, FILTER_VALIDATE_INT);
              if (!$allocationId) { throw new Exception('Allocation ID is required.'); }

              // --- Fetch Existing Data & Budget Info for Permissions ---
              $existingAllocation = getBudgetAllocationById($pdo, $allocationId);
              if (!$existingAllocation) { throw new Exception('Allocation not found or already deleted.'); }

              $budgetId = $existingAllocation['budget_id'];
              $budgetDetails = getBudgetById($pdo, $budgetId);
              if (!$budgetDetails) { throw new Exception('Associated budget not found.'); }
              $budgetType = $budgetDetails['budget_type'];
              // $budgetDeptId = $budgetDetails['department_id']; // Not directly needed for delete check

              // --- Permission Check for Delete ---
              $canDelete = false;
              $roleLower = strtolower($currentUserRole);
              // $isFinanceStaff flag is available

              // Living Plan v1.30 Delete Rules:
              // Allow ONLY for azwk_staff (Finance Dept) -> ONLY on 'Admin' budget allocations.
              // Disallow for all others.

              if ($roleLower === 'azwk_staff' && $isFinanceStaff) { // User is azwk_staff in Finance Dept
                  if ($budgetType === 'Admin') {
                      $canDelete = true;
                  }
              }
              // No other roles (Director, azwk_staff/AZ@Work) can delete.

              if (!$canDelete) {
                  error_log("Delete Permission Denied: User={$currentUserId}, Role={$roleLower}, IsFinanceStaff={$isFinanceStaff}, BudgetType={$budgetType}");
                  throw new Exception('Permission denied to delete this allocation.');
              }
              // --- End Permission Check ---


              // Call DAL function (soft delete)
              $success = softDeleteAllocation($pdo, $allocationId); // Use specific soft delete function

              if ($success) {
                  $response = ['success' => true, 'message' => 'Allocation deleted successfully.'];
              } else {
                  throw new Exception('Failed to delete allocation. Check permissions or if already deleted.');
              }
              break;

        // --- GET ALLOCATION DETAILS (for Edit Modal) ---
        case 'get_allocation_details':
             if ($method !== 'POST') { throw new Exception('Invalid method for get_allocation_details action.'); }

             $allocationId = filter_var($requestData['allocation_id'] ?? null, FILTER_VALIDATE_INT);
             if (!$allocationId) { throw new Exception('Allocation ID is required.'); }

             // Call DAL function to get details AND budget type for permission checks
             // This function needs to be created in budget_data.php
             $allocationData = getBudgetAllocationById($pdo, $allocationId); // Use CORRECT function name

             if (!$allocationData) {
                 // Function returns false if not found or DB error
                 throw new Exception('Allocation not found, already deleted, or database error.');
             }

             // **Permission Check**: Ensure the user can actually view/edit this specific allocation.
             // We need the budget details for this. getBudgetAllocationById returns budget_type.
             // We might need more budget details (owner, dept) depending on role.
             // Let's add a basic check here, assuming the DAL function doesn't do full permission check.
             $budgetTypeForCheck = $allocationData['budget_type'];
             $budgetIdForCheck = $allocationData['budget_id'];
             // Fetch budget details for more robust check if needed (e.g., for staff owner check)
             // $budgetDetailsForCheck = getBudgetById($pdo, $budgetIdForCheck);

             $canAccess = false;
             $roleLowerForCheck = strtolower($currentUserRole);

             if ($roleLowerForCheck === 'director') {
                 $canAccess = true; // Assume director can see all
             } elseif ($roleLowerForCheck === 'finance') {
                 // Finance role has universal department access per v1.27
                 $canAccess = true; // Removed department check
             } elseif ($roleLowerForCheck === 'azwk_staff') {
                 // Check if it's their own staff budget OR if they are finance staff
                 $budgetDetailsForCheck = getBudgetById($pdo, $budgetIdForCheck); // Need budget owner ID
                 $isFinanceStaffCheck = false; // Re-check finance status for this specific context
                  try {
                     $userDataCheck = getUserById($pdo, $currentUserId);
                     if (!empty($userDataCheck['department_id'])) {
                         $deptDataCheck = getDepartmentById($pdo, $userDataCheck['department_id']);
                         if (isset($deptDataCheck['slug']) && strtolower($deptDataCheck['slug']) === 'finance') {
                             $isFinanceStaffCheck = true;
                         }
                     }
                 } catch (Exception $e) { /* ignore */ }

                 if ($isFinanceStaffCheck) { // Finance staff can see details
                     $canAccess = true;
                 } elseif ($budgetDetailsForCheck && $budgetTypeForCheck === 'Staff' && $budgetDetailsForCheck['user_id'] == $currentUserId) { // Own staff budget
                     $canAccess = true;
                 }
             }

             if (!$canAccess) {
                  throw new Exception('Permission denied to access details for this allocation.');
             }
             // End Permission Check

             $response = [
                 'success' => true,

                 'allocation' => $allocationData, // Return the allocation data directly
                 'budget_type' => $budgetTypeForCheck // budget_type is already in $allocationData from the DAL function
             ];
             break; // End of get_allocation_details case

        // --- GET ALLOCATIONS FOR FILTERS (AJAX from budgets.php) ---
        case 'get_allocations_for_filters':
            if ($method !== 'POST') { throw new Exception('Invalid method for get_allocations_for_filters action.'); }

            // Get filters from POST data
            $filterFiscalYearStart = filter_input(INPUT_POST, 'fiscal_year_start', FILTER_SANITIZE_SPECIAL_CHARS);
            $filterGrantId = filter_input(INPUT_POST, 'grant_id', FILTER_VALIDATE_INT);
            $filterDepartmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            $filterBudgetId = $_POST['budget_id'] ?? null; // Can be 'all', '', or a specific ID

            // Prepare filters for getBudgetsForUser (to determine accessible budgets)
            $budgetFilterParams = [];
            if ($filterFiscalYearStart) {
                $yearDate = DateTime::createFromFormat('Y-m-d', $filterFiscalYearStart);
                if ($yearDate) {
                    $budgetFilterParams['fiscal_year'] = $yearDate->format('Y');
                }
            }
            if ($filterGrantId) {
                $budgetFilterParams['grant_id'] = $filterGrantId;
            }
            if ($filterDepartmentId) {
                $budgetFilterParams['department_id'] = $filterDepartmentId;
            }

            // Get all budgets the user *could* access based on primary filters (Year, Grant, Dept)
            // $isFinanceStaff is determined earlier in the script
            $accessibleBudgets = getBudgetsForUser(
                $pdo,
                $currentUserId,
                $currentUserRole,
                $financeAccessibleDeptIds,
                $budgetFilterParams,
                $isFinanceStaff // Pass the finance staff flag
            );

            $budgetIdsToFetch = [];
            if ($filterBudgetId === 'all' || empty($filterBudgetId)) {
                // User wants all accessible budgets matching the other filters
                $budgetIdsToFetch = array_column($accessibleBudgets, 'id');
            } else {
                // User selected a specific budget - verify they have access to it
                $selectedBudgetIdInt = filter_var($filterBudgetId, FILTER_VALIDATE_INT);
                if ($selectedBudgetIdInt) {
                    $found = false;
                    foreach ($accessibleBudgets as $budget) {
                        if ($budget['id'] == $selectedBudgetIdInt) {
                            $found = true;
                            break;
                        }
                    }
                    if ($found) {
                        $budgetIdsToFetch = [$selectedBudgetIdInt];
                    } else {
                        // User selected a specific budget they don't have access to (based on other filters)
                        error_log("Permission denied: User {$currentUserId} requested specific budget ID {$selectedBudgetIdInt} but doesn't have access based on other filters.");
                        // Return empty list
                    }
                }
            }

            $allocations = [];
            if (!empty($budgetIdsToFetch)) {
                // Call the new DAL function (to be created)
                $allocations = getAllocationsByBudgetList($pdo, $budgetIdsToFetch);
            } else {
                 error_log("No accessible budget IDs found for user {$currentUserId} with filters: " . print_r($_POST, true));
            }

            $response = ['success' => true, 'allocations' => $allocations];
            break;



        default:
            throw new Exception('Invalid action specified.');
    }

} catch (Exception $e) {
    // Log the exception message if not already logged by DAL
    error_log("Error in ajax_allocation_handler.php (Action: {$action}): " . $e->getMessage());
    $response = ['success' => false, 'message' => $e->getMessage()]; // Send specific error back to client
}

// --- Output JSON Response ---
echo json_encode($response);
exit;
?>