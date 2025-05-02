<?php
// data_access/budget_data.php

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php'; // For check_permission and user ID
require_once __DIR__ . '/../data_access/vendor_data.php'; // For doesVendorRequireClientName

/**
 * Fetches all non-deleted allocations for a specific budget ID.
 * Joins with vendors table to get vendor name.
 * Orders by transaction date descending.
 *
 * @param PDO $pdo Database connection object.
 * @param int $budget_id The ID of the budget to fetch allocations for.
 * @return array List of allocations or empty array on failure/no allocations.
 */
function getAllocationsByBudget(PDO $pdo, int $budget_id): array
{
    // Basic permission check might be needed here depending on who can view allocations
    // For now, assume the calling page (budgets.php) handles primary access control.

    if ($budget_id <= 0) {
        return [];
    }

    try {
        // Select all necessary fields, joining vendors
        $sql = "SELECT 
                    ba.*, 
                    v.name AS vendor_name,
                    u_created.full_name AS created_by_name,
                    u_updated.full_name AS updated_by_name,
                    u_processed.full_name AS processed_by_name
                FROM budget_allocations ba
                LEFT JOIN vendors v ON ba.vendor_id = v.id 
                LEFT JOIN users u_created ON ba.created_by_user_id = u_created.id
                LEFT JOIN users u_updated ON ba.updated_by_user_id = u_updated.id
                LEFT JOIN users u_processed ON ba.fin_processed_by_user_id = u_processed.id
                WHERE ba.budget_id = :budget_id 
                  AND ba.deleted_at IS NULL
                ORDER BY ba.transaction_date DESC, ba.created_at DESC"; // Order by date

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error fetching allocations for budget ID ($budget_id): " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches all non-deleted allocations for a list of budget IDs.
 * Joins with vendors table to get vendor name.
 * Orders by budget ID and then transaction date descending.
 *
 * @param PDO $pdo Database connection object.
 * @param array $budget_ids An array of budget IDs to fetch allocations for.
 * @return array List of allocations, or empty array on failure/no allocations.
 */
function getAllocationsByBudgetList(PDO $pdo, array $budget_ids): array
{
    if (empty($budget_ids)) {
        return [];
    }

    // Sanitize budget IDs to ensure they are integers
    $sanitized_budget_ids = array_filter($budget_ids, function($id) {
        return is_numeric($id) && $id > 0;
    });

    if (empty($sanitized_budget_ids)) {
        error_log("getAllocationsByBudgetList: No valid budget IDs provided after sanitization.");
        return [];
    }

    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($sanitized_budget_ids), '?'));

    try {
        // JOIN budgets table to get the department_id for each allocation's budget
        $sql = "SELECT
                    ba.*,
                    b.department_id, -- Fetch the budget's department ID
                    v.name AS vendor_name,
                    u_created.full_name AS created_by_name,
                    u_updated.full_name AS updated_by_name,
                    u_processed.full_name AS processed_by_name
                FROM budget_allocations ba
                JOIN budgets b ON ba.budget_id = b.id -- JOIN budgets table
                LEFT JOIN vendors v ON ba.vendor_id = v.id
                LEFT JOIN users u_created ON ba.created_by_user_id = u_created.id
                LEFT JOIN users u_updated ON ba.updated_by_user_id = u_updated.id
                LEFT JOIN users u_processed ON ba.fin_processed_by_user_id = u_processed.id
                WHERE ba.budget_id IN ($placeholders)
                  AND ba.deleted_at IS NULL
                  AND b.deleted_at IS NULL -- Ensure the parent budget isn't deleted
                ORDER BY ba.budget_id, ba.transaction_date DESC, ba.created_at DESC"; // Order by budget then date

        $stmt = $pdo->prepare($sql);
        
        // Bind the sanitized budget IDs
        $i = 1;
        foreach ($sanitized_budget_ids as $id) {
            $stmt->bindValue($i++, (int)$id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return flat list as the AJAX handler seems to expect this based on the original code
        return $results; 

    } catch (PDOException $e) {
        error_log("Error fetching allocations for budget ID list: " . $e->getMessage());
        return [];
    }
}


/**
 * Fetches a single non-deleted allocation by its ID.
 * Joins with vendors table.
 *
 * @param PDO $pdo Database connection object.
 * @param int $allocation_id The ID of the allocation to fetch.
 * @return array|false Allocation data as an associative array, or false if not found.
 */
function getSingleAllocation(PDO $pdo, int $allocation_id)
{
    // Add permission checks if necessary (e.g., can the current user view/edit this specific allocation?)

    if ($allocation_id <= 0) {
        return false;
    }

    try {
        $sql = "SELECT 
                    ba.*, 
                    v.name AS vendor_name 
                FROM budget_allocations ba
                LEFT JOIN vendors v ON ba.vendor_id = v.id
                WHERE ba.id = :id AND ba.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $allocation_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC); // Returns false if not found

    } catch (PDOException $e) {
        error_log("Error fetching single allocation ID ($allocation_id): " . $e->getMessage());
        return false;
    }
}


/**
 * Fetches a single non-deleted allocation by its ID, including the budget type.
 *
 * @param PDO $pdo Database connection object.
 * @param int $allocation_id The ID of the allocation to fetch.
 * @return array|false An array containing 'allocation' data and 'budget_type', or false if not found/error.
 */
function getAllocationById(PDO $pdo, int $allocation_id): array|false
{
    // Basic check: Allocation must exist and not be deleted.
    // More specific permission checks (can the current user access this budget/allocation)
    // should ideally be handled in the calling layer (e.g., AJAX handler) using the returned data.

    if ($allocation_id <= 0) {
        return false;
    }

    try {
        $sql = "SELECT 
                    ba.*, 
                    b.budget_type
                FROM budget_allocations ba
                JOIN budgets b ON ba.budget_id = b.id
                WHERE ba.id = :id AND ba.deleted_at IS NULL AND b.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $allocation_id, PDO::PARAM_INT);
        $stmt->execute();
        $allocation_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($allocation_data) {
            return [
                'allocation' => $allocation_data, 
                'budget_type' => $allocation_data['budget_type']
            ];
        } else {
            return false; // Not found or budget is deleted
        }

    } catch (PDOException $e) {
        error_log("Error fetching allocation ID ($allocation_id) with budget type: " . $e->getMessage());
        return false;
    }
}


