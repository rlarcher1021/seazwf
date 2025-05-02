<?php
// includes/data_access/checkin_data.php

// This file will contain functions related to the 'check_ins' table
// and potentially related tables like check_in_answers if applicable.

/**
 * Gets the total count of check-ins for today, optionally filtered by site.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @return int The count of check-ins, or 0 on failure.
 */
function getTodaysCheckinCount(PDO $pdo, $site_filter_id): int
{
    // Get the start and end of today in the application's timezone
    $today_start = date('Y-m-d 00:00:00');
    $tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));

    $sql = "SELECT COUNT(ci.id) FROM check_ins ci";
    $params = [
        ':today_start' => $today_start,
        ':tomorrow_start' => $tomorrow_start
    ];
    $where_clauses = ["ci.check_in_time >= :today_start AND ci.check_in_time < :tomorrow_start"];

    // Site filtering logic remains the same
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getTodaysCheckinCount: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return 0; // Invalid filter
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getTodaysCheckinCount: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return 0;
        }
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getTodaysCheckinCount: " . $e->getMessage());
        return 0;
    }
}

/**
 * Gets the count of check-ins within the last hour, optionally filtered by site.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @return int The count of check-ins, or 0 on failure.
 */
function getLastHourCheckinCount(PDO $pdo, $site_filter_id): int
{
    $sql = "SELECT COUNT(ci.id) FROM check_ins ci";
    $params = [':one_hour_ago' => date('Y-m-d H:i:s', strtotime('-1 hour'))];
    $where_clauses = ["ci.check_in_time >= :one_hour_ago"];

    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getLastHourCheckinCount: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return 0; // Invalid filter
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR getLastHourCheckinCount: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return 0;

        } // Closing brace for the if (!$stmt) check
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getLastHourCheckinCount: " . $e->getMessage());
        return 0;
    }
}

/**
 * Gets the count of today's check-ins where a specific question column is 'YES', optionally filtered by site.
 * IMPORTANT: The $question_column_name MUST be validated before calling this function to prevent SQL injection.
 * It should match the pattern 'q_[a-z0-9_]+'.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @param string $question_column_name The validated, prefixed question column name (e.g., 'q_needs_assistance').
 * @return int The count of check-ins, or 0 on failure or if the column name is invalid.
 */
function getTodaysCheckinCountByQuestion(PDO $pdo, $site_filter_id, string $question_column_name): int
{
    // **Crucial Validation**
    if (!preg_match('/^q_[a-z0-9_]+$/', $question_column_name) || strlen($question_column_name) > 64) {
        error_log("ERROR getTodaysCheckinCountByQuestion: Invalid question column name provided: '{$question_column_name}'");
        return 0;
    }

    // Build query safely using the validated column name in backticks
    $sql = "SELECT COUNT(ci.id) FROM check_ins ci";
    $params = [];
    $where_clauses = [
        "DATE(ci.check_in_time) = CURDATE()",
        "`" . $question_column_name . "` = 'YES'" // Safely include validated column name
    ];

    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getTodaysCheckinCountByQuestion: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return 0; // Invalid filter
    }

    $sql .= " WHERE " . implode(" AND ", $where_clauses);

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR getTodaysCheckinCountByQuestion: Prepare failed for column '{$question_column_name}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return 0;
        }
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        // Catch potential errors if the column doesn't exist despite validation (shouldn't happen with prior checks)
        error_log("EXCEPTION in getTodaysCheckinCountByQuestion for column '{$question_column_name}': " . $e->getMessage());

    } // Closing brace for catch block
    return 0; // Fallback return to satisfy static analysis

} // Closing brace for getTodaysCheckinCountByQuestion function



/**
 * Gets the total count of check-ins for the last 7 days (including today),
 * optionally filtered by site.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @return int The count of check-ins, or 0 on failure.
 */
