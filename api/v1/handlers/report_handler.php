<?php

/**
 * Handles the logic for the GET /api/v1/reports endpoint.
 *
 * @param PDO $pdo The database connection object.
 * @param array $apiKeyData Authenticated API key data (including id, permissions, user_id, site_id).
 * @param array $queryParams The query parameters from the request ($_GET).
 * @return array An array containing the report data and pagination info, ready for JSON encoding.
 * @throws InvalidArgumentException For bad request errors (e.g., invalid parameters).
 * @throws RuntimeException For internal server errors (e.g., database issues).
 */
function handleGetReports(PDO $pdo, array $apiKeyData, array $queryParams): array
{
    // 1. Validate 'type' parameter
    if (!isset($queryParams['type']) || empty(trim($queryParams['type']))) {
        throw new InvalidArgumentException("Missing or empty 'type' query parameter.", 400); // Use code for status
    }
    $reportType = trim($queryParams['type']);
    $allowedTypes = ['checkin_detail', 'allocation_detail'];
    if (!in_array($reportType, $allowedTypes)) {
        throw new InvalidArgumentException("Invalid 'type' parameter. Allowed types: " . implode(', ', $allowedTypes) . ".", 400);
    }

    // 2. Validate Common Parameters (Dates, Pagination)
    $validatedParams = validateCommonReportParams($queryParams);

    // 3. Dispatch to specific report generator based on type
    switch ($reportType) {
        case 'checkin_detail':
            return generateCheckinDetailReport($pdo, $apiKeyData, $validatedParams);
        case 'allocation_detail':
            return generateAllocationDetailReport($pdo, $apiKeyData, $validatedParams);
        default:
            // This case should not be reachable due to the check above, but included for safety.
             throw new RuntimeException("Unhandled report type: {$reportType}", 500);
    }
}

/**
 * Validates common query parameters (dates, pagination).
 *
 * @param array $queryParams Raw query parameters.
 * @return array Validated parameters (start_date, end_date, limit, page, offset).
 * @throws InvalidArgumentException If validation fails.
 */
