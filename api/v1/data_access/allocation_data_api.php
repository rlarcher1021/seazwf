<?php

/**
 * Fetches budget allocations based on provided filters and pagination.
 *
 * Handles validation of query parameters, dynamic SQL query building with prepared statements,
 * execution, and fetching results including pagination information.
 *
 * @param mysqli $conn The database connection object.
 * @param array $queryParams Associative array of query parameters (e.g., $_GET).
 * @return array An array containing 'data' (array of allocation records) and 'pagination' info.
 * @throws InvalidArgumentException If query parameters have invalid format.
 * @throws RuntimeException If a database error occurs.
 */
function getAllocations(mysqli $conn, array $queryParams): array
{
    // --- Parameter Validation & Defaults ---
    $filters = [];
    $params = [];
    $types = "";

    // Fiscal Year (Validate as YYYY integer)
    if (isset($queryParams['fiscal_year'])) {
        $year = filter_var($queryParams['fiscal_year'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1900, 'max_range' => date('Y') + 10] // Adjust range as needed
        ]);
        if ($year === false) {
            throw new InvalidArgumentException('Invalid query parameter format for fiscal_year. Must be a valid year (YYYY).');
        }
        // Assuming fiscal year filtering is based on budget's start year
        $filters[] = "YEAR(b.fiscal_year_start) = ?";
        $params[] = $year;
        $types .= "i";
    }

    // Grant ID (Validate as positive integer)
    if (isset($queryParams['grant_id'])) {
        $grantId = filter_var($queryParams['grant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($grantId === false) {
            throw new InvalidArgumentException('Invalid query parameter format for grant_id. Must be a positive integer.');
        }
        $filters[] = "b.grant_id = ?";
        $params[] = $grantId;
        $types .= "i";
    }

    // Department ID (Validate as positive integer)
    if (isset($queryParams['department_id'])) {
        $deptId = filter_var($queryParams['department_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deptId === false) {
            throw new InvalidArgumentException('Invalid query parameter format for department_id. Must be a positive integer.');
        }
        $filters[] = "b.department_id = ?";
        $params[] = $deptId;
        $types .= "i";
    }

    // Budget ID (Validate as positive integer)
    if (isset($queryParams['budget_id'])) {
        $budgetId = filter_var($queryParams['budget_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($budgetId === false) {
            throw new InvalidArgumentException('Invalid query parameter format for budget_id. Must be a positive integer.');
        }
        $filters[] = "ba.budget_id = ?";
        $params[] = $budgetId;
        $types .= "i";
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
        $stmtCount = $conn->prepare($countSql);
        if (!$stmtCount) {
             throw new RuntimeException("Database error preparing count query: " . $conn->error);
        }
        if (!empty($params)) {
            $stmtCount->bind_param($types, ...$params);
        }
        $stmtCount->execute();
        $resultCount = $stmtCount->get_result();
        $totalRecords = (int)$resultCount->fetch_assoc()['total'];
        $stmtCount->close();
    } catch (mysqli_sql_exception $e) {
        error_log("API DB Error (Count Allocations): " . $e->getMessage());
        throw new RuntimeException("Database error counting allocations.", 0, $e);
    }

    // --- Fetch Paginated Data ---
    $dataSql = "SELECT ba.*, b.name as budget_name, b.fiscal_year_start, b.grant_id, b.department_id " // Select specific fields needed
             . $baseSql . " " . $whereSql
             . " ORDER BY ba.transaction_date DESC, ba.id DESC " // Example ordering
             . " LIMIT ? OFFSET ?";

    $data = [];
    try {
        $stmtData = $conn->prepare($dataSql);
         if (!$stmtData) {
             throw new RuntimeException("Database error preparing data query: " . $conn->error);
        }

        // Add limit and offset params
        $dataParams = $params;
        $dataTypes = $types . "ii"; // Add types for limit and offset
        $dataParams[] = $limit;
        $dataParams[] = $offset;

        if (!empty($dataParams)) {
            $stmtData->bind_param($dataTypes, ...$dataParams);
        }
        $stmtData->execute();
        $resultData = $stmtData->get_result();
        $data = $resultData->fetch_all(MYSQLI_ASSOC);
        $stmtData->close();

    } catch (mysqli_sql_exception $e) {
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