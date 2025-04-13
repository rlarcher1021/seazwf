<?php
// includes/db_connect.php

// --- Add PHPMailer use statements at the top (even if not used directly here, good practice) ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
// --- End Add ---
// Define Upload Path Constants (Used by configurations.php and checkin.php)
define('AD_UPLOAD_PATH', dirname(__DIR__) . '/assets/uploads/ads/'); // Use dirname(__DIR__) for reliability
define('AD_UPLOAD_URL_BASE', 'assets/uploads/ads/');    // Relative URL path for src attribute
// Define the path to the configuration file relative to this script
// Go up one level from 'includes' (to public_html), then up one MORE level, then into 'config'
$configPath = dirname(__DIR__, 2) . '/config/config.ini';

if (!file_exists($configPath)) {
    error_log("CRITICAL ERROR: Configuration file not found at expected location: " . $configPath);
    die("A critical configuration error occurred. Please contact the system administrator.");
}

// Parse the INI file
$config = parse_ini_file($configPath, true);

if ($config === false || !isset($config['database'])) {
    error_log("CRITICAL ERROR: Failed to parse configuration file or missing [database] section.");
    die("A critical configuration error occurred while reading settings. Please contact the system administrator.");
}

// Database credentials from config file
$db_host = $config['database']['host'];
$db_name = $config['database']['dbname'];
$db_user = $config['database']['username'];
$db_pass = $config['database']['password'];
$db_charset = $config['database']['charset'] ?? 'utf8mb4';

// PDO options
$options = [
    // --- PRODUCTION RECOMMENDED SETTING ---
    // PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // --- TEMPORARY DEBUGGING SETTING ---
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_WARNING, // Change back before deploying!
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Data Source Name (DSN)
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed. Please notify the administrator.");
}


// ===============================================
// --- Helper Functions ---
// ===============================================

/**
 * GET/SET site configs (BOOLEAN ONLY - 1/0).
 * NOTE: SET operations via this function were problematic. Use direct SQL for SET.
 * Primarily useful for GET.
 */
function get_or_set_site_config($pdo, $site_id, $config_key, $value_to_set = null) {
    // (Keeping the last version of this function - primarily used for GET)
    try {
        $trimmed_config_key = trim($config_key);
        if ($value_to_set === null) { // GET
             $stmt_check = $pdo->prepare("SELECT config_value FROM site_configurations WHERE site_id = :site_id AND config_key = :config_key");
             if (!$stmt_check) { error_log("ERROR get_or_set_site_config: GET prepare failed for key '{$trimmed_config_key}'."); return null; }
             $execute_check = $stmt_check->execute(['site_id' => $site_id, 'config_key' => $trimmed_config_key]);
             if(!$execute_check) { error_log("ERROR get_or_set_site_config: GET execute failed for key '{$trimmed_config_key}': " . implode("|", $stmt_check->errorInfo())); return null; }
             $result = $stmt_check->fetchColumn();
             return ($result !== false) ? $result : null;
        } else { // SET (CAUTION: Known Issues)
            error_log("DEBUG get_or_set_site_config (CAUTION - SET): Attempting SIMPLE UPDATE Key='{$trimmed_config_key}', Value='{$value_to_set}', Site='{$site_id}'");
            $value_to_bind = ($value_to_set == 1 || $value_to_set === true || strtoupper((string)$value_to_set) === 'TRUE') ? 1 : 0;
            error_log("DEBUG get_or_set_site_config (CAUTION - SET): Binding '{$trimmed_config_key}' as INT with value: {$value_to_bind}");
            $sql_update = "UPDATE site_configurations SET config_value = :value, updated_at = NOW() WHERE site_id = :site_id AND config_key = :config_key";
            $stmt_update = $pdo->prepare($sql_update);
            if (!$stmt_update) { error_log("ERROR get_or_set_site_config: UPDATE prepare failed for key '{$trimmed_config_key}'."); return false; }
            $stmt_update->bindParam(':value', $value_to_bind, PDO::PARAM_INT);
            $stmt_update->bindParam(':site_id', $site_id, PDO::PARAM_INT);
            $stmt_update->bindParam(':config_key', $trimmed_config_key, PDO::PARAM_STR);
            $update_success = $stmt_update->execute();
            $rows_affected = $stmt_update->rowCount();
            error_log("DEBUG get_or_set_site_config (CAUTION - SET): SIMPLE UPDATE execute result: " . ($update_success ? 'true':'false') . ", Rows affected: " . $rows_affected);
            if ($update_success) {
                 if ($rows_affected === 0) error_log("DEBUG get_or_set_site_config (CAUTION - SET): SIMPLE UPDATE successful, but 0 rows affected.");
                 else error_log("DEBUG get_or_set_site_config (CAUTION - SET): SIMPLE UPDATE successful, {$rows_affected} rows affected.");
                return true;
            } else {
                error_log("ERROR get_or_set_site_config (CAUTION - SET): SIMPLE UPDATE failed for key '{$trimmed_config_key}'. Error: " . implode(" | ", $stmt_update->errorInfo()));
                return false;
            }
        }
    } catch (PDOException $e) { error_log("EXCEPTION in get_or_set_site_config for site $site_id, key $config_key: " . $e->getMessage()); return false; }
}