/**
 * Adds a new budget allocation.
 * Handles new vendor_id and client_name fields.
 * Performs server-side validation for conditional client_name.
 *
 * @param PDO $pdo Database connection object.
 * @param array $data Associative array containing allocation data. Expected keys match form fields.
 * @return int|false The ID of the newly created allocation, or false on failure.
 */
function addAllocation(PDO $pdo, array $data): int|false
{
    // Permission Check: Ensure user has rights to add allocation to this specific budget_id
    // This might involve checking budget type ('Staff' vs 'Admin') and user role/assignment.
    // Example (needs refinement based on exact rules):
    // if (!canUserManageAllocation($pdo, $_SESSION['user_id'], $_SESSION['active_role'], $data['budget_id'], 'add')) {
    //     error_log("Permission denied: User {$_SESSION['user_id']} cannot add allocation to budget {$data['budget_id']}");
    //     return false;
    // }

    // --- Validation ---
    // Basic validation (presence, types) should happen in AJAX handler ideally.
    // Server-side validation for critical rules:
    $vendor_id = filter_var($data['vendor_id'] ?? null, FILTER_VALIDATE_INT);
    $client_name = isset($data['client_name']) ? trim($data['client_name']) : null;
    $budget_id = filter_var($data['budget_id'] ?? null, FILTER_VALIDATE_INT);
    $transaction_date = $data['transaction_date'] ?? null; // Validate format YYYY-MM-DD

    if (!$vendor_id || !$budget_id || !$transaction_date /* add other required fields */) {
         error_log("Add Allocation Error: Missing required fields.");
         // Set a more specific error message if possible
         return false;
    }

    // Conditional Client Name Validation
    $requires_client_name = doesVendorRequireClientName($pdo, $vendor_id);
    if ($requires_client_name === null) {
        error_log("Add Allocation Error: Could not verify vendor requirement for ID $vendor_id.");
        // Set specific error: "Invalid or inactive vendor selected."
        return false;
    }
    if ($requires_client_name && empty($client_name)) {
         error_log("Add Allocation Error: Client Name is required for vendor ID $vendor_id.");
         // Set specific error: "Client Name is required for the selected vendor."
         return false;
    }
     if (!$requires_client_name) {
        $client_name = null; // Ensure client_name is NULL if not required by vendor
    }
    // --- End Validation ---


    try {
        $sql = "INSERT INTO budget_allocations (
                    budget_id, transaction_date, vendor_id, client_name, voucher_number, 
                    enrollment_date, class_start_date, purchase_date, payment_status, 
                    program_explanation, 
                    funding_dw, funding_dw_admin, funding_dw_sus, 
                    funding_adult, funding_adult_admin, funding_adult_sus, 
                    funding_rr, funding_h1b, 
                    funding_youth_is, funding_youth_os, funding_youth_admin, 
                    fin_voucher_received, fin_accrual_date, fin_obligated_date, fin_comments, fin_expense_code,
                    created_by_user_id, updated_by_user_id 
                    -- fin_processed_by_user_id and fin_processed_at are set separately
                ) VALUES (
                    :budget_id, :transaction_date, :vendor_id, :client_name, :voucher_number,
                    :enrollment_date, :class_start_date, :purchase_date, :payment_status,
                    :program_explanation,
                    :funding_dw, :funding_dw_admin, :funding_dw_sus, 
                    :funding_adult, :funding_adult_admin, :funding_adult_sus, 
                    :funding_rr, :funding_h1b, 
                    :funding_youth_is, :funding_youth_os, :funding_youth_admin, 
                    :fin_voucher_received, :fin_accrual_date, :fin_obligated_date, :fin_comments, :fin_expense_code,
                    :created_by_user_id, :updated_by_user_id
                )";

        $stmt = $pdo->prepare($sql);

        // Bind parameters (ensure all keys exist in $data or handle defaults)
        $created_by = $_SESSION['user_id'] ?? null; // Get current user ID

        // Convert potential empty strings from form to null for date/optional fields
        $enrollment_date = empty($data['enrollment_date']) ? null : $data['enrollment_date'];
        $class_start_date = empty($data['class_start_date']) ? null : $data['class_start_date'];
        $purchase_date = empty($data['purchase_date']) ? null : $data['purchase_date'];
        $fin_accrual_date = empty($data['fin_accrual_date']) ? null : $data['fin_accrual_date'];
        $fin_obligated_date = empty($data['fin_obligated_date']) ? null : $data['fin_obligated_date'];

        // Default payment status if not provided (should be 'U' or 'P' or 'Void' from form)
        $payment_status = $data['payment_status'] ?? 'U';
        if (!in_array($payment_status, ['P', 'U', 'Void'])) {
            $payment_status = 'U'; // Default to Unpaid if invalid value submitted
        }


        $stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $stmt->bindParam(':transaction_date', $data['transaction_date']); // Assumes YYYY-MM-DD format
        $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
        $stmt->bindParam(':client_name', $client_name, PDO::PARAM_STR); // Already validated and set to null if not required
        $stmt->bindParam(':voucher_number', $data['voucher_number']);
        $stmt->bindParam(':enrollment_date', $enrollment_date);
        $stmt->bindParam(':class_start_date', $class_start_date);
        $stmt->bindParam(':purchase_date', $purchase_date);
        $stmt->bindParam(':payment_status', $payment_status); // Use validated status
        $stmt->bindParam(':program_explanation', $data['program_explanation']);

        // Funding amounts - use ?? 0.00 to default if not provided
        $stmt->bindValue(':funding_dw', $data['funding_dw'] ?? 0.00);
        $stmt->bindValue(':funding_dw_admin', $data['funding_dw_admin'] ?? 0.00);
        $stmt->bindValue(':funding_dw_sus', $data['funding_dw_sus'] ?? 0.00);
        $stmt->bindValue(':funding_adult', $data['funding_adult'] ?? 0.00);
        $stmt->bindValue(':funding_adult_admin', $data['funding_adult_admin'] ?? 0.00);
        $stmt->bindValue(':funding_adult_sus', $data['funding_adult_sus'] ?? 0.00);
        $stmt->bindValue(':funding_rr', $data['funding_rr'] ?? 0.00);
        $stmt->bindValue(':funding_h1b', $data['funding_h1b'] ?? 0.00);
        $stmt->bindValue(':funding_youth_is', $data['funding_youth_is'] ?? 0.00);
        $stmt->bindValue(':funding_youth_os', $data['funding_youth_os'] ?? 0.00);
        $stmt->bindValue(':funding_youth_admin', $data['funding_youth_admin'] ?? 0.00);

        // Finance fields (might be restricted based on role adding)
        $stmt->bindParam(':fin_voucher_received', $data['fin_voucher_received']);
        $stmt->bindParam(':fin_accrual_date', $fin_accrual_date);
        $stmt->bindParam(':fin_obligated_date', $fin_obligated_date);
        $stmt->bindParam(':fin_comments', $data['fin_comments']);
        $stmt->bindParam(':fin_expense_code', $data['fin_expense_code']);

        $stmt->bindParam(':created_by_user_id', $created_by, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by_user_id', $created_by, PDO::PARAM_INT); // Set updated_by on creation too


        if ($stmt->execute()) {
            return (int)$pdo->lastInsertId();
        } else {
            error_log("Error adding allocation: " . implode(", ", $stmt->errorInfo()));
            return false;
        }

    } catch (PDOException $e) {
        error_log("PDOException adding allocation: " . $e->getMessage());
        // Check for foreign key constraint errors etc.
        return false;
    }
}


