<?php
/*
 * File: configurations.php
 * Path: /configurations.php
 * Created: 2024-08-01 13:30:00 MST
 * Author: Robert Archer
 * Updated: 2025-04-10 - Unified fixes for site settings, question titles, transactions.
 * Description: Administrator page/section for managing system configurations.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Use require_once for functions defined in db_connect
require_once 'includes/db_connect.php'; // Provides $pdo and helper functions
require_once 'includes/auth.php';       // Ensures user is logged in

// --- Role Check ---
if (!isset($_SESSION['active_role']) || $_SESSION['active_role'] !== 'administrator') {
    $_SESSION['flash_message'] = "Access denied. Administrator privileges required.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Determine Active Tab ---
$allowed_tabs = ['site_settings', 'global_questions', 'site_questions', 'notifiers'];
$activeTab = $_GET['tab'] ?? $_SESSION['selected_config_tab'] ?? 'site_settings'; // Default tab
if (!in_array($activeTab, $allowed_tabs)) {
    $activeTab = 'site_settings';
}
$_SESSION['selected_config_tab'] = $activeTab; // Store active tab

// --- Determine Selected Site ID ---
$selected_config_site_id = null;
$sites_list_for_dropdown = [];
try {
    $stmt_sites = $pdo->query("SELECT id, name, is_active FROM sites ORDER BY name ASC");
    $sites_list_for_dropdown = $stmt_sites->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($sites_list_for_dropdown)) {
        $site_id_from_get = filter_input(INPUT_GET, 'site_id', FILTER_VALIDATE_INT);
        $site_id_from_session = $_SESSION['selected_config_site_id'] ?? null;
        $target_site_id = $site_id_from_get ?? $site_id_from_session ?? $sites_list_for_dropdown[0]['id'];
        $found = false;
        foreach ($sites_list_for_dropdown as $site) {
            if ($site['id'] == $target_site_id) {
                $selected_config_site_id = (int)$target_site_id; $found = true; break;
            }
        }
        if (!$found && !empty($sites_list_for_dropdown)) {
            $selected_config_site_id = (int)$sites_list_for_dropdown[0]['id'];
             if ($site_id_from_get) { $_SESSION['flash_message'] = "Invalid site ID specified."; $_SESSION['flash_type'] = 'warning';}
        }
        $_SESSION['selected_config_site_id'] = $selected_config_site_id;
    }
} catch (PDOException $e) {
    error_log("Config Error - Fetching site list for dropdown: " . $e->getMessage());
    $_SESSION['flash_message'] = "Error loading site list."; $_SESSION['flash_type'] = 'error';
}

// --- Flash Message Handling ---
$flash_message = $_SESSION['flash_message'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// --- Handle Form Submissions (POST Requests) ---
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$success = false; // Default success state for POST actions

// --- START: Handle POST request for saving site settings ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_site_settings' && isset($_POST['site_id'])) {
    $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
    if ($posted_site_id && $selected_config_site_id && $posted_site_id == $selected_config_site_id) {
        // Get values from form
        $site_is_active = isset($_POST['site_is_active']) ? 1 : 0;
        $allow_email_collection = isset($_POST['allow_email_collection']) ? 1 : 0;
        $allow_notifier = isset($_POST['allow_notifier']) ? 1 : 0;
        $email_collection_description = trim($_POST['email_collection_description_site'] ?? '');

        $pdo->beginTransaction();
        $success = true; // Assume success initially for this block
        $error_info = '';

        // 1. Update sites table
        try {
             $sql_site = "UPDATE sites SET is_active = :is_active, email_collection_desc = :email_desc WHERE id = :site_id";
             $stmt_site = $pdo->prepare($sql_site);
             if (!$stmt_site->execute([':is_active' => $site_is_active, ':email_desc' => $email_collection_description, ':site_id' => $posted_site_id])) {
                 $success = false; $error_info = $stmt_site->errorInfo()[2] ?? 'Unknown sites UPDATE error';
                 error_log("ERROR POST HANDLER (Site ID: {$posted_site_id}): Failed to update sites table. Error: " . print_r($stmt_site->errorInfo(), true));
             } else { $rows_affected_site = $stmt_site->rowCount(); error_log("SUCCESS POST HANDLER (Site ID: {$posted_site_id}): Updated sites table. Rows affected: {$rows_affected_site}."); }
        } catch (PDOException $e) { $success = false; $error_info = $e->getMessage(); error_log("EXCEPTION POST HANDLER (Site ID: {$posted_site_id}): Failed to update sites table. Exception: " . $e->getMessage()); }

        // 2. Update site_configurations only if sites update succeeded
        if ($success) {
            try {
                 $sql_config = "INSERT INTO site_configurations (site_id, config_key, config_value, created_at, updated_at) VALUES (:site_id, :config_key, :config_value, NOW(), NOW()) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()";
                 $stmt_config = $pdo->prepare($sql_config);
                 // Email config
                 $config_key_email = 'allow_email_collection';
                 if (!$stmt_config->execute([':site_id' => $posted_site_id, ':config_key' => $config_key_email, ':config_value' => $allow_email_collection])) {
                     $success = false; $error_info = $stmt_config->errorInfo()[2] ?? 'UPSERT error (email)';
                     error_log("ERROR POST HANDLER CONFIG UPSERT (Email - Site ID: {$posted_site_id}): Failed. Error: " . print_r($stmt_config->errorInfo(), true));
                 }
                 // Notifier config only if previous succeeded
                 if ($success) {
                     $config_key_notifier = 'allow_notifier';
                     if (!$stmt_config->execute([':site_id' => $posted_site_id, ':config_key' => $config_key_notifier, ':config_value' => $allow_notifier])) {
                         $success = false; $error_info = $stmt_config->errorInfo()[2] ?? 'UPSERT error (notifier)';
                         error_log("ERROR POST HANDLER CONFIG UPSERT (Notifier - Site ID: {$posted_site_id}): Failed. Error: " . print_r($stmt_config->errorInfo(), true));
                     }
                 }
            } catch (PDOException $e) { $success = false; $error_info = $e->getMessage(); error_log("EXCEPTION POST HANDLER CONFIG UPSERT (Site ID: {$posted_site_id}): DB operation failed. Exception: " . $e->getMessage()); }
        }

        // Commit or Rollback
        if ($success) { $pdo->commit(); $_SESSION['flash_message'] = "Site settings updated."; $_SESSION['flash_type'] = 'success'; }
        else { if ($pdo->inTransaction()) { $pdo->rollBack(); } $flash_error = "Failed to update site settings."; if (!empty($error_info)) { $flash_error .= " (Details logged)."; } $_SESSION['flash_message'] = $flash_error; $_SESSION['flash_type'] = 'error'; error_log("ERROR POST HANDLER update_site_settings (Site ID: {$posted_site_id}): Rolled back. Error: {$error_info}"); }

        // Redirect
        $_SESSION['selected_config_site_id'] = $posted_site_id; $_SESSION['selected_config_tab'] = 'site_settings';
        header("Location: configurations.php?tab=site_settings&site_id=" . $posted_site_id); exit;
    } else { /* Handle invalid/mismatched site ID - Set flash message and redirect */
         $_SESSION['flash_message'] = "Error: Invalid site selection during update."; $_SESSION['flash_type'] = 'error';
         header("Location: configurations.php?tab=site_settings"); exit;
    }
}
// --- END: Handle POST request for saving site settings ---