/**
 * Reorders items (move up/down or renumber after delete) for 'site_questions'.
 * (Keeping the last working version of this function)
 */
function reorder_items($pdo, $table_name, $order_column, $group_column = null, $group_value = null, $item_id = null, $direction = null) {
    error_log("[DEBUG db_connect.php] Entering reorder_items() - table: $table_name, order_col: $order_column, group_col: $group_column, group_val: $group_value, item_id: $item_id, direction: $direction");
    $allowed_tables = ['site_questions'];
    if (!in_array($table_name, $allowed_tables)) { error_log("reorder_items: Invalid table '$table_name'"); return false; }
    if ($direction !== null && !in_array($direction, ['up', 'down'])) { error_log("reorder_items: Invalid direction '$direction'"); return false; }
    if (($direction === 'up' || $direction === 'down') && $item_id === null) { error_log("reorder_items: Missing item_id for move operation."); return false; }
    if ($group_column && $group_value === null) { error_log("reorder_items: Missing group_value for group_column '$group_column'."); return false; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $order_column)) { error_log("reorder_items: Invalid order_column name '$order_column'."); return false; }
    if ($group_column && !preg_match('/^[a-zA-Z0-9_]+$/', $group_column)) { error_log("reorder_items: Invalid group_column name '$group_column'."); return false; }

    $group_condition_sql = ''; $params = [];
    if ($group_column && $group_value !== null) { $group_condition_sql = " WHERE `$group_column` = :group_value"; $params[':group_value'] = $group_value; }
    else { $group_condition_sql = " WHERE 1=1"; }

    try {
        $pdo->beginTransaction();
        if ($direction === 'up' || $direction === 'down') { // Move operation
            if ($item_id === null) throw new Exception("item_id required for move operations.");
            $sql_current = "SELECT `$order_column` FROM `$table_name` WHERE `id` = :item_id" . ($group_condition_sql ? str_replace('WHERE', 'AND', $group_condition_sql) : '');
            $stmt_current = $pdo->prepare($sql_current); $current_params = array_merge($params, [':item_id' => $item_id]);
            if (!$stmt_current->execute($current_params)) throw new PDOException("Failed to execute query for current item order.");
            $current_order = $stmt_current->fetchColumn(); if ($current_order === false) throw new Exception("Current item id '{$item_id}' not found.");

            $order_comparison = ($direction === 'up') ? '<' : '>'; $order_direction_sort = ($direction === 'up') ? 'DESC' : 'ASC';
            $sql_adjacent = "SELECT `id`, `$order_column` FROM `$table_name` " . $group_condition_sql . " AND `$order_column` $order_comparison :current_order " . " ORDER BY `$order_column` $order_direction_sort LIMIT 1";
            $adjacent_params = array_merge($params, [':current_order' => $current_order]); $stmt_adjacent = $pdo->prepare($sql_adjacent);
            if (!$stmt_adjacent->execute($adjacent_params)) throw new PDOException("Failed to execute query for adjacent item.");
            $adjacent_item = $stmt_adjacent->fetch(PDO::FETCH_ASSOC);

            if ($adjacent_item) {
                $sql_update_current = "UPDATE `$table_name` SET `$order_column` = :adjacent_order WHERE `id` = :item_id";
                $stmt_update_current = $pdo->prepare($sql_update_current);
                if (!$stmt_update_current->execute([':adjacent_order' => $adjacent_item[$order_column], ':item_id' => $item_id])) throw new PDOException("Failed to update current item order.");
                $sql_update_adjacent = "UPDATE `$table_name` SET `$order_column` = :current_order WHERE `id` = :adjacent_id";
                $stmt_update_adjacent = $pdo->prepare($sql_update_adjacent);
                if (!$stmt_update_adjacent->execute([':current_order' => $current_order, ':adjacent_id' => $adjacent_item['id']])) throw new PDOException("Failed to update adjacent item order.");
                error_log("Reorder successful: Swapped item $item_id with " . $adjacent_item['id']);
            } else { error_log("Reorder: Item $item_id is already at the top/bottom or no adjacent item found within group."); }
             $pdo->commit(); return true;
        } else { // Renumbering operation
             error_log("Reorder: Renumbering items for group $group_column = $group_value");
             $sql_select_all = "SELECT `id` FROM `$table_name` $group_condition_sql ORDER BY `$order_column` ASC";
             $stmt_select_all = $pdo->prepare($sql_select_all); if (!$stmt_select_all->execute($params)) throw new PDOException("Failed to fetch items for renumbering.");
             $items = $stmt_select_all->fetchAll(PDO::FETCH_COLUMN);
             $stmt_renumber = $pdo->prepare("UPDATE `$table_name` SET `$order_column` = :new_order WHERE `id` = :id");
             foreach ($items as $index => $id_to_renumber) { $new_order = $index; if (!$stmt_renumber->execute([':new_order' => $new_order, ':id' => $id_to_renumber])) throw new PDOException("Failed to renumber item id '{$id_to_renumber}'."); }
             error_log("Reorder: Renumbering complete for group $group_column = $group_value. " . count($items) . " items renumbered.");
             $pdo->commit(); return true;
        }
    } catch (PDOException | Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log("Error in reorder_items for table '$table_name', group '$group_value', item '$item_id': " . $e->getMessage()); return false; }
}


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
 * Checks if a dynamic question column exists and creates it.
 * Expects the SANITIZED BASE NAME. Adds 'q_' prefix internally.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $sanitized_base_name The sanitized base name (e.g., 'needs_resume').
 * @return bool True if column exists or was created successfully, false otherwise.
 */
