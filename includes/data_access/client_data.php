<?php
// includes/data_access/client_data.php

/**
 * Saves or updates answers for a specific client to the client_answers table.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to handle existing answers.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $clientId The ID of the client whose answers are being saved.
 * @param array $answers An associative array of answers [ question_id => answer ].
 *                      Answer should typically be 'Yes' or 'No'.
 * @return bool True if all answers were processed successfully (or no answers given), false if any insertion/update failed.
 */
function saveClientAnswers(PDO $pdo, int $clientId, array $answers): bool
{
    if (empty($answers)) {
        return true; // Nothing to save, considered success.
    }

    $sql = "INSERT INTO client_answers (client_id, question_id, answer, created_at, updated_at)
            VALUES (:client_id, :question_id, :answer, NOW(), NOW())
            ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = NOW()";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR saveClientAnswers: Prepare failed for client ID {$clientId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }

        $all_successful = true; // Track if all operations succeed

        foreach ($answers as $question_id => $answer) {
            // Basic validation: ensure question_id is an integer and answer is reasonable (e.g., not excessively long)
            if (!filter_var($question_id, FILTER_VALIDATE_INT) || $question_id <= 0) {
                error_log("ERROR saveClientAnswers: Invalid question_id '{$question_id}' for client ID {$clientId}. Skipping.");
                $all_successful = false;
                continue; // Skip this answer
            }
            // Allow empty/null answers? For now, assume 'Yes'/'No' or similar short text. Trim it.
            $trimmed_answer = trim((string)$answer);
             if (strlen($trimmed_answer) > 255) { // Limit answer length reasonably
                 error_log("ERROR saveClientAnswers: Answer too long for question_id '{$question_id}', client ID {$clientId}. Skipping.");
                 $all_successful = false;
                 continue;
             }


            $success = $stmt->execute([
                ':client_id' => $clientId,
                ':question_id' => (int)$question_id,
                ':answer' => $trimmed_answer // Use trimmed answer
            ]);

            if (!$success) {
                error_log("ERROR saveClientAnswers: Execute failed for client ID {$clientId}, question ID {$question_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
                $all_successful = false;
                // Decide whether to continue processing other answers or stop on first failure.
                // For now, let's continue and report overall failure if any occurred.
            }
        }

        return $all_successful;

    } catch (PDOException $e) {
        error_log("EXCEPTION in saveClientAnswers for client ID {$clientId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves all answers for a specific client from the client_answers table.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $clientId The ID of the client whose answers are being retrieved.
 * @return array An associative array of answers [ question_id => answer ]. Returns an empty array if no answers are found or on error.
 */
function getClientAnswers(PDO $pdo, int $clientId): array
{
    $answers = [];
    $sql = "SELECT question_id, answer FROM client_answers WHERE client_id = :client_id";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getClientAnswers: Prepare failed for client ID {$clientId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return []; // Return empty array on prepare failure
        }

        $stmt->execute([':client_id' => $clientId]);

        // Fetch results into an associative array [question_id => answer]
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $answers[$row['question_id']] = $row['answer'];
        }

        return $answers;

    } catch (PDOException $e) {
        error_log("EXCEPTION in getClientAnswers for client ID {$clientId}: " . $e->getMessage());
        return []; // Return empty array on exception
    }
}

/**
 * Searches for clients based on a search term, respecting user roles and site permissions.
 * (Used by Staff UI Client Editor)
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $searchTerm The term to search for in first_name, last_name, or username.
 * @param string $session_role The role of the currently logged-in user.
 * @param ?int $session_site_id The site ID of the currently logged-in user (if applicable).
 * @param bool $is_site_admin Whether the currently logged-in user is a site admin.
 * @return array An array of matching client records (id, username, first_name, last_name, site_id, site_name), or an empty array on error or no results.
 */
function searchClients(PDO $pdo, string $searchTerm, string $session_role, ?int $session_site_id, bool $is_site_admin): array
{
    // Basic permission check - should be redundant if page access is controlled, but good practice.
    if ($session_role !== 'administrator' && $session_role !== 'director' && !$is_site_admin) {
        error_log("WARN searchClients: Unauthorized search attempt by user with role '{$session_role}' and site admin status " . ($is_site_admin ? 'true' : 'false'));
        return [];
    }

    $whereConditions = [];
    $executeParams = [];
    $trimmedSearchTerm = trim($searchTerm);
    $isSearchTermEmpty = empty($trimmedSearchTerm);

    // Determine if site filtering is needed for Site Admins
    $applySiteFilter = ($session_role !== 'administrator' && $session_role !== 'director' && $is_site_admin && $session_site_id !== null && $session_site_id > 0);

    // --- Build WHERE conditions and parameters based on search term ---

    if ($isSearchTermEmpty) {
        // Search term is EMPTY: Only apply site filter if needed
        if ($applySiteFilter) {
            $whereConditions[] = "c.site_id = :session_site_id";
            $executeParams[':session_site_id'] = $session_site_id;
        }
        // No other conditions needed when search term is empty
    } else {
        // Search term is NOT EMPTY: Apply search term filter AND site filter if needed
        $whereConditions[] = "(LOWER(TRIM(c.first_name)) LIKE :searchTerm1 OR LOWER(TRIM(c.last_name)) LIKE :searchTerm2 OR LOWER(TRIM(c.username)) LIKE :searchTerm3)";
        $searchTermValue = '%' . strtolower($trimmedSearchTerm) . '%';
        $executeParams[':searchTerm1'] = $searchTermValue;
        $executeParams[':searchTerm2'] = $searchTermValue;
        $executeParams[':searchTerm3'] = $searchTermValue;

        if ($applySiteFilter) {
            $whereConditions[] = "c.site_id = :session_site_id";
            $executeParams[':session_site_id'] = $session_site_id;
        }
    }

    // --- Construct the SQL query ---
    $sql = "SELECT
                c.id, c.username, c.first_name, c.last_name, c.site_id, s.name AS site_name
            FROM
                clients c
            LEFT JOIN sites s ON c.site_id = s.id";

    // Append WHERE clause if there are conditions
    if (!empty($whereConditions)) {
        $sql .= " WHERE " . implode(' AND ', $whereConditions);
    }

    // Always add ordering
    $sql .= " ORDER BY c.last_name, c.first_name";

    try {
        // Set PDO error mode to exception for better error handling within this function
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            // Log the actual SQL and parameters for debugging
            error_log("ERROR searchClients: Prepare failed. SQL: {$sql}. Params: " . print_r($executeParams, true) . ". PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        // Execute the statement with the dynamically built parameters array
        $stmt->execute($executeParams);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results ?: []; // Return results or empty array if fetchAll returns false/null

    } catch (PDOException $e) {
        error_log("EXCEPTION in searchClients: " . $e->getMessage() . " SQL: " . $sql);
        return [];
    }
}

/**
 * Fetches detailed client information for editing, including profile data and answers to global questions.
 *
 * @param PDO $pdo The database connection object.
 * @param int $clientId The ID of the client to fetch.
 * @return array|null An associative array containing 'profile' and 'answers' keys, or null if client not found or on error.
 *                    'profile': Associative array of client details (id, first_name, last_name, site_id, email_preference_jobs, username, email).
 *                    'answers': Array of associative arrays, each containing (question_id, question_text, question_title, answer).
 */
function getClientDetailsForEditing(PDO $pdo, int $clientId): ?array
{
    $clientData = ['profile' => null, 'answers' => []];

    // 1. Fetch Client Profile Data
    $profileSql = "SELECT id, first_name, last_name, site_id, email_preference_jobs, username, email
                   FROM clients
                   WHERE id = :client_id AND deleted_at IS NULL";
    try {
        $profileStmt = $pdo->prepare($profileSql);
        $profileStmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);
        $profileStmt->execute();
        $clientData['profile'] = $profileStmt->fetch(PDO::FETCH_ASSOC);

        // If client not found, return null immediately
        if (!$clientData['profile']) {
            return null;
        }

    } catch (PDOException $e) {
        error_log("EXCEPTION in getClientDetailsForEditing (Profile Fetch) for client ID {$clientId}: " . $e->getMessage());
        return null; // Return null on profile fetch error
    }

    // 2. Fetch Client Answers with Question Details
    $answersSql = "SELECT
                       ca.question_id,
                       gq.question_text,
                       gq.question_title,
                       ca.answer
                   FROM
                       client_answers ca
                   JOIN
                       global_questions gq ON ca.question_id = gq.id
                   WHERE
                       ca.client_id = :client_id";
    try {
        $answersStmt = $pdo->prepare($answersSql);
        $answersStmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);
        $answersStmt->execute();
        $clientData['answers'] = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("EXCEPTION in getClientDetailsForEditing (Answers Fetch) for client ID {$clientId}: " . $e->getMessage());
        // Decide if partial data is acceptable or return null. Returning null for consistency.
        return null;
    }

    return $clientData;
}


