<?php
// includes/data_access/budget_allocations_dal.php
// Data Access Layer for Budget Allocations

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../utils.php'; // For role checks, etc.
require_once __DIR__ . '/budgets_dal.php'; // Need budget details like type
require_once __DIR__ . '/finance_access_data.php'; // Need finance access check potentially

// Define field categories for easier permission handling
const ALLOCATION_CORE_FIELDS = [
    'transaction_date', 'payee_vendor', 'voucher_number', 'enrollment_date',
    'class_start_date', 'purchase_date', 'payment_status', 'program_explanation'
];
const ALLOCATION_FUNDING_FIELDS = [
    'funding_dw', 'funding_dw_admin', 'funding_dw_sus', 'funding_adult', 'funding_adult_admin',
    'funding_adult_sus', 'funding_rr', 'funding_h1b', 'funding_youth_is', 'funding_youth_os', 'funding_youth_admin'
];
const ALLOCATION_FINANCE_FIELDS = [
    'fin_voucher_received', 'fin_accrual_date', 'fin_obligated_date', 'fin_comments',
    'fin_expense_code', 'fin_processed_by_user_id', 'fin_processed_at'
];
const ALLOCATION_FINANCE_EDITABLE_FUNDING = [ // Funding fields Finance can edit
    'funding_dw_admin', 'funding_adult_admin', 'funding_youth_is', 'funding_youth_os', 'funding_youth_admin'
];

/**
 * Retrieves a single budget allocation by its ID, ensuring it's not soft-deleted.
 * Includes parent budget type for permission checks.
 *
 * @param PDO $pdo The database connection object.
 * @param int $allocationId The ID of the allocation to retrieve.
 * @return array|false An associative array representing the allocation including budget_type, or false if not found/deleted.
 */
function getBudgetAllocationById(PDO $pdo, int $allocationId): array|false {
    // Basic authentication check - adjust as needed
    // if (!isset($_SESSION['user_id'])) { return false; }

    $sql = "SELECT ba.*, b.budget_type
            FROM budget_allocations ba
            JOIN budgets b ON ba.budget_id = b.id
            WHERE ba.id = :allocation_id AND ba.deleted_at IS NULL AND b.deleted_at IS NULL";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':allocation_id', $allocationId, PDO::PARAM_INT);
        $stmt->execute();
        $allocation = $stmt->fetch(PDO::FETCH_ASSOC);

        // Convert decimal fields back to float/numeric if needed (PDO might return strings)
        if ($allocation) {
            foreach (ALLOCATION_FUNDING_FIELDS as $field) {
                if (isset($allocation[$field])) {
                    $allocation[$field] = (float)$allocation[$field];
                }
            }
        }
        return $allocation;

    } catch (PDOException $e) {
        error_log("Database error in getBudgetAllocationById: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves all non-deleted allocations for a specific budget ID.
 *
 * @param PDO $pdo The database connection object.
 * @param int $budgetId The ID of the budget whose allocations are to be retrieved.
 * @return array An array of associative arrays representing the allocations. Empty array if none found or error.
 */
function getAllocationsByBudgetId(PDO $pdo, int $budgetId): array {
     // Basic authentication check - adjust as needed
    // if (!isset($_SESSION['user_id'])) { return []; }

    // Ensure the parent budget itself isn't deleted
    $budget = getBudgetById($pdo, $budgetId); // Use existing function from budgets_dal
    if (!$budget) {
        error_log("Attempted to get allocations for non-existent or deleted budget ID: {$budgetId}");
        return [];
    }

    $sql = "SELECT ba.* -- Select all columns from budget_allocations
            FROM budget_allocations ba
            WHERE ba.budget_id = :budget_id AND ba.deleted_at IS NULL
            ORDER BY ba.transaction_date DESC, ba.created_at DESC"; // Order by date, then creation time
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':budget_id', $budgetId, PDO::PARAM_INT);
        $stmt->execute();
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert decimal fields
         foreach ($allocations as &$allocation) {
            foreach (ALLOCATION_FUNDING_FIELDS as $field) {
                if (isset($allocation[$field])) {
                    $allocation[$field] = (float)$allocation[$field];
                }
            }
        }
        unset($allocation); // Break reference

        return $allocations;

    } catch (PDOException $e) {
        error_log("Database error in getAllocationsByBudgetId: " . $e->getMessage());
        return []; // Return empty array on error
    }
}


/**
 * Adds a new budget allocation. Permissions vary based on user role and parent budget type.
 *
 * @param PDO $pdo The database connection object.
 * @param array $allocationData Associative array containing allocation data. Must include 'budget_id'.
 * @param int $currentUserId The ID of the user performing the action.
 * @param string $currentUserRole The role of the user performing the action ('Director', 'Staff', 'Finance').
 * @param array $financeAccessibleDeptIds Department IDs the finance user can access (only relevant for Finance role).
 * @return int|false The ID of the newly inserted allocation, or false on failure or permission denied.
 */