function create_question_column_if_not_exists($pdo, $sanitized_base_name) {
    if (empty($sanitized_base_name)) {
        error_log("Error creating column: Received empty sanitized base name.");
        return false;
    }
    $actual_column_name = 'q_' . $sanitized_base_name; // Add prefix internally
    $target_table = 'check_ins';
    try {
        // Check if column exists
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt_check->execute([':table_name' => $target_table, ':column_name' => $actual_column_name]);
        $column_exists = (bool) $stmt_check->fetchColumn();
        if (!$column_exists) {
            error_log("Column '{$actual_column_name}' does not exist in '{$target_table}'. Attempting to add.");
            $sql_add_column = "ALTER TABLE `{$target_table}` ADD COLUMN `" . $actual_column_name . "` ENUM('YES', 'NO') NULL DEFAULT NULL";
            $result = $pdo->exec($sql_add_column);
            if ($result !== false) { error_log("Successfully added column '{$actual_column_name}' to '{$target_table}' table."); return true; }
            else { $errorInfo = $pdo->errorInfo(); error_log("Failed to add column '{$actual_column_name}' to '{$target_table}' table. Error: " . ($errorInfo[2] ?? 'Unknown error')); return false; }
        } else { error_log("Column '{$actual_column_name}' already exists in '{$target_table}'."); return true; }
    } catch (PDOException $e) { error_log("Error checking/creating column '{$actual_column_name}' in '{$target_table}': " . $e->getMessage()); return false; }
}


/**
 * Attempts to delete a dynamic question column from 'check_ins'.
 * Expects the SANITIZED BASE NAME. Adds 'q_' prefix internally.
 * Does NOT check for data presence.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $sanitized_base_name The sanitized base name (e.g., 'needs_resume').
 * @return bool True if column was dropped or didn't exist, false on failure.
 */
function delete_question_column_if_unused($pdo, $sanitized_base_name) { // Simplified version
    if (empty($sanitized_base_name)) { error_log("delete_question_column: Cannot determine column for empty base name '{$sanitized_base_name}'."); return false; }
    $actual_column_name = 'q_' . $sanitized_base_name; // Add prefix internally
    $check_ins_table = 'check_ins';
    error_log("Attempting to delete column (if exists): {$actual_column_name} from {$check_ins_table}");
    try {
        // Check if column exists
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt_check->execute([':table_name' => $check_ins_table, ':column_name' => $actual_column_name]);
        $column_exists = (bool) $stmt_check->fetchColumn();
        if (!$column_exists) { error_log("Column '{$actual_column_name}' does not exist. No deletion needed."); return true; }
        // Attempt to Drop Column
        error_log("Column '{$actual_column_name}' exists. Attempting deletion.");
        $sql_drop_column = "ALTER TABLE `{$check_ins_table}` DROP COLUMN `{$actual_column_name}`";
        $result = $pdo->exec($sql_drop_column);
        if ($result !== false) { error_log("Successfully dropped column '{$actual_column_name}'."); return true; }
        else { $errorInfo = $pdo->errorInfo(); error_log("Failed to drop column '{$actual_column_name}'. Error: " . ($errorInfo[2] ?? 'Unknown error')); return false; }
    } catch (PDOException $e) { error_log("Error during deletion attempt of column '{$actual_column_name}': " . $e->getMessage()); return false; }
}


// --- REMOVED Email Helper Functions ---
// function fetchAdminEmails($pdo) { ... }
// function sendAdminColumnNotificationEmail(...) { ... }

?>