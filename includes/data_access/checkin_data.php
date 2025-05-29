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
 * Fetches a list of recent check-ins, including site name and their dynamic answers from checkin_answers.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @param array $active_question_columns (This parameter is no longer used for selecting q_* columns but kept for signature compatibility for now. It can be removed in a future refactor if no longer needed by calling code for other purposes.)
 * @param int $limit Maximum number of check-ins to return.
 * @return array An array of check-in data, each including a 'dynamic_answers' sub-array, or empty array on failure.
 */
function getRecentCheckins(PDO $pdo, $site_filter_id, array $active_question_columns = [], int $limit = 5): array
{
    // --- Step 1: Fetch Recent Core Check-in Data (including site_id for each check_in) ---
    $sql_checkins = "SELECT ci.id, ci.first_name, ci.last_name, ci.check_in_time, ci.site_id, s.name as site_name
            FROM check_ins ci
            JOIN sites s ON ci.site_id = s.id";

    $params_checkins = [];
    // Add the 2-hour time limit condition
    $where_clauses = ["ci.check_in_time >= NOW() - INTERVAL 2 HOUR"];

    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params_checkins[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getRecentCheckins: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return []; // Invalid filter
    }

     if (!empty($where_clauses)) {
        $sql_checkins .= " WHERE " . implode(" AND ", $where_clauses);
    }

    $sql_checkins .= " ORDER BY ci.check_in_time DESC LIMIT :limit";
    $params_checkins[':limit'] = $limit;

    $recent_checkins = [];
    $checkin_ids_map = []; // To map check_in_id to its site_id

    try {
        $stmt_checkins = $pdo->prepare($sql_checkins);
         if (!$stmt_checkins) {
             error_log("ERROR getRecentCheckins (Checkins Query): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }

        $stmt_checkins->bindParam(':limit', $params_checkins[':limit'], PDO::PARAM_INT);
        if (isset($params_checkins[':site_id_filter'])) {
             $stmt_checkins->bindParam(':site_id_filter', $params_checkins[':site_id_filter'], PDO::PARAM_INT);
        }

        $stmt_checkins->execute();
        $fetched_checkins = $stmt_checkins->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($fetched_checkins)) {
            return [];
        }

        foreach ($fetched_checkins as $ci) {
            $recent_checkins[$ci['id']] = $ci; // Store by check_in_id for easier update
            $checkin_ids_map[$ci['id']] = $ci['site_id'];
        }

    } catch (PDOException $e) {
        error_log("EXCEPTION in getRecentCheckins (Checkins Query): " . $e->getMessage());
        return [];
    }

    $checkin_ids = array_keys($recent_checkins);

    // --- Step 2: Fetch Dynamic Answers for the Recent Check-in IDs ---
    $answers_by_checkin_id = [];
    if (!empty($checkin_ids)) {
        $placeholders = implode(',', array_fill(0, count($checkin_ids), '?'));
        $sql_answers = "SELECT ca.check_in_id, ca.question_id, gq.question_text, ca.answer AS answer_text
                        FROM checkin_answers ca
                        JOIN global_questions gq ON ca.question_id = gq.id
                        WHERE ca.check_in_id IN ({$placeholders})";
        try {
            $stmt_answers = $pdo->prepare($sql_answers);
            if (!$stmt_answers) {
                error_log("ERROR getRecentCheckins (Answers Query): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            } else {
                $stmt_answers->execute($checkin_ids);
                $all_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($all_answers as $answer_row) {
                    $answers_by_checkin_id[$answer_row['check_in_id']][$answer_row['question_id']] = [
                        'question_text' => $answer_row['question_text'],
                        'answer_text' => $answer_row['answer_text']
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log("EXCEPTION in getRecentCheckins (Answers Query): " . $e->getMessage());
        }
    }

    // --- Step 3: Determine Missing Data Status ---
    $site_questions_cache = []; // Cache site questions to avoid redundant DB calls

    foreach ($recent_checkins as $check_in_id => &$checkin_details) { // Use reference
        $current_site_id = $checkin_details['site_id'];
        $checkin_details['missing_data_status'] = 'complete'; // Assume complete initially

        // Fetch site-specific questions if not already cached
        if (!isset($site_questions_cache[$current_site_id])) {
            $sql_site_questions = "SELECT sq.global_question_id FROM site_questions sq WHERE sq.site_id = :site_id";
            try {
                $stmt_site_q = $pdo->prepare($sql_site_questions);
                $stmt_site_q->bindParam(':site_id', $current_site_id, PDO::PARAM_INT);
                $stmt_site_q->execute();
                $site_questions_cache[$current_site_id] = $stmt_site_q->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (PDOException $e) {
                error_log("EXCEPTION in getRecentCheckins (Site Questions Query for site {$current_site_id}): " . $e->getMessage());
                $site_questions_cache[$current_site_id] = []; // Set to empty on error
            }
        }

        $required_question_ids = $site_questions_cache[$current_site_id];
        $answered_question_ids = isset($answers_by_checkin_id[$check_in_id]) ? array_keys($answers_by_checkin_id[$check_in_id]) : [];

        if (empty($required_question_ids)) { // No questions configured for the site
             $checkin_details['missing_data_status'] = 'complete'; // Or 'not_applicable' if preferred
        } else {
            foreach ($required_question_ids as $req_q_id) {
                if (!in_array($req_q_id, $answered_question_ids)) {
                    $checkin_details['missing_data_status'] = 'missing';
                    break; // Found a missing answer, no need to check further for this check-in
                }
            }
        }
        // Populate dynamic_answers for display purposes (as before, but using the structured answers)
        $display_answers = [];
        if(isset($answers_by_checkin_id[$check_in_id])) {
            foreach($answers_by_checkin_id[$check_in_id] as $q_id => $answer_data) {
                $display_answers[] = [
                    'question_text' => $answer_data['question_text'],
                    'answer_text' => $answer_data['answer_text']
                ];
            }
        }
        $checkin_details['dynamic_answers'] = $display_answers;
    }
    unset($checkin_details); // Unset reference

    return array_values($recent_checkins); // Return as a simple array
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
    // Corrected SQL query structure (Attempt 2)
    // Corrected SQL query structure (Attempt 4 - Join users table)
    // Note: ci.additional_data was temporarily removed from the SELECT list below (after ci.client_id)
    // due to a runtime "Unknown column" error. The Living Plan and Schema file indicate this column should exist.
    // This removal is to stop the immediate error. The discrepancy with the live DB schema needs investigation.
    $sql_checkins = "SELECT
                ci.id, ci.first_name, ci.last_name, ci.check_in_time, ci.client_email, ci.client_id,
                -- ci.q_unemployment_assistance, ci.q_age, ci.q_veteran, ci.q_school, ci.q_employment_layoff, -- Deprecated q_* columns
                -- ci.q_unemployment_claim, ci.q_employment_services, ci.q_equus, ci.q_seasonal_farmworker, -- Deprecated q_* columns
                s.name AS site_name,
                u.full_name AS notified_staff_name
            FROM
                check_ins ci
            LEFT JOIN
                sites s ON ci.site_id = s.id
            LEFT JOIN
                users u ON ci.notified_staff_id = u.id";

    // Build WHERE clause and params for check-ins
    $params_checkins = [];
    $where_clauses = [];

    // Site Filter
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params_checkins[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
        error_log("getCheckinsByFiltersPaginated: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
        return []; // Invalid filter
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

        // Bind parameters with explicit types
        if (isset($params_checkins[':site_id_filter'])) {
            $stmt_checkins->bindParam(':site_id_filter', $params_checkins[':site_id_filter'], PDO::PARAM_INT);
        }
        if (isset($params_checkins[':start_date'])) {
            $stmt_checkins->bindParam(':start_date', $params_checkins[':start_date'], PDO::PARAM_STR);
        }
        if (isset($params_checkins[':end_date'])) {
            $stmt_checkins->bindParam(':end_date', $params_checkins[':end_date'], PDO::PARAM_STR);
        }
        $stmt_checkins->bindParam(':limit', $params_checkins[':limit'], PDO::PARAM_INT);
        $stmt_checkins->bindParam(':offset', $params_checkins[':offset'], PDO::PARAM_INT);


        $stmt_checkins->execute();
        $fetched_checkins = $stmt_checkins->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($fetched_checkins)) {
            return []; // No check-ins found for this page
        }

        // Store fetched check-ins by ID and collect IDs for answer fetching
        foreach ($fetched_checkins as $ci_row) {
            $paginated_checkins[$ci_row['id']] = $ci_row;
            $checkin_ids[] = $ci_row['id'];
        }

    } catch (PDOException $e) {
        error_log("EXCEPTION in getCheckinsByFiltersPaginated (Checkins Query): " . $e->getMessage());
        return [];
    }

    // --- Step 2: Fetch Dynamic Answers for the Paginated Check-in IDs ---
    $answers_by_checkin_id = [];
    if (!empty($checkin_ids)) {
        $placeholders = implode(',', array_fill(0, count($checkin_ids), '?'));
        $sql_answers = "SELECT ca.check_in_id, gq.question_text, ca.answer AS answer_text
                        FROM checkin_answers ca
                        JOIN global_questions gq ON ca.question_id = gq.id
                        WHERE ca.check_in_id IN ({$placeholders})";
        try {
            $stmt_answers = $pdo->prepare($sql_answers);
            if (!$stmt_answers) {
                error_log("ERROR getCheckinsByFiltersPaginated (Answers Query): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            } else {
                $stmt_answers->execute($checkin_ids);
                $all_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($all_answers as $answer_row) {
                    // Group answers by check_in_id, then by question_text
                    $answers_by_checkin_id[$answer_row['check_in_id']][] = [
                        'question_text' => $answer_row['question_text'],
                        'answer_text' => $answer_row['answer_text']
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log("EXCEPTION in getCheckinsByFiltersPaginated (Answers Query): " . $e->getMessage());
            // Continue, but dynamic answers might be missing for some/all
        }
    }

    // --- Step 3: Combine Paginated Check-ins with their Dynamic Answers ---
    $final_report_data = [];
    foreach ($paginated_checkins as $check_in_id => $checkin_details) {
        $checkin_details['dynamic_answers'] = $answers_by_checkin_id[$check_in_id] ?? []; // Add empty array if no answers
        $final_report_data[] = $checkin_details;
    }

    return $final_report_data;
}


/**
 * Generates custom report data based on selected metrics, grouping, and filters.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $validated_metrics Array of validated metric keys (e.g., 'total_checkins', 'q_veteran_yes_count').
 * @param array $question_metric_columns Array of validated, prefixed question column names for 'YES' counts.
 * @param string $group_by Grouping dimension (e.g., 'day', 'week', 'month', 'site', 'none').
 * @param int|string|null $site_filter_id Site ID, 'all', or null.
 * @param string|null $start_date Start date (YYYY-MM-DD).
 * @param string|null $end_date End date (YYYY-MM-DD).
 * @param string|null &$grouping_column_alias Reference to store the alias of the grouping column.
 * @return array|null Report data array or null on error.
 */
function generateCustomReportData(
    PDO $pdo,
    array $validated_metrics,
    array $question_metric_columns, // These are the q_... column names
    string $group_by,
    $site_filter_id,
    ?string $start_date,
    ?string $end_date,
    ?string &$grouping_column_alias // Pass by reference to get the alias
): ?array {
    $select_parts = [];
    $params = [];
    $where_clauses = [];
    $grouping_column_alias = null; // Initialize

    // --- Date and Site Filters (Common for all metrics) ---
    if (!empty($start_date) && ($start_timestamp = strtotime($start_date)) !== false) {
        $where_clauses[] = "ci.check_in_time >= :start_date_filter";
        $params[':start_date_filter'] = date('Y-m-d 00:00:00', $start_timestamp);
    }
    if (!empty($end_date) && ($end_timestamp = strtotime($end_date)) !== false) {
        $where_clauses[] = "ci.check_in_time <= :end_date_filter";
        $params[':end_date_filter'] = date('Y-m-d 23:59:59', $end_timestamp);
    }

    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
        error_log("generateCustomReportData: Invalid site_filter_id: " . print_r($site_filter_id, true));
        return null;
    }

    // --- Grouping Logic ---
    $group_by_sql = "";
    if ($group_by !== 'none') {
        switch ($group_by) {
            case 'day':
                $grouping_column_alias = 'grouping_period';
                $select_parts[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m-%d') AS {$grouping_column_alias}";
                $group_by_sql = "GROUP BY {$grouping_column_alias} ORDER BY {$grouping_column_alias} ASC";
                break;
            case 'week':
                $grouping_column_alias = 'grouping_period';
                // Ensure week starts on a consistent day, e.g., Sunday (mode 0 for WEEKDAY) or Monday (mode 1 for STR_TO_DATE format '%x%v')
                // Using YEARWEEK might be simpler if week definition is flexible.
                // For ISO 8601 week (Monday start):
                $select_parts[] = "CONCAT(YEAR(ci.check_in_time), '-W', LPAD(WEEK(ci.check_in_time, 1), 2, '0')) AS {$grouping_column_alias}";
                $group_by_sql = "GROUP BY {$grouping_column_alias} ORDER BY {$grouping_column_alias} ASC";
                break;
            case 'month':
                $grouping_column_alias = 'grouping_period';
                $select_parts[] = "DATE_FORMAT(ci.check_in_time, '%Y-%m') AS {$grouping_column_alias}";
                $group_by_sql = "GROUP BY {$grouping_column_alias} ORDER BY {$grouping_column_alias} ASC";
                break;
            case 'site':
                if ($site_filter_id === 'all') { // Only makes sense if viewing all sites
                    $grouping_column_alias = 'site_name';
                    $select_parts[] = "s.name AS {$grouping_column_alias}";
                    $group_by_sql = "GROUP BY {$grouping_column_alias} ORDER BY {$grouping_column_alias} ASC";
                } else {
                    // Grouping by site doesn't make sense if a specific site is already filtered
                    // Or, treat as 'none' if a single site is selected. For now, let's assume 'none' was intended.
                    $group_by = 'none'; // Override
                }
                break;
        }
    }


    // --- Metrics Logic ---
    if (in_array('total_checkins', $validated_metrics)) {
        $select_parts[] = "COUNT(DISTINCT ci.id) AS total_checkins";
    }

    // Add question-based metrics (count of 'YES' answers)
    // $question_metric_columns contains validated 'q_...' names
    foreach ($question_metric_columns as $q_col_name) {
        // $q_col_name is already validated, e.g., 'q_veteran'
        // The alias should be distinct and descriptive
        $metric_alias = $q_col_name . "_yes_count";
        // Summing instances where the answer is 'YES'
        // IMPORTANT: This assumes answers are stored directly in check_ins.q_... columns.
        // If answers are in checkin_answers, this logic needs a JOIN and different aggregation.
        // For now, sticking to the q_* columns as per current reports.php structure for these metrics.
        $select_parts[] = "SUM(CASE WHEN ci.`" . $q_col_name . "` = 'YES' THEN 1 ELSE 0 END) AS `" . $metric_alias . "`";
    }


    if (empty($select_parts)) {
        error_log("generateCustomReportData: No valid metrics selected.");
        return null;
    }

    // --- Build Final SQL ---
    $sql = "SELECT " . implode(", ", $select_parts) . "
            FROM check_ins ci
            LEFT JOIN sites s ON ci.site_id = s.id"; // Join sites for 'group by site'

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $sql .= " " . $group_by_sql;

    // error_log("Custom Report SQL: " . $sql);
    // error_log("Custom Report Params: " . print_r($params, true));


    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR generateCustomReportData: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in generateCustomReportData: " . $e->getMessage() . " | SQL: " . $sql);
        return null;
    }
}


/**
 * Saves a new check-in record to the database.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $checkin_data Associative array of check-in data. Expected keys:
 *        'site_id', 'first_name', 'last_name', 'client_email', 'notified_staff_id',
 *        'client_id' (optional), 'additional_data' (optional, JSON string),
 *        and dynamic question answers like 'q_veteran' => 'YES'/'NO'.
 * @return int|false The ID of the newly inserted check-in record, or false on failure.
 */
function saveCheckin(PDO $pdo, array $checkin_data): int|false
{
    // Separate standard columns from dynamic question columns (q_...)
    $standard_columns = ['site_id', 'first_name', 'last_name', 'client_email', 'notified_staff_id', 'client_id', 'additional_data'];
    $standard_data = [];
    $question_data = []; // These are the q_* columns for check_ins table

    foreach ($checkin_data as $key => $value) {
        if (in_array($key, $standard_columns)) {
            $standard_data[$key] = ($value === '' && $key !== 'client_email' && $key !== 'additional_data') ? null : $value; // Allow empty email/additional_data
        } elseif (strpos($key, 'q_') === 0) {
            // Basic validation for q_ columns for direct insertion into check_ins
            if (preg_match('/^q_[a-z0-9_]+$/', $key) && strlen($key) <= 64) {
                 $question_data[$key] = ($value === '') ? null : $value; // Store q_ data
            } else {
                error_log("saveCheckin: Invalid dynamic question key '{$key}' skipped for check_ins table.");
            }
        }
    }
     // Ensure 'check_in_time' is always set to NOW() by the database or explicitly
    // $standard_data['check_in_time'] = date('Y-m-d H:i:s'); // Or use NOW() in SQL

    // Combine standard and question data for the main check_ins insert
    $insert_data = array_merge($standard_data, $question_data);


    // Build SQL for check_ins table
    $columns = implode(", ", array_map(function($col) { return "`" . $col . "`"; }, array_keys($insert_data)));
    $placeholders = ":" . implode(", :", array_keys($insert_data));
    $sql = "INSERT INTO check_ins ({$columns}, `check_in_time`) VALUES ({$placeholders}, NOW())";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR saveCheckin (Prepare): " . implode(" | ", $pdo->errorInfo()));
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        // Bind values
        foreach ($insert_data as $key => $value) {
            $param_type = PDO::PARAM_STR;
            if ($key === 'site_id' || $key === 'notified_staff_id' || $key === 'client_id') {
                $param_type = ($value === null) ? PDO::PARAM_NULL : PDO::PARAM_INT;
            }
            $stmt->bindValue(":" . $key, $value, $param_type);
        }

        if ($stmt->execute()) {
            $new_check_in_id = (int)$pdo->lastInsertId();

            // Save dynamic question answers from 'answers_for_separate_table'
            if (isset($checkin_data['answers_for_separate_table']) && is_array($checkin_data['answers_for_separate_table']) && !empty($checkin_data['answers_for_separate_table'])) {
                $answers_for_separate_table = $checkin_data['answers_for_separate_table'];
                if (!saveCheckinAnswers($pdo, $new_check_in_id, $answers_for_separate_table)) {
                    $pdo->rollBack();
                    error_log("saveCheckin: Failed to save dynamic answers from 'answers_for_separate_table' for check-in ID {$new_check_in_id}. Transaction rolled back.");
                    return false;
                }
            }

            $pdo->commit();
            return $new_check_in_id;
        } else {
            error_log("ERROR saveCheckin (Execute): " . implode(" | ", $stmt->errorInfo()) . " SQL: " . $sql . " Data: " . print_r($insert_data, true));
            $pdo->rollBack(); // Rollback on execute failure
            return false;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("EXCEPTION in saveCheckin: " . $e->getMessage() . " Data: " . print_r($insert_data,true));
        return false;
    }
}

/**
 * Fetches check-in data for export, including dynamic answers from checkin_answers.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|null $site_filter_id The specific site ID, or null for all sites.
 * @param string $start_date Start date string (YYYY-MM-DD HH:MM:SS).
 * @param string $end_date End date string (YYYY-MM-DD HH:MM:SS).
 * @param array $deprecated_active_question_columns This parameter is no longer used for dynamic columns.
 * @return array An associative array with 'data' and 'dynamic_headers' keys, or ['data' => [], 'dynamic_headers' => []] on failure/no data.
 */
function getCheckinDataForExport(PDO $pdo, ?int $site_filter_id, string $start_date, string $end_date, array $deprecated_active_question_columns = []): array
{
    $base_sql = "SELECT
                    ci.id AS CheckinID,
                    s.name AS SiteName,
                    ci.first_name AS FirstName,
                    ci.last_name AS LastName,
                    ci.check_in_time AS CheckinTime,
                    u.full_name AS NotifiedStaff,
                    ci.client_email AS ClientEmail
                FROM check_ins ci
                LEFT JOIN sites s ON ci.site_id = s.id
                LEFT JOIN users u ON ci.notified_staff_id = u.id";

    $params = [];
    $where_clauses = [];

    if ($site_filter_id !== null) {
        $where_clauses[] = "ci.site_id = :site_id";
        $params[':site_id'] = $site_filter_id;
    }
    // Dates are expected to be full datetime strings already
    if (!empty($start_date)) {
        $where_clauses[] = "ci.check_in_time >= :start_date";
        $params[':start_date'] = $start_date;
    }
    if (!empty($end_date)) {
        $where_clauses[] = "ci.check_in_time <= :end_date";
        $params[':end_date'] = $end_date;
    }

    if (!empty($where_clauses)) {
        $base_sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $base_sql .= " ORDER BY ci.check_in_time ASC, ci.id ASC";

    $checkins_data = [];
    $checkin_ids = [];

    try {
        $stmt_checkins = $pdo->prepare($base_sql);
        if (!$stmt_checkins) {
            error_log("ERROR getCheckinDataForExport (Base Checkins): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return ['data' => [], 'dynamic_headers' => []];
        }
        $stmt_checkins->execute($params);
        $fetched_checkins = $stmt_checkins->fetchAll(PDO::FETCH_ASSOC);

        if (empty($fetched_checkins)) {
            return ['data' => [], 'dynamic_headers' => []];
        }

        // Initialize with base data and collect IDs
        foreach ($fetched_checkins as $row) {
            $checkins_data[$row['CheckinID']] = $row; // Store by CheckinID for easy merge
            $checkin_ids[] = $row['CheckinID'];
        }
    } catch (PDOException $e) {
        error_log("EXCEPTION in getCheckinDataForExport (Base Checkins): " . $e->getMessage());
        return ['data' => [], 'dynamic_headers' => []];
    }

    // Step 2: Fetch all dynamic answers for these check-ins and determine dynamic headers
    $dynamic_answers_map = []; // Stores [checkin_id][question_text] => answer
    $dynamic_headers_set = [];   // Stores unique question_text for headers

    if (!empty($checkin_ids)) {
        $placeholders = implode(',', array_fill(0, count($checkin_ids), '?'));
        $answers_sql = "SELECT
                            ca.check_in_id,
                            gq.question_text,
                            ca.answer
                        FROM checkin_answers ca
                        JOIN global_questions gq ON ca.question_id = gq.id
                        WHERE ca.check_in_id IN ({$placeholders})
                        ORDER BY gq.question_text ASC"; // Order by question_text for consistent header order later

        try {
            $stmt_answers = $pdo->prepare($answers_sql);
            if (!$stmt_answers) {
                error_log("ERROR getCheckinDataForExport (Answers): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                // Continue without dynamic answers if this part fails, base data is still useful
            } else {
                $stmt_answers->execute($checkin_ids);
                $fetched_answers = $stmt_answers->fetchAll(PDO::FETCH_ASSOC);

                foreach ($fetched_answers as $answer_row) {
                    $dynamic_answers_map[$answer_row['check_in_id']][$answer_row['question_text']] = $answer_row['answer'];
                    $dynamic_headers_set[$answer_row['question_text']] = true; // Use question_text as key to ensure uniqueness
                }
            }
        } catch (PDOException $e) {
            error_log("EXCEPTION in getCheckinDataForExport (Answers): " . $e->getMessage());
            // Continue without dynamic answers
        }
    }

    $final_dynamic_headers = array_keys($dynamic_headers_set);
    sort($final_dynamic_headers); // Ensure consistent column order in CSV

    // Step 3: Combine base data with dynamic answers
    $export_final_data = [];
    foreach ($checkins_data as $checkin_id => $base_data_row) {
        $csv_row = $base_data_row; // Start with base data (CheckinID, SiteName, etc.)
        
        // Add dynamic answers in the order of $final_dynamic_headers
        foreach ($final_dynamic_headers as $header_text) {
            // The key for the CSV row should be the question_text itself
            $csv_row[$header_text] = $dynamic_answers_map[$checkin_id][$header_text] ?? ''; // Default to empty string if no answer
        }
        $export_final_data[] = $csv_row;
    }

    return ['data' => $export_final_data, 'dynamic_headers' => $final_dynamic_headers];
}


/**
 * Fetches aggregated question response counts for a given site and time frame.
 * This function now correctly queries the `checkin_answers` table.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID, 'all', or null.
 * @param string $time_frame A string indicating the time frame (e.g., 'today', 'last7days', 'last30days').
 * @return array|null An array of question response counts or null on error.
 *                    Example: ['Are you a veteran?' => ['YES' => 10, 'NO' => 5]]
 */
function getAggregatedQuestionResponses(PDO $pdo, $site_filter_id, string $time_frame): ?array
{
    $sql_answers = "SELECT
                        gq.question_text,
                        ca.answer,
                        COUNT(ca.id) as response_count
                    FROM checkin_answers ca
                    JOIN global_questions gq ON ca.question_id = gq.id
                    JOIN check_ins ci ON ca.check_in_id = ci.id";

    $params = [];
    $where_clauses = [];

    // Time Frame Filter
    // error_log("getAggregatedQuestionResponses: Received time_frame = '" . $time_frame . "'"); // Log removed
    switch ($time_frame) {
        case 'today':
            $where_clauses[] = "DATE(ci.check_in_time) = CURDATE()";
            break;
        case 'last_7_days': // Corrected case
        case 'last7days':   // Keep old case for compatibility if used elsewhere
            $where_clauses[] = "ci.check_in_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND ci.check_in_time < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'last_30_days': // Corrected case
        case 'last30days':   // Keep old case for compatibility
            $where_clauses[] = "ci.check_in_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND ci.check_in_time < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'last_365_days': // Added new case
            $where_clauses[] = "ci.check_in_time >= DATE_SUB(CURDATE(), INTERVAL 364 DAY) AND ci.check_in_time < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
            break;
        default:
            error_log("getAggregatedQuestionResponses: Invalid time_frame '{$time_frame}'");
            return null;
    }

    // Site Filter
    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $where_clauses[] = "ci.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
        error_log("getAggregatedQuestionResponses: Invalid site_filter_id: " . print_r($site_filter_id, true));
        return null;
    }

    if (!empty($where_clauses)) {
        $sql_answers .= " WHERE " . implode(" AND ", $where_clauses);
    }

    $sql_answers .= " GROUP BY gq.question_text, ca.answer ORDER BY gq.question_text, ca.answer";

    try {
        $stmt = $pdo->prepare($sql_answers);
        if (!$stmt) {
            error_log("ERROR getAggregatedQuestionResponses: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $aggregated_responses = [];
        foreach ($results as $row) {
            if (!isset($aggregated_responses[$row['question_text']])) {
                $aggregated_responses[$row['question_text']] = [];
            }
            $aggregated_responses[$row['question_text']][$row['answer']] = (int)$row['response_count'];
        }
        return $aggregated_responses;

    } catch (PDOException $e) {
        error_log("EXCEPTION in getAggregatedQuestionResponses: " . $e->getMessage() . " | SQL: " . $sql_answers);
        return null;
    }
}


/**
 * Saves answers to dynamic questions for a specific check-in.
 * This function now correctly inserts into the `checkin_answers` table.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $checkinId The ID of the check-in record.
 * @param array $answers An associative array where keys are question_ids (from global_questions)
 *                       and values are the answers (e.g., 'YES', 'NO', or text).
 * @return bool True on success, false on failure.
 */
function saveCheckinAnswers(PDO $pdo, int $checkinId, array $answers): bool
{
    if (empty($answers)) {
        return true; // No answers to save, consider it a success.
    }

    $sql = "INSERT INTO checkin_answers (check_in_id, question_id, answer, created_at)
            VALUES (:check_in_id, :question_id, :answer, NOW())";

    try {
        $transactionOwner = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionOwner = true;
        }

        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR saveCheckinAnswers (Prepare): " . implode(" | ", $pdo->errorInfo()));
            if ($transactionOwner) {
                $pdo->rollBack();
            }
            return false;
        }

        foreach ($answers as $question_id => $answer_text) {
            // Ensure question_id is numeric and answer_text is not overly long
            if (!is_numeric($question_id) || (is_string($answer_text) && strlen($answer_text) > 255)) {
                 error_log("saveCheckinAnswers: Invalid question_id ('{$question_id}') or answer length for checkin '{$checkinId}'. Skipping.");
                continue; // Skip this answer
            }
            if ($answer_text === '' || $answer_text === null) { // Do not save empty or null answers
                // error_log("saveCheckinAnswers: Empty answer for question_id '{$question_id}' for checkin '{$checkinId}'. Skipping.");
                continue;
            }


            $params = [
                ':check_in_id' => $checkinId,
                ':question_id' => (int)$question_id,
                ':answer' => $answer_text
            ];

            if (!$stmt->execute($params)) {
                error_log("ERROR saveCheckinAnswers (Execute for question_id {$question_id}): " . implode(" | ", $stmt->errorInfo()));
                if ($transactionOwner) {
                    $pdo->rollBack();
                }
                return false;
            }
        }

        if ($transactionOwner) {
            $pdo->commit();
        }
        return true;

    } catch (PDOException $e) {
        error_log("EXCEPTION in saveCheckinAnswers for checkinId {$checkinId}: " . $e->getMessage() . " Answers: " . print_r($answers, true));
        // Check inTransaction() first because the transaction might have been rolled back by the caller
        // if $transactionOwner is false.
        if ($transactionOwner && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}
// Ensure there's a final newline if it's the end of the file, or ensure it integrates correctly if not.
?>