/**
 * Updates an existing budget allocation.
 * Handles new vendor_id and client_name fields.
 * Performs server-side validation for conditional client_name.
 * Enforces field-level edit permissions based on role.
 *
 * @param PDO $pdo Database connection object.
 * @param int $allocation_id The ID of the allocation to update.
 * @param array $data Associative array containing allocation data to update.
 * @return bool True on success, false on failure or no changes made.
 */
function updateAllocation(PDO $pdo, int $allocation_id, array $data): bool
{
     // Permission Check: Ensure user has rights to edit this specific allocation
     // This is complex and depends on role, budget type, and potentially who created it.
     // Example:
     // $originalAllocation = getSingleAllocation($pdo, $allocation_id);
     // if (!$originalAllocation || !canUserManageAllocation($pdo, $_SESSION['user_id'], $_SESSION['active_role'], $originalAllocation['budget_id'], 'edit', $originalAllocation)) {
     //     error_log("Permission denied: User {$_SESSION['user_id']} cannot edit allocation {$allocation_id}");
     //     return false;
     // }

    if ($allocation_id <= 0) {
        return false;
    }

    // --- Validation ---
    $vendor_id = filter_var($data['vendor_id'] ?? null, FILTER_VALIDATE_INT);
    $client_name = isset($data['client_name']) ? trim($data['client_name']) : null;

    if (!$vendor_id /* add other required fields if they can be changed */) {
         error_log("Update Allocation Error: Missing required fields.");
         return false;
    }

    // Conditional Client Name Validation
    $requires_client_name = doesVendorRequireClientName($pdo, $vendor_id);
     if ($requires_client_name === null) {
        error_log("Update Allocation Error: Could not verify vendor requirement for ID $vendor_id.");
        // Set specific error: "Invalid or inactive vendor selected."
        return false;
    }
    if ($requires_client_name && empty($client_name)) {
         error_log("Update Allocation Error: Client Name is required for vendor ID $vendor_id.");
         // Set specific error: "Client Name is required for the selected vendor."
         return false;
    }
     if (!$requires_client_name) {
        $client_name = null; // Ensure client_name is NULL if not required by vendor
    }
    // --- End Validation ---

    // --- Build SQL dynamically based on allowed fields for the role ---
    // This is crucial for security. Define which fields each role can update.
    $allowed_fields_staff = [ // Example for AZ@Work Staff on 'Staff' budgets
        'transaction_date', 'vendor_id', 'client_name', 'voucher_number',
        'enrollment_date', 'class_start_date', 'purchase_date', 'payment_status', // Can they change status? Maybe only to 'Void'?
        'program_explanation',
        'funding_dw', 'funding_dw_admin', 'funding_dw_sus',
        'funding_adult', 'funding_adult_admin', 'funding_adult_sus',
        'funding_rr', 'funding_h1b',
        'funding_youth_is', 'funding_youth_os', 'funding_youth_admin'
        // Cannot edit fin_* fields
    ];
     $allowed_fields_finance = [ // Example for Finance on 'Admin' budgets (all fields) or 'Staff' (only fin_*)
        'transaction_date', 'vendor_id', 'client_name', 'voucher_number',
        'enrollment_date', 'class_start_date', 'purchase_date', 'payment_status',
        'program_explanation',
        'funding_dw', 'funding_dw_admin', 'funding_dw_sus',
        'funding_adult', 'funding_adult_admin', 'funding_adult_sus',
        'funding_rr', 'funding_h1b',
        'funding_youth_is', 'funding_youth_os', 'funding_youth_admin',
        'fin_voucher_received', 'fin_accrual_date', 'fin_obligated_date', 'fin_comments', 'fin_expense_code',
        'fin_processed_by_user_id', 'fin_processed_at' // Set these when Finance processes
    ];
     $allowed_fields_director = array_merge($allowed_fields_staff, [ // Director can edit everything Staff can + potentially fin_*? TBD
         'fin_voucher_received', 'fin_accrual_date', 'fin_obligated_date', 'fin_comments', 'fin_expense_code'
         // Can Director process? Maybe not.
     ]);

    // Determine allowed fields based on role and potentially budget type (fetched earlier in permission check)
    $current_role = $_SESSION['active_role'] ?? null;
    $allowed_fields = [];
    // *** Add logic here to determine $allowed_fields based on $current_role and budget type ***
    // Example:
    if ($current_role === 'director') {
        $allowed_fields = $allowed_fields_director;
    } elseif ($current_role === 'finance') {
        // Needs logic based on budget type
        $allowed_fields = $allowed_fields_finance; // Simplified for now
    } elseif ($current_role === 'azwk_staff') {
         $allowed_fields = $allowed_fields_staff;
    } else {
        error_log("Update Allocation Error: Role '{$current_role}' has no defined edit permissions.");
        return false; // No permissions defined
    }


    $sql_parts = [];
    $params = [];
    foreach ($data as $key => $value) {
        // Only include fields that are allowed for the role AND actually submitted in the form data
        if (in_array($key, $allowed_fields)) {
             // Special handling for boolean/null/dates if needed
             if (in_array($key, ['enrollment_date', 'class_start_date', 'purchase_date', 'fin_accrual_date', 'fin_obligated_date', 'fin_processed_at'])) {
                 $params[':' . $key] = empty($value) ? null : $value;
             } elseif ($key === 'client_name') {
                 $params[':' . $key] = $client_name; // Use validated client_name
             } elseif ($key === 'payment_status') {
                 // Validate payment status value
                 $status_val = $value ?? 'U';
                 if (!in_array($status_val, ['P', 'U', 'Void'])) {
                     $status_val = 'U'; // Default if invalid
                 }
                 // Add specific permission check if only certain roles can void
                 if ($status_val === 'Void' && !check_permission(['director'/*, 'azwk_staff'? TBD */])) {
                     error_log("Permission Denied: Role {$current_role} cannot set status to Void.");
                     continue; // Skip updating this field
                 }
                 $params[':' . $key] = $status_val;
             }
             else {
                 $params[':' . $key] = $value;
             }
             $sql_parts[] = "`$key` = :$key";
        }
    }

    // Always update the 'updated_by_user_id' and 'updated_at'
    $sql_parts[] = "`updated_by_user_id` = :updated_by_user_id";
    $params[':updated_by_user_id'] = $_SESSION['user_id'] ?? null;
    // updated_at is handled by DB trigger or NOW()

    if (empty($sql_parts)) {
        error_log("Update Allocation Warning: No valid or permitted fields submitted for update on ID $allocation_id.");
        return true; // No changes attempted, consider this success? Or false? Or specific code?
    }

    try {
        $sql = "UPDATE budget_allocations SET " . implode(', ', $sql_parts) . " WHERE id = :id AND deleted_at IS NULL";
        $params[':id'] = $allocation_id;

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute($params);

        if (!$success) {
             error_log("Error updating allocation ($allocation_id): " . implode(", ", $stmt->errorInfo()));
             return false;
        }

        // Return true if execute succeeded, even if rowCount is 0 (no actual change needed)
        return true;
        // return $stmt->rowCount() > 0; // Use this if you only want true when rows are actually changed

    } catch (PDOException $e) {
        error_log("PDOException updating allocation ($allocation_id): " . $e->getMessage());
        return false;
    }
}


