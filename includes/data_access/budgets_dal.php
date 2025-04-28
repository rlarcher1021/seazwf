<?php
// includes/data_access/budgets_dal.php
// Data Access Layer for Budgets

require_once __DIR__ . '/../db_connect.php'; // Adjust path as needed
require_once __DIR__ . '/../utils.php'; // For potential utility functions like checking roles
require_once __DIR__ . '/grants_dal.php';
require_once __DIR__ . '/finance_access_data.php'; // Needed for finance access check within staff role
require_once __DIR__ . '/department_data.php'; // Needed to get department slug

/**
 * Retrieves a single budget by its ID, ensuring it's not soft-deleted.
 * Includes related grant name, user name (first/last), and department name.
 * Accessible by authenticated users (permissions checked in calling script).
 *
 * @param PDO $pdo The database connection object.
 * @param int $id The ID of the budget to retrieve.
 * @return array|false An associative array representing the budget with related names, or false if not found or deleted.
 */
function getBudgetById(PDO $pdo, int $id): array|false {
    // Basic authentication check - adjust as needed
    // if (!isset($_SESSION['user_id'])) { return false; }

    $sql = "SELECT
                b.id, b.name, b.user_id, b.grant_id, b.department_id,
                b.fiscal_year_start, b.fiscal_year_end, b.budget_type, b.notes,
                b.created_at, b.updated_at,
                g.name as grant_name,
                u.full_name as user_full_name, -- Use full_name based on user_data.php schema
                d.name as department_name -- Assuming departments table has name
            FROM budgets b
            JOIN grants g ON b.grant_id = g.id
            LEFT JOIN users u ON b.user_id = u.id -- Changed to LEFT JOIN to handle NULL user_id for Admin budgets
            JOIN departments d ON b.department_id = d.id
            WHERE b.id = :id AND b.deleted_at IS NULL AND g.deleted_at IS NULL"; // Also check grant isn't deleted
            // Add checks for user/department active status if needed

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error in getBudgetById: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves budgets relevant for a specific user based on role and potential department access.
 * This is a placeholder and needs refinement based on exact permission rules.
 * Directors see all AZ@Work budgets. Staff see their own 'Staff' budgets. Finance see budgets for allowed departments.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $user_role
 * @param array $finance_dept_ids Array of department IDs finance user can access (if applicable)
 * @param array $filters Optional filters like fiscal_year, grant_id
 * @return array
 */
function getBudgetsForUser(PDO $pdo, int $user_id, string $user_role, array $finance_dept_ids = [], array $filters = []): array {

    // Fetch user's department details first (needed for staff role)
    $user_dept_id = null;
    $user_dept_slug = null;
    try {
        $stmt_user_dept = $pdo->prepare("SELECT department_id FROM users WHERE id = :user_id");
        $stmt_user_dept->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt_user_dept->execute();
        $user_dept_id = $stmt_user_dept->fetchColumn();

        if ($user_dept_id) {
            $dept_details = getDepartmentById($pdo, $user_dept_id);
            $user_dept_slug = $dept_details['slug'] ?? null;
        }
    } catch (PDOException $e) {
        error_log("Error fetching user department details in getBudgetsForUser: " . $e->getMessage());
    }

    $is_staff_in_finance = ($user_role === 'azwk_staff' && strtolower((string)$user_dept_slug) === 'finance');
    error_log("[getBudgetsForUser] User ID: {$user_id}, Role: {$user_role}, Dept Slug: {$user_dept_slug}, Is Staff in Finance: " . ($is_staff_in_finance ? 'Yes' : 'No'));

    $combined_results = [];
    $processed_budget_ids = []; // To avoid duplicates

    // --- 1. Define Query Execution Helper ---
    $executeQuery = function(array $role_where_clauses, array $role_params) use ($pdo, $filters): array {
        $base_sql = "SELECT b.*, g.name as grant_name, d.name as department_name
                     FROM budgets b
                     LEFT JOIN grants g ON b.grant_id = g.id
                     LEFT JOIN departments d ON b.department_id = d.id
                     WHERE b.deleted_at IS NULL"; // Base query selects non-deleted budgets

        $query_filter_clauses = [];
        $query_params = []; // Params derived from $filters

        // Apply general filters from $filters array
        if (!empty($filters['fiscal_year'])) {
            $year = filter_var($filters['fiscal_year'], FILTER_VALIDATE_INT);
            if ($year) {
                $query_filter_clauses[] = "YEAR(b.fiscal_year_start) = :filter_fiscal_year";
                $query_params[':filter_fiscal_year'] = $year;
            }
        }
        if (!empty($filters['grant_id'])) {
            $grant_id = filter_var($filters['grant_id'], FILTER_VALIDATE_INT);
            if ($grant_id) {
                $query_filter_clauses[] = "b.grant_id = :filter_grant_id";
                $query_params[':filter_grant_id'] = $grant_id;
            }
        }
        if (!empty($filters['department_id'])) {
             $department_id = filter_var($filters['department_id'], FILTER_VALIDATE_INT);
             if ($department_id) {
                 // Department filter is applied differently based on role, handled within role clauses or here if needed globally
                 // For simplicity, let's assume department filter applies AFTER role selection
                 $query_filter_clauses[] = "b.department_id = :filter_department_id";
                 $query_params[':filter_department_id'] = $department_id;
             }
         }
 
         // Combine role-specific params and filter params
         $final_params = array_merge($role_params, $query_params);

         // Combine role-specific WHERE clauses and filter WHERE clauses
         $all_where_conditions = array_merge($role_where_clauses, $query_filter_clauses);
         $final_sql = $base_sql; // Start with the base SQL

         if (!empty($all_where_conditions)) {
             // Append all conditions using AND
             $final_sql .= " AND (" . implode(") AND (", $all_where_conditions) . ")";
         }

         $final_sql .= " ORDER BY b.fiscal_year_start DESC, g.name ASC, b.name ASC";

         error_log("[getBudgetsForUser Query] SQL: " . $final_sql);
         error_log("[getBudgetsForUser Query] Params: " . print_r($final_params, true));

         try {
             $stmt = $pdo->prepare($final_sql);
             $stmt->execute($final_params); // Use combined parameters
             $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
             error_log("[getBudgetsForUser Query] Row count: " . count($results));
             return $results;
         } catch (PDOException $e) {
             error_log("Database error executing query part: " . $e->getMessage() . " | SQL: " . $final_sql);
             return [];
         }
    }; // End of $executeQuery closure definition
    // --- End Helper Function Definition ---


    // --- Determine which queries to run ---

    if ($user_role === 'director') {
        // Director sees all - run base query with only filters applied
        $combined_results = $executeQuery([], []);

    } elseif ($user_role === 'azwk_staff') {
        // --- Staff Role ---
        if ($is_staff_in_finance) {
            // Staff in Finance sees all budgets (like director)
            error_log("[getBudgetsForUser] Staff in Finance detected. Fetching all budgets.");
            $combined_results = $executeQuery([], []);
            // No need to check processed_budget_ids as it's a single query fetching all relevant budgets.
        } else {
            // Non-Finance Staff: Only get their assigned 'Staff' budgets
            error_log("[getBudgetsForUser] Non-Finance Staff detected. Fetching assigned Staff budgets.");
            $staff_role_clause = ["(b.user_id = :role_user_id AND b.budget_type = 'Staff')"];
            $staff_params = [':role_user_id' => $user_id];
            $combined_results = $executeQuery($staff_role_clause, $staff_params);
             // No need to check processed_budget_ids as it's a single query fetching only relevant budgets.
        }

    } elseif ($user_role === 'finance') {
        // --- Finance Role ---
        if (!empty($finance_dept_ids)) {
            $finance_role_clause = [];
            $finance_params = [];
            $dept_placeholders = [];
            foreach ($finance_dept_ids as $index => $dept_id) {
                $placeholder = ':role_dept_id_' . $index;
                $dept_placeholders[] = $placeholder;
                $finance_params[$placeholder] = $dept_id;
            }
            if (!empty($dept_placeholders)) {
                 $finance_role_clause[] = "(b.department_id IN (" . implode(',', $dept_placeholders) . "))";
                 $combined_results = $executeQuery($finance_role_clause, $finance_params);
                 // No need to check processed_budget_ids here as it's the only query for this role
            }
        }
        // If $finance_dept_ids is empty, $combined_results remains empty, which is correct.
    } else {
        // Unknown role
        return [];
    }

    // --- Final Processing (e.g., sorting if needed after merge) ---
    // The ORDER BY in the query should handle most cases, but if merging results
    // from different queries, you might want to re-sort the $combined_results array here in PHP.
    // Example: usort($combined_results, function($a, $b) { ... });

    error_log("[getBudgetsForUser] Final combined result count for User ID {$user_id}: " . count($combined_results));
    return $combined_results;
}


// Removed closing PHP tag here to include the function below

/**
 * Retrieves distinct fiscal year start dates from non-deleted budgets.
 * Useful for populating filter dropdowns.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of distinct fiscal year start dates (YYYY-MM-DD strings), ordered descending. Empty array on error.
 */
function getDistinctFiscalYearStarts(PDO $pdo): array
{
    // Consider filtering based on user role/access if necessary,
    // but typically fiscal years are global context.
    $sql = "SELECT DISTINCT fiscal_year_start
            FROM budgets
            WHERE deleted_at IS NULL
            ORDER BY fiscal_year_start DESC";
    try {
        $stmt = $pdo->query($sql);
        if (!$stmt) {
             error_log("ERROR getDistinctFiscalYearStarts: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        // Fetch just the dates as a simple array
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getDistinctFiscalYearStarts: " . $e->getMessage());
        return [];
    }
}