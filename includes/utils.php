<?php
// includes/utils.php

/**
 * Sanitizes a raw user-input title into a standardized base name
 * suitable for storage and deriving column names.
 * - Lowercase, underscores for spaces, alphanumeric + underscore, truncated.
 * - DOES NOT ADD 'q_' prefix.
 * Example: "Needs Resume?" -> "needs_resume"
 *
 * @param string $raw_title User-provided title.
 * @return string Sanitized base name, or empty string if sanitization results in empty.
 */
function sanitize_title_to_base_name($raw_title) {
    $base_name = trim($raw_title);
    // Replace sequences of whitespace with a single underscore
    $base_name = preg_replace('/\s+/', '_', $base_name);
    // Remove any characters that are NOT letters, numbers, or underscore
    $base_name = preg_replace('/[^a-zA-Z0-9_]/', '', $base_name);
    // Convert to lowercase
    $base_name = strtolower($base_name);
    // Truncate if necessary (adjust length as needed, 50 is reasonable)
    $base_name = substr($base_name, 0, 50);
    // Remove leading/trailing underscores that might result
    $base_name = trim($base_name, '_');
    // Ensure it's not empty after sanitization
    if (empty($base_name)) {
         error_log("sanitize_title_to_base_name resulted in empty string for input: " . $raw_title);
         return ''; // Return empty and let caller handle error
    }
    return $base_name;
}


/**
 * Formats a stored base name (like 'needs_resume') into a
 * user-friendly display label (like "Needs Resume").
 *
 * @param string $base_name The stored base name (e.g., 'needs_resume').
 * @return string Formatted display label.
 */
function format_base_name_for_display($base_name) {
    if (empty($base_name)) {
        return 'N/A'; // Return N/A or empty if base name is empty
    }
    // Replace underscores with spaces
    $display_label = str_replace('_', ' ', $base_name);
    // Capitalize the first letter of each word
    $display_label = ucwords($display_label);
    return $display_label;
}



/**
 * Reorders items (move up/down or renumber after delete) for specified tables.
 * Handles both moving a specific item up/down and renumbering all items within a group.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $table_name The name of the table (e.g., 'site_questions', 'site_ads').
 * @param string $order_column The column storing the order (e.g., 'display_order').
 * @param string|null $group_column The column defining the group (e.g., 'site_id').
 * @param mixed|null $group_value The value of the group column.
 * @param int|null $item_id The ID of the item to move (required for 'up'/'down').
 * @param string|null $direction 'up', 'down', or null (for renumbering).
 * @return bool True on success, false on failure.
 */