/**
 * Soft deletes a budget allocation.
 *
 * @param PDO $pdo Database connection object.
 * @param int $allocation_id The ID of the allocation to soft delete.
 * @return bool True on success, false on failure.
 */
function softDeleteAllocation(PDO $pdo, int $allocation_id): bool
{
    // Permission Check: Ensure user has rights to delete this specific allocation
    // Example:
    // $originalAllocation = getSingleAllocation($pdo, $allocation_id);
    // if (!$originalAllocation || !canUserManageAllocation($pdo, $_SESSION['user_id'], $_SESSION['active_role'], $originalAllocation['budget_id'], 'delete', $originalAllocation)) {
    //     error_log("Permission denied: User {$_SESSION['user_id']} cannot delete allocation {$allocation_id}");
    //     return false;
    // }

     if ($allocation_id <= 0) {
        return false;
    }

    try {
        $sql = "UPDATE budget_allocations 
                SET deleted_at = NOW(), 
                    updated_by_user_id = :updated_by_user_id 
                WHERE id = :id AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':updated_by_user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':id', $allocation_id, PDO::PARAM_INT);

        return $stmt->execute();
        // Consider checking rowCount > 0

    } catch (PDOException $e) {
        error_log("Error soft deleting allocation ($allocation_id): " . $e->getMessage());
        return false;
    }
}