function addBudgetAllocation(PDO $pdo, array $allocationData, int $currentUserId, string $currentUserRole, array $financeAccessibleDeptIds = []): int|false {

    // 1. Validate essential data
    if (empty($allocationData['budget_id'])) {
        error_log("Add Allocation Error: budget_id is missing.");
        return false;
    }
    $budgetId = filter_var($allocationData['budget_id'], FILTER_VALIDATE_INT);
    if (!$budgetId) {
         error_log("Add Allocation Error: Invalid budget_id.");
        return false;
    }

    // 2. Fetch parent budget details (including type and department)
    $budget = getBudgetById($pdo, $budgetId); // Fetches budget details including department_id

    if (!$budget) {
        error_log("Add Allocation Error: Parent budget ID {$budgetId} not found, deleted, or error fetching."); // Modified error
        return false;
    }
    $budgetType = $budget['budget_type'];
    $budgetDepartmentId = $budget['department_id'];

    // 3. Permission Check
    $canAdd = false;
    $allowedFields = [];

    $roleLower = strtolower($currentUserRole); // Convert role to lowercase for comparison

    if ($roleLower === 'director') { // Compare lowercase
        // Directors can add any field to any (AZ@Work) budget.
        // Assuming Directors only manage AZ@Work department budgets. Add check if needed.
        $canAdd = true;
        $allowedFields = array_merge(ALLOCATION_CORE_FIELDS, ALLOCATION_FUNDING_FIELDS, ALLOCATION_FINANCE_FIELDS);
    } elseif ($roleLower === 'staff') { // Compare lowercase
        // Staff can add Core + Funding fields ONLY to their OWN 'Staff' type budgets.
        if ($budgetType === 'Staff' && $budget['user_id'] == $currentUserId) {
             $canAdd = true;
             $allowedFields = array_merge(ALLOCATION_CORE_FIELDS, ALLOCATION_FUNDING_FIELDS);
             // Staff cannot add fin_* fields directly
        } else {
             error_log("Permission Denied (Staff): User {$currentUserId} cannot add allocation to budget ID {$budgetId} (Type: {$budgetType}, Owner: {$budget['user_id']}).");
        }
    } elseif ($roleLower === 'finance') { // Compare lowercase
        // Finance can add Core + Funding + Fin_* fields ONLY to 'Admin' type budgets in their allowed departments.
        if ($budgetType === 'Admin' && in_array($budgetDepartmentId, $financeAccessibleDeptIds)) {
            $canAdd = true;
            $allowedFields = array_merge(ALLOCATION_CORE_FIELDS, ALLOCATION_FUNDING_FIELDS, ALLOCATION_FINANCE_FIELDS);
        } else {
             error_log("Permission Denied (Finance): User {$currentUserId} cannot add allocation to budget ID {$budgetId} (Type: {$budgetType}, Dept: {$budgetDepartmentId}). Allowed Depts: " . implode(',', $financeAccessibleDeptIds));
        }
    }

    if (!$canAdd) {
        error_log("Add Allocation Error: Permission denied because \$canAdd is false. User {$currentUserId} (Role: {$currentUserRole}) on budget {$budgetId}. Check role comparison logic."); // More specific error
        return false; // <<< EXIT HERE!
    }

    // 4. Prepare data for insertion (filter based on allowed fields)
    $insertData = [];
    $columns = [];
    $placeholders = [];
    $params = [];

    // Always include budget_id and created_by_user_id
    $columns = ['budget_id', 'created_by_user_id'];
    $placeholders = [':budget_id', ':created_by_user_id'];
    $params[':budget_id'] = $budgetId;
    $params[':created_by_user_id'] = $currentUserId;

    // Add fields from $allocationData if they are allowed for the current user role
    foreach ($allowedFields as $field) {
        if (isset($allocationData[$field])) {
            $columns[] = $field;
            $placeholders[] = ':' . $field;
            // Basic sanitization/type handling (expand as needed)
            if (in_array($field, ALLOCATION_FUNDING_FIELDS)) {
                $params[':' . $field] = filter_var($allocationData[$field], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? 0.00;
            } elseif (str_ends_with($field, '_date')) {
                 $params[':' . $field] = !empty($allocationData[$field]) ? filter_var($allocationData[$field], FILTER_SANITIZE_SPECIAL_CHARS) : null; // Assuming YYYY-MM-DD format
            } elseif ($field === 'payment_status') {
                 $params[':' . $field] = in_array($allocationData[$field], ['P', 'U']) ? $allocationData[$field] : 'U';
            } elseif ($field === 'fin_processed_by_user_id') {
                 // If Finance is adding, record them as processor
                 $params[':' . $field] = ($currentUserRole === 'Finance') ? $currentUserId : null;
            } elseif ($field === 'fin_processed_at') {
                 // If Finance is adding, record the time
                 $params[':' . $field] = ($currentUserRole === 'Finance') ? date('Y-m-d H:i:s') : null;
            }
            else {
                $params[':' . $field] = filter_var($allocationData[$field], FILTER_SANITIZE_SPECIAL_CHARS); // Basic string sanitization
            }
        } elseif ($field === 'fin_processed_by_user_id' && $currentUserRole === 'Finance') {
             // Ensure finance processor is set if finance is adding, even if not in $allocationData explicitly
             $columns[] = 'fin_processed_by_user_id';
             $placeholders[] = ':fin_processed_by_user_id';
             $params[':fin_processed_by_user_id'] = $currentUserId;
        } elseif ($field === 'fin_processed_at' && $currentUserRole === 'Finance') {
             // Ensure finance processed time is set if finance is adding
             $columns[] = 'fin_processed_at';
             $placeholders[] = ':fin_processed_at';
             $params[':fin_processed_at'] = date('Y-m-d H:i:s');
        }
    }

    // Ensure required fields are present (example: transaction_date, payee_vendor)
    if (!in_array('transaction_date', $columns) || !in_array('payee_vendor', $columns)) {
         error_log("Add Allocation Error: Required fields (transaction_date, payee_vendor) missing from \$columns array or not allowed for user role.");
         return false;
    }
     // Set default payment status if not provided
    if (!in_array('payment_status', $columns)) {
        $columns[] = 'payment_status';
        $placeholders[] = ':payment_status';
        $params[':payment_status'] = 'U'; // Default to Unpaid
    }


    // 5. Construct and Execute SQL
    $sql = "INSERT INTO budget_allocations (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR addBudgetAllocation: Prepare failed. SQL: {$sql} Params: " . print_r($params, true) . " PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }

        // Bind parameters carefully (types are inferred by PDO based on value, but explicit binding can be added if needed)
        $success = $stmt->execute($params);

        if ($success) {
            return (int)$pdo->lastInsertId();
        } else {
            error_log("Error adding budget allocation: " . implode(";", $stmt->errorInfo()) . " SQL: {$sql} Params: " . print_r($params, true));
            return false;
        }
    } catch (PDOException $e) {
        error_log("Database error in addBudgetAllocation: " . $e->getMessage() . " SQL: {$sql} Params: " . print_r($params, true));
        return false;
    }
}