function getLastSevenDaysCheckinCount(PDO $pdo, $site_filter_id): int
{
    // Get the start of 6 days ago and the end of today (start of tomorrow)
    $seven_days_ago_start = date('Y-m-d 00:00:00', strtotime('-6 days')); // Includes today
    $tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));

    $sql = "SELECT COUNT(ci.id) FROM check_ins ci";
    $params = [
        ':start_date' => $seven_days_ago_start,
        ':end_date' => $tomorrow_start
    ];
    $where_clauses = ["ci.check_in_time >= :start_date AND ci.check_in_time < :end_date"];

    // Site filtering logic
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getLastSevenDaysCheckinCount: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return 0; // Invalid filter
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getLastSevenDaysCheckinCount: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return 0;
        }
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getLastSevenDaysCheckinCount: " . $e->getMessage());
        return 0;
    }
}


/**
 * Gets the daily check-in counts for the last 7 days (including today),
 * optionally filtered by site.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @return array An associative array mapping date ('Y-m-d') to check-in count, or empty array on failure.
 */
function getDailyCheckinCountsLast7Days(PDO $pdo, $site_filter_id): array
{
    // Get the start of 6 days ago and the end of today (start of tomorrow)
    $start_date = date('Y-m-d 00:00:00', strtotime('-6 days')); // Includes today
    $end_date = date('Y-m-d 00:00:00', strtotime('+1 day'));

    $sql = "SELECT DATE(ci.check_in_time) as checkin_date, COUNT(ci.id) as daily_count
            FROM check_ins ci";

    $params = [
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];
    $where_clauses = ["ci.check_in_time >= :start_date AND ci.check_in_time < :end_date"];

    // Site filtering logic
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getDailyCheckinCountsLast7Days: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return []; // Invalid filter
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    $sql .= " GROUP BY checkin_date ORDER BY checkin_date ASC";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getDailyCheckinCountsLast7Days: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert results to 'Y-m-d' => count format
        $daily_counts = [];
        foreach ($results as $row) {
            $daily_counts[$row['checkin_date']] = (int)$row['daily_count'];
        }
        return $daily_counts;

    } catch (PDOException $e) {
        error_log("EXCEPTION in getDailyCheckinCountsLast7Days: " . $e->getMessage());
        return [];
    }
}


// Removed stray return and closing braces that were causing syntax errors

/**
 * Fetches a list of recent check-ins, including site name and dynamic question columns.
 * IMPORTANT: $active_question_columns MUST contain validated, prefixed column names.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @param array $active_question_columns Array of validated, prefixed question column names (e.g., ['q_needs_assistance', 'q_resume_help']).
 * @param int $limit Maximum number of check-ins to return.
 * @return array An array of check-in data, or empty array on failure.
 */
function getRecentCheckins(PDO $pdo, $site_filter_id, array $active_question_columns, int $limit = 5): array
{
    $dynamic_select_sql = "";
    if (!empty($active_question_columns)) {
        $safe_dynamic_cols = [];
        foreach ($active_question_columns as $col) {
            // **Crucial Validation** (redundant if validated before call, but safer)
            if (preg_match('/^q_[a-z0-9_]+$/', $col) && strlen($col) <= 64) {
                $safe_dynamic_cols[] = "`" . $col . "`"; // Add backticks
            } else {
                 error_log("WARNING getRecentCheckins: Skipping invalid column name in dynamic select: '{$col}'");
            }
        }
        if (!empty($safe_dynamic_cols)) {
            $dynamic_select_sql = ", " . implode(", ", $safe_dynamic_cols);
        }
    }

    $sql = "SELECT ci.id, ci.first_name, ci.last_name, ci.check_in_time, s.name as site_name" . $dynamic_select_sql . "
            FROM check_ins ci
            JOIN sites s ON ci.site_id = s.id";

    $params = [];
    $where_clauses = [];

    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getRecentCheckins: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return []; // Invalid filter
    }

     if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    $sql .= " ORDER BY ci.check_in_time DESC LIMIT :limit";
    $params[':limit'] = $limit;

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR getRecentCheckins: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }

        // Bind limit as integer
        $stmt->bindParam(':limit', $params[':limit'], PDO::PARAM_INT);
        // Bind site filter if applicable
        if (isset($params[':site_id_filter'])) {
             $stmt->bindParam(':site_id_filter', $params[':site_id_filter'], PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getRecentCheckins: " . $e->getMessage());
        return [];
    }
}


/**
 * Gets the total count of check-ins based on site and date filters.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @param string|null $start_date Start date string (YYYY-MM-DD) or null.
 * @param string|null $end_date End date string (YYYY-MM-DD) or null.
 * @return int The total count of matching records, or 0 on failure.
 */