// --- START: Handle other POST actions (Global Q, Site Q, Notifiers) ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
     $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
     $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
     $redirect_tab = $_POST['active_tab_on_submit'] ?? $activeTab;

     $success = false; // Default success state for these actions
     $message = "An error occurred performing the action.";
     $message_type = 'error';
     $transaction_started = false; // Track transaction state for specific actions

     // Transactions are handled WITHIN each case block below

     try {
        switch ($action) {
            // --- Global Question Actions ---
            case 'add_global_question':
                $q_text = trim($_POST['question_text'] ?? '');
                $raw_title_input = trim($_POST['question_title'] ?? ''); // Get raw input
                $base_title_sanitized = sanitize_title_to_base_name($raw_title_input); // Sanitize

                if (!empty($q_text) && !empty($base_title_sanitized)) {
                     $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM global_questions WHERE question_title = :title");
                     $stmt_check->execute([':title' => $base_title_sanitized]); // Check unique standardized title
                     if ($stmt_check->fetchColumn() == 0) {
                          // Create column first (outside transaction)
                          $column_created_or_exists = create_question_column_if_not_exists($pdo, $base_title_sanitized);
                          if ($column_created_or_exists) {
                               $pdo->beginTransaction(); $transaction_started = true;
                               $sql_add_gq = "INSERT INTO global_questions (question_text, question_title) VALUES (:text, :title)";
                               $stmt_add_gq = $pdo->prepare($sql_add_gq);
                               if ($stmt_add_gq->execute([':text' => $q_text, ':title' => $base_title_sanitized])) { // Insert base title
                                    $success = true; $pdo->commit(); $transaction_started = false;
                                    $message = "Global question added successfully (Internal Title: '{$base_title_sanitized}')."; $message_type = 'success';
                                    error_log("Admin Action: Added Global Question '{$base_title_sanitized}'.");
                               } else { $success = false; $message = "Failed to add global question record."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false; }
                          } else { $success = false; $message = "Failed to create corresponding data column 'q_{$base_title_sanitized}'."; /* No transaction */ }
                     } else { $success = false; $message = "A question generating the internal title '{$base_title_sanitized}' already exists."; /* No transaction */ }
                } else { $success = false; $message = "Invalid input. Question Text and a valid Title are required."; /* No transaction */ }
                break; // End case add_global_question

            case 'delete_global_question':
                $column_deleted_successfully = false;
                $base_title_to_delete = null;
                if ($item_id) {
                    $stmt_get_title = $pdo->prepare("SELECT question_title FROM global_questions WHERE id = :id");
                    $stmt_get_title->execute([':id' => $item_id]);
                    $base_title_to_delete = $stmt_get_title->fetchColumn();
                    if ($base_title_to_delete) {
                        $pdo->beginTransaction(); $transaction_started = true;
                        $stmt_del_gq = $pdo->prepare("DELETE FROM global_questions WHERE id = :id");
                        if ($stmt_del_gq->execute([':id' => $item_id])) {
                            $pdo->commit(); $transaction_started = false; $success = true;
                            // Attempt column delete AFTER commit
                            $column_deleted_successfully = delete_question_column_if_unused($pdo, $base_title_to_delete); // Pass base name
                            $message = "Global question '" . htmlspecialchars(format_base_name_for_display($base_title_to_delete)) . "' deleted.";
                            if ($column_deleted_successfully) { $message .= " Associated data column 'q_{$base_title_to_delete}' dropped or did not exist."; error_log("Admin Action: Deleted Global Question ID {$item_id} ('{$base_title_to_delete}'). Column drop successful."); }
                            else { $message .= " <strong style='color:orange;'>Warning:</strong> Could not drop column 'q_{$base_title_to_delete}'. Manual check required."; error_log("Admin Action: Deleted Global Question ID {$item_id} ('{$base_title_to_delete}'). Column drop FAILED."); }
                            $message_type = 'success';
                        } else { $success = false; $message = "Failed to delete global question record."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false; }
                    } else { $success = false; $message = "Global question not found for deletion."; /* No transaction */ }
                } else { $success = false; $message = "Invalid item ID for deletion."; }
                 if (!isset($success)) { $success = false; } // Ensure set
                break; // End case delete_global_question

            // --- Site Question Actions ---
            case 'assign_site_question':
                 $gq_id_to_assign = filter_input(INPUT_POST, 'global_question_id', FILTER_VALIDATE_INT);
                 $assign_is_active = isset($_POST['assign_is_active']) ? 1 : 0;
                 if ($posted_site_id && $gq_id_to_assign) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt_order = $pdo->prepare("SELECT MAX(display_order) FROM site_questions WHERE site_id = :site_id");
                      $stmt_order->execute([':site_id' => $posted_site_id]);
                      $max_order = $stmt_order->fetchColumn() ?? -1; $new_order = $max_order + 1;
                      $sql_assign = "INSERT INTO site_questions (site_id, global_question_id, display_order, is_active) VALUES (:site_id, :gq_id, :order, :active)";
                      $stmt_assign = $pdo->prepare($sql_assign);
                      if ($stmt_assign->execute([':site_id' => $posted_site_id, ':gq_id' => $gq_id_to_assign, ':order' => $new_order, ':active' => $assign_is_active])) {
                           $success = true; $pdo->commit(); $transaction_started = false; $message = "Question assigned to site."; $message_type = 'success'; error_log("Admin Action: Assigned Global Q ID {$gq_id_to_assign} to Site ID {$posted_site_id}.");
                      } else { $success = false; $message = "Failed to assign question (already assigned?)."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false; }
                 } else { $success = false; $message = "Invalid site or question selected for assignment."; }
                 break;

            case 'remove_site_question':
                 if ($posted_site_id && $item_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt_remove = $pdo->prepare("DELETE FROM site_questions WHERE id = :id AND site_id = :site_id");
                      if ($stmt_remove->execute([':id' => $item_id, ':site_id' => $posted_site_id])) {
                           if (reorder_items($pdo, 'site_questions', 'display_order', 'site_id', $posted_site_id)) {
                                $success = true; $pdo->commit(); $transaction_started = false; $message = "Question removed and remaining reordered."; $message_type = 'success'; error_log("Admin Action: Removed Site Q link ID {$item_id} from Site ID {$posted_site_id}.");
                           } else { $success = false; $message = "Question removed, but failed to reorder."; $message_type = 'warning'; if ($transaction_started) $pdo->rollBack(); $transaction_started = false; }
                      } else { $success = false; $message = "Failed to remove question link."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false;}
                 } else { $success = false; $message = "Invalid site or item ID for removal."; }
                 break;

            case 'toggle_site_question_active':
                 if ($posted_site_id && $item_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $sql_toggle = "UPDATE site_questions SET is_active = NOT is_active WHERE id = :id AND site_id = :site_id";
                      $stmt_toggle = $pdo->prepare($sql_toggle);
                      if ($stmt_toggle->execute([':id' => $item_id, ':site_id' => $posted_site_id])) {
                           $success = true; $pdo->commit(); $transaction_started = false; $message = "Question status toggled."; $message_type = 'success';
                      } else { $success = false; $message = "Failed to toggle question status."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false;}
                 } else { $success = false; $message = "Invalid site or item ID for status toggle."; }
                 break;

            case 'move_site_question_up':
            case 'move_site_question_down': // Relies on helper function's transaction
                if ($posted_site_id && $item_id) {
                     $direction = ($action === 'move_site_question_up') ? 'up' : 'down';
                     if(reorder_items($pdo, 'site_questions', 'display_order', 'site_id', $posted_site_id, $item_id, $direction)) {
                         $success = true; $message = "Question reordered."; $message_type = 'success';
                     } else { $success = false; $message = "Failed to reorder question."; }
                } else { $success = false; $message = "Invalid site or item ID for reordering."; }
                break;

            // --- Notifier Actions ---
             case 'add_notifier':
                $staff_name = trim($_POST['staff_name'] ?? '');
                $staff_email = filter_input(INPUT_POST, 'staff_email', FILTER_VALIDATE_EMAIL);
                if ($posted_site_id && !empty($staff_name) && $staff_email) {
                    $pdo->beginTransaction(); $transaction_started = true;
                    $sql_add_n = "INSERT INTO staff_notifications (site_id, staff_name, staff_email, is_active) VALUES (:site_id, :name, :email, 1)";
                    $stmt_add_n = $pdo->prepare($sql_add_n);
                    if($stmt_add_n->execute([':site_id' => $posted_site_id, ':name' => $staff_name, ':email' => $staff_email])) {
                        $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier added."; $message_type = 'success';
                    } else { $success = false; $message = "Failed to add notifier."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false;}
                } else { $success = false; $message = "Invalid input."; }
                break;

            case 'edit_notifier':
                 $staff_name_edit = trim($_POST['staff_name_edit'] ?? '');
                 $staff_email_edit = filter_input(INPUT_POST, 'staff_email_edit', FILTER_VALIDATE_EMAIL);
                 if ($posted_site_id && $item_id && !empty($staff_name_edit) && $staff_email_edit) {
                     $pdo->beginTransaction(); $transaction_started = true;
                     $sql_edit_n = "UPDATE staff_notifications SET staff_name = :name, staff_email = :email WHERE id = :id AND site_id = :site_id";
                     $stmt_edit_n = $pdo->prepare($sql_edit_n);
                     if($stmt_edit_n->execute([':name' => $staff_name_edit, ':email' => $staff_email_edit, ':id' => $item_id, ':site_id' => $posted_site_id])) {
                         $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier updated."; $message_type = 'success';
                     } else { $success = false; $message = "Failed to update notifier."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false;}
                 } else { $success = false; $message = "Invalid input."; }
                 break;

            case 'delete_notifier':
                 if ($posted_site_id && $item_id) {
                     $pdo->beginTransaction(); $transaction_started = true;
                     $sql_del_n = "DELETE FROM staff_notifications WHERE id = :id AND site_id = :site_id";
                     $stmt_del_n = $pdo->prepare($sql_del_n);
                     if($stmt_del_n->execute([':id' => $item_id, ':site_id' => $posted_site_id])) {
                          $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier deleted."; $message_type = 'success';
                     } else { $success = false; $message = "Failed to delete notifier."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false;}
                 } else { $success = false; $message = "Invalid site or item ID."; }
                 break;

            case 'toggle_notifier_active':
                 if ($posted_site_id && $item_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $sql_toggle_n = "UPDATE staff_notifications SET is_active = NOT is_active WHERE id = :id AND site_id = :site_id";
                      $stmt_toggle_n = $pdo->prepare($sql_toggle_n);
                      if ($stmt_toggle_n->execute([':id' => $item_id, ':site_id' => $posted_site_id])) {
                           $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier status toggled."; $message_type = 'success';
                      } else { $success = false; $message = "Failed to toggle notifier status."; if ($transaction_started) $pdo->rollBack(); $transaction_started = false;}
                 } else { $success = false; $message = "Invalid site or item ID."; }
                 break;

            default:
                $success = false; // Mark as failure if action unknown
                $message = "Unknown or invalid action specified.";
                $message_type = 'error';
                break;
        } // End switch ($action)

     } catch (PDOException $e) { // Outer catch for PDO errors during actions
          if (isset($transaction_started) && $transaction_started === true && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to PDOException in action '{$action}'."); }
          $success = false; $message = "Database error processing action '{$action}'. Details logged."; $message_type = 'error';
          error_log("PDOException processing action '{$action}' for site {$posted_site_id}, item {$item_id}: " . $e->getMessage());
     } catch (Exception $e) { // Outer catch for general errors during actions
           if (isset($transaction_started) && $transaction_started === true && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to general Exception in action '{$action}'."); }
          $success = false; $message = "General error processing action '{$action}'. Details logged."; $message_type = 'error';
          error_log("Exception processing action '{$action}' for site {$posted_site_id}, item {$item_id}: " . $e->getMessage());
     }

     // Set flash message and redirect (common for all actions in this block)
     $_SESSION['flash_message'] = $message;
     $_SESSION['flash_type'] = $message_type;
     $_SESSION['selected_config_tab'] = $redirect_tab;
     $redirect_site_id = ($posted_site_id && in_array($redirect_tab, ['site_settings', 'site_questions', 'notifiers'])) ? $posted_site_id : ($selected_config_site_id ?? null);
     $_SESSION['selected_config_site_id'] = $redirect_site_id;
     $redirect_url = "configurations.php?tab=" . urlencode($redirect_tab);
     if ($redirect_site_id !== null) { $redirect_url .= "&site_id=" . $redirect_site_id; }
     header("Location: " . $redirect_url); exit;
}
// --- END: Handle other POST actions ---


// =========================================================================
// --- Fetch Data for Display ---
// =========================================================================

$global_questions_list = []; // For display (array of arrays)
$site_questions_assigned = []; // Holds questions assigned to the selected site
$site_questions_available = []; // Holds global questions *not* assigned to selected site
$notifiers = [];
$config_error_message = ''; // Store errors during fetch

$selected_site_details = null; // Holds name, is_active, desc from 'sites' table
$site_allow_email = 0; // Holds config value for allow_email_collection (0 or 1)
$site_allow_notifier = 0; // Holds config value for allow_notifier (0 or 1)

// 1. Fetch Global Questions (needed regardless of selected site)
try {
    $stmt_gq = $pdo->query("SELECT id, question_text, question_title FROM global_questions ORDER BY question_title ASC"); // Fetch base title
    $global_questions_list = $stmt_gq->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Config Error - Fetching Global Qs List: " . $e->getMessage());
    $config_error_message .= " Error loading global questions."; $global_questions_list = [];
}

// 2. Fetch data specific to the selected site ID
if ($selected_config_site_id !== null) {
     try {
         // Fetch site details directly
         $stmt_site_read = $pdo->prepare("SELECT id, name, email_collection_desc, is_active FROM sites WHERE id = :id");
         $stmt_site_read->execute([':id' => $selected_config_site_id]);
         $selected_site_details = $stmt_site_read->fetch(PDO::FETCH_ASSOC);

         if ($selected_site_details) {
             $selected_site_details['is_active'] = (int)$selected_site_details['is_active'];
             // Fetch boolean config settings directly
             $stmt_config_read = $pdo->prepare("SELECT config_key, config_value FROM site_configurations WHERE site_id = :id AND config_key IN ('allow_email_collection', 'allow_notifier')");
             $stmt_config_read->execute([':id' => $selected_config_site_id]);
             $configs = $stmt_config_read->fetchAll(PDO::FETCH_KEY_PAIR);
             $site_allow_email = isset($configs['allow_email_collection']) ? (int)$configs['allow_email_collection'] : 0;
             $site_allow_notifier = isset($configs['allow_notifier']) ? (int)$configs['allow_notifier'] : 0;
             error_log("READING settings for Site ID {$selected_config_site_id}: Active={$selected_site_details['is_active']}, AllowEmail={$site_allow_email}, AllowNotify={$site_allow_notifier}");

              // Fetch Site-Specific Questions (Assigned to this site)
              $sql_sq = "SELECT sq.id as site_question_id, sq.global_question_id, sq.display_order, sq.is_active,
                              gq.question_text, gq.question_title /* base title */
                       FROM site_questions sq JOIN global_questions gq ON sq.global_question_id = gq.id
                       WHERE sq.site_id = :site_id ORDER BY sq.display_order ASC";
              $stmt_sq = $pdo->prepare($sql_sq); $stmt_sq->execute([':site_id' => $selected_config_site_id]);
              $site_questions_assigned = $stmt_sq->fetchAll(PDO::FETCH_ASSOC);

              // Determine Available Global Questions (using $global_questions_list)
              $assigned_gq_ids = array_column($site_questions_assigned, 'global_question_id');
              $site_questions_available = [];
              if (!empty($global_questions_list)) {
                  foreach ($global_questions_list as $gq_data) {
                      if (!in_array($gq_data['id'], $assigned_gq_ids)) { $site_questions_available[$gq_data['id']] = $gq_data; }
                  }
              }

              // Fetch Notifiers
              $stmt_n = $pdo->prepare("SELECT id, staff_name, staff_email, is_active FROM staff_notifications WHERE site_id = :site_id ORDER BY staff_name ASC");
              $stmt_n->execute([':site_id' => $selected_config_site_id]);
              $notifiers = $stmt_n->fetchAll(PDO::FETCH_ASSOC);
         } else {
             error_log("Config Error - Site details not found for ID {$selected_config_site_id}");
             $config_error_message .= " Could not load details for the selected site."; $selected_config_site_id = null;
             $selected_site_details = null; $site_allow_email = 0; $site_allow_notifier = 0; $site_questions_assigned = []; $notifiers = [];
             // All global questions are available if site invalid
             if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { $site_questions_available[$gq_data['id']] = $gq_data; }}
         }
     } catch (PDOException $e) {
          error_log("Config Error - Fetching site-specific data for site {$selected_config_site_id}: " . $e->getMessage());
          $config_error_message .= " Error loading data for the selected site.";
          $selected_site_details = null; $site_allow_email = 0; $site_allow_notifier = 0; $site_questions_assigned = []; $notifiers = [];
          if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { $site_questions_available[$gq_data['id']] = $gq_data; }}
     }
} else { // No site selected
     if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { $site_questions_available[$gq_data['id']] = $gq_data; }}
}
// --- End Data Fetching ---