// TODO: Implement updateBudgetAllocation() with complex field-level permission logic.
// TODO: Implement softDeleteBudgetAllocation() with permission checks.


// Removed closing PHP tag here to include functions below

/**
 * Updates an existing budget allocation with strict field-level permission checks.
 *
 * @param PDO $pdo The database connection object.
 * @param int $allocationId The ID of the allocation to update.
 * @param array $updateData Associative array containing the data to update (key = column name, value = new value).
 * @param int $currentUserId The ID of the user performing the action.
 * @param string $currentUserRole The role of the user ('Director', 'Staff', 'Finance').
 * @param array $financeAccessibleDeptIds Department IDs the finance user can access (required if role is 'Finance').
 * @return bool True on success (if changes were made), false on failure, permission denied, or no changes needed.
 */
function updateBudgetAllocation(PDO $pdo, int $allocationId, array $updateData, int $currentUserId, string $currentUserRole, array $financeAccessibleDeptIds = [], bool $isFinanceStaff = false): bool // Added $isFinanceStaff param
{
    // 1. Fetch existing allocation data (includes budget_type)
    $existingAllocation = getBudgetAllocationById($pdo, $allocationId);
    if (!$existingAllocation) {
        error_log("Update Allocation Error: Allocation ID {$allocationId} not found or already deleted.");
        return false;
    }
    $budgetId = $existingAllocation['budget_id'];
    $budgetType = $existingAllocation['budget_type'];

    // 2. Fetch parent budget details (for owner and department ID)
    $budget = getBudgetById($pdo, $budgetId);
    if (!$budget) {
        // This shouldn't happen if getBudgetAllocationById succeeded, but check anyway
        error_log("Update Allocation Error: Parent budget ID {$budgetId} for allocation {$allocationId} not found or deleted.");
        return false;
    }
    $budgetOwnerUserId = $budget['user_id'];
    $budgetDepartmentId = $budget['department_id'];

    // 3. Permission Check (Basic Access): Ensure user has basic rights to modify based on handler logic.
    // The handler already filtered $updateData based on field-level permissions.
    // We just need a basic check here to prevent unauthorized access if someone bypasses the handler logic.
    // This check can be simplified or removed if handler validation is deemed sufficient.
    $roleLower = strtolower($currentUserRole);
    $canPotentiallyEdit = false;
    if ($roleLower === 'director' && $budgetType === 'Staff') {
        $canPotentiallyEdit = true;
    } elseif ($roleLower === 'azwk_staff') {
        if ($isFinanceStaff && ($budgetType === 'Staff' || $budgetType === 'Admin')) {
            $canPotentiallyEdit = true;
        } elseif (!$isFinanceStaff && $budgetType === 'Staff' && $budgetOwnerUserId == $currentUserId) {
            $canPotentiallyEdit = true;
        }
    }

    if (!$canPotentiallyEdit) {
        error_log("Basic Permission Denied (Update DAL): User {$currentUserId} (Role: {$currentUserRole}, IsFinance: {$isFinanceStaff}) cannot modify allocation {$allocationId} on budget {$budgetId} (Type: {$budgetType}, Owner: {$budgetOwnerUserId}). Handler filtering might have been bypassed.");
        return false; // Basic access check failed
    }
    // Note: Field-level filtering is now assumed to be done by the handler.

    // 4. Prepare SET clauses and parameters based *only* on fields present in $updateData
    $setClauses = [];
    $params = [];
    $params[':allocation_id'] = $allocationId;
    $params[':current_user_id'] = $currentUserId; // For updated_by_user_id

    $financeIsUpdating = false;

    foreach ($updateData as $field => $value) {
        // Check if the field exists in the table to prevent SQL errors (basic safety)
        // A more robust check might involve fetching table schema, but this is simpler.
        if (array_key_exists($field, $existingAllocation) || in_array($field, ALLOCATION_FINANCE_FIELDS) || in_array($field, ALLOCATION_CORE_FIELDS) || in_array($field, ALLOCATION_FUNDING_FIELDS)) {

            // Check if the new value is actually different from the existing value
            // Handle type comparisons carefully (e.g., float, null)
            $existingValue = $existingAllocation[$field] ?? null;
            $newValue = $value; // Keep original for comparison

             // Type casting/sanitization for comparison and binding
            if (in_array($field, ALLOCATION_FUNDING_FIELDS)) {
                $existingValue = isset($existingAllocation[$field]) ? (float)$existingAllocation[$field] : 0.0;
                $newValue = filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? 0.00;
            } elseif (str_ends_with($field, '_date')) {
                $newValue = !empty($value) ? filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS) : null;
            } elseif ($field === 'payment_status') {
                $newValue = in_array($value, ['P', 'U']) ? $value : $existingValue; // Keep existing if invalid
            } elseif ($field === 'fin_processed_by_user_id' || $field === 'created_by_user_id' || $field === 'updated_by_user_id') {
                 $newValue = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE); // Allow null for FKs
            } else {
                 $newValue = filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS); // Basic string sanitization
            }

            // Compare appropriately (handle nulls and type differences)
            $isDifferent = false;
            if (is_null($existingValue) && !is_null($newValue)) {
                $isDifferent = true;
            } elseif (!is_null($existingValue) && is_null($newValue)) {
                 $isDifferent = true;
            } elseif (is_float($existingValue)) {
                 // Use epsilon comparison for floats
                 $isDifferent = abs($existingValue - (float)$newValue) > 0.001; // Adjust epsilon as needed
            } elseif ($existingValue != $newValue) { // Standard comparison for other types
                 $isDifferent = true;
            }


            if ($isDifferent) {
                $setClauses[] = "{$field} = :{$field}";
                $params[":{$field}"] = $newValue; // Use the processed new value

                // Track if Finance is updating any finance-specific fields
                if ($currentUserRole === 'Finance' && (in_array($field, ALLOCATION_FINANCE_FIELDS) || in_array($field, ALLOCATION_FINANCE_EDITABLE_FUNDING))) {
                    $financeIsUpdating = true;
                }
            }
        }
        // No 'else' needed - we only process fields present in $updateData passed from the handler.
    }

    // 5. Check if there's anything to update
    if (empty($setClauses)) {
        // No allowed fields were changed
        return true; // Indicate success, but no DB action needed
    }

    // 6. Add audit fields
    $setClauses[] = "updated_by_user_id = :current_user_id";
    $setClauses[] = "updated_at = CURRENT_TIMESTAMP"; // Use DB function for accuracy

    // If Finance made changes to their designated fields, update finance processing info
    if ($financeIsUpdating) {
        $setClauses[] = "fin_processed_by_user_id = :current_user_id"; // Record finance user
        $setClauses[] = "fin_processed_at = CURRENT_TIMESTAMP";
    }

    // 7. Construct and Execute SQL
    $sql = "UPDATE budget_allocations SET " . implode(', ', $setClauses) . " WHERE id = :allocation_id AND deleted_at IS NULL";

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR updateBudgetAllocation: Prepare failed for allocation ID {$allocationId}. SQL: {$sql} Params: " . print_r($params, true) . " PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }

        $success = $stmt->execute($params);

        if (!$success) {
            error_log("Error updating budget allocation ID {$allocationId}: " . implode(";", $stmt->errorInfo()) . " SQL: {$sql} Params: " . print_r($params, true));
            return false;
        }

        // rowCount() > 0 indicates the update was successful and changed at least one row
        return $stmt->rowCount() > 0;

    } catch (PDOException $e) {
        error_log("Database error in updateBudgetAllocation for allocation ID {$allocationId}: " . $e->getMessage() . " SQL: {$sql} Params: " . print_r($params, true));
        return false;
    }
}
/**
 * Soft deletes a budget allocation by setting the deleted_at timestamp.
 * Permissions are checked based on user role and budget ownership/type.
 *
 * @param PDO $pdo The database connection object.
 * @param int $allocationId The ID of the allocation to soft delete.
 * @param int $currentUserId The ID of the user performing the action.
 * @param string $currentUserRole The role of the user ('Director', 'Staff', 'Finance').
 * @return bool True on success, false on failure or permission denied.
 */