function getCheckinCountByFilters(PDO $pdo, $site_filter_id, ?string $start_date, ?string $end_date): int
{
    $sql = "SELECT COUNT(ci.id) FROM check_ins ci";
    $params = [];
    $where_clauses = [];

    // Site Filter
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
        error_log("getCheckinCountByFilters: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
        return 0;
    }

    // Date Filters
    if (!empty($start_date) && ($start_timestamp = strtotime($start_date)) !== false) {
        $where_clauses[] = "ci.check_in_time >= :start_date";
        $params[':start_date'] = date('Y-m-d 00:00:00', $start_timestamp);
    }
    if (!empty($end_date) && ($end_timestamp = strtotime($end_date)) !== false) {
        $where_clauses[] = "ci.check_in_time <= :end_date";
        $params[':end_date'] = date('Y-m-d 23:59:59', $end_timestamp);
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getCheckinCountByFilters: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return 0;
        }
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getCheckinCountByFilters: " . $e->getMessage());
        return 0;
    }
}


/**
 * Fetches paginated check-in data based on site and date filters, including associated dynamic question answers.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @param string|null $start_date Start date string (YYYY-MM-DD) or null.
 * @param string|null $end_date End date string (YYYY-MM-DD) or null.
 * @param int $limit Number of records per page.
 * @param int $offset Starting record offset.
 * @return array An array of check-in data rows, each including a 'dynamic_answers' sub-array, or empty array on failure.
 */