function validateCommonReportParams(array $queryParams): array
{
    $validated = [];

    // Dates (Optional)
    $validated['start_date'] = null;
    if (isset($queryParams['start_date']) && !empty(trim($queryParams['start_date']))) {
        $date = DateTime::createFromFormat('Y-m-d', trim($queryParams['start_date']));
        if (!$date || $date->format('Y-m-d') !== trim($queryParams['start_date'])) {
            throw new InvalidArgumentException("Invalid 'start_date' format. Use YYYY-MM-DD.", 400);
        }
        $validated['start_date'] = $date->format('Y-m-d');
    }

    $validated['end_date'] = null;
    if (isset($queryParams['end_date']) && !empty(trim($queryParams['end_date']))) {
        $date = DateTime::createFromFormat('Y-m-d', trim($queryParams['end_date']));
        if (!$date || $date->format('Y-m-d') !== trim($queryParams['end_date'])) {
            throw new InvalidArgumentException("Invalid 'end_date' format. Use YYYY-MM-DD.", 400);
        }
        // Ensure end_date includes the full day for comparison <=
        $validated['end_date'] = $date->format('Y-m-d 23:59:59');
        // Also store the original for potential display/validation if needed elsewhere
        $validated['end_date_original'] = $date->format('Y-m-d');

        // Optional: Check if start_date is before end_date if both are provided
        if ($validated['start_date'] && $validated['end_date_original'] < $validated['start_date']) {
             throw new InvalidArgumentException("'end_date' cannot be before 'start_date'.", 400);
        }
    }


    // Pagination (Optional, with defaults)
    $limit = filter_var($queryParams['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]); // Max limit 1000
    if ($limit === false) {
        throw new InvalidArgumentException("Invalid 'limit' parameter. Must be an integer between 1 and 1000.", 400);
    }
    $validated['limit'] = $limit;

    $page = filter_var($queryParams['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false) {
        throw new InvalidArgumentException("Invalid 'page' parameter. Must be a positive integer.", 400);
    }
    $validated['page'] = $page;

    // Calculate offset
    $validated['offset'] = ($validated['page'] - 1) * $validated['limit'];

    // Pass through other params for type-specific validation
    $validated['other_params'] = array_diff_key($queryParams, array_flip(['type', 'start_date', 'end_date', 'limit', 'page']));


    return $validated;
}


/**
 * Generates the 'checkin_detail' report.
 *
 * @param PDO $pdo
 * @param array $apiKeyData
 * @param array $validatedParams (Includes common params and 'other_params')
 * @return array Report data and pagination.
 * @throws InvalidArgumentException For permission errors (403) or bad parameters (400).
 * @throws RuntimeException For internal errors (500).
 */
function generateCheckinDetailReport(PDO $pdo, array $apiKeyData, array $validatedParams): array
{
    // --- Authorization (Scope Check) ---
    $hasReadAll = checkApiKeyPermission('read:all_checkin_data', $apiKeyData);
    $hasReadSite = checkApiKeyPermission('read:site_checkin_data', $apiKeyData);

    $whereClauses = ['ci.deleted_at IS NULL']; // Base clause for soft delete if applicable (assuming check_ins might have it)
    $queryParams = [];
    $siteIdFilter = null;

    if ($hasReadAll) {
        // Can filter by site_id if provided
        if (isset($validatedParams['other_params']['site_id'])) {
            $siteId = filter_var($validatedParams['other_params']['site_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($siteId === false) {
                 throw new InvalidArgumentException("Invalid 'site_id' parameter. Must be a positive integer.", 400);
            }
            $whereClauses[] = "ci.site_id = :site_id_param";
            $queryParams[':site_id_param'] = $siteId;
            $siteIdFilter = $siteId; // Store for count query
        }
    } elseif ($hasReadSite) {
        // Must filter by associated_site_id
        $associatedSiteId = $apiKeyData['associated_site_id'];
        if (empty($associatedSiteId)) {
            throw new InvalidArgumentException("Permission 'read:site_checkin_data' requires the API key to have an associated site ID.", 403); // 403 Forbidden
        }
        // Ignore any site_id provided by user
        if (isset($validatedParams['other_params']['site_id'])) {
             // Optionally log a warning: User tried to override site filter with read:site_checkin_data permission
             error_log("API Warning: 'site_id' parameter ignored for key ID {$apiKeyData['id']} due to 'read:site_checkin_data' scope.");
        }
        $whereClauses[] = "ci.site_id = :associated_site_id";
        $queryParams[':associated_site_id'] = $associatedSiteId;
        $siteIdFilter = $associatedSiteId; // Store for count query
    } else {
        // No permission
        throw new InvalidArgumentException("Permission denied. Requires 'read:all_checkin_data' or 'read:site_checkin_data'.", 403); // 403 Forbidden
    }

    // --- Apply Date Filters ---
    if ($validatedParams['start_date']) {
        $whereClauses[] = "ci.check_in_time >= :start_date";
        $queryParams[':start_date'] = $validatedParams['start_date'];
    }
    if ($validatedParams['end_date']) {
        $whereClauses[] = "ci.check_in_time <= :end_date";
        $queryParams[':end_date'] = $validatedParams['end_date'];
    }

    // --- Build Queries ---
    $baseSql = "FROM check_ins ci";
    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    // Count Query (without pagination)
    $countSql = "SELECT COUNT(*) " . $baseSql . " " . $whereSql;
    try {
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($queryParams);
        $totalRecords = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("API DB Error (Count Checkin Report): " . $e->getMessage());
        throw new RuntimeException("Database error while counting check-in records.", 500);
    }

    // Data Query (with pagination)
    // Select desired columns - adjust as needed based on what the report should show
    $selectColumns = "ci.id, ci.site_id, ci.first_name, ci.last_name, ci.check_in_time, ci.client_email, ci.notified_staff_id"; // Add other relevant q_* fields?
    $dataSql = "SELECT " . $selectColumns . " "
             . $baseSql . " "
             . $whereSql . " "
             . "ORDER BY ci.check_in_time DESC " // Example ordering
             . "LIMIT :limit OFFSET :offset";

    // Add limit and offset to parameters for data query
    $queryParams[':limit'] = $validatedParams['limit'];
    $queryParams[':offset'] = $validatedParams['offset'];

    try {
        $dataStmt = $pdo->prepare($dataSql);
        // Bind parameters explicitly for LIMIT/OFFSET as they need INT type
        foreach ($queryParams as $key => $value) {
             $type = ($key === ':limit' || $key === ':offset') ? PDO::PARAM_INT : PDO::PARAM_STR;
             // Adjust type for specific known INT params if needed (e.g., site_id)
             if ($key === ':site_id_param' || $key === ':associated_site_id') {
                 $type = PDO::PARAM_INT;
             }
             $dataStmt->bindValue($key, $value, $type);
        }
        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("API DB Error (Data Checkin Report): " . $e->getMessage());
        throw new RuntimeException("Database error while fetching check-in data.", 500);
    }

    // --- Calculate Pagination ---
    $totalPages = ($totalRecords > 0 && $validatedParams['limit'] > 0) ? ceil($totalRecords / $validatedParams['limit']) : 0;

    // --- Format Response ---
    return [
        'data' => $data,
        'pagination' => [
            'page' => $validatedParams['page'],
            'limit' => $validatedParams['limit'],
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ];
}


/**
 * Generates the 'allocation_detail' report.
 *
 * @param PDO $pdo
 * @param array $apiKeyData
 * @param array $validatedParams (Includes common params and 'other_params')
 * @return array Report data and pagination.
 * @throws InvalidArgumentException For permission errors (403) or bad parameters (400).
 * @throws RuntimeException For internal errors (500).
 */
function generateAllocationDetailReport(PDO $pdo, array $apiKeyData, array $validatedParams): array
{
    // --- Authorization (Scope Check) ---
    $hasReadAll = checkApiKeyPermission('read:all_allocation_data', $apiKeyData);
    $hasReadOwn = checkApiKeyPermission('read:own_allocation_data', $apiKeyData);

    $whereClauses = ['ba.deleted_at IS NULL', 'b.deleted_at IS NULL']; // Base clauses for soft delete
    $queryParams = [];
    $joins = "FROM budget_allocations ba JOIN budgets b ON ba.budget_id = b.id"; // Base JOIN

    if ($hasReadAll) {
        // Can filter by site_id, department_id, grant_id, budget_id, user_id
        if (isset($validatedParams['other_params']['site_id'])) {
            $siteId = filter_var($validatedParams['other_params']['site_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($siteId === false) throw new InvalidArgumentException("Invalid 'site_id'. Must be positive integer.", 400);
            // Assuming budgets table has site_id - Check Schema! If not, need another JOIN
            // From schema: users has site_id, budgets has user_id. Need JOIN users u ON b.user_id = u.id
            // Let's assume for now budgets *might* get a site_id later or we filter via user's site.
            // Revisit this join/filter logic based on exact schema linkage.
            // For now, let's assume budgets *does* have site_id for the example.
            // $joins .= " LEFT JOIN sites s ON b.site_id = s.id"; // Example if budgets had site_id
            // $whereClauses[] = "b.site_id = :site_id_param";
            // $queryParams[':site_id_param'] = $siteId;
             error_log("API Warning: site_id filter for allocations currently not implemented due to schema linkage uncertainty (budgets.site_id).");
             // To implement: Add JOIN to users table: JOIN users u ON b.user_id = u.id
             // Then filter: $whereClauses[] = "u.site_id = :site_id_param";
        }
        if (isset($validatedParams['other_params']['department_id'])) {
            $deptId = filter_var($validatedParams['other_params']['department_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($deptId === false) throw new InvalidArgumentException("Invalid 'department_id'. Must be positive integer.", 400);
            $whereClauses[] = "b.department_id = :dept_id_param";
            $queryParams[':dept_id_param'] = $deptId;
        }
        if (isset($validatedParams['other_params']['grant_id'])) {
            $grantId = filter_var($validatedParams['other_params']['grant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($grantId === false) throw new InvalidArgumentException("Invalid 'grant_id'. Must be positive integer.", 400);
            $whereClauses[] = "b.grant_id = :grant_id_param";
            $queryParams[':grant_id_param'] = $grantId;
        }
        if (isset($validatedParams['other_params']['budget_id'])) {
            $budgetId = filter_var($validatedParams['other_params']['budget_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($budgetId === false) throw new InvalidArgumentException("Invalid 'budget_id'. Must be positive integer.", 400);
            $whereClauses[] = "ba.budget_id = :budget_id_param";
            $queryParams[':budget_id_param'] = $budgetId;
        }
         if (isset($validatedParams['other_params']['user_id'])) {
            $userId = filter_var($validatedParams['other_params']['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) throw new InvalidArgumentException("Invalid 'user_id'. Must be positive integer.", 400);
            $whereClauses[] = "b.user_id = :user_id_param"; // Filter by budget owner
            $queryParams[':user_id_param'] = $userId;
        }

    } elseif ($hasReadOwn) {
        // Must filter by associated_user_id
        $associatedUserId = $apiKeyData['associated_user_id'];
        if (empty($associatedUserId)) {
            throw new InvalidArgumentException("Permission 'read:own_allocation_data' requires the API key to have an associated user ID.", 403); // 403 Forbidden
        }
        // Ignore disallowed filters
        $disallowedParams = ['site_id', 'department_id', 'grant_id', 'user_id'];
        foreach($disallowedParams as $param) {
            if (isset($validatedParams['other_params'][$param])) {
                 error_log("API Warning: '{$param}' parameter ignored for key ID {$apiKeyData['id']} due to 'read:own_allocation_data' scope.");
            }
        }
        // Apply mandatory user filter
        $whereClauses[] = "b.user_id = :associated_user_id";
        $queryParams[':associated_user_id'] = $associatedUserId;

        // Allow filtering by budget_id (only budgets they own)
        if (isset($validatedParams['other_params']['budget_id'])) {
            $budgetId = filter_var($validatedParams['other_params']['budget_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($budgetId === false) throw new InvalidArgumentException("Invalid 'budget_id'. Must be positive integer.", 400);
            $whereClauses[] = "ba.budget_id = :budget_id_param";
            $queryParams[':budget_id_param'] = $budgetId;
            // Note: The b.user_id = :associated_user_id clause ensures they can only filter their own budgets.
        }

    } else {
        // No permission
        throw new InvalidArgumentException("Permission denied. Requires 'read:all_allocation_data' or 'read:own_allocation_data'.", 403); // 403 Forbidden
    }

    // --- Apply Date Filters ---
    if ($validatedParams['start_date']) {
        $whereClauses[] = "ba.transaction_date >= :start_date";
        $queryParams[':start_date'] = $validatedParams['start_date'];
    }
    if ($validatedParams['end_date']) {
        // Use end_date which includes time up to 23:59:59
        $whereClauses[] = "ba.transaction_date <= :end_date";
        $queryParams[':end_date'] = $validatedParams['end_date'];
    }

    // --- Build Queries ---
    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    // Count Query
    $countSql = "SELECT COUNT(ba.id) " . $joins . " " . $whereSql;
     try {
        $countStmt = $pdo->prepare($countSql);
        // Bind parameters (ensure correct types if needed, though PDO often handles it)
         foreach ($queryParams as $key => $value) {
             $type = PDO::PARAM_STR; // Default
             if (in_array($key, [':site_id_param', ':dept_id_param', ':grant_id_param', ':budget_id_param', ':user_id_param', ':associated_user_id'])) {
                 $type = PDO::PARAM_INT;
             }
             $countStmt->bindValue($key, $value, $type);
         }
        $countStmt->execute();
        $totalRecords = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("API DB Error (Count Allocation Report): " . $e->getMessage());
        throw new RuntimeException("Database error while counting allocation records.", 500);
    }


    // Data Query
    // Select desired columns - adjust as needed
    $selectColumns = "ba.id, ba.budget_id, b.name as budget_name, b.department_id, b.grant_id, b.user_id as budget_owner_user_id, ba.transaction_date, ba.vendor_id, ba.client_name, ba.voucher_number, ba.payment_status, ba.created_at";
    $dataSql = "SELECT " . $selectColumns . " "
             . $joins . " "
             . $whereSql . " "
             . "ORDER BY ba.transaction_date DESC, ba.id DESC " // Example ordering
             . "LIMIT :limit OFFSET :offset";

    // Add limit and offset for data query
    $queryParams[':limit'] = $validatedParams['limit'];
    $queryParams[':offset'] = $validatedParams['offset'];

    try {
        $dataStmt = $pdo->prepare($dataSql);
        // Bind parameters explicitly for type safety
        foreach ($queryParams as $key => $value) {
             $type = PDO::PARAM_STR; // Default
             if (in_array($key, [':site_id_param', ':dept_id_param', ':grant_id_param', ':budget_id_param', ':user_id_param', ':associated_user_id', ':limit', ':offset'])) {
                 $type = PDO::PARAM_INT;
             }
             $dataStmt->bindValue($key, $value, $type);
         }
        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("API DB Error (Data Allocation Report): " . $e->getMessage());
        throw new RuntimeException("Database error while fetching allocation data.", 500);
    }

    // --- Calculate Pagination ---
    $totalPages = ($totalRecords > 0 && $validatedParams['limit'] > 0) ? ceil($totalRecords / $validatedParams['limit']) : 0;

    // --- Format Response ---
    return [
        'data' => $data,
        'pagination' => [
            'page' => $validatedParams['page'],
            'limit' => $validatedParams['limit'],
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ];
}

?>