/**
 * Updates specific fields in the client's profile.
 * Dynamically builds the UPDATE statement based on the provided data.
 *
 * @param PDO $pdo The database connection object.
 * @param int $clientId The ID of the client to update.
 * @param array $dataToUpdate An associative array where keys are the column names
 *                            (e.g., 'first_name', 'site_id') and values are the new values.
 *                            Only allows updating 'first_name', 'last_name', 'site_id', 'email_preference_jobs'.
 * @return bool True on successful update, false on failure or if no valid fields were provided.
 */
function updateClientProfileFields(PDO $pdo, int $clientId, array $dataToUpdate): bool
{
    $allowedFields = ['first_name', 'last_name', 'site_id', 'email_preference_jobs'];
    $setClauses = [];
    $params = [':client_id' => $clientId];

    foreach ($dataToUpdate as $field => $value) {
        if (in_array($field, $allowedFields)) {
            $placeholder = ':' . $field;
            $setClauses[] = "`" . $field . "` = " . $placeholder;
            $params[$placeholder] = $value;
        } else {
            // Log or warn about disallowed fields? For now, just ignore them.
            error_log("WARN updateClientProfileFields: Attempted to update disallowed field '{$field}' for client ID {$clientId}.");
        }
    }

    // If no valid fields to update, return false or true?
    // Returning false seems more indicative that no update occurred.
    if (empty($setClauses)) {
        error_log("INFO updateClientProfileFields: No valid fields provided to update for client ID {$clientId}.");
        return false;
    }

    $sql = "UPDATE clients SET " . implode(', ', $setClauses) . " WHERE id = :client_id AND deleted_at IS NULL"; // Ensure we don't update deleted clients

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR updateClientProfileFields: Prepare failed for client ID {$clientId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }

        return $stmt->execute($params);

    } catch (PDOException $e) {
        error_log("EXCEPTION in updateClientProfileFields for client ID {$clientId}: " . $e->getMessage());
        return false;
    }
}