function getCheckinsByFiltersPaginated(PDO $pdo, $site_filter_id, ?string $start_date, ?string $end_date, int $limit, int $offset): array
{
    // --- Step 1: Fetch Paginated Core Check-in Data ---
    $sql_checkins = "SELECT
                        ci.id, ci.first_name, ci.last_name, ci.check_in_time, ci.client_email,
                        s.name as site_name, sn.staff_name as notified_staff
                    FROM
                        check_ins ci
                    JOIN
                        sites s ON ci.site_id = s.id
                    LEFT JOIN
                        staff_notifications sn ON ci.notified_staff_id = sn.id"; // Assuming staff_notifications table exists

    // Build WHERE clause and params for check-ins
    $params_checkins = [];
    $where_clauses = [];

    // Site Filter
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params_checkins[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
        error_log("getCheckinsByFiltersPaginated: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
        return [];
    }

    // Date Filters
    if (!empty($start_date) && ($start_timestamp = strtotime($start_date)) !== false) {
        $where_clauses[] = "ci.check_in_time >= :start_date";
        $params_checkins[':start_date'] = date('Y-m-d 00:00:00', $start_timestamp);
    }
    if (!empty($end_date) && ($end_timestamp = strtotime($end_date)) !== false) {
        $where_clauses[] = "ci.check_in_time <= :end_date";
        $params_checkins[':end_date'] = date('Y-m-d 23:59:59', $end_timestamp);
    }

    if (!empty($where_clauses)) {
        $sql_checkins .= " WHERE " . implode(" AND ", $where_clauses);
    }

    // Add ORDER BY and LIMIT/OFFSET for check-ins
    $sql_checkins .= " ORDER BY ci.check_in_time DESC LIMIT :limit OFFSET :offset";
    $params_checkins[':limit'] = $limit;
    $params_checkins[':offset'] = $offset;

    $paginated_checkins = [];
    $checkin_ids = [];

    try {
        $stmt_checkins = $pdo->prepare($sql_checkins);
        if (!$stmt_checkins) {
            error_log("ERROR getCheckinsByFiltersPaginated (Checkins Query): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        // Bind parameters for check-ins query
        foreach ($params_checkins as $key => &$val) {
             $type = PDO::PARAM_STR;
             if ($key === ':site_id_filter' || $key === ':limit' || $key === ':offset') {
                 $type = PDO::PARAM_INT;
             }
             $stmt_checkins->bindValue($key, $val, $type);
        }
        unset($val);

        $stmt_checkins->execute();
        $paginated_checkins = $stmt_checkins->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($paginated_checkins)) {
            return []; // No check-ins found for this page
        }

        // Extract IDs for the next query
        $checkin_ids = array_column($paginated_checkins, 'id');

    } catch (PDOException $e) {
        error_log("EXCEPTION in getCheckinsByFiltersPaginated (Checkins Query): " . $e->getMessage() . " | SQL: " . $sql_checkins);
        return [];
    }

    // --- Step 2: Fetch Answers for the Paginated Check-in IDs ---
    $answers_by_checkin_id = [];
    if (!empty($checkin_ids)) {
        $placeholders = implode(',', array_fill(0, count($checkin_ids), '?'));
        $sql_answers = "SELECT ca.check_in_id, gq.question_title, ca.answer
                        FROM checkin_answers ca
                        JOIN global_questions gq ON ca.question_id = gq.id
                        WHERE ca.check_in_id IN ({$placeholders})";

        try {
            $stmt_answers = $pdo->prepare($sql_answers);
            if (!$stmt_answers) {
                error_log("ERROR getCheckinsByFiltersPaginated (Answers Query): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                // Continue without answers, but log the error
            } else {
                $stmt_answers->execute($checkin_ids);
                $answer_results = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                // Group answers by check_in_id
                foreach ($answer_results as $answer_row) {
                    $cid = $answer_row['check_in_id'];
                    if (!isset($answers_by_checkin_id[$cid])) {
                        $answers_by_checkin_id[$cid] = [];
                    }
                    // Use question_title as the key for the answer
                    $answers_by_checkin_id[$cid][$answer_row['question_title']] = $answer_row['answer'];
                }
            }
        } catch (PDOException $e) {
            error_log("EXCEPTION in getCheckinsByFiltersPaginated (Answers Query): " . $e->getMessage() . " | SQL: " . $sql_answers);
            // Continue without answers if this query fails
        }
    }

    // --- Step 3: Combine Check-in Data with Answers ---
    $final_results = [];
    foreach ($paginated_checkins as $checkin) {
        // Add the dynamic answers array (empty if no answers found or query failed)
        $checkin['dynamic_answers'] = $answers_by_checkin_id[$checkin['id']] ?? [];
        $final_results[] = $checkin;
    }

    return $final_results;
}


/**
 * Generates aggregated data for the custom report builder based on selected metrics and grouping.
 * IMPORTANT: $validated_metrics and $question_metric_columns must contain validated column names.
 *
 * @param PDO $pdo PDO connection object.
 * @param array $validated_metrics Array of metric names (e.g., 'total_checkins', 'q_needs_assistance').
 * @param array $question_metric_columns Array of only the validated 'q_...' metric names.
 * @param string $group_by Grouping dimension ('none', 'day', 'week', 'month', 'site').
 * @param int|string|null $site_filter_id Site filter ('all', specific ID, or null).
 * @param string|null $start_date Start date filter (YYYY-MM-DD).
 * @param string|null $end_date End date filter (YYYY-MM-DD).
 * @param string &$grouping_column_alias Output parameter to return the alias used for the grouping column.
 * @return array|false Array of aggregated results or false on failure.
 */
function generateCustomReportData(PDO $pdo, array $validated_metrics, array $question_metric_columns, string $group_by, $site_filter_id, ?string $start_date, ?string $end_date, ?string &$grouping_column_alias): ?array
{
    $select_clauses = [];
    $group_by_sql = "";
    $order_by_sql = "";
    $params = [];
    $grouping_column_alias = 'grouping_key'; // Default alias

    // Setup Grouping
    switch ($group_by) {
        case 'day':
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m-%d') AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
            break;
        case 'week':
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%x-%v') AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
            break;
        case 'month':
            $select_clauses[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m') AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY {$grouping_column_alias}";
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
            break;
        case 'site':
            if ($site_filter_id !== 'all') {
                 error_log("generateCustomReportData: Cannot group by site unless 'All Sites' is selected.");
                 return null; // Invalid combination
            }
            $select_clauses[] = "s.name AS {$grouping_column_alias}";
            $group_by_sql = "GROUP BY ci.site_id, s.name"; // Group by ID and Name
            $order_by_sql = "ORDER BY {$grouping_column_alias} ASC";
            break;
        case 'none':
        default:
             $grouping_column_alias = null; // Indicate no grouping column in SELECT
             // No GROUP BY clause needed for overall totals
            break;
    }

    // Setup Metrics in SELECT
    foreach ($validated_metrics as $metric) {
        if ($metric === 'total_checkins') {
            $select_clauses[] = "COUNT(ci.id) AS total_checkins";
        } elseif (in_array($metric, $question_metric_columns)) {
             // Column name already validated before calling this function
             $alias = $metric . '_yes_count';
             $select_clauses[] = "SUM(CASE WHEN ci.`" . $metric . "` = 'YES' THEN 1 ELSE 0 END) AS `" . $alias . "`";
        }
    }
     if (empty($select_clauses)) {
         error_log("generateCustomReportData: No valid SELECT clauses generated.");
         return null;
     }

    // Setup WHERE Clause
    $where_clauses = [];
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id";
        $params[':site_id'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("generateCustomReportData: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return null;
    }
    if (!empty($start_date) && ($start_timestamp = strtotime($start_date)) !== false) {
        $where_clauses[] = "ci.check_in_time >= :start_date";
        $params[':start_date'] = date('Y-m-d 00:00:00', $start_timestamp);
    }
    if (!empty($end_date) && ($end_timestamp = strtotime($end_date)) !== false) {
        $where_clauses[] = "ci.check_in_time <= :end_date";
        $params[':end_date'] = date('Y-m-d 23:59:59', $end_timestamp);
    }
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Final SQL Assembly
    $sql = "SELECT " . implode(', ', $select_clauses) . "
            FROM check_ins ci ";
    // Add JOIN only if grouping by site
    if ($group_by === 'site') {
         $sql .= " JOIN sites s ON ci.site_id = s.id ";
    }
     $sql .= $where_sql . " "
           . $group_by_sql . " "
           . $order_by_sql;

    // Execute Query
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR generateCustomReportData: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }

        // Bind parameters
        foreach ($params as $key => &$val) {
            $type = ($key === ':site_id') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $val, $type);
        }
        unset($val);

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results ?: []; // Return results or empty array

    } catch (PDOException $e) {
        error_log("EXCEPTION in generateCustomReportData: " . $e->getMessage() . " | SQL: " . $sql);
        return null; // Indicate failure
    }
}


/**
 * Saves a new check-in record to the database, including dynamic question answers.
 * Handles the transaction automatically.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $checkin_data Associative array containing check-in details:
 *        'site_id' => int,
 *        'first_name' => string,
 *        'last_name' => string,
 *        'check_in_time' => string (YYYY-MM-DD HH:MM:SS),
 *        'notified_staff_id' => int|null,
 *        'client_email' => string|null,
 *        'question_answers' => array ['q_column_name' => 'YES'|'NO', ...]
 * @return int|false The new check-in ID on success, false on failure.
 */
function saveCheckin(PDO $pdo, array $checkin_data): int|false
{
    // Extract base data
    $site_id = $checkin_data['site_id'] ?? null;
    $first_name = $checkin_data['first_name'] ?? null;
    $last_name = $checkin_data['last_name'] ?? null;
    $check_in_time = $checkin_data['check_in_time'] ?? date('Y-m-d H:i:s');
    $notified_staff_id = $checkin_data['notified_staff_id'] ?? null;
    $client_email = $checkin_data['client_email'] ?? null;
    $question_answers = $checkin_data['question_answers'] ?? [];

    // Basic validation of required fields
    if (empty($site_id) || empty($first_name) || empty($last_name)) {
        error_log("ERROR saveCheckin: Missing required fields (site_id, first_name, last_name).");
        return false;
    }

    // --- Dynamically build INSERT query ---
    $columns = ['site_id', 'first_name', 'last_name', 'check_in_time', 'notified_staff_id', 'client_email'];
    $placeholders = [ ':site_id', ':first_name', ':last_name', ':check_in_time', ':notified_staff_id', ':client_email'];
    $values = [
        ':site_id' => $site_id,
        ':first_name' => $first_name,
        ':last_name' => $last_name,
        ':check_in_time' => $check_in_time,
        ':notified_staff_id' => $notified_staff_id ?: null, // Ensure NULL if empty/0
        ':client_email' => $client_email ?: null // Ensure NULL if empty
    ];

    // Add dynamic question columns and placeholders
    foreach ($question_answers as $column_name => $answer) {
        // **Crucial Validation** for column names before including in SQL
        if (!empty($column_name) && preg_match('/^q_[a-z0-9_]+$/', $column_name) && strlen($column_name) <= 64) {
            // Validate answer value
            if ($answer === 'YES' || $answer === 'NO') {
                $columns[] = "`" . $column_name . "`"; // Backticks for safety
                $placeholder_name = ':' . $column_name; // Create placeholder like :q_needs_help
                $placeholders[] = $placeholder_name;
                $values[$placeholder_name] = $answer;
            } else {
                 error_log("WARNING saveCheckin: Invalid answer value '{$answer}' for column '{$column_name}'. Skipping column.");
                 // Optionally handle this differently, e.g., set to NULL or return false
            }
        } else {
            error_log("WARNING saveCheckin: Skipping potentially invalid dynamic column name '{$column_name}' during INSERT build.");
        }
    }

    $sql_insert = "INSERT INTO check_ins (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $pdo->beginTransaction(); // Start transaction

        $stmt_insert = $pdo->prepare($sql_insert);
        if (!$stmt_insert) {
             error_log("ERROR saveCheckin: Prepare failed. SQL: {$sql_insert}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             $pdo->rollBack();
             return false;
        }

        // Execute INSERT
        $insert_success = $stmt_insert->execute($values);

        if ($insert_success) {
             $check_in_id = $pdo->lastInsertId();
             $pdo->commit(); // Commit transaction
             return (int)$check_in_id;
        } else {
             $errorInfo = $stmt_insert->errorInfo();
             error_log("ERROR saveCheckin: Execute failed. SQLSTATE[{$errorInfo[0]}] Driver Error[{$errorInfo[1]}]: {$errorInfo[2]} --- Query: {$sql_insert} --- Values: " . print_r($values, true));
             $pdo->rollBack(); // Rollback transaction
             return false;
        }
    } catch (PDOException $e) {
         if ($pdo->inTransaction()) {
             $pdo->rollBack(); // Ensure rollback on exception
         }
         error_log("EXCEPTION in saveCheckin: " . $e->getMessage() . " --- SQL: {$sql_insert} --- Values: " . print_r($values, true));
         return false;
    }
}



/**
 * Fetches check-in data for CSV export based on site and date filters.
 * Returns all matching records without pagination.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|null $site_filter_id The specific site ID, or null for all sites.
 * @param string $start_date Start date string (YYYY-MM-DD HH:MM:SS).
 * @param string $end_date End date string (YYYY-MM-DD HH:MM:SS).
 * @param array $active_question_columns Array of validated, prefixed question column names (e.g., ['q_needs_assistance']).
 * @return array|false An array of check-in data rows, or false on failure.
 */
function getCheckinDataForExport(PDO $pdo, ?int $site_filter_id, string $start_date, string $end_date, array $active_question_columns): array|false
{
    // Build dynamic SELECT part safely
    $dynamic_select_sql = "";
    if (!empty($active_question_columns)) {
        $safe_dynamic_cols = [];
        foreach ($active_question_columns as $col) {
            // Validation should happen before calling, but double-check format
            if (preg_match('/^q_[a-z0-9_]+$/', $col) && strlen($col) <= 64) {
                // Use the prefixed name directly as the alias for simplicity in export script
                $safe_dynamic_cols[] = "`ci`.`" . $col . "` AS `" . $col . "`";
            } else {
                error_log("WARNING getCheckinDataForExport: Skipping invalid column name in dynamic select: '{$col}'");
            }
        }
        if (!empty($safe_dynamic_cols)) {
            $dynamic_select_sql = ", " . implode(", ", $safe_dynamic_cols);
        }
    }

    // Base SQL - Select necessary columns for export
    $sql = "SELECT
                ci.id as CheckinID,
                s.name as SiteName,
                ci.first_name as FirstName,
                ci.last_name as LastName,
                ci.check_in_time as CheckinTime,
                ci.client_email as ClientEmail, -- Added client email here
                sn.staff_name as NotifiedStaff
                {$dynamic_select_sql}
            FROM check_ins ci
            JOIN sites s ON ci.site_id = s.id
            LEFT JOIN staff_notifications sn ON ci.notified_staff_id = sn.id";

    // Build WHERE clause and params
    $params = [];
    $where_clauses = [];

    // Site Filter
    if ($site_filter_id !== null) {
        $where_clauses[] = "ci.site_id = :site_id";
        $params[':site_id'] = $site_filter_id;
    }

    // Date Filters (Assume dates are already validated and formatted with time)
    $where_clauses[] = "ci.check_in_time >= :start_date";
    $params[':start_date'] = $start_date;
    $where_clauses[] = "ci.check_in_time <= :end_date";
    $params[':end_date'] = $end_date;

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    // Add ORDER BY
    $sql .= " ORDER BY ci.check_in_time ASC"; // Order chronologically for export

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getCheckinDataForExport: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }

        // Bind parameters
        foreach ($params as $key => &$val) {
             $type = ($key === ':site_id') ? PDO::PARAM_INT : PDO::PARAM_STR;
             $stmt->bindValue($key, $val, $type);
        }
        unset($val);

        $execute_success = $stmt->execute();
        if (!$execute_success) {
            error_log("ERROR getCheckinDataForExport: Execute failed. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return false;
        }

        // Fetch all matching records
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (PDOException $e) {
        error_log("EXCEPTION in getCheckinDataForExport: " . $e->getMessage() . " | SQL: " . $sql);
        return false;
    }
}


// --- Other Check-in Data Functions ---
// Example function signatures (to be implemented during page refactoring):
// function getCheckinsBySiteAndDateRange(PDO $pdo, int $site_id, string $start_date, string $end_date): array { ... } // Non-paginated version
// function getCheckinById(PDO $pdo, int $checkin_id): ?array { ... }
// function getCheckinAnswers(PDO $pdo, int $checkin_id): array { ... }
// function saveCheckinAnswer(PDO $pdo, int $checkin_id, string $question_column, string $answer): bool { ... } // Likely not needed if saving all at once

/**
 * Saves answers to dynamic questions for a specific check-in.
 * Assumes the checkin_answers table exists with columns: check_in_id, question_id, answer.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $checkinId The ID of the check-in record.
 * @param array $answers An associative array where keys are question_id and values are the answers ('Yes' or 'No').
 * @return bool True on success, false on failure.
 */
function saveCheckinAnswers(PDO $pdo, int $checkinId, array $answers): bool
{
    if (empty($answers)) {
        return true; // Nothing to save
    }

    // Use ON DUPLICATE KEY UPDATE to handle potential re-submissions or edge cases gracefully.
    $sql = "INSERT INTO checkin_answers (check_in_id, question_id, answer) VALUES (:check_in_id, :question_id, :answer)
            ON DUPLICATE KEY UPDATE answer = VALUES(answer)";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR saveCheckinAnswers: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }

        // Use a transaction for atomicity: all answers save or none do.
        $pdo->beginTransaction();

        foreach ($answers as $questionId => $answer) {
            // Basic validation for question ID and answer format
            if (!is_numeric($questionId) || !in_array($answer, ['Yes', 'No'], true)) {
                 error_log("WARNING saveCheckinAnswers: Invalid data skipped for checkin ID {$checkinId}. Question ID: {$questionId}, Answer: {$answer}");
                 continue; // Skip invalid entries, but don't fail the whole batch unless execute fails
            }

            $params = [
                ':check_in_id' => $checkinId,
                ':question_id' => (int)$questionId,
                ':answer' => $answer
            ];

            if (!$stmt->execute($params)) {
                // Log specific error for the failed execution
                error_log("ERROR saveCheckinAnswers: Execute failed for checkin ID {$checkinId}, question ID {$questionId}. PDO Error: " . implode(" | ", $stmt->errorInfo()));
                $pdo->rollBack(); // Rollback the transaction on any failure
                return false;
            }
        }

        // If all executions succeeded, commit the transaction
        $pdo->commit();
        return true;

    } catch (PDOException $e) {
        error_log("EXCEPTION in saveCheckinAnswers for checkin ID {$checkinId}: " . $e->getMessage());
        // Ensure rollback if an exception occurs during the transaction
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

?>