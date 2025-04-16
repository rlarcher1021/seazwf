<?php
// includes/data_access/question_data.php

/**
 * Reorders items (move up/down or renumber after delete) for 'site_questions'.
 * Handles both moving a specific item up/down and renumbering all items within a group.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $table_name Currently hardcoded to 'site_questions'.
 * @param string $order_column The column storing the order (e.g., 'question_order').
 * @param string|null $group_column The column defining the group (e.g., 'site_id').
 * @param mixed|null $group_value The value of the group column.
 * @param int|null $item_id The ID of the item to move (required for 'up'/'down').
 * @param string|null $direction 'up', 'down', or null (for renumbering).
 * @return bool True on success, false on failure.
 */


/**
 * Checks if a dynamic question column exists in 'check_ins' and creates it if not.
 * Expects the SANITIZED BASE NAME (e.g., 'needs_resume'). Adds 'q_' prefix internally.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $sanitized_base_name The sanitized base name (e.g., 'needs_resume').
 * @return bool True if column exists or was created successfully, false otherwise.
 */
function create_question_column_if_not_exists(PDO $pdo, string $sanitized_base_name): bool
{
    if (empty($sanitized_base_name)) {
        error_log("Error creating column: Received empty sanitized base name.");
        return false;
    }
    // Validate base name format before using in SQL
    if (!preg_match('/^[a-z0-9_]+$/', $sanitized_base_name)) {
         error_log("Error creating column: Invalid format for sanitized base name '{$sanitized_base_name}'. Should be lowercase alphanumeric/underscore.");
         return false;
    }

    $actual_column_name = 'q_' . $sanitized_base_name; // Add prefix internally
    $target_table = 'check_ins';

    // Further validation on column name length (e.g., MySQL limit is 64 chars)
    if (strlen($actual_column_name) > 64) {
         error_log("Error creating column: Resulting column name '{$actual_column_name}' exceeds maximum length (64 chars).");
         return false;
    }


    try {
        // Check if column exists using information_schema (safer than relying on exceptions for flow control)
        $stmt_check = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name"
        );
        if (!$stmt_check) {
             error_log("Failed to prepare column existence check for '{$actual_column_name}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $stmt_check->execute([':table_name' => $target_table, ':column_name' => $actual_column_name]);
        $column_exists = (bool) $stmt_check->fetchColumn();

        if (!$column_exists) {
            error_log("Column '{$actual_column_name}' does not exist in '{$target_table}'. Attempting to add.");
            // Use backticks for table and column names in ALTER TABLE
            // Define column type clearly. Using ENUM here as per original code.
            $sql_add_column = "ALTER TABLE `{$target_table}` ADD COLUMN `{$actual_column_name}` ENUM('YES', 'NO') NULL DEFAULT NULL";

            // Use exec() for DDL statements like ALTER TABLE. Check return value.
            $result = $pdo->exec($sql_add_column);

            if ($result !== false) {
                error_log("Successfully added column '{$actual_column_name}' to '{$target_table}' table.");
                return true;
            } else {
                $errorInfo = $pdo->errorInfo();
                error_log("Failed to add column '{$actual_column_name}' to '{$target_table}' table. SQLSTATE[{$errorInfo[0]}] Driver Error[{$errorInfo[1]}]: {$errorInfo[2]}");
                return false;
            }
        } else {
            // Column already exists, which is considered success in this context.
            error_log("Column '{$actual_column_name}' already exists in '{$target_table}'. No action needed.");
            return true;
        }
    } catch (PDOException $e) {
        error_log("PDOException while checking/creating column '{$actual_column_name}' in '{$target_table}': " . $e->getMessage());
        return false;
    }
}


/**
 * Attempts to delete a dynamic question column from 'check_ins'.
 * Expects the SANITIZED BASE NAME (e.g., 'needs_resume'). Adds 'q_' prefix internally.
 * Does NOT check if the column contains data before dropping.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $sanitized_base_name The sanitized base name (e.g., 'needs_resume').
 * @return bool True if column was dropped successfully or didn't exist initially, false on failure.
 */
function delete_question_column(PDO $pdo, string $sanitized_base_name): bool
{
    if (empty($sanitized_base_name)) {
        error_log("delete_question_column: Cannot delete column for empty base name.");
        return false;
    }
     // Validate base name format before using in SQL
    if (!preg_match('/^[a-z0-9_]+$/', $sanitized_base_name)) {
         error_log("Error deleting column: Invalid format for sanitized base name '{$sanitized_base_name}'. Should be lowercase alphanumeric/underscore.");
         return false;
    }

    $actual_column_name = 'q_' . $sanitized_base_name; // Add prefix internally
    $check_ins_table = 'check_ins';

     // Further validation on column name length
    if (strlen($actual_column_name) > 64) {
         error_log("Error deleting column: Resulting column name '{$actual_column_name}' exceeds maximum length (64 chars). Cannot exist.");
         return false; // Or true, as it cannot exist to be deleted? False seems safer.
    }

    error_log("Attempting to delete column (if exists): {$actual_column_name} from {$check_ins_table}");

    try {
        // Check if column exists first (optional but good practice to avoid errors if it doesn't)
        $stmt_check = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name"
        );
         if (!$stmt_check) {
             error_log("Failed to prepare column existence check for deletion of '{$actual_column_name}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false; // Fail deletion if check cannot be prepared
        }
        $stmt_check->execute([':table_name' => $check_ins_table, ':column_name' => $actual_column_name]);
        $column_exists = (bool) $stmt_check->fetchColumn();

        if (!$column_exists) {
            error_log("Column '{$actual_column_name}' does not exist in '{$check_ins_table}'. No deletion needed.");
            return true; // Considered success as the goal is for the column not to exist.
        }

        // Attempt to Drop Column
        error_log("Column '{$actual_column_name}' exists. Attempting deletion.");
        // Use backticks for table and column names
        $sql_drop_column = "ALTER TABLE `{$check_ins_table}` DROP COLUMN `{$actual_column_name}`";

        // Use exec() for DDL and check result
        $result = $pdo->exec($sql_drop_column);

        if ($result !== false) {
            error_log("Successfully dropped column '{$actual_column_name}' from '{$check_ins_table}'.");
            return true;
        } else {
            $errorInfo = $pdo->errorInfo();
            error_log("Failed to drop column '{$actual_column_name}' from '{$check_ins_table}'. SQLSTATE[{$errorInfo[0]}] Driver Error[{$errorInfo[1]}]: {$errorInfo[2]}");
            return false;
        }
    } catch (PDOException $e) {
        error_log("PDOException during deletion attempt of column '{$actual_column_name}': " . $e->getMessage());
        return false;
    }
}


/**
 * Fetches the distinct base titles of active questions, optionally filtered by site.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|string|null $site_filter_id The specific site ID to filter by, 'all' for no site filter, or null if context is invalid.
 * @return array An array of unique, active question base titles, or empty array on failure.
 */
function getActiveQuestionTitles(PDO $pdo, $site_filter_id): array
{
    $sql = "SELECT DISTINCT gq.question_title
            FROM global_questions gq
            JOIN site_questions sq ON gq.id = sq.global_question_id
            WHERE sq.is_active = TRUE";
    $params = [];

    if ($site_filter_id !== 'all' && is_numeric($site_filter_id) && $site_filter_id > 0) {
        $sql .= " AND sq.site_id = :site_id_filter";
        $params[':site_id_filter'] = (int)$site_filter_id;
    } elseif ($site_filter_id !== 'all' && $site_filter_id !== null) {
         error_log("getActiveQuestionTitles: Invalid site_filter_id provided: " . print_r($site_filter_id, true));
         return []; // Return empty if filter is invalid but not 'all' or null
    }
    // If $site_filter_id is 'all' or null, no site filtering is applied.

    $sql .= " ORDER BY gq.question_title ASC";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getActiveQuestionTitles: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        $execute_success = $stmt->execute($params);
        if (!$execute_success) {
            error_log("ERROR getActiveQuestionTitles: Execute failed. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return [];
        }

        // Fetch just the titles as a flat array
        $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $titles ?: []; // Return fetched titles or empty array if none found

    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveQuestionTitles: " . $e->getMessage());
        return [];
    }
}


/**
 * Fetches all unique base question titles from the global_questions table.
 * Used for validation in the custom report builder.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of unique global question base titles, or empty array on failure.
 */
function getAllGlobalQuestionTitles(PDO $pdo): array
{
    try {
        // Fetch distinct titles to avoid duplicates if any exist
        $stmt = $pdo->query("SELECT DISTINCT question_title FROM global_questions ORDER BY question_title ASC");
        if (!$stmt) {
            error_log("ERROR getAllGlobalQuestionTitles: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }
        $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $titles ?: []; // Return fetched titles or empty array if none found
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllGlobalQuestionTitles: " . $e->getMessage());
        return [];
    }
}


/**
 * Fetches active questions assigned to a specific site, ordered by display order.
 * Returns the global question ID, text, and title (base name).
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array An array of associative arrays representing the questions, or empty array on failure.
 */
function getActiveQuestionsForSite(PDO $pdo, int $site_id): array
{
    $sql = "SELECT sq.global_question_id, gq.question_text, gq.question_title
            FROM site_questions sq
            JOIN global_questions gq ON sq.global_question_id = gq.id
            WHERE sq.site_id = :site_id AND sq.is_active = TRUE
            ORDER BY sq.display_order ASC";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getActiveQuestionsForSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
            error_log("ERROR getActiveQuestionsForSite: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveQuestionsForSite for site ID {$site_id}: " . $e->getMessage());
        return [];
    }
}


/**
 * Checks if a global question with the given sanitized base title already exists.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $sanitized_base_title The sanitized base title to check.
 * @return bool True if the title exists, false otherwise or on error.
 */
function globalQuestionTitleExists(PDO $pdo, string $sanitized_base_title): bool
{
    if (empty($sanitized_base_title)) return false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM global_questions WHERE question_title = :title");
        if (!$stmt) {
             error_log("ERROR globalQuestionTitleExists: Prepare failed for title '{$sanitized_base_title}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false; // Indicate error or uncertainty
        }
        $stmt->execute([':title' => $sanitized_base_title]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("EXCEPTION in globalQuestionTitleExists for title '{$sanitized_base_title}': " . $e->getMessage());
        return false; // Indicate error or uncertainty
    }
}

/**
 * Adds a new global question after ensuring the column exists.
 * Assumes title uniqueness and column creation were checked beforehand if needed.
 * Handles its own transaction.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $question_text The full text of the question.
 * @param string $sanitized_base_title The validated, sanitized base title.
 * @return int|false The new global question ID on success, false on failure.
 */
function addGlobalQuestion(PDO $pdo, string $question_text, string $sanitized_base_title): int|false
{
     if (empty($question_text) || empty($sanitized_base_title)) {
         error_log("ERROR addGlobalQuestion: Missing text or sanitized title.");
         return false;
     }
     // Optional: Re-validate title format here if desired
     if (!preg_match('/^[a-z0-9_]+$/', $sanitized_base_title) || strlen($sanitized_base_title) > 50) {
         error_log("ERROR addGlobalQuestion: Invalid sanitized base title format '{$sanitized_base_title}'.");
         return false;
     }

    try {
        // Note: Column creation (create_question_column_if_not_exists) should ideally happen
        // *before* calling this function or within the same transaction in the calling code
        // for better atomicity, but adding it here for simplicity based on original flow.
        if (!create_question_column_if_not_exists($pdo, $sanitized_base_title)) {
             error_log("ERROR addGlobalQuestion: Failed prerequisite check/create for column 'q_{$sanitized_base_title}'.");
             return false;
        }

        $pdo->beginTransaction();
        $sql = "INSERT INTO global_questions (question_text, question_title) VALUES (:text, :title)";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR addGlobalQuestion: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             $pdo->rollBack(); return false;
        }
        $success = $stmt->execute([':text' => $question_text, ':title' => $sanitized_base_title]);
        if ($success) {
            $new_id = $pdo->lastInsertId();
            $pdo->commit();
            return (int)$new_id;
        } else {
            error_log("ERROR addGlobalQuestion: Execute failed. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            $pdo->rollBack();
            return false;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("EXCEPTION in addGlobalQuestion: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches the sanitized base title for a given global question ID.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_question_id The ID of the global question.
 * @return string|null The base title, or null if not found or on error.
 */
function getGlobalQuestionTitleById(PDO $pdo, int $global_question_id): ?string
{
    try {
        $stmt = $pdo->prepare("SELECT question_title FROM global_questions WHERE id = :id");
         if (!$stmt) {
             error_log("ERROR getGlobalQuestionTitleById: Prepare failed for ID {$global_question_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $stmt->execute([':id' => $global_question_id]);
        $title = $stmt->fetchColumn();
        return ($title !== false) ? (string)$title : null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getGlobalQuestionTitleById for ID {$global_question_id}: " . $e->getMessage());
        return null;
    }
}


/**
 * Fetches all details for a specific global question by its ID.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_question_id The ID of the global question.
 * @return array|null An associative array with question details (id, question_text, question_title), or null if not found or on error.
 */
function getGlobalQuestionById(PDO $pdo, int $global_question_id): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT id, question_text, question_title FROM global_questions WHERE id = :id");
         if (!$stmt) {
             error_log("ERROR getGlobalQuestionById: Prepare failed for ID {$global_question_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $stmt->execute([':id' => $global_question_id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($question !== false) ? $question : null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getGlobalQuestionById for ID {$global_question_id}: " . $e->getMessage());
        return null;
    }
}

/**
 * Updates the text of a specific global question.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_question_id The ID of the global question to update.
 * @param string $new_text The new question text.
 * @return bool True on success, false on failure.
 */
function updateGlobalQuestionText(PDO $pdo, int $global_question_id, string $new_text): bool
{
    if (empty($new_text)) {
        error_log("ERROR updateGlobalQuestionText: New text cannot be empty for ID {$global_question_id}.");
        return false;
    }
    try {
        $sql = "UPDATE global_questions SET question_text = :text WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR updateGlobalQuestionText: Prepare failed for ID {$global_question_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([':text' => $new_text, ':id' => $global_question_id]);
        if (!$success) {
            error_log("ERROR updateGlobalQuestionText: Execute failed for ID {$global_question_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return false;
        }
        // Check if any row was actually updated
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateGlobalQuestionText for ID {$global_question_id}: " . $e->getMessage());
        return false;
    }
}


/**
 * Deletes a global question by its ID. Does NOT handle column deletion.
 * Column deletion should be handled separately after successful deletion.
 * Handles its own transaction.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_question_id The ID of the global question to delete.
 * @return bool True on success, false on failure.
 */
function deleteGlobalQuestion(PDO $pdo, int $global_question_id): bool
{
    try {
        $pdo->beginTransaction();
        // Optional: Delete related site_questions first if needed due to FK constraints
        // $stmt_del_site = $pdo->prepare("DELETE FROM site_questions WHERE global_question_id = :gid");
        // $stmt_del_site->execute([':gid' => $global_question_id]);

        $stmt = $pdo->prepare("DELETE FROM global_questions WHERE id = :id");
         if (!$stmt) {
             error_log("ERROR deleteGlobalQuestion: Prepare failed for ID {$global_question_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             $pdo->rollBack(); return false;
        }
        $success = $stmt->execute([':id' => $global_question_id]);
        if ($success) {
            $pdo->commit();
            return true;
        } else {
             error_log("ERROR deleteGlobalQuestion: Execute failed for ID {$global_question_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             $pdo->rollBack();
             return false;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("EXCEPTION in deleteGlobalQuestion for ID {$global_question_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Assigns a global question to a site with a calculated display order.
 * Handles its own transaction.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site.
 * @param int $global_question_id The ID of the global question.
 * @param int $is_active 1 if active, 0 if inactive.
 * @return bool True on success, false on failure (e.g., already assigned).
 */
function assignQuestionToSite(PDO $pdo, int $site_id, int $global_question_id, int $is_active): bool
{
    try {
        $pdo->beginTransaction();
        // Get max display order for the site
        $stmt_order = $pdo->prepare("SELECT MAX(display_order) FROM site_questions WHERE site_id = :id");
        if (!$stmt_order) { $pdo->rollBack(); error_log("ERROR assignQuestionToSite: Prepare failed (order)."); return false; }
        $stmt_order->execute([':id' => $site_id]);
        $max_order = $stmt_order->fetchColumn() ?? -1;
        $new_order = $max_order + 1;

        // Insert the assignment
        $sql = "INSERT INTO site_questions (site_id, global_question_id, display_order, is_active)
                VALUES (:sid, :gid, :order, :active)";
        $stmt_assign = $pdo->prepare($sql);
        if (!$stmt_assign) { $pdo->rollBack(); error_log("ERROR assignQuestionToSite: Prepare failed (insert)."); return false; }

        $success = $stmt_assign->execute([
            ':sid' => $site_id,
            ':gid' => $global_question_id,
            ':order' => $new_order,
            ':active' => $is_active
        ]);

        if ($success) {
            $pdo->commit();
            return true;
        } else {
            // Check for duplicate entry error (SQLSTATE 23000)
            $errorInfo = $stmt_assign->errorInfo();
            if ($errorInfo[0] === '23000') {
                 error_log("WARNING assignQuestionToSite: Attempted to assign duplicate question (Site: {$site_id}, GlobalQ: {$global_question_id}).");
            } else {
                 error_log("ERROR assignQuestionToSite: Execute failed. SQLSTATE[{$errorInfo[0]}] Driver Error[{$errorInfo[1]}]: {$errorInfo[2]}");
            }
            $pdo->rollBack();
            return false;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("EXCEPTION in assignQuestionToSite (Site: {$site_id}, GlobalQ: {$global_question_id}): " . $e->getMessage());
        return false;
    }
}

/**
 * Removes a question assignment from a site by its site_questions ID.
 * Does NOT handle reordering; reordering should be called separately if needed.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_question_id The ID of the record in the site_questions table.
 * @param int $site_id The ID of the site (for verification).
 * @return bool True on success, false on failure.
 */
function removeQuestionFromSite(PDO $pdo, int $site_question_id, int $site_id): bool
{
     try {
        $stmt = $pdo->prepare("DELETE FROM site_questions WHERE id = :id AND site_id = :sid");
         if (!$stmt) {
             error_log("ERROR removeQuestionFromSite: Prepare failed for ID {$site_question_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $site_question_id, ':sid' => $site_id]);
        if (!$success) {
             error_log("ERROR removeQuestionFromSite: Execute failed for ID {$site_question_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        // Check rowCount to see if a row was actually deleted
        return ($success && $stmt->rowCount() > 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in removeQuestionFromSite for ID {$site_question_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Toggles the active status of a site question assignment.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_question_id The ID of the record in the site_questions table.
 * @param int $site_id The ID of the site (for verification).
 * @return bool True on success, false on failure.
 */
function toggleSiteQuestionActive(PDO $pdo, int $site_question_id, int $site_id): bool
{
    $sql = "UPDATE site_questions SET is_active = NOT is_active WHERE id = :id AND site_id = :sid";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR toggleSiteQuestionActive: Prepare failed for ID {$site_question_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $site_question_id, ':sid' => $site_id]);
         if (!$success) {
             error_log("ERROR toggleSiteQuestionActive: Execute failed for ID {$site_question_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in toggleSiteQuestionActive for ID {$site_question_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches all questions assigned to a specific site (active and inactive), ordered by display order.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array An array of associative arrays representing the assigned questions, or empty array on failure.
 */
function getSiteQuestionsAssigned(PDO $pdo, int $site_id): array
{
    $sql = "SELECT sq.id as site_question_id, sq.global_question_id, sq.display_order, sq.is_active,
                   gq.question_text, gq.question_title
            FROM site_questions sq
            JOIN global_questions gq ON sq.global_question_id = gq.id
            WHERE sq.site_id = :site_id
            ORDER BY sq.display_order ASC";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getSiteQuestionsAssigned: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }
        $stmt->execute([':site_id' => $site_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getSiteQuestionsAssigned for site ID {$site_id}: " . $e->getMessage());
        return [];
    }
}


/**
 * Fetches all global questions with their full details.
 *
 * @param PDO $pdo PDO connection object.
 * @return array Array of global questions or empty array on failure.
 */
function getAllGlobalQuestions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT id, question_text, question_title FROM global_questions ORDER BY question_title ASC");
        if (!$stmt) {
             error_log("ERROR getAllGlobalQuestions: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllGlobalQuestions: " . $e->getMessage());
        return [];
    }
}


// Add other question related data access functions here later...
// For example:
// function updateQuestion(PDO $pdo, int $question_id, string $title, string $type, bool $is_global): bool { ... }

/**
 * Fetches aggregated 'YES' counts for active questions within a specific timeframe and site filter.
 * Returns data formatted for a Chart.js pie chart.
 *
 * @param PDO $pdo PDO connection object.
 * @param int|string $site_id Site ID to filter by, or 'all'.
 * @param string $time_frame Time period ('today', 'last_7_days', 'last_30_days', 'last_365_days').
 * @return array|null An array ['labels' => [...], 'data' => [...]] on success, or null on error.
 */
function getAggregatedQuestionResponses(PDO $pdo, $site_id, string $time_frame): ?array
{
    // 1. Determine Date Range
    $end_date = date('Y-m-d H:i:s'); // Now
    $start_date = '';
    switch ($time_frame) {
        case 'last_7_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-6 days')); // Include today
            break;
        case 'last_30_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-29 days'));
            break;
        case 'last_365_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-364 days'));
            break;
        case 'today':
        default:
            $start_date = date('Y-m-d 00:00:00'); // Start of today
            break;
    }

    // 2. Get Active Questions for the site filter
    // We need both the prefixed column names (q_...) and the display labels.
    $active_question_columns = [];
    $active_chart_labels = [];
    $question_base_titles = getActiveQuestionTitles($pdo, $site_id); // Reuse existing function

    if ($question_base_titles === []) {
        error_log("getAggregatedQuestionResponses: No active questions found for site_id '{$site_id}'. Returning empty data.");
        return ['labels' => [], 'data' => []]; // Return empty structure, not null, if no questions
    }

    // Utils are included in the calling script (ajax_report_handler.php)

    foreach ($question_base_titles as $base_title) {
        if (!empty($base_title)) {
            $sanitized_base = sanitize_title_to_base_name($base_title);
            if (!empty($sanitized_base)) {
                $prefixed_col_name = 'q_' . $sanitized_base;
                if (preg_match('/^q_[a-z0-9_]+$/', $prefixed_col_name) && strlen($prefixed_col_name) <= 64) {
                    $formatted_label = format_base_name_for_display($sanitized_base);
                    $active_question_columns[] = $prefixed_col_name;
                    $active_chart_labels[] = $formatted_label;
                } else {
                    error_log("getAggregatedQuestionResponses: Skipping invalid generated column '{$prefixed_col_name}' from base '{$base_title}'.");
                }
            }
        }
    }

    if (empty($active_question_columns)) {
        error_log("getAggregatedQuestionResponses: No VALID active question columns derived for site_id '{$site_id}'. Returning empty data.");
        return ['labels' => [], 'data' => []];
    }

    // 3. Build SQL Query
    $select_clauses = [];
    foreach ($active_question_columns as $col_name) {
        // IMPORTANT: Use backticks around column names in case they match SQL keywords
        $select_clauses[] = "SUM(CASE WHEN ci.`" . $col_name . "` = 'YES' THEN 1 ELSE 0 END) AS `" . $col_name . "_yes_count`";
    }

    $sql = "SELECT " . implode(', ', $select_clauses) . " FROM check_ins ci";

    $where_clauses = [];
    $params = [];

    // Add site filter if not 'all'
    if ($site_id !== 'all') {
        $where_clauses[] = "ci.site_id = :site_id";
        $params[':site_id'] = (int)$site_id;
    }

    // Add date range filter
    $where_clauses[] = "ci.check_in_time BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(' AND ', $where_clauses);
    }

    // 4. Execute Query
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getAggregatedQuestionResponses: Prepare failed. Site: {$site_id}, Timeframe: {$time_frame}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }

        $execute_success = $stmt->execute($params);
        if (!$execute_success) {
            error_log("ERROR getAggregatedQuestionResponses: Execute failed. Site: {$site_id}, Timeframe: {$time_frame}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return null;
        }

        $result_row = $stmt->fetch(PDO::FETCH_ASSOC);

        // 5. Format Results
        $data_counts = [];
        if ($result_row) {
            foreach ($active_question_columns as $col_name) {
                $count_key = $col_name . '_yes_count';
                // Fetch the count, default to 0 if null (can happen if no rows match WHERE)
                $data_counts[] = isset($result_row[$count_key]) ? (int)$result_row[$count_key] : 0;
            }
        } else {
            // If fetch returns false (no rows matched), return zeros for all counts
            $data_counts = array_fill(0, count($active_question_columns), 0);
        }

        return [
            'labels' => $active_chart_labels,
            'data' => $data_counts
        ];

    } catch (PDOException $e) {
        error_log("EXCEPTION in getAggregatedQuestionResponses. Site: {$site_id}, Timeframe: {$time_frame}. Error: " . $e->getMessage());
        return null;
    }
}