/**
 * Retrieves a single client by their ID, excluding deleted clients.
 * Intended for API use.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $clientId The ID of the client to retrieve.
 * @return array|null An associative array of the client's data (id, first_name, last_name, email, site_id, client_qr_identifier, email_preference_jobs) or null if not found or on error.
 */
function getClientById(PDO $pdo, int $clientId): ?array
{
    $sql = "SELECT id, first_name, last_name, email, site_id, client_qr_identifier, email_preference_jobs
            FROM clients
            WHERE id = :client_id AND deleted_at IS NULL";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getClientById: Prepare failed for client ID {$clientId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }

        $stmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->execute();
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        return $client ?: null; // Return the client array or null if fetch returned false

    } catch (PDOException $e) {
        error_log("EXCEPTION in getClientById for client ID {$clientId}: " . $e->getMessage());
        return null;
    }
}

/**
 * Searches for clients based on various parameters with pagination, excluding deleted clients.
 * Intended for API use.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $params Associative array of search parameters. Allowed keys: 'name', 'email', 'qr_identifier'.
 * @param int $page The current page number (1-based).
 * @param int $limit The number of items per page.
 * @return array An array containing 'total_items' (int) and 'clients' (array of client data), or ['total_items' => 0, 'clients' => []] on error.
 */
function searchClientsApi(PDO $pdo, array $params, int $page, int $limit): array
{
    $selectFields = "id, first_name, last_name, email, site_id, client_qr_identifier, email_preference_jobs";
    $baseSql = "FROM clients WHERE deleted_at IS NULL";
    $whereConditions = [];
    $executeParams = [];

    // Build WHERE clause based on parameters
    if (!empty($params['name'])) {
        $whereConditions[] = "(first_name LIKE :name OR last_name LIKE :name)";
        $executeParams[':name'] = '%' . trim($params['name']) . '%';
    }
    if (!empty($params['email'])) {
        $whereConditions[] = "email = :email";
        $executeParams[':email'] = trim($params['email']);
    }
    if (!empty($params['qr_identifier'])) {
        $whereConditions[] = "client_qr_identifier = :qr_identifier";
        $executeParams[':qr_identifier'] = trim($params['qr_identifier']);
    }

    $whereSql = "";
    if (!empty($whereConditions)) {
        $whereSql = " AND " . implode(' AND ', $whereConditions);
    }

    // --- Total Count Query ---
    $countSql = "SELECT COUNT(*) " . $baseSql . $whereSql;
    $totalItems = 0;
    try {
        $countStmt = $pdo->prepare($countSql);
        if (!$countStmt) {
            error_log("ERROR searchClientsApi (Count): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return ['total_items' => 0, 'clients' => []];
        }
        $countStmt->execute($executeParams);
        $totalItems = (int)$countStmt->fetchColumn();

    } catch (PDOException $e) {
        error_log("EXCEPTION in searchClientsApi (Count): " . $e->getMessage() . " SQL: " . $countSql);
        return ['total_items' => 0, 'clients' => []];
    }

    // --- Data Query ---
    $clients = [];
    if ($totalItems > 0) {
        $offset = ($page - 1) * $limit;
        $dataSql = "SELECT " . $selectFields . " " . $baseSql . $whereSql . " ORDER BY last_name, first_name LIMIT :limit OFFSET :offset";

        try {
            $dataStmt = $pdo->prepare($dataSql);
            if (!$dataStmt) {
                error_log("ERROR searchClientsApi (Data): Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                // Return total count but empty client list as data fetch failed
                return ['total_items' => $totalItems, 'clients' => []];
            }

            // Bind WHERE parameters
            foreach ($executeParams as $key => $value) {
                $dataStmt->bindValue($key, $value);
            }
            // Bind LIMIT and OFFSET parameters
            $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $dataStmt->execute();
            $clients = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("EXCEPTION in searchClientsApi (Data): " . $e->getMessage() . " SQL: " . $dataSql);
            // Return total count but empty client list as data fetch failed
            return ['total_items' => $totalItems, 'clients' => []];
        }
    }

    return [
        'total_items' => $totalItems,
        'clients' => $clients ?: [] // Ensure clients is always an array
    ];
}


?>