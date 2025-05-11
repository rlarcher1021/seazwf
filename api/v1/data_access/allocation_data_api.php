<?php

/**
 * Fetches budget allocations based on provided filters and pagination.
 *
 * Handles validation of query parameters, dynamic SQL query building with prepared statements,
 * execution, and fetching results including pagination information.
 *
 * @param PDO $pdo The database connection object (PDO).
 * @param array $queryParams Associative array of query parameters (e.g., $_GET).
 * @return array An array containing 'data' (array of allocation records) and 'pagination' info.
 * @throws InvalidArgumentException If query parameters have invalid format.
 * @throws RuntimeException If a database error occurs.
 */
function getAllocations(PDO $pdo, array $queryParams): array
{
    // --- Parameter Validation & Defaults ---
    $filters = [];
    $params = []; // Use associative array for named placeholders

    // Fiscal Year (Validate as YYYY integer)
    if (isset($queryParams['fiscal_year']) && $queryParams['fiscal_year'] !== '') {
        $year = filter_var($queryParams['fiscal_year'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1900, 'max_range' => date('Y') + 10] // Adjust range as needed
        ]);
        if ($year === false) {
            // If it's set and not empty, but still invalid, then throw error
            throw new InvalidArgumentException('Invalid query parameter format for fiscal_year. Must be a valid year (YYYY).');
        }
        // Assuming fiscal year filtering is based on budget's start year
        $filters[] = "YEAR(b.fiscal_year_start) = :fiscal_year"; // Use named placeholder
        $params[':fiscal_year'] = $year;
    }

    // Grant ID (Validate as positive integer)
    if (isset($queryParams['grant_id']) && $queryParams['grant_id'] !== '') {
        $grantId = filter_var($queryParams['grant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($grantId === false) {
            // If it's set and not empty, but still invalid, then throw error
            throw new InvalidArgumentException('Invalid query parameter format for grant_id. Must be a positive integer.');
        }
        $filters[] = "b.grant_id = :grant_id"; // Use named placeholder
        $params[':grant_id'] = $grantId;
    }

    // Department ID (Validate as positive integer)
    if (isset($queryParams['department_id']) && $queryParams['department_id'] !== '') {
        $deptId = filter_var($queryParams['department_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deptId === false) {
            // If it's set and not empty, but still invalid, then throw error
            throw new InvalidArgumentException('Invalid query parameter format for department_id. Must be a positive integer.');
        }
        $filters[] = "b.department_id = :department_id"; // Use named placeholder
        $params[':department_id'] = $deptId;
    }

    // Budget ID (Validate as positive integer)
    if (isset($queryParams['budget_id']) && $queryParams['budget_id'] !== '') {
        $budgetId = filter_var($queryParams['budget_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($budgetId === false) {
            // If it's set and not empty, but still invalid, then throw error
            throw new InvalidArgumentException('Invalid query parameter format for budget_id. Must be a positive integer.');
        }
        $filters[] = "ba.budget_id = :budget_id"; // Use named placeholder
        $params[':budget_id'] = $budgetId;
    }

    // User ID (Validate as positive integer, for filtering by creator)
    if (isset($queryParams['user_id']) && $queryParams['user_id'] !== '') {
        $userId = filter_var($queryParams['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            // If it's set and not empty, but still invalid, then throw error
            throw new InvalidArgumentException('Invalid query parameter format for user_id. Must be a positive integer.');
        }
        $filters[] = "ba.created_by_user_id = :user_id"; // Use named placeholder
        $params[':user_id'] = $userId;
    }

    // Pagination
    $page = filter_var($queryParams['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $limit = filter_var($queryParams['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]); // Max limit 100
    if ($page === false || $limit === false) {
        throw new InvalidArgumentException('Invalid query parameter format for page or limit. Must be positive integers.');
    }
    $offset = ($page - 1) * $limit;

    // --- Build SQL Query ---
    $baseSql = "FROM budget_allocations ba JOIN budgets b ON ba.budget_id = b.id";
    // Add optional joins if filtering by grant or department requires them (already joined budgets)
    // Example: if filtering by grant name instead of ID, you'd join grants here.
    // Example: if filtering by department name instead of ID, you'd join departments here.

    $whereClauses = ["ba.deleted_at IS NULL", "b.deleted_at IS NULL"]; // Base non-deleted filter
    if (!empty($filters)) {
        $whereClauses = array_merge($whereClauses, $filters);
    }
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);

    // --- Count Total Records ---
    $countSql = "SELECT COUNT(ba.id) as total " . $baseSql . " " . $whereSql;
    $totalRecords = 0;
    try {
        $stmtCount = $pdo->prepare($countSql);
        // Bind parameters directly in execute for PDO
        $stmtCount->execute($params); // Pass associative array for named placeholders
        $totalRecords = (int)$stmtCount->fetchColumn(); // Fetch the single count value
    } catch (PDOException $e) { // Catch PDOException
        error_log("API DB Error (Count Allocations): " . $e->getMessage());
        throw new RuntimeException("Database error counting allocations.", 0, $e);
    }

    // --- Fetch Paginated Data ---
    $dataSql = "SELECT ba.*, b.name as budget_name, b.fiscal_year_start, b.grant_id, b.department_id " // Select specific fields needed
             . $baseSql . " " . $whereSql
             . " ORDER BY ba.transaction_date DESC, ba.id DESC " // Example ordering
             . " LIMIT :limit OFFSET :offset"; // Use named placeholders for limit/offset

    $data = [];
    try {
        $stmtData = $pdo->prepare($dataSql);

        // Bind the filter parameters (if any) using named placeholders
        foreach ($params as $key => $value) {
             // Determine type (simple check, adjust if needed for floats/blobs etc.)
             $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
             $stmtData->bindValue($key, $value, $paramType);
        }

        // Bind LIMIT and OFFSET using named placeholders
        $stmtData->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmtData->execute();
        $data = $stmtData->fetchAll(PDO::FETCH_ASSOC); // Fetch all results as associative array

    } catch (PDOException $e) { // Catch PDOException
        error_log("API DB Error (Fetch Allocations): " . $e->getMessage());
        throw new RuntimeException("Database error fetching allocations.", 0, $e);
    }

    // --- Return Results ---
    return [
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => ($limit > 0) ? ceil($totalRecords / $limit) : 0
        ]
    ];
}

?>