function softDeleteBudgetAllocation(PDO $pdo, int $allocationId, int $currentUserId, string $currentUserRole): bool
{
    // 1. Fetch existing allocation data (includes budget_type)
    $allocation = getBudgetAllocationById($pdo, $allocationId);
    if (!$allocation) {
        error_log("Soft Delete Allocation Error: Allocation ID {$allocationId} not found or already deleted.");
        return false; // Already deleted or doesn't exist
    }
    $budgetId = $allocation['budget_id'];
    $budgetType = $allocation['budget_type'];

    // 2. Fetch parent budget details (for owner user_id)
    $budget = getBudgetById($pdo, $budgetId);
     if (!$budget) {
        error_log("Soft Delete Allocation Error: Parent budget ID {$budgetId} for allocation {$allocationId} not found or deleted.");
        return false;
    }
    $budgetOwnerUserId = $budget['user_id'];
    // $budgetDepartmentId = $budget['department_id']; // Potentially needed for Director check

    // 3. Permission Check
    $canDelete = false;
    $roleLower = strtolower($currentUserRole); // Convert role to lowercase for comparison

    if ($roleLower === 'director') { // Compare lowercase
        // Assuming Directors can delete any allocation within AZ@Work budgets.
        // Add department check here if needed: e.g., check if $budgetDepartmentId is an AZ@Work dept.
        $canDelete = true; // Simplify for now, assuming access if budget exists
    } elseif ($roleLower === 'staff') { // Compare lowercase
        // Staff can only delete allocations on their own 'Staff' budgets.
        if ($budgetType === 'Staff' && $budgetOwnerUserId == $currentUserId) {
            $canDelete = true;
        }
    } elseif ($roleLower === 'finance') { // Compare lowercase
        // Finance cannot delete allocations.
        $canDelete = false;
    }

    if (!$canDelete) {
        error_log("Permission Denied (Delete): User {$currentUserId} (Role: {$currentUserRole}) cannot delete allocation ID {$allocationId} (Budget Type: {$budgetType}, Owner: {$budgetOwnerUserId}).");
        return false;
    }

    // 4. Execute Soft Delete
    $sql = "UPDATE budget_allocations SET deleted_at = NOW(), updated_by_user_id = :current_user_id
            WHERE id = :allocation_id AND deleted_at IS NULL"; // Ensure we only delete once

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR softDeleteBudgetAllocation: Prepare failed for allocation ID {$allocationId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $params = [
            ':allocation_id' => $allocationId,
            ':current_user_id' => $currentUserId
        ];
        $success = $stmt->execute($params);
        if (!$success) {
            error_log("Error soft-deleting budget allocation ID {$allocationId}: " . implode(";", $stmt->errorInfo()));
            return false;
        }

        // rowCount() > 0 indicates the update was successful
        return $stmt->rowCount() > 0;
} catch (PDOException $e) {
        error_log("Database error in softDeleteBudgetAllocation: " . $e->getMessage());
        return false;
    }
} // Closing brace for softDeleteBudgetAllocation function