// --- Page Setup & Header ---
$pageTitle = "Configurations";
require_once 'includes/header.php';

// --- Notifier Edit View Data Fetch ---
$view_state = $_GET['view'] ?? 'list';
$edit_item_id = filter_input(INPUT_GET, 'edit_item_id', FILTER_VALIDATE_INT);
$edit_item_data = null;
if ($activeTab === 'notifiers' && $view_state === 'edit_notifier' && $edit_item_id && $selected_config_site_id) {
    try {
         $stmt_edit_n = $pdo->prepare("SELECT * FROM staff_notifications WHERE id = :id AND site_id = :site_id");
         $stmt_edit_n->execute([':id' => $edit_item_id, ':site_id' => $selected_config_site_id]);
         $edit_item_data = $stmt_edit_n->fetch(PDO::FETCH_ASSOC);
         if(!$edit_item_data) { $_SESSION['flash_message'] = "Notifier not found."; $_SESSION['flash_type'] = 'warning'; header('Location: configurations.php?site_id='.$selected_config_site_id.'&tab=notifiers'); exit; }
     } catch (PDOException $e) { $_SESSION['flash_message'] = "DB error fetching notifier."; $_SESSION['flash_type'] = 'error'; error_log("Error fetching notifier ID {$edit_item_id} for edit: ".$e->getMessage()); header('Location: configurations.php?site_id='.$selected_config_site_id.'&tab=notifiers'); exit; }
}
?>

            <!-- Page Header Section -->
            <div class="header">
                 <!-- Site Selector Dropdown -->
                 <?php if (!empty($sites_list_for_dropdown)): ?>
                 <div class="site-selector">
                     <label for="config-site-select">Configure Site:</label>
                     <select id="config-site-select" name="site_id_selector"
                             onchange="location = 'configurations.php?site_id=' + this.value + '&tab=<?php echo urlencode($activeTab); ?>';">
                         <?php foreach ($sites_list_for_dropdown as $site): ?>
                            <option value="<?php echo $site['id']; ?>" <?php echo ($site['id'] == $selected_config_site_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?> <?php echo !$site['is_active'] ? '(Inactive)' : ''; ?>
                            </option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                 <?php else: ?>
                    <div class="message-area message-warning">No active sites found in the system. Some configuration options may be limited.</div>
                 <?php endif; ?>
             </div>

             <!-- Display Flash Messages -->
             <?php if ($flash_message): ?> <div class="message-area message-<?php echo htmlspecialchars($flash_type); ?>"><?php echo $flash_message; ?></div> <?php endif; ?>
             <?php if ($config_error_message): ?> <div class="message-area message-error"><?php echo htmlspecialchars($config_error_message); ?></div> <?php endif; ?>

             <!-- Main Configuration Area with Tabs -->
             <div class="admin-settings">
                 <!-- Tab Links -->
                 <div class="tabs">
                    <div class="tab <?php echo ($activeTab === 'site_settings') ? 'active' : ''; ?>" data-tab="site-settings">Site Settings</div>
<div class="tab <?php echo ($activeTab === 'global_questions') ? 'active' : ''; ?>" data-tab="global-questions">Global Questions</div>
<div class="tab <?php echo ($activeTab === 'site_questions') ? 'active' : ''; ?>" data-tab="site-questions">Site Questions</div>
<div class="tab <?php echo ($activeTab === 'notifiers') ? 'active' : ''; ?>" data-tab="notifiers">Email Notifiers</div>
                 </div>

                 <!-- Tab Content Panes -->
                 <div id="tab-content">
                     <!-- Site Settings Tab Pane -->
                     <div class="tab-pane <?php echo ($activeTab === 'site_settings') ? 'active' : ''; ?>" id="site-settings">
                         <?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
                             <div class="settings-section">
                                 <h3 class="settings-section-title">Settings for <?php echo htmlspecialchars($selected_site_details['name']); ?></h3>
                                 <form method="POST" action="configurations.php?tab=site_settings&site_id=<?php echo $selected_config_site_id; ?>">
                                     <input type="hidden" name="action" value="update_site_settings"> <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                     <div class="settings-form two-column">
                                         <!-- Toggles using $selected_site_details, $site_allow_email, $site_allow_notifier -->
                                         <div class="form-group"><label class="form-label">Site Status</label><div class="toggle-switch"><?php $site_active_flag = ($selected_site_details['is_active'] == 1); ?><input type="checkbox" id="site_is_active" name="site_is_active" value="1" <?php echo $site_active_flag ? 'checked' : ''; ?>><label for="site_is_active" class="toggle-label"><span class="toggle-button"></span></label><span class="toggle-text"><?php echo $site_active_flag ? 'Active' : 'Inactive'; ?></span></div></div>
                                         <div class="form-group"><label class="form-label">Allow Client Email Collection?</label><div class="toggle-switch"><?php $email_enabled_flag = ($site_allow_email == 1); ?><input type="checkbox" id="allow_email_collection" name="allow_email_collection" value="1" <?php echo $email_enabled_flag ? 'checked' : ''; ?>><label for="allow_email_collection" class="toggle-label"><span class="toggle-button"></span></label><span class="toggle-text"><?php echo $email_enabled_flag ? 'Enabled' : 'Disabled'; ?></span></div></div>
                                         <div class="form-group"><label class="form-label">Allow Staff Notifier Selection?</label><div class="toggle-switch"><?php $notifier_enabled_flag = ($site_allow_notifier == 1); ?><input type="checkbox" id="allow_notifier" name="allow_notifier" value="1" <?php echo $notifier_enabled_flag ? 'checked' : ''; ?>><label for="allow_notifier" class="toggle-label"><span class="toggle-button"></span></label><span class="toggle-text"><?php echo $notifier_enabled_flag ? 'Enabled' : 'Disabled'; ?></span></div></div>
                                         <div class="form-group full-width" id="email-desc-group" style="<?php echo !$email_enabled_flag ? 'display: none;' : ''; ?>"><label for="email_desc_site" class="form-label">Email Collection Description</label><textarea id="email_desc_site" name="email_collection_description_site" class="form-control" rows="2"><?php echo htmlspecialchars($selected_site_details['email_collection_desc'] ?? ''); ?></textarea><p class="form-description">Text displayed above the optional email input on the check-in form.</p></div>
                                     </div>
                                     <div class="form-actions"><button type="submit" class="btn btn-primary" name="save_site_settings" value="1"><i class="fas fa-save"></i> Save Site Settings</button></div>
                                 </form>
                             </div>
                         <?php else: ?> <div class="message-area message-info">Please select a site to configure its settings.</div> <?php endif; ?>
                     </div>

                     <!-- Global Question Library Tab Pane -->
                     <div class="tab-pane <?php echo ($activeTab === 'global_questions') ? 'active' : ''; ?>" id="global-questions">
                         <div class="settings-section">
                             <h3 class="settings-section-title">Global Question Library</h3>
                             <p>Define unique questions. The 'Question Title' will be standardized (e.g., "Needs Resume" becomes <code>needs_resume</code>) for internal use and determines the column name <code>q_needs_resume</code>. The standardized title must be unique.</p>
                             <!-- Add Global Question Form -->
                             <div class="admin-form-container form-section">
                                 <h4 class="form-section-title">Add New Global Question</h4>
                                 <form method="POST" action="configurations.php?tab=global_questions<?php echo $selected_config_site_id ? '&site_id='.$selected_config_site_id : ''; ?>">
                                     <input type="hidden" name="action" value="add_global_question">
                                     <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                                     <div class="settings-form two-column">
                                          <div class="form-group">
                                              <label for="add_gq_title" class="form-label">Question Title:</label>
                                              <input type="text" id="add_gq_title" name="question_title" class="form-control" required maxlength="50" title="Use letters, numbers, spaces. Max 50 chars. Will be standardized.">
                                              <p class="form-description">Enter a descriptive title (e.g., 'Needs Resume Assistance'). Standardized version must be unique.</p>
                                          </div>
                                         <div class="form-group full-width"><label for="add_gq_text" class="form-label">Full Question Text (Displayed to Client):</label><textarea id="add_gq_text" name="question_text" class="form-control" rows="2" required></textarea></div>
                                     </div>
                                     <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Global Question</button></div>
                                 </form>
                             </div>
                             <!-- List Global Questions -->
                             <h4 class="form-section-title">Existing Global Questions</h4>
                             <div class="table-container">
                                 <table>
                                     <thead><tr><th>Display Label</th><th>Internal Title</th><th>Question Text</th><th>Actions</th></tr></thead>
                                     <tbody>
                                         <?php if (!empty($global_questions_list)): ?>
                                             <?php foreach ($global_questions_list as $gq): ?>
                                                 <tr>
                                                     <td><?php echo htmlspecialchars(format_base_name_for_display($gq['question_title'])); ?></td>
                                                     <td><code><?php echo htmlspecialchars($gq['question_title']); ?></code></td>
                                                     <td><?php echo htmlspecialchars($gq['question_text']); ?></td>
                                                     <td class="actions-cell">
                                                         <form method="POST" action="configurations.php?tab=global_questions<?php echo $selected_config_site_id ? '&site_id='.$selected_config_site_id : ''; ?>" onsubmit="return confirm('WARNING: Deleting question \'<?php echo htmlspecialchars(format_base_name_for_display($gq['question_title'])); ?>\' removes it from ALL sites and attempts to delete its data column `q_<?php echo htmlspecialchars($gq['question_title']); ?>`. Are you sure?');">
                                                             <input type="hidden" name="action" value="delete_global_question"><input type="hidden" name="item_id" value="<?php echo $gq['id']; ?>">
                                                             <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                                                             <button type="submit" class="btn btn-outline btn-sm delete-button" title="Delete Global Question"><i class="fas fa-trash"></i></button>
                                                         </form>
                                                     </td>
                                                 </tr>
                                             <?php endforeach; ?>
                                         <?php else: ?> <tr><td colspan="4" style="text-align: center;">No global questions defined.</td></tr> <?php endif; ?>
                                     </tbody>
                                 </table>
                             </div>
                         </div>
                     </div>

                     <!-- Site Question Assignment Tab Pane -->
                     <div class="tab-pane <?php echo ($activeTab === 'site_questions') ? 'active' : ''; ?>" id="site-questions">
                          <?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
                             <div class="settings-section">
                                 <h3 class="settings-section-title">Assign/Manage Questions for: <?php echo htmlspecialchars($selected_site_details['name']); ?></h3>
                                 <p>Select questions from the Global Library to use on this site's check-in form. Order matters.</p>
                                 <!-- Assign Question Form -->
                                 <div class="admin-form-container form-section">
                                      <h4 class="form-section-title">Assign Global Question</h4>
                                      <form method="POST" action="configurations.php?tab=site_questions&site_id=<?php echo $selected_config_site_id; ?>">
                                          <input type="hidden" name="action" value="assign_site_question"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                          <div class="settings-form two-column">
                                              <div class="form-group">
                                                  <label for="assign_gq_id" class="form-label">Available Questions:</label>
                                                  <select id="assign_gq_id" name="global_question_id" class="form-control" required <?php echo empty($site_questions_available) ? 'disabled' : ''; ?>>
                                                      <option value="">-- Choose Question --</option>
                                                      <?php foreach ($site_questions_available as $gq_id => $gq_data): ?>
                                                          <option value="<?php echo $gq_id; ?>">[<?php echo htmlspecialchars(format_base_name_for_display($gq_data['question_title'])); ?>] <?php echo htmlspecialchars(substr($gq_data['question_text'], 0, 60)) . (strlen($gq_data['question_text']) > 60 ? '...' : ''); ?></option>
                                                      <?php endforeach; ?>
                                                      <?php if (empty($site_questions_available)): ?> <option value="" disabled>All global questions assigned</option> <?php endif; ?>
                                                  </select>
                                              </div>
                                               <div class="form-group" style="align-self: end;"> <label class="form-check-label"><input type="checkbox" name="assign_is_active" value="1" checked class="form-check-input"> Active on Check-in form?</label> </div>
                                          </div>
                                          <div class="form-actions"> <button type="submit" class="btn btn-primary" <?php echo empty($site_questions_available) ? 'disabled' : ''; ?>><i class="fas fa-plus"></i> Assign to Site</button> </div>
                                      </form>
                                 </div>
                                 <!-- List Assigned Site Questions -->
                                 <h4 class="form-section-title">Questions Currently Assigned (Order affects display)</h4>
                                 <div class="table-container">
                                     <table>
                                         <thead><tr><th>Order</th><th>Display Label</th><th>Internal Title</th><th>Question Text</th><th>Status</th><th>Actions</th></tr></thead>
                                         <tbody>
                                             <?php $sq_count = count($site_questions_assigned); if ($sq_count > 0): ?>
                                                 <?php foreach ($site_questions_assigned as $i => $sq): ?>
                                                     <tr>
                                                         <td><?php echo htmlspecialchars($sq['display_order']); ?></td>
                                                         <td><?php echo htmlspecialchars(format_base_name_for_display($sq['question_title'])); ?></td>
                                                         <td><code><?php echo htmlspecialchars($sq['question_title']); ?></code></td>
                                                         <td><?php echo htmlspecialchars($sq['question_text']); ?></td>
                                                         <td><span class="status-badge <?php echo $sq['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $sq['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                                         <td class="actions-cell">
                                                              <?php if ($i > 0): ?><form method="POST" action="configurations.php?tab=site_questions&site_id=<?php echo $selected_config_site_id; ?>"><input type="hidden" name="action" value="move_site_question_up"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><button type="submit" class="btn btn-outline btn-sm" title="Move Up"><i class="fas fa-arrow-up"></i></button></form><?php endif; ?>
                                                              <?php if ($i < $sq_count - 1): ?><form method="POST" action="configurations.php?tab=site_questions&site_id=<?php echo $selected_config_site_id; ?>"><input type="hidden" name="action" value="move_site_question_down"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><button type="submit" class="btn btn-outline btn-sm" title="Move Down"><i class="fas fa-arrow-down"></i></button></form><?php endif; ?>
                                                              <form method="POST" action="configurations.php?tab=site_questions&site_id=<?php echo $selected_config_site_id; ?>"><input type="hidden" name="action" value="toggle_site_question_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $sq['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $sq['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
                                                              <form method="POST" action="configurations.php?tab=site_questions&site_id=<?php echo $selected_config_site_id; ?>" onsubmit="return confirm('Remove this question from this site?');"><input type="hidden" name="action" value="remove_site_question"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Remove from Site"><i class="fas fa-unlink"></i></button></form>
                                                         </td>
                                                     </tr>
                                                 <?php endforeach; ?>
                                             <?php else: ?> <tr><td colspan="6" style="text-align: center;">No questions assigned.</td></tr> <?php endif; ?>
                                         </tbody>
                                     </table>
                                 </div>
                             </div>
                         <?php else: ?> <div class="message-area message-info">Please select a site to manage its questions.</div> <?php endif; ?>
                     </div>

                      <!-- Notifier Management Tab Pane -->
                     <div class="tab-pane <?php echo ($activeTab === 'notifiers') ? 'active' : ''; ?>" id="notifiers">
                          <?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
                             <div class="settings-section">
                                 <h3 class="settings-section-title">Manage Email Notifiers for: <?php echo htmlspecialchars($selected_site_details['name']); ?></h3>
                                 <!-- Add/Edit Forms -->
                                <?php if($view_state === 'add_notifier'): ?>
                                     <div class="admin-form-container form-section">
                                         <h4 class="subsection-title">Add New Notifier</h4>
                                         <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>">
                                              <input type="hidden" name="action" value="add_notifier"> <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                             <div class="settings-form two-column">
                                                 <div class="form-group"><label for="add_n_name" class="form-label">Staff Name:</label><input type="text" id="add_n_name" name="staff_name" class="form-control" required></div>
                                                 <div class="form-group"><label for="add_n_email" class="form-label">Staff Email:</label><input type="email" id="add_n_email" name="staff_email" class="form-control" required></div>
                                             </div>
                                             <div class="form-actions"> <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Notifier</button> <a href="configurations.php?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers" class="btn btn-outline">Cancel</a> </div>
                                         </form>
                                     </div>
                                <?php elseif($view_state === 'edit_notifier' && $edit_item_data): ?>
                                      <div class="admin-form-container form-section">
                                          <h4 class="subsection-title">Edit Notifier</h4>
                                          <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>">
                                               <input type="hidden" name="action" value="edit_notifier"> <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"> <input type="hidden" name="item_id" value="<?php echo $edit_item_data['id']; ?>">
                                              <div class="settings-form two-column">
                                                  <div class="form-group"><label for="edit_n_name" class="form-label">Staff Name:</label><input type="text" id="edit_n_name" name="staff_name_edit" class="form-control" required value="<?php echo htmlspecialchars($edit_item_data['staff_name']); ?>"></div>
                                                  <div class="form-group"><label for="edit_n_email" class="form-label">Staff Email:</label><input type="email" id="edit_n_email" name="staff_email_edit" class="form-control" required value="<?php echo htmlspecialchars($edit_item_data['staff_email']); ?>"></div>
                                              </div>
                                              <div class="form-actions"> <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button> <a href="configurations.php?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers" class="btn btn-outline">Cancel</a> </div>
                                          </form>
                                      </div>
                                <?php endif; ?>
                                 <!-- Notifier List Table -->
                                 <?php if($view_state === 'list'): ?>
                                 <h4 class="form-section-title">Existing Notifiers</h4>
                                 <div class="table-container">
                                     <table>
                                         <thead><tr><th>Staff Name</th><th>Email Address</th><th>Status</th><th>Actions</th></tr></thead>
                                         <tbody>
                                             <?php if (!empty($notifiers)): ?>
                                                 <?php foreach ($notifiers as $n): ?>
                                                      <tr>
                                                         <td><?php echo htmlspecialchars($n['staff_name']); ?></td>
                                                         <td><?php echo htmlspecialchars($n['staff_email']); ?></td>
                                                         <td><span class="status-badge <?php echo $n['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $n['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                                         <td class="actions-cell">
                                                              <a href="?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers&view=edit_notifier&edit_item_id=<?php echo $n['id']; ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                                              <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>"><input type="hidden" name="action" value="toggle_notifier_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $n['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $n['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
                                                              <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>" onsubmit="return confirm('Delete this notifier?');"><input type="hidden" name="action" value="delete_notifier"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Delete"><i class="fas fa-trash"></i></button></form>
                                                         </td>
                                                     </tr>
                                                 <?php endforeach; ?>
                                             <?php else: ?> <tr><td colspan="4" style="text-align: center;">No notifiers defined.</td></tr> <?php endif; ?>
                                         </tbody>
                                     </table>
                                 </div>
                                 <div class="form-actions"> <a href="?site_id=<?php echo $selected_config_site_id; ?>&view=add_notifier&tab=notifiers" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Notifier</a> </div>
                                 <?php endif; ?>
                             </div>
                          <?php else: ?> <div class="message-area message-info">Please select a site to manage its notifiers.</div> <?php endif; ?>
                     </div>

                 </div> <!-- End #tab-content -->
             </div> <!-- End .admin-settings -->

    <!-- ================================================== -->
    <!-- START: JavaScript for Tab Functionality          -->
    <!-- ================================================== -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tabs .tab');
            const panes = document.querySelectorAll('#tab-content .tab-pane');
            const siteSelector = document.getElementById('config-site-select');

            function activateTab(tabToActivate) {
                if (!tabToActivate) return;
                const targetPaneId = tabToActivate.getAttribute('data-tab');
                const targetPane = document.getElementById(targetPaneId);
                tabs.forEach(t => t.classList.remove('active'));
                panes.forEach(p => p.classList.remove('active'));
                tabToActivate.classList.add('active');
                if (targetPane) { targetPane.classList.add('active'); }
                 else { console.error("Tab pane with ID '" + targetPaneId + "' not found."); }
                 try { /* Update URL logic */
                     const currentUrl = new URL(window.location.href);
                     currentUrl.searchParams.set('tab', targetPaneId);
                     const currentSiteId = siteSelector ? siteSelector.value : null;
                     if (['site_settings', 'site_questions', 'notifiers'].includes(targetPaneId)) { if (currentSiteId) currentUrl.searchParams.set('site_id', currentSiteId); else currentUrl.searchParams.delete('site_id'); }
                     else { currentUrl.searchParams.delete('site_id'); }
                     currentUrl.searchParams.delete('view'); currentUrl.searchParams.delete('edit_item_id');
                     window.history.replaceState({ path: currentUrl.toString() }, '', currentUrl.toString());
                 } catch (e) { console.error("Error updating URL:", e); }
            }

            tabs.forEach(tab => { tab.addEventListener('click', function(e) { e.preventDefault(); activateTab(this); }); });

             if (siteSelector) {
                 siteSelector.onchange = function() {
                     const selectedSiteId = this.value; const activeTabLink = document.querySelector('.tabs .tab.active');
                     const activeTabId = activeTabLink ? activeTabLink.getAttribute('data-tab') : 'site_settings';
                     location.href = `configurations.php?site_id=${selectedSiteId}&tab=${activeTabId}`;
                 };
             }
            const initialActiveTab = document.querySelector('.tabs .tab.active');
            if (initialActiveTab) { activateTab(initialActiveTab); }
            else if (tabs.length > 0) { activateTab(tabs[0]); }
        });
    </script>
    <!-- ================================================== -->
    <!-- END: JavaScript for Tab Functionality            -->
    <!-- ================================================== -->

     <!-- JS for Conditional Email Description & Toggle Text Update -->
     <script>
         document.addEventListener('DOMContentLoaded', function() {
             const emailToggle = document.getElementById('allow_email_collection');
             const descriptionGroup = document.getElementById('email-desc-group');
             function toggleDescriptionVisibility() { if (emailToggle && descriptionGroup) { descriptionGroup.style.display = emailToggle.checked ? 'block' : 'none'; } }
             function updateToggleText(checkboxElement) {
                 if (!checkboxElement) return; const parentSwitch = checkboxElement.closest('.toggle-switch'); if (!parentSwitch) return;
                 const textSpan = parentSwitch.querySelector('.toggle-text'); if (!textSpan) return;
                 if (checkboxElement.id === 'site_is_active') { textSpan.textContent = checkboxElement.checked ? 'Active' : 'Inactive'; }
                 else { textSpan.textContent = checkboxElement.checked ? 'Enabled' : 'Disabled'; }
             }
             if (emailToggle) { emailToggle.addEventListener('change', toggleDescriptionVisibility); }
             document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(toggle => {
                 toggle.addEventListener('change', function() { updateToggleText(this); if (this.id === 'allow_email_collection') { toggleDescriptionVisibility(); } });
             });
             // Initial state handled by PHP
         });
     </script>
     <!-- Remove Inline <style> block if it exists -->

<?php require_once 'includes/footer.php'; ?>