// TODO: Add permission check function: canUserManageAllocation(...)
// This function would encapsulate the complex logic of whether a user
// can add/edit/delete/void an allocation based on their role, the budget type,
// budget assignment, and potentially the original creator of the allocation.

/**
 * Fetches all non-deleted budgets with related grant, user, and department names.
 * Orders by budget name.
 *
 * @param PDO $pdo Database connection object.
 * @return array List of budgets or empty array on failure/no budgets.
 */
function getAllBudgets(PDO $pdo): array
{
    // Permission check: Ensure user has rights to view all budgets (e.g., Director/Admin)
    // This might need refinement based on specific access rules.
    if (!check_permission(['director', 'administrator'])) {
         error_log("Permission denied: User {$_SESSION['user_id']} attempted to call getAllBudgets without sufficient privileges.");
         // Depending on requirements, might return only budgets assigned to the user, or empty array.
         return []; 
    }

    try {
        $sql = "SELECT 
                    b.id, b.name AS name, b.user_id, b.grant_id, b.department_id,
                    b.fiscal_year_start, b.fiscal_year_end, b.budget_type, b.notes,
                    b.created_at, b.updated_at,
                    g.name AS grant_name,
                    u.full_name AS user_full_name,
                    d.name AS department_name
                FROM budgets b
                LEFT JOIN grants g ON b.grant_id = g.id
                LEFT JOIN users u ON b.user_id = u.id
                LEFT JOIN departments d ON b.department_id = d.id
                WHERE b.deleted_at IS NULL
                ORDER BY b.name ASC"; // Order by budget name

        $stmt = $pdo->query($sql); // Use query() for simple select without parameters
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error fetching all budgets: " . $e->getMessage());
        return [];
    }
}

/**
 * Adds a new budget to the database.
 *
 * @param PDO $pdo PDO database connection object.
 * @param string $name Name of the budget.
 * @param int $user_id ID of the assigned user.
 * @param int $grant_id ID of the related grant.
 * @param int $department_id ID of the responsible department.
 * @param string $fiscal_year_start Start date of the fiscal year (YYYY-MM-DD).
 * @param string $fiscal_year_end End date of the fiscal year (YYYY-MM-DD).
 * @param string $budget_type Type of budget ('Staff' or 'Admin').
 * @param string|null $notes Optional notes for the budget.
 * @return int|false The ID of the newly created budget, or false on failure.
 */
function addBudget(PDO $pdo, string $name, int $user_id, int $grant_id, int $department_id, string $fiscal_year_start, string $fiscal_year_end, string $budget_type, ?string $notes): int|false
{
    // Basic permission check (ensure user is director/admin)
    if (!check_permission(['director', 'administrator'])) {
        error_log("Permission denied: User {$_SESSION['user_id']} attempted to add a budget.");
        return false;
    }

    // Validate budget type
    if (!in_array($budget_type, ['Staff', 'Admin'])) {
        error_log("Invalid budget type provided: " . $budget_type);
        return false;
    }

    try {
        $sql = "INSERT INTO budgets (name, user_id, grant_id, department_id, fiscal_year_start, fiscal_year_end, budget_type, notes, created_at, updated_at)
                VALUES (:name, :user_id, :grant_id, :department_id, :fiscal_year_start, :fiscal_year_end, :budget_type, :notes, NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);

        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        // Handle potentially null user_id for Admin budgets
        if (empty($user_id) || $user_id <= 0) { // Check if user_id is empty, false, 0, or negative
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        }
        $stmt->bindParam(':grant_id', $grant_id, PDO::PARAM_INT);
        $stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
        $stmt->bindParam(':fiscal_year_start', $fiscal_year_start, PDO::PARAM_STR);
        $stmt->bindParam(':fiscal_year_end', $fiscal_year_end, PDO::PARAM_STR);
        $stmt->bindParam(':budget_type', $budget_type, PDO::PARAM_STR);
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR); // Binds null correctly if $notes is null

        if ($stmt->execute()) {
            return (int)$pdo->lastInsertId();
        } else {
            error_log("Error adding budget: " . implode(", ", $stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        error_log("PDOException adding budget: " . $e->getMessage());
        // Check for specific errors like duplicate entry if needed (e.g., based on name/user/grant/fy combo?)
        return false;
    }
}

/**
 * Updates an existing budget.
 *
 * @param PDO $pdo PDO database connection object.
 * @param int $budget_id ID of the budget to update.
 * @param string $name New name.
 * @param int $user_id New assigned user ID.
 * @param int $grant_id New grant ID.
 * @param int $department_id New department ID.
 * @param string $fiscal_year_start New fiscal year start date.
 * @param string $fiscal_year_end New fiscal year end date.
 * @param string $budget_type New budget type.
 * @param string|null $notes New notes.
 * @return bool True on success or if no rows affected, false on error.
 */
function updateBudget(PDO $pdo, int $budget_id, string $name, int $user_id, int $grant_id, int $department_id, string $fiscal_year_start, string $fiscal_year_end, string $budget_type, ?string $notes): bool
{
     // Basic permission check (ensure user is director/admin)
    if (!check_permission(['director', 'administrator'])) {
        error_log("Permission denied: User {$_SESSION['user_id']} attempted to update budget ID {$budget_id}.");
        return false;
    }
     if ($budget_id <= 0) return false;

    // Validate budget type
    if (!in_array($budget_type, ['Staff', 'Admin'])) {
        error_log("Invalid budget type provided for update: " . $budget_type);
        return false;
    }

    try {
        $sql = "UPDATE budgets SET
                    name = :name,
                    user_id = :user_id,
                    grant_id = :grant_id,
                    department_id = :department_id,
                    fiscal_year_start = :fiscal_year_start,
                    fiscal_year_end = :fiscal_year_end,
                    budget_type = :budget_type,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :budget_id AND deleted_at IS NULL";

        $stmt = $pdo->prepare($sql);

        $stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        // Handle potentially null user_id for Admin budgets
        if (empty($user_id) || $user_id <= 0) { // Check if user_id is empty, false, 0, or negative
             $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
             $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        }
        $stmt->bindParam(':grant_id', $grant_id, PDO::PARAM_INT);
        $stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
        $stmt->bindParam(':fiscal_year_start', $fiscal_year_start, PDO::PARAM_STR);
        $stmt->bindParam(':fiscal_year_end', $fiscal_year_end, PDO::PARAM_STR);
        $stmt->bindParam(':budget_type', $budget_type, PDO::PARAM_STR);
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);

        $success = $stmt->execute();

        if (!$success) {
            error_log("Error updating budget ($budget_id): " . implode(", ", $stmt->errorInfo()));
            return false;
        }
        // Return true even if rowCount is 0 (no change needed)
        return true;

    } catch (PDOException $e) {
        error_log("PDOException updating budget ($budget_id): " . $e->getMessage());
        return false;
    }
}

/**
 * Soft deletes a budget by setting the deleted_at timestamp.
 *
 * @param PDO $pdo PDO database connection object.
 * @param int $budget_id ID of the budget to soft delete.
 * @return bool True on success, false on failure.
 */
function softDeleteBudget(PDO $pdo, int $budget_id): bool
{
     // Basic permission check (ensure user is director/admin)
    if (!check_permission(['director', 'administrator'])) {
        error_log("Permission denied: User {$_SESSION['user_id']} attempted to delete budget ID {$budget_id}.");
        return false;
    }
    if ($budget_id <= 0) return false;

    // Optional: Check if budget has active allocations before deleting?

    try {
        $sql = "UPDATE budgets SET deleted_at = NOW(), updated_at = NOW()
                WHERE id = :budget_id AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        
        return $stmt->execute();
        // Consider checking rowCount > 0 if needed

    } catch (PDOException $e) {
        error_log("Error soft deleting budget ($budget_id): " . $e->getMessage());
        return false;
    }
}
// function updateBudget(...) { ... }
/**
 * Fetches distinct fiscal year start dates from non-deleted budgets.
 * Orders by year descending.
 *
 * @param PDO $pdo Database connection object.
 * @return array List of distinct fiscal year start dates (YYYY-MM-DD) or empty array on failure.
 */
function getDistinctFiscalYears(PDO $pdo): array
{
    try {
        // Select distinct fiscal_year_start and extract the year part for ordering
        // Order by the year part descending to show recent years first
        $sql = "SELECT DISTINCT fiscal_year_start 
                FROM budgets 
                WHERE deleted_at IS NULL AND fiscal_year_start IS NOT NULL
                ORDER BY YEAR(fiscal_year_start) DESC, fiscal_year_start DESC"; 
        $stmt = $pdo->query($sql);
        // Fetch just the date strings
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0); 
    } catch (PDOException $e) {
        error_log("Error fetching distinct fiscal years: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches budgets relevant to the specified user based on their role.
 * - 'azwk_staff' or 'outside_staff': Fetches only budgets directly assigned to their user_id.
 * - 'director' or 'administrator': Fetches all non-deleted budgets.
 * Returns only id and name, ordered by name.
 *
 * @param PDO $pdo Database connection object.
 * @param int $user_id The ID of the user.
 * @param string $role The active role of the user.
 * @return array List of budgets (id, name) or empty array on failure/no budgets.
 */
function getBudgetsForUser(PDO $pdo, int $user_id, string $role): array
{
    if ($user_id <= 0 || empty($role)) {
        return [];
    }

    try {
        $sql = "SELECT id, name 
                FROM budgets 
                WHERE deleted_at IS NULL";

        $params = [];

        // Filter by user_id for staff roles
        if (in_array($role, ['azwk_staff', 'outside_staff'])) {
            $sql .= " AND user_id = :user_id";
            $params[':user_id'] = $user_id;
        } elseif (!in_array($role, ['director', 'administrator'])) {
            // If role is unrecognized and not staff, return empty to be safe
             error_log("getBudgetsForUser: Unrecognized role '{$role}' for user {$user_id}. Returning no budgets.");
            return [];
        }
        // Directors/Admins get all non-deleted budgets (no additional WHERE clause needed)

        $sql .= " ORDER BY name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error fetching budgets for user ID ($user_id), role ($role): " . $e->getMessage());
        return [];
    }
}
// function softDeleteBudget(...) { ... }