function reorder_items(PDO $pdo, string $table_name, string $order_column, ?string $group_column = null, $group_value = null, ?int $item_id = null, ?string $direction = null): bool
{
    error_log("[DEBUG utils.php] Entering reorder_items() - table: $table_name, order_col: $order_column, group_col: $group_column, group_val: $group_value, item_id: $item_id, direction: $direction");

    // Basic validation
    $allowed_tables = ['site_questions', 'site_ads']; // Allow both tables
    if (!in_array($table_name, $allowed_tables)) {
        error_log("reorder_items: Invalid or unsupported table '$table_name'");
        return false;
    }
    if ($direction !== null && !in_array($direction, ['up', 'down'])) {
        error_log("reorder_items: Invalid direction '$direction'");
        return false;
    }
    if (($direction === 'up' || $direction === 'down') && $item_id === null) {
        error_log("reorder_items: Missing item_id for move operation.");
        return false;
    }
    if ($group_column && $group_value === null) {
        error_log("reorder_items: Missing group_value for group_column '$group_column'.");
        return false;
    }
    // Basic check for valid column names (prevent SQL injection via column names)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $order_column)) {
        error_log("reorder_items: Invalid order_column name '$order_column'.");
        return false;
    }
    if ($group_column && !preg_match('/^[a-zA-Z0-9_]+$/', $group_column)) {
        error_log("reorder_items: Invalid group_column name '$group_column'.");
        return false;
    }

    // Build group condition SQL and parameters
    $group_condition_sql = '';
    $params = [];
    if ($group_column && $group_value !== null) {
        $group_condition_sql = " WHERE `$group_column` = :group_value";
        $params[':group_value'] = $group_value;
    } else {
        $group_condition_sql = " WHERE 1=1";
    }

    try {
        $pdo->beginTransaction();

        if ($direction === 'up' || $direction === 'down') { // Move operation
            if ($item_id === null) { throw new Exception("item_id required for move operations."); }

            // Get current item's order
            $sql_current = "SELECT `$order_column` FROM `$table_name` WHERE `id` = :item_id" . ($group_condition_sql ? str_replace('WHERE', 'AND', $group_condition_sql) : '');
            $stmt_current = $pdo->prepare($sql_current);
            $current_params = array_merge($params, [':item_id' => $item_id]);
            if (!$stmt_current) throw new PDOException("Failed to prepare query for current item order. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            if (!$stmt_current->execute($current_params)) throw new PDOException("Failed to execute query for current item order. Statement Error: " . implode(" | ", $stmt_current->errorInfo()));
            $current_order = $stmt_current->fetchColumn();
            if ($current_order === false) throw new Exception("Current item id '{$item_id}' not found within the specified group.");

            // Find adjacent item
            $order_comparison = ($direction === 'up') ? '<' : '>';
            $order_direction_sort = ($direction === 'up') ? 'DESC' : 'ASC';
            $sql_adjacent = "SELECT `id`, `$order_column` FROM `$table_name` "
                          . $group_condition_sql
                          . " AND `$order_column` $order_comparison :current_order "
                          . " ORDER BY `$order_column` $order_direction_sort LIMIT 1";
            $adjacent_params = array_merge($params, [':current_order' => $current_order]);
            $stmt_adjacent = $pdo->prepare($sql_adjacent);
            if (!$stmt_adjacent) throw new PDOException("Failed to prepare query for adjacent item. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            if (!$stmt_adjacent->execute($adjacent_params)) throw new PDOException("Failed to execute query for adjacent item. Statement Error: " . implode(" | ", $stmt_adjacent->errorInfo()));
            $adjacent_item = $stmt_adjacent->fetch(PDO::FETCH_ASSOC);

            if ($adjacent_item) {
                // Swap orders
                $sql_update_current = "UPDATE `$table_name` SET `$order_column` = :adjacent_order WHERE `id` = :item_id";
                $stmt_update_current = $pdo->prepare($sql_update_current);
                if (!$stmt_update_current) throw new PDOException("Failed to prepare update for current item. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                if (!$stmt_update_current->execute([':adjacent_order' => $adjacent_item[$order_column], ':item_id' => $item_id])) throw new PDOException("Failed to update current item order. Statement Error: " . implode(" | ", $stmt_update_current->errorInfo()));

                $sql_update_adjacent = "UPDATE `$table_name` SET `$order_column` = :current_order WHERE `id` = :adjacent_id";
                $stmt_update_adjacent = $pdo->prepare($sql_update_adjacent);
                 if (!$stmt_update_adjacent) throw new PDOException("Failed to prepare update for adjacent item. PDO Error: " . implode(" | ", $pdo->errorInfo()));
                if (!$stmt_update_adjacent->execute([':current_order' => $current_order, ':adjacent_id' => $adjacent_item['id']])) throw new PDOException("Failed to update adjacent item order. Statement Error: " . implode(" | ", $stmt_update_adjacent->errorInfo()));

                error_log("Reorder successful: Swapped item $item_id (order $current_order) with " . $adjacent_item['id'] . " (order " . $adjacent_item[$order_column] . ")");
            } else {
                error_log("Reorder: Item $item_id is already at the " . ($direction === 'up' ? 'top' : 'bottom') . " or no adjacent item found within group.");
            }
            $pdo->commit();
            return true;

        } else { // Renumbering operation (direction is null)
            error_log("Reorder: Renumbering items for group $group_column = $group_value");
            $sql_select_all = "SELECT `id` FROM `$table_name` $group_condition_sql ORDER BY `$order_column` ASC";
            $stmt_select_all = $pdo->prepare($sql_select_all);
            if (!$stmt_select_all) throw new PDOException("Failed to prepare select for renumbering. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            if (!$stmt_select_all->execute($params)) throw new PDOException("Failed to execute select for renumbering. Statement Error: " . implode(" | ", $stmt_select_all->errorInfo()));
            $items = $stmt_select_all->fetchAll(PDO::FETCH_COLUMN);

            $stmt_renumber = $pdo->prepare("UPDATE `$table_name` SET `$order_column` = :new_order WHERE `id` = :id");
             if (!$stmt_renumber) throw new PDOException("Failed to prepare update for renumbering. PDO Error: " . implode(" | ", $pdo->errorInfo()));

            foreach ($items as $index => $id_to_renumber) {
                $new_order = $index;
                if (!$stmt_renumber->execute([':new_order' => $new_order, ':id' => $id_to_renumber])) {
                    throw new PDOException("Failed to renumber item id '{$id_to_renumber}' to order '{$new_order}'. Statement Error: " . implode(" | ", $stmt_renumber->errorInfo()));
                }
            }
            error_log("Reorder: Renumbering complete for group $group_column = $group_value. " . count($items) . " items renumbered.");
            $pdo->commit();
            return true;
        }
    } catch (PDOException | Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in reorder_items (utils.php) for table '$table_name', group '$group_value', item '$item_id': " . $e->getMessage());
        return false;
    }
}



/**
 * Formats a MySQL DATETIME or TIMESTAMP string into a user-friendly format.
 *
 * @param string|null $timestamp The timestamp string (e.g., 'YYYY-MM-DD HH:MM:SS') or null.
 * @param string $format The desired output format (PHP date format string).
 * @return string The formatted date/time string, or 'N/A' if input is null/invalid.
 */
function formatTimestamp(?string $timestamp, string $format = 'M d, Y g:i A'): string
{
    if (empty($timestamp)) {
        return 'N/A';
    }
    try {
        $date = new DateTime($timestamp);
        return $date->format($format);
    } catch (Exception $e) {
        // Log error if needed
        error_log("Error formatting timestamp '{$timestamp}': " . $e->getMessage());
        return 'Invalid Date'; // Or return the original string, or 'N/A'
    }
}



// --- CSRF Protection Functions ---

/**
 * Generates a CSRF token and stores it in the session.
 *
 * @return string The generated CSRF token.
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a submitted CSRF token against the one stored in the session.
 *
 * @param string $submittedToken The token submitted with the form.
 * @return bool True if the token is valid, false otherwise.
 */
function verifyCsrfToken(string $submittedToken): bool
{
     if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($submittedToken) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    // Use hash_equals for timing attack safe comparison
    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}

// --- Flash Message Functions ---

/**
 * Sets a flash message in the session.
 *
 * @param string $key A unique key for the message (e.g., 'error', 'success', 'profile_error').
 * @param string $message The message content.
 * @param string $type The type of message (e.g., 'success', 'error', 'warning', 'info') - used for styling.
 */
function set_flash_message(string $key, string $message, string $type = 'info'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][$key] = ['message' => $message, 'type' => $type];
}

/**
 * Displays and clears flash messages for a specific key.
 *
 * @param string $key The key of the message to display.
 * @param string $default_type The default Bootstrap alert type if not specified (e.g., 'danger', 'success').
 */
function display_flash_messages(string $key, string $default_type = 'info'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash_messages'][$key])) {
        $flash_message = $_SESSION['flash_messages'][$key];
        $message = htmlspecialchars($flash_message['message'], ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars($flash_message['type'] ?? $default_type, ENT_QUOTES, 'UTF-8');

        echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>";
        echo $message;
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo "</div>";

        // Clear the message after displaying
        unset($_SESSION['flash_messages'][$key]);
    }
}

/**
 * Checks if the current user's active role is 'Director' (case-insensitive).
 * Uses active_role to respect impersonation.
 *
 * @return bool True if the active role is Director, false otherwise.
 */
function isDirector(): bool
{
    if (session_status() == PHP_SESSION_NONE) {
        session_start(); // Ensure session is started just in case
    }
    // Use active_role and case-insensitive comparison
    return isset($_SESSION['active_role']) && strtolower($_SESSION['active_role']) === 'director';
}

// Add similar functions for other roles if needed, e.g., isStaff(), isFinance()