/**
 * Retrieves all non-deleted allocations for a specific list of budget IDs.
 * Includes vendor name and creator/processor names for display.
 *
 * @param PDO $pdo The database connection object.
 * @param array $budgetIds An array of integer budget IDs.
 * @return array An array of associative arrays representing the allocations. Empty array if none found or error.
 */
function getAllocationsByBudgetList(PDO $pdo, array $budgetIds): array
{
    if (empty($budgetIds)) {
        return []; // No budget IDs provided
    }

    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($budgetIds), '?'));

    // Ensure all IDs are integers (important for security and query correctness)
    $intBudgetIds = array_map('intval', $budgetIds);

    $sql = "SELECT
                ba.*,
                v.name as vendor_name,
                v.client_name_required,
                creator.full_name as created_by_user_name,
                processor.full_name as fin_processed_by_user_name,
                b.name as budget_name, -- Include budget name for potential grouping/display
                b.budget_type -- Include budget type for potential JS logic
            FROM budget_allocations ba
            JOIN budgets b ON ba.budget_id = b.id
            LEFT JOIN vendors v ON ba.vendor_id = v.id
            LEFT JOIN users creator ON ba.created_by_user_id = creator.id
            LEFT JOIN users processor ON ba.fin_processed_by_user_id = processor.id
            WHERE ba.budget_id IN ({$placeholders})
              AND ba.deleted_at IS NULL
              AND b.deleted_at IS NULL -- Ensure parent budget is not deleted
            ORDER BY b.name ASC, ba.transaction_date DESC, ba.created_at DESC"; // Order by budget, then date

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getAllocationsByBudgetList: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        // Execute with the integer budget IDs
        $stmt->execute($intBudgetIds);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert decimal fields
        foreach ($allocations as &$allocation) {
            foreach (ALLOCATION_FUNDING_FIELDS as $field) {
                if (isset($allocation[$field])) {
                    $allocation[$field] = (float)$allocation[$field];
                }
            }
        }
        unset($allocation); // Break reference

        return $allocations;

    } catch (PDOException $e) {
        error_log("Database error in getAllocationsByBudgetList: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Retrieves all non-deleted allocations for a specific list of budget IDs.
 *
 * @param PDO $pdo The database connection object.
 * @param array $budgetIds An array of budget IDs whose allocations are to be retrieved.
 * @return array An array of associative arrays representing the allocations. Empty array if none found or error.
 */
function getAllocationsByBudgetIds(PDO $pdo, array $budgetIds): array {
    // Basic authentication check - adjust as needed
    // if (!isset($_SESSION['user_id'])) { return []; }

    if (empty($budgetIds)) {
        return []; // No budget IDs provided, return empty array
    }

    // Ensure all IDs are integers
    $budgetIds = array_map('intval', $budgetIds);
    $budgetIds = array_filter($budgetIds, function($id) { return $id > 0; }); // Remove non-positive IDs

    if (empty($budgetIds)) {
        return []; // Return empty if filtering resulted in no valid IDs
    }

    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($budgetIds), '?'));

    // Note: We don't need to check if the parent budgets exist here,
    // as the $budgetIds array comes from getBudgetsForUser which already filters for accessible budgets.
    // However, joining vendors is useful for displaying the vendor name directly.
    $sql = "SELECT ba.*, v.name as vendor_name, b.department_id, -- Select all columns from budget_allocations and vendor name, budget dept id
                   u_created.full_name AS created_by_name,
                   u_updated.full_name AS updated_by_name,
                   u_processed.full_name AS processed_by_name
            FROM budget_allocations ba
            LEFT JOIN vendors v ON ba.vendor_id = v.id AND v.deleted_at IS NULL -- Join vendors
            JOIN budgets b ON ba.budget_id = b.id -- Join budgets to get department ID
            LEFT JOIN users u_created ON ba.created_by_user_id = u_created.id
            LEFT JOIN users u_updated ON ba.updated_by_user_id = u_updated.id
            LEFT JOIN users u_processed ON ba.fin_processed_by_user_id = u_processed.id
            WHERE ba.budget_id IN ({$placeholders})
              AND ba.deleted_at IS NULL
              AND b.deleted_at IS NULL -- Ensure parent budget is not deleted
            ORDER BY ba.transaction_date DESC, ba.created_at DESC"; // Order by date, then creation time

    try {
        $stmt = $pdo->prepare($sql);
        // Bind each budget ID individually
        foreach ($budgetIds as $k => $id) {
            $stmt->bindValue(($k + 1), $id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert decimal fields
         foreach ($allocations as &$allocation) {
            foreach (ALLOCATION_FUNDING_FIELDS as $field) {
                if (isset($allocation[$field])) {
                    $allocation[$field] = (float)$allocation[$field];
                }
            }
            // Ensure vendor_name is set, even if null from LEFT JOIN
             if (!isset($allocation['vendor_name'])) {
                 $allocation['vendor_name'] = null;
             }
        }
        unset($allocation); // Break reference

        return $allocations;

    } catch (PDOException $e) {
        error_log("Database error in getAllocationsByBudgetIds: " . $e->getMessage() . " Budget IDs: " . implode(',', $budgetIds));
        return []; // Return empty array on error
    }
}
/**
 * Retrieves filtered, paginated, and permission-controlled budget allocations for reporting.
 * Includes related entity names (budget, grant, vendor, department, users).
 *
 * @param PDO $pdo The database connection object.
 * @param array $filters Associative array of filters (e.g., ['fiscal_year' => '2024', 'grant_id' => 5, ...]).
 *                       Supported filters: fiscal_year, grant_id, department_id, budget_id, vendor_id.
 * @param string $userRole The role of the current user (e.g., 'administrator', 'director', 'azwk_staff').
 * @param int|null $userDepartmentId The department ID of the current user (null if not applicable or admin/director).
 * @param int $userId The ID of the current user (needed for staff/finance checks).
 * @param int $page The current page number (1-based).
 * @param int $limit The number of records per page.
 * @return array|false An array containing 'data' (array of allocations) and 'total' (total matching records), or false on error.
 */
function getAllocationsForReport(PDO $pdo, array $filters, string $userRole, ?int $userDepartmentId, int $userId, int $page = 1, int $limit = 25): array|false
{
    // --- Base Query Construction ---
    $select = "SELECT
                    ba.id, ba.budget_id, ba.transaction_date, ba.vendor_id, ba.client_name, ba.voucher_number,
                    ba.enrollment_date, ba.class_start_date, ba.purchase_date, ba.payment_status, ba.program_explanation,
                    ba.funding_dw, ba.funding_dw_admin, ba.funding_dw_sus, ba.funding_adult, ba.funding_adult_admin,
                    ba.funding_adult_sus, ba.funding_rr, ba.funding_h1b, ba.funding_youth_is, ba.funding_youth_os,
                    ba.funding_youth_admin,
                    ba.fin_voucher_received, ba.fin_accrual_date, ba.fin_obligated_date, ba.fin_comments, ba.fin_expense_code,
                    ba.fin_processed_by_user_id, ba.fin_processed_at,
                    ba.created_at, ba.created_by_user_id, ba.updated_at, ba.updated_by_user_id,
                    b.name as budget_name, b.budget_type, b.fiscal_year_start, b.fiscal_year_end,
                    g.name as grant_name,
                    v.name as vendor_name,
                    d.name as department_name,
                    uc.full_name as created_by_name,
                    uu.full_name as updated_by_name,
                    up.full_name as processed_by_name";

    $from = " FROM budget_allocations ba
              JOIN budgets b ON ba.budget_id = b.id
              JOIN grants g ON b.grant_id = g.id
              JOIN departments d ON b.department_id = d.id
              LEFT JOIN vendors v ON ba.vendor_id = v.id
              LEFT JOIN users uc ON ba.created_by_user_id = uc.id
              LEFT JOIN users uu ON ba.updated_by_user_id = uu.id
              LEFT JOIN users up ON ba.fin_processed_by_user_id = up.id";

    $whereClauses = [
        "ba.deleted_at IS NULL",
        "b.deleted_at IS NULL",
        "g.deleted_at IS NULL",
        "d.deleted_at IS NULL",
        "(v.deleted_at IS NULL OR ba.vendor_id IS NULL)" // Allow if vendor is null or not deleted
    ];
    $params = [];

    // --- Apply Filters ---
    if (!empty($filters['fiscal_year']) && is_numeric($filters['fiscal_year'])) {
        $year = intval($filters['fiscal_year']);
        // Assuming fiscal year matches the start year in YYYY format
        $whereClauses[] = "YEAR(b.fiscal_year_start) = :fiscal_year";
        $params[':fiscal_year'] = $year;
    }
    if (!empty($filters['grant_id']) && is_numeric($filters['grant_id'])) {
        $whereClauses[] = "b.grant_id = :grant_id";
        $params[':grant_id'] = intval($filters['grant_id']);
    }
    if (!empty($filters['department_id']) && is_numeric($filters['department_id'])) {
        $whereClauses[] = "b.department_id = :department_id";
        $params[':department_id'] = intval($filters['department_id']);
    }
     if (!empty($filters['budget_id']) && is_numeric($filters['budget_id'])) {
        $whereClauses[] = "ba.budget_id = :budget_id";
        $params[':budget_id'] = intval($filters['budget_id']);
    }
    if (!empty($filters['vendor_id']) && is_numeric($filters['vendor_id'])) {
        $whereClauses[] = "ba.vendor_id = :vendor_id";
        $params[':vendor_id'] = intval($filters['vendor_id']);
    }

    // --- Apply Permission-Based Filtering ---
    $roleLower = strtolower($userRole);

    if ($roleLower === 'azwk_staff') {
        // Check if finance staff (assuming finance dept has a specific ID or slug)
        // We need the department slug or ID for finance. Let's assume slug 'finance' for now.
        $financeDeptSlug = 'finance'; // TODO: Confirm Finance department identifier
        $isFinanceStaff = false;
        if ($userDepartmentId) {
            $deptCheckSql = "SELECT slug FROM departments WHERE id = :dept_id";
            $deptStmt = $pdo->prepare($deptCheckSql);
            $deptStmt->bindParam(':dept_id', $userDepartmentId, PDO::PARAM_INT);
            $deptStmt->execute();
            $deptSlug = $deptStmt->fetchColumn();
            if ($deptSlug === $financeDeptSlug) {
                $isFinanceStaff = true;
            }
        }

        if ($isFinanceStaff) {
            // Finance staff see allocations for budgets in departments they have access to.
            // Use the existing function to get accessible department IDs.
            $accessibleDeptIds = getAccessibleDepartmentIdsForFinanceUser($pdo, $userId);
            if (!empty($accessibleDeptIds)) {
                $deptPlaceholders = implode(',', array_fill(0, count($accessibleDeptIds), '?'));
                $whereClauses[] = "b.department_id IN ({$deptPlaceholders})";
                // Add these IDs to the parameters array sequentially
                foreach ($accessibleDeptIds as $deptId) {
                    $params[] = $deptId; // PDO will handle binding these unnamed placeholders
                }
            } else {
                 // Finance user has access to no departments, should see nothing
                 $whereClauses[] = "1 = 0"; // Effectively blocks results
            }
        } else {
            // Regular staff see allocations linked to budgets they manage (b.user_id = current user)
            $whereClauses[] = "b.user_id = :current_user_id";
            $params[':current_user_id'] = $userId;
        }
    } elseif ($roleLower === 'director') {
        // Directors see all allocations within AZ@Work departments.
        // Assuming an 'is_az_work_dept' flag exists in the 'departments' table.
        // If not, this might need adjustment or removal if directors see everything.
        // $whereClauses[] = "d.is_az_work_dept = 1"; // Uncomment if flag exists
        // For now, assume directors see all (no additional WHERE clause needed)
    } elseif ($roleLower === 'administrator') {
        // Administrators see all allocations.
        // No additional WHERE clause needed.
    } else {
        // Unknown or restricted role, should see nothing.
        error_log("getAllocationsForReport: Unknown or unauthorized role '{$userRole}' for user ID {$userId}.");
        $whereClauses[] = "1 = 0"; // Block results
    }

    // --- Construct Final WHERE Clause ---
    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // --- Get Total Count (for pagination) ---
    $countSql = "SELECT COUNT(ba.id) " . $from . " " . $whereSql;
    try {
        $countStmt = $pdo->prepare($countSql);
        // Bind parameters for count query (handle unnamed placeholders if they exist)
        $countParams = [];
        $unnamedIndex = 0;
        foreach ($params as $key => $value) {
            if (is_int($key) || ctype_digit($key)) { // Check if it's an unnamed placeholder param
                 $countParams[] = $value;
            } elseif (substr($key, 0, 1) === ':') { // Named placeholder
                 $countParams[$key] = $value;
            }
        }
        $countStmt->execute($countParams);
        $totalRecords = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Database error getting allocation report count: " . $e->getMessage() . " SQL: " . $countSql . " Params: " . print_r($countParams, true));
        return false;
    }

    // --- Get Paginated Data ---
    $offset = ($page - 1) * $limit;
    $dataSql = $select . " " . $from . " " . $whereSql . " ORDER BY b.name ASC, ba.transaction_date DESC, ba.created_at DESC LIMIT :limit OFFSET :offset";

    try {
        $dataStmt = $pdo->prepare($dataSql);
        // Bind all parameters for data query (including limit/offset)
        $dataParams = $params; // Start with filter/permission params
        $dataParams[':limit'] = $limit;
        $dataParams[':offset'] = $offset;

        // Bind named parameters
        foreach ($dataParams as $key => $value) {
             if (substr($key, 0, 1) === ':') {
                 $type = PDO::PARAM_STR; // Default type
                 if (is_int($value) || $key === ':limit' || $key === ':offset') {
                     $type = PDO::PARAM_INT;
                 } elseif (is_bool($value)) {
                     $type = PDO::PARAM_BOOL;
                 } elseif (is_null($value)) {
                     $type = PDO::PARAM_NULL;
                 }
                 $dataStmt->bindValue($key, $value, $type);
             }
        }
         // Bind unnamed parameters (for department IN clause if used)
        $unnamedIndex = 1;
        foreach ($params as $key => $value) {
            if (is_int($key) || ctype_digit($key)) {
                $dataStmt->bindValue($unnamedIndex++, $value, PDO::PARAM_INT); // Assuming dept IDs are INTs
            }
        }


        $dataStmt->execute();
        $allocations = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

         // Convert decimal fields
         foreach ($allocations as &$allocation) {
            foreach (ALLOCATION_FUNDING_FIELDS as $field) {
                if (isset($allocation[$field])) {
                    $allocation[$field] = (float)$allocation[$field];
                }
            }
        }
        unset($allocation); // Break reference


        return [
            'data' => $allocations,
            'total' => $totalRecords
        ];

    } catch (PDOException $e) {
        error_log("Database error getting allocation report data: " . $e->getMessage() . " SQL: " . $dataSql . " Params: " . print_r($dataParams, true));
        return false;
    }
}