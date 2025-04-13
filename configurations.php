<?php
/*
 * File: configurations.php
 * Path: /configurations.php
 * Created: 2024-08-01 13:30:00 MST
 * Updated: 2025-04-13 - Integrated full Ads Management features.
 * Description: Administrator page for managing system configurations including
 *              sites, global questions, site questions, notifiers, and ads.
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
$allowed_tabs = ['site_settings', 'global_questions', 'site_questions', 'notifiers', 'ads_management'];
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

// =========================================================================
// --- START: Handle Form Submissions (POST Requests) ---
// =========================================================================
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$success = false; // Default success state for POST actions

// --- Logic specifically for Update Site Settings (simpler structure) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_site_settings' && isset($_POST['site_id'])) {
    $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
    // Ensure the POSTed site_id matches the one currently selected for configuration
    if ($posted_site_id && $selected_config_site_id && $posted_site_id == $selected_config_site_id) {
        // Get values from form
        $site_is_active = isset($_POST['site_is_active']) ? 1 : 0;
        $allow_email_collection = isset($_POST['allow_email_collection']) ? 1 : 0;
        $allow_notifier = isset($_POST['allow_notifier']) ? 1 : 0;
        $email_collection_description = trim($_POST['email_collection_description_site'] ?? '');

        $pdo->beginTransaction();
        $success = true; $error_info = '';

        // 1. Update sites table
        try {
             $sql_site = "UPDATE sites SET is_active = :is_active, email_collection_desc = :email_desc WHERE id = :site_id";
             $stmt_site = $pdo->prepare($sql_site);
             if (!$stmt_site->execute([':is_active' => $site_is_active, ':email_desc' => $email_collection_description, ':site_id' => $posted_site_id])) {
                 $success = false; $error_info = $stmt_site->errorInfo()[2] ?? 'Unknown sites UPDATE error';
                 error_log("ERROR POST HANDLER (Site ID: {$posted_site_id}): Failed to update sites table. Error: " . print_r($stmt_site->errorInfo(), true));
             } else { error_log("SUCCESS POST HANDLER (Site ID: {$posted_site_id}): Updated sites table. Rows affected: {$stmt_site->rowCount()}."); }
        } catch (PDOException $e) { $success = false; $error_info = $e->getMessage(); error_log("EXCEPTION POST HANDLER (Site ID: {$posted_site_id}): Failed to update sites table. Exception: " . $e->getMessage()); }

        // 2. Update site_configurations (UPSERT) only if sites update succeeded
        if ($success) {
            try {
                 $sql_config = "INSERT INTO site_configurations (site_id, config_key, config_value, created_at, updated_at) VALUES (:site_id, :config_key, :config_value, NOW(), NOW()) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()";
                 $stmt_config = $pdo->prepare($sql_config);
                 // Email config
                 if (!$stmt_config->execute([':site_id' => $posted_site_id, ':config_key' => 'allow_email_collection', ':config_value' => $allow_email_collection])) {
                     $success = false; $error_info = $stmt_config->errorInfo()[2] ?? 'UPSERT error (email)';
                     error_log("ERROR POST HANDLER CONFIG UPSERT (Email - Site ID: {$posted_site_id}): Failed. Error: " . print_r($stmt_config->errorInfo(), true));
                 }
                 // Notifier config only if previous succeeded
                 if ($success) {
                     if (!$stmt_config->execute([':site_id' => $posted_site_id, ':config_key' => 'allow_notifier', ':config_value' => $allow_notifier])) {
                         $success = false; $error_info = $stmt_config->errorInfo()[2] ?? 'UPSERT error (notifier)';
                         error_log("ERROR POST HANDLER CONFIG UPSERT (Notifier - Site ID: {$posted_site_id}): Failed. Error: " . print_r($stmt_config->errorInfo(), true));
                     }
                 }
            } catch (PDOException $e) { $success = false; $error_info = $e->getMessage(); error_log("EXCEPTION POST HANDLER CONFIG UPSERT (Site ID: {$posted_site_id}): DB operation failed. Exception: " . $e->getMessage()); }
        }

        // Commit or Rollback
        if ($success) { $pdo->commit(); $_SESSION['flash_message'] = "Site settings updated."; $_SESSION['flash_type'] = 'success'; }
        else { if ($pdo->inTransaction()) { $pdo->rollBack(); } $flash_error = "Failed to update site settings."; if (!empty($error_info)) { $flash_error .= " (Details logged)."; } $_SESSION['flash_message'] = $flash_error; $_SESSION['flash_type'] = 'error'; error_log("ERROR POST HANDLER update_site_settings (Site ID: {$posted_site_id}): Rolled back. Error: {$error_info}"); }

        // Redirect back to the same tab and site
        $_SESSION['selected_config_site_id'] = $posted_site_id; $_SESSION['selected_config_tab'] = 'site_settings';
        header("Location: configurations.php?tab=site_settings&site_id=" . $posted_site_id); exit;
    } else {
         $_SESSION['flash_message'] = "Error: Invalid site selection during update."; $_SESSION['flash_type'] = 'error';
         header("Location: configurations.php?tab=site_settings"); exit; // Redirect back to default view for the tab
    }
}
// --- END: Handle POST request for saving site settings ---

// --- START: Handle all other POST actions via Switch ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
     $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
     $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT); // Used for deletes, toggles, moves
     $redirect_tab = $_POST['active_tab_on_submit'] ?? $activeTab; // Get tab from hidden input

     $success = false;
     $message = "An error occurred performing the action.";
     $message_type = 'error';
     $transaction_started = false;
     // Initialize variables for potential file cleanup
     $destination_path_physical = null;
     $unique_filename = null;
     $uploaded_image_path_db = null;

     try {
        switch ($action) {
            // ========================
            // Global Question Actions
            // ========================
            case 'add_global_question':
                $q_text = trim($_POST['question_text'] ?? '');
                $raw_title_input = trim($_POST['question_title'] ?? '');
                $base_title_sanitized = sanitize_title_to_base_name($raw_title_input);

                if (!empty($q_text) && !empty($base_title_sanitized)) {
                     $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM global_questions WHERE question_title = :title");
                     $stmt_check->execute([':title' => $base_title_sanitized]);
                     if ($stmt_check->fetchColumn() == 0) {
                          $column_created_or_exists = create_question_column_if_not_exists($pdo, $base_title_sanitized);
                          if ($column_created_or_exists) {
                               $pdo->beginTransaction(); $transaction_started = true;
                               $sql_add_gq = "INSERT INTO global_questions (question_text, question_title) VALUES (:text, :title)";
                               $stmt_add_gq = $pdo->prepare($sql_add_gq);
                               if ($stmt_add_gq->execute([':text' => $q_text, ':title' => $base_title_sanitized])) {
                                    $success = true; $pdo->commit(); $transaction_started = false; $message = "Global question added successfully (Internal Title: '{$base_title_sanitized}')."; $message_type = 'success'; error_log("Admin Action: Added Global Question '{$base_title_sanitized}'.");
                               } else { $message = "Failed to add global question record."; }
                          } else { $message = "Failed to create corresponding data column 'q_{$base_title_sanitized}'."; }
                     } else { $message = "A question generating the internal title '{$base_title_sanitized}' already exists."; }
                } else { $message = "Invalid input. Question Text and a valid Title are required."; }
                if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; } // Rollback if needed
                break;

            case 'delete_global_question':
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
                            $column_deleted = delete_question_column_if_unused($pdo, $base_title_to_delete);
                            $message = "Global question '" . htmlspecialchars(format_base_name_for_display($base_title_to_delete)) . "' deleted.";
                            if ($column_deleted) { $message .= " Associated data column dropped or did not exist."; error_log("Admin Action: Deleted Global Question ID {$item_id} ('{$base_title_to_delete}'). Column drop successful."); }
                            else { $message .= " <strong style='color:orange;'>Warning:</strong> Could not drop column 'q_{$base_title_to_delete}'. Manual check required."; error_log("Admin Action: Deleted Global Question ID {$item_id} ('{$base_title_to_delete}'). Column drop FAILED."); }
                            $message_type = 'success';
                        } else { $message = "Failed to delete global question record."; }
                    } else { $message = "Global question not found for deletion."; }
                } else { $message = "Invalid item ID for deletion."; }
                if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                break;

            // ========================
            // Global Ad Actions
            // ========================
            case 'add_global_ad':
                 $ad_type = $_POST['ad_type'] ?? null; $ad_title = trim($_POST['ad_title'] ?? ''); $ad_text = trim($_POST['ad_text'] ?? ''); $is_active = isset($_POST['is_active']) ? 1 : 0; $image_file = $_FILES['ad_image'] ?? null;
                 if (!in_array($ad_type, ['text', 'image'])) { $message = "Invalid ad type."; break; }
                 if ($ad_type === 'text' && empty($ad_text)) { $message = "Text content required."; break; }
                 if ($ad_type === 'image' && (empty($image_file) || $image_file['error'] === UPLOAD_ERR_NO_FILE)) { $message = "Image file required."; break; }

                 if ($ad_type === 'image' && $image_file && $image_file['error'] === UPLOAD_ERR_OK) {
                      $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']; $max_size = 2 * 1024 * 1024; // 2 MB
                      if (!in_array($image_file['type'], $allowed_types)) { $message = "Invalid image type."; break; }
                      if ($image_file['size'] > $max_size) { $message = "Image size exceeds limit."; break; }
                      $extension = pathinfo($image_file['name'], PATHINFO_EXTENSION); $safe_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($image_file['name'], PATHINFO_FILENAME)); $unique_filename = uniqid($safe_filename_base . '_', true) . '.' . strtolower($extension); $destination_path_physical = AD_UPLOAD_PATH . $unique_filename;
                      if (!is_dir(AD_UPLOAD_PATH)) { mkdir(AD_UPLOAD_PATH, 0755, true); }
                      if (!is_writable(AD_UPLOAD_PATH)) { error_log("Config ERROR: Ad upload directory not writable: " . AD_UPLOAD_PATH); $message = "Server error: Cannot write to upload directory."; break; }
                      if (move_uploaded_file($image_file['tmp_name'], $destination_path_physical)) { $uploaded_image_path_db = AD_UPLOAD_URL_BASE . $unique_filename; error_log("Admin Action: Uploaded Ad Image '{$unique_filename}'"); }
                      else { error_log("Config ERROR: Failed move uploaded ad file '{$image_file['name']}'."); $message = "Failed to save uploaded image."; break; }
                 } elseif ($ad_type === 'image' && $image_file && $image_file['error'] !== UPLOAD_ERR_OK && $image_file['error'] !== UPLOAD_ERR_NO_FILE) { $upload_errors = [ /*...*/ ]; $message = "Upload error: " . ($upload_errors[$image_file['error']] ?? "Code {$image_file['error']}."); error_log("Config upload error: {$message}"); break; }

                 $pdo->beginTransaction(); $transaction_started = true;
                 $sql = "INSERT INTO global_ads (ad_type, ad_title, ad_text, image_path, is_active) VALUES (:type, :title, :text, :image, :active)"; $stmt = $pdo->prepare($sql);
                 $params = [ ':type' => $ad_type, ':title' => $ad_title ?: null, ':text' => ($ad_type === 'text') ? $ad_text : null, ':image' => $uploaded_image_path_db, ':active' => $is_active ];
                 if ($stmt->execute($params)) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Global ad added."; $message_type = 'success'; }
                 else { $message = "DB error saving ad."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; if ($uploaded_image_path_db && $destination_path_physical && file_exists($destination_path_physical)) { unlink($destination_path_physical); error_log("Config Warning: Deleted orphaned ad image '{$unique_filename}' (DB fail)."); } }
                 break;

            case 'delete_global_ad':
                 if ($item_id) {
                      $stmt_get = $pdo->prepare("SELECT image_path FROM global_ads WHERE id = :id AND ad_type = 'image'"); $stmt_get->execute([':id' => $item_id]); $image_path = $stmt_get->fetchColumn();
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt_del = $pdo->prepare("DELETE FROM global_ads WHERE id = :id");
                      if ($stmt_del->execute([':id' => $item_id])) {
                           $pdo->commit(); $transaction_started = false; $success = true; $message = "Global ad deleted."; $message_type = 'success'; error_log("Admin Action: Deleted Global Ad ID {$item_id}.");
                           if ($image_path) { $physical_path = AD_UPLOAD_PATH . basename($image_path); if (file_exists($physical_path)) { if (!unlink($physical_path)) { $message .= " <strong style='color:orange;'>Warning:</strong> Failed to delete image file."; error_log("Config ERROR: Failed to delete image '{$physical_path}'."); } else { error_log("Admin Action: Deleted image '{$physical_path}'."); } } else { error_log("Config Warning: Image file '{$physical_path}' not found."); } }
                      } else { $message = "DB error deleting ad."; }
                 } else { $message = "Invalid ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;

            case 'toggle_global_ad_active':
                  if ($item_id) {
                       $pdo->beginTransaction(); $transaction_started = true;
                       $sql = "UPDATE global_ads SET is_active = NOT is_active WHERE id = :id"; $stmt = $pdo->prepare($sql);
                       if ($stmt->execute([':id' => $item_id])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Global ad status toggled."; $message_type = 'success'; }
                       else { $message = "DB error toggling status."; }
                  } else { $message = "Invalid ID."; }
                  if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                  break;

            case 'update_global_ad':
                 $edit_ad_id = filter_input(INPUT_POST, 'edit_ad_id', FILTER_VALIDATE_INT);
                 if (!$edit_ad_id) { $message = "Invalid Ad ID."; break; }
                 $ad_type = $_POST['edit_ad_type'] ?? null; $ad_title = trim($_POST['edit_ad_title'] ?? ''); $ad_text = trim($_POST['edit_ad_text'] ?? ''); $is_active = isset($_POST['edit_is_active']) ? 1 : 0; $image_file = $_FILES['edit_ad_image'] ?? null; $delete_current_image = isset($_POST['delete_current_image']) ? 1 : 0;
                 if (!in_array($ad_type, ['text', 'image'])) { $message = "Invalid ad type."; break; }
                 if ($ad_type === 'text' && empty($ad_text)) { $message = "Text content required."; break; }

                 $stmt_get = $pdo->prepare("SELECT ad_type, image_path FROM global_ads WHERE id = :id"); $stmt_get->execute([':id' => $edit_ad_id]); $old = $stmt_get->fetch(PDO::FETCH_ASSOC);
                 if (!$old) { $message = "Ad not found."; break; } $old_image_path = $old['image_path']; $image_path_to_save = $old_image_path;

                 // Handle Image Upload/Deletion
                 if ($ad_type === 'image' && $image_file && $image_file['error'] === UPLOAD_ERR_OK) {
                      $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']; $max_size = 2 * 1024 * 1024;
                      if (!in_array($image_file['type'], $allowed_types)) { $message = "Invalid image type."; break; }
                      if ($image_file['size'] > $max_size) { $message = "Image size exceeds limit."; break; }
                      $extension = pathinfo($image_file['name'], PATHINFO_EXTENSION); $safe_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($image_file['name'], PATHINFO_FILENAME)); $unique_filename = uniqid($safe_filename_base . '_', true) . '.' . strtolower($extension); $destination_path_physical = AD_UPLOAD_PATH . $unique_filename;
                      if (!is_dir(AD_UPLOAD_PATH)) { mkdir(AD_UPLOAD_PATH, 0755, true); }
                      if (!is_writable(AD_UPLOAD_PATH)) { $message = "Upload directory not writable."; break; }
                      if (move_uploaded_file($image_file['tmp_name'], $destination_path_physical)) { $uploaded_image_path_db = AD_UPLOAD_URL_BASE . $unique_filename; $image_path_to_save = $uploaded_image_path_db; error_log("Admin Action: Uploaded NEW image '{$unique_filename}' for Ad ID {$edit_ad_id}."); }
                      else { $message = "Failed to save replacement image."; break; }
                 } elseif ($ad_type === 'image' && $delete_current_image && $old_image_path) { $image_path_to_save = null; error_log("Admin Action: Marked image '{$old_image_path}' for deletion for Ad ID {$edit_ad_id}."); }
                   elseif ($ad_type === 'text' && $old['ad_type'] === 'image' && $old_image_path) { $image_path_to_save = null; error_log("Admin Action: Type changed, mark image '{$old_image_path}' for deletion for Ad ID {$edit_ad_id}."); }

                 // Update Database
                 $pdo->beginTransaction(); $transaction_started = true;
                 $sql = "UPDATE global_ads SET ad_type = :type, ad_title = :title, ad_text = :text, image_path = :image, is_active = :active WHERE id = :id"; $stmt = $pdo->prepare($sql);
                 $params = [ ':type' => $ad_type, ':title' => $ad_title ?: null, ':text' => ($ad_type === 'text') ? $ad_text : null, ':image' => ($ad_type === 'image') ? $image_path_to_save : null, ':active' => $is_active, ':id' => $edit_ad_id ];
                 if ($stmt->execute($params)) {
                      $pdo->commit(); $transaction_started = false; $success = true; $message = "Global ad updated."; $message_type = 'success'; error_log("Admin Action: Updated Global Ad ID {$edit_ad_id}.");
                      $image_to_delete = null; // Determine if old image needs deleting
                      if ($uploaded_image_path_db && $old_image_path && $old_image_path !== $uploaded_image_path_db) { $image_to_delete = $old_image_path; } // Replaced
                      elseif ($old_image_path && $image_path_to_save === null) { $image_to_delete = $old_image_path; } // Deleted or type change
                      if ($image_to_delete) { $physical_path = AD_UPLOAD_PATH . basename($image_to_delete); if (file_exists($physical_path)) { if (!unlink($physical_path)) { error_log("Config ERROR: Failed to delete old image '{$physical_path}'."); } else { error_log("Admin Action: Deleted old image '{$physical_path}'."); } } }
                 } else { $message = "DB error updating ad."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; if ($uploaded_image_path_db && $destination_path_physical && file_exists($destination_path_physical)) { unlink($destination_path_physical); error_log("Config Warning: Deleted new image '{$unique_filename}' (DB fail)."); } }
                 break;


            // ========================
            // Site Question Actions
            // ========================
            case 'assign_site_question':
                 $gq_id = filter_input(INPUT_POST, 'global_question_id', FILTER_VALIDATE_INT); $active = isset($_POST['assign_is_active']) ? 1 : 0;
                 if ($posted_site_id && $gq_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt_order = $pdo->prepare("SELECT MAX(display_order) FROM site_questions WHERE site_id = :id"); $stmt_order->execute([':id' => $posted_site_id]); $max = $stmt_order->fetchColumn() ?? -1;
                      $sql = "INSERT INTO site_questions (site_id, global_question_id, display_order, is_active) VALUES (:sid, :gid, :order, :active)"; $stmt = $pdo->prepare($sql);
                      if ($stmt->execute([':sid' => $posted_site_id, ':gid' => $gq_id, ':order' => $max + 1, ':active' => $active])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Question assigned."; $message_type = 'success'; }
                      else { $message = "Failed to assign (already assigned?)."; }
                 } else { $message = "Invalid site/question."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'remove_site_question':
                 if ($posted_site_id && $item_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt = $pdo->prepare("DELETE FROM site_questions WHERE id = :id AND site_id = :sid");
                      if ($stmt->execute([':id' => $item_id, ':sid' => $posted_site_id])) {
                           if (reorder_items($pdo, 'site_questions', 'display_order', 'site_id', $posted_site_id)) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Question removed & reordered."; $message_type = 'success'; }
                           else { $message = "Removed, but failed reorder."; $message_type = 'warning'; }
                      } else { $message = "Failed remove link."; }
                 } else { $message = "Invalid site/item ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'toggle_site_question_active':
                 if ($posted_site_id && $item_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $sql = "UPDATE site_questions SET is_active = NOT is_active WHERE id = :id AND site_id = :sid"; $stmt = $pdo->prepare($sql);
                      if ($stmt->execute([':id' => $item_id, ':sid' => $posted_site_id])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Question status toggled."; $message_type = 'success'; }
                      else { $message = "Failed toggle status."; }
                 } else { $message = "Invalid site/item ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'move_site_question_up':
            case 'move_site_question_down':
                if ($posted_site_id && $item_id) {
                     $direction = ($action === 'move_site_question_up') ? 'up' : 'down';
                     if(reorder_items($pdo, 'site_questions', 'display_order', 'site_id', $posted_site_id, $item_id, $direction)) { $success = true; $message = "Question reordered."; $message_type = 'success'; }
                     else { $message = "Failed reorder."; }
                } else { $message = "Invalid site/item ID."; }
                break;

            // ========================
            // Site Ad Assignment Actions
            // ========================
            case 'assign_site_ad':
                 $ga_id = filter_input(INPUT_POST, 'global_ad_id', FILTER_VALIDATE_INT); $active = isset($_POST['assign_is_active']) ? 1 : 0;
                 if ($posted_site_id && $ga_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt_order = $pdo->prepare("SELECT MAX(display_order) FROM site_ads WHERE site_id = :id"); $stmt_order->execute([':id' => $posted_site_id]); $max = $stmt_order->fetchColumn() ?? -1;
                      $sql = "INSERT INTO site_ads (site_id, global_ad_id, display_order, is_active) VALUES (:sid, :gid, :order, :active)"; $stmt = $pdo->prepare($sql);
                      if ($stmt->execute([':sid' => $posted_site_id, ':gid' => $ga_id, ':order' => $max + 1, ':active' => $active])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Ad assigned."; $message_type = 'success'; }
                      else { $message = "Failed assign (already assigned?)."; }
                 } else { $message = "Invalid site/ad."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'remove_site_ad':
                 if ($posted_site_id && $item_id) { // item_id is site_ad_id
                      $pdo->beginTransaction(); $transaction_started = true;
                      $stmt = $pdo->prepare("DELETE FROM site_ads WHERE id = :id AND site_id = :sid");
                      if ($stmt->execute([':id' => $item_id, ':sid' => $posted_site_id])) {
                           if (reorder_items($pdo, 'site_ads', 'display_order', 'site_id', $posted_site_id)) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Ad removed & reordered."; $message_type = 'success'; }
                           else { $message = "Removed, but failed reorder."; $message_type = 'warning'; }
                      } else { $message = "Failed remove link."; }
                 } else { $message = "Invalid site/item ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'toggle_site_ad_active':
                 if ($posted_site_id && $item_id) { // item_id is site_ad_id
                      $pdo->beginTransaction(); $transaction_started = true;
                      $sql = "UPDATE site_ads SET is_active = NOT is_active WHERE id = :id AND site_id = :sid"; $stmt = $pdo->prepare($sql);
                      if ($stmt->execute([':id' => $item_id, ':sid' => $posted_site_id])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Site ad status toggled."; $message_type = 'success'; }
                      else { $message = "Failed toggle status."; }
                 } else { $message = "Invalid site/item ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'move_site_ad_up':
            case 'move_site_ad_down':
                if ($posted_site_id && $item_id) { // item_id is site_ad_id
                     $direction = ($action === 'move_site_ad_up') ? 'up' : 'down';
                     if(reorder_items($pdo, 'site_ads', 'display_order', 'site_id', $posted_site_id, $item_id, $direction)) { $success = true; $message = "Site ad reordered."; $message_type = 'success'; }
                     else { $message = "Failed reorder."; }
                } else { $message = "Invalid site/item ID."; }
                break;

            // ========================
            // Notifier Actions
            // ========================
             case 'add_notifier':
                 $name = trim($_POST['staff_name'] ?? ''); $email = filter_input(INPUT_POST, 'staff_email', FILTER_VALIDATE_EMAIL);
                 if ($posted_site_id && !empty($name) && $email) {
                     $pdo->beginTransaction(); $transaction_started = true;
                     $sql = "INSERT INTO staff_notifications (site_id, staff_name, staff_email, is_active) VALUES (:sid, :name, :email, 1)"; $stmt = $pdo->prepare($sql);
                     if($stmt->execute([':sid' => $posted_site_id, ':name' => $name, ':email' => $email])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier added."; $message_type = 'success'; }
                     else { $message = "Failed add notifier."; }
                 } else { $message = "Invalid input."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'edit_notifier':
                 $name = trim($_POST['staff_name_edit'] ?? ''); $email = filter_input(INPUT_POST, 'staff_email_edit', FILTER_VALIDATE_EMAIL);
                 if ($posted_site_id && $item_id && !empty($name) && $email) {
                     $pdo->beginTransaction(); $transaction_started = true;
                     $sql = "UPDATE staff_notifications SET staff_name = :name, staff_email = :email WHERE id = :id AND site_id = :sid"; $stmt = $pdo->prepare($sql);
                     if($stmt->execute([':name' => $name, ':email' => $email, ':id' => $item_id, ':sid' => $posted_site_id])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier updated."; $message_type = 'success'; }
                     else { $message = "Failed update notifier."; }
                 } else { $message = "Invalid input."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'delete_notifier':
                 if ($posted_site_id && $item_id) {
                     $pdo->beginTransaction(); $transaction_started = true;
                     $sql = "DELETE FROM staff_notifications WHERE id = :id AND site_id = :sid"; $stmt = $pdo->prepare($sql);
                     if($stmt->execute([':id' => $item_id, ':sid' => $posted_site_id])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier deleted."; $message_type = 'success'; }
                     else { $message = "Failed delete notifier."; }
                 } else { $message = "Invalid site/item ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;
            case 'toggle_notifier_active':
                 if ($posted_site_id && $item_id) {
                      $pdo->beginTransaction(); $transaction_started = true;
                      $sql = "UPDATE staff_notifications SET is_active = NOT is_active WHERE id = :id AND site_id = :sid"; $stmt = $pdo->prepare($sql);
                      if ($stmt->execute([':id' => $item_id, ':sid' => $posted_site_id])) { $success = true; $pdo->commit(); $transaction_started = false; $message = "Notifier status toggled."; $message_type = 'success'; }
                      else { $message = "Failed toggle status."; }
                 } else { $message = "Invalid site/item ID."; }
                 if (!$success && $transaction_started) { $pdo->rollBack(); $transaction_started = false; }
                 break;

            // ========================
            // Default Action
            // ========================
            default:
                $success = false;
                $message = "Unknown or invalid action specified: " . htmlspecialchars($action);
                $message_type = 'error';
                break;
        } // End switch ($action)

     } catch (PDOException $e) { // Outer catch for PDO errors
          if (isset($transaction_started) && $transaction_started === true && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to PDOException in action '{$action}'."); }
          // Cleanup uploaded ad file on generic DB error if applicable
           if ($action === 'add_global_ad' || $action === 'update_global_ad') {
                $temp_phys_path = $destination_path_physical ?? null; // Use specific var if set
                if ($temp_phys_path && file_exists($temp_phys_path)) { unlink($temp_phys_path); error_log("Config Warning: Deleted orphaned ad image '{$unique_filename}' due to PDOException during '{$action}'."); }
           }
          $success = false; $message = "Database error processing action '{$action}'. Details logged."; $message_type = 'error';
          error_log("PDOException processing action '{$action}' for site {$posted_site_id}, item {$item_id}: " . $e->getMessage());
     } catch (Exception $e) { // Outer catch for general errors
           if (isset($transaction_started) && $transaction_started === true && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to general Exception in action '{$action}'."); }
           // Cleanup uploaded ad file on generic error if applicable
           if ($action === 'add_global_ad' || $action === 'update_global_ad') {
                 $temp_phys_path = $destination_path_physical ?? null;
                 if ($temp_phys_path && file_exists($temp_phys_path)) { unlink($temp_phys_path); error_log("Config Warning: Deleted orphaned ad image '{$unique_filename}' due to Exception during '{$action}'."); }
           }
          $success = false; $message = "General error processing action '{$action}'. Details logged."; $message_type = 'error';
          error_log("Exception processing action '{$action}' for site {$posted_site_id}, item {$item_id}: " . $e->getMessage());
     }

     // --- Redirect after processing action ---
     $_SESSION['flash_message'] = $message;
     $_SESSION['flash_type'] = $message_type;
     $_SESSION['selected_config_tab'] = $redirect_tab;
     // Determine correct site ID for redirect URL based on tab
     $redirect_site_id = ($posted_site_id && in_array($redirect_tab, ['site_settings', 'site_questions', 'notifiers', 'ads_management'])) ? $posted_site_id : ($selected_config_site_id ?? null);
     if ($redirect_tab === 'global_questions') { $redirect_site_id = null; } // Don't need site ID for global questions tab

     $_SESSION['selected_config_site_id'] = $redirect_site_id; // Store potentially updated site ID for next load
     $redirect_url = "configurations.php?tab=" . urlencode($redirect_tab);
     if ($redirect_site_id !== null) { $redirect_url .= "&site_id=" . $redirect_site_id; }
     // Prevent redirecting back to edit view after processing update/delete/toggle
     $redirect_url = preg_replace('/&view=[^&]*/', '', $redirect_url);       // Remove any &view=...
     $redirect_url = preg_replace('/&edit_item_id=[^&]*/', '', $redirect_url); // Remove any &edit_item_id=...
     $redirect_url = preg_replace('/&edit_ad_id=[^&]*/', '', $redirect_url);   // Remove any &edit_ad_id=...
     header("Location: " . $redirect_url); exit;
}
// =========================================================================
// --- END: Handle Form Submissions (POST Requests) ---
// =========================================================================


// =========================================================================
// --- START: Logic for Edit Views (Fetching data before display) ---
// =========================================================================
$view_state = $_GET['view'] ?? 'list'; // Default to 'list' view
$edit_item_id = filter_input(INPUT_GET, 'edit_item_id', FILTER_VALIDATE_INT); // Used for Notifier edit
$edit_ad_id = filter_input(INPUT_GET, 'edit_ad_id', FILTER_VALIDATE_INT);     // Used for Ad edit

$edit_notifier_data = null;
$edit_ad_data = null;

// --- Fetch Notifier Edit Data ---
if ($activeTab === 'notifiers' && $view_state === 'edit_notifier' && $edit_item_id && $selected_config_site_id) {
    try {
         $stmt_edit_n = $pdo->prepare("SELECT * FROM staff_notifications WHERE id = :id AND site_id = :site_id");
         $stmt_edit_n->execute([':id' => $edit_item_id, ':site_id' => $selected_config_site_id]);
         $edit_notifier_data = $stmt_edit_n->fetch(PDO::FETCH_ASSOC);
         if(!$edit_notifier_data) { $_SESSION['flash_message'] = "Notifier not found."; $_SESSION['flash_type'] = 'warning'; header('Location: configurations.php?site_id='.$selected_config_site_id.'&tab=notifiers'); exit; }
     } catch (PDOException $e) { /* ... existing error handling ... */ }
}
// --- Fetch Global Ad Edit Data ---
elseif ($activeTab === 'ads_management' && $view_state === 'edit_global_ad' && $edit_ad_id) {
    try {
         $stmt_edit_ad = $pdo->prepare("SELECT * FROM global_ads WHERE id = :id");
         $stmt_edit_ad->execute([':id' => $edit_ad_id]);
         $edit_ad_data = $stmt_edit_ad->fetch(PDO::FETCH_ASSOC);
         if(!$edit_ad_data) {
              $_SESSION['flash_message'] = "Global Ad not found for editing."; $_SESSION['flash_type'] = 'warning';
              $redirect_url = "configurations.php?tab=ads_management"; if ($selected_config_site_id) { $redirect_url .= "&site_id=" . $selected_config_site_id; } header("Location: " . $redirect_url); exit;
         }
     } catch (PDOException $e) {
          $_SESSION['flash_message'] = "Database error fetching ad for editing."; $_SESSION['flash_type'] = 'error'; error_log("Error fetching Global Ad ID {$edit_ad_id} for edit: " . $e->getMessage());
          $redirect_url = "configurations.php?tab=ads_management"; if ($selected_config_site_id) { $redirect_url .= "&site_id=" . $selected_config_site_id; } header("Location: " . $redirect_url); exit;
     }
}
// =========================================================================
// --- END: Logic for Edit Views ---
// =========================================================================


// =========================================================================
// --- START: Fetch Data for Display (List Views & Dropdowns) ---
// =========================================================================
$global_questions_list = [];
$site_questions_assigned = [];
$site_questions_available = [];
$notifiers = [];
$global_ads_list = [];
$site_ads_assigned = [];
$site_ads_available = [];
$selected_site_details = null;
$site_allow_email = 0;
$site_allow_notifier = 0;
$config_error_message = ''; // Store errors during fetch

// 1. Fetch Global Data (needed regardless of site selection)
try {
    $stmt_gq = $pdo->query("SELECT id, question_text, question_title FROM global_questions ORDER BY question_title ASC");
    $global_questions_list = $stmt_gq->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("Config Error - Fetching Global Qs List: " . $e->getMessage()); $config_error_message .= " Error loading global questions."; }

try {
    $stmt_ga = $pdo->query("SELECT id, ad_type, ad_title, ad_text, image_path, is_active FROM global_ads ORDER BY ad_title ASC, created_at DESC");
    $global_ads_list = $stmt_ga->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("Config Error - Fetching Global Ads List: " . $e->getMessage()); $config_error_message .= " Error loading global ads."; $global_ads_list = []; }

// 2. Fetch Site-Specific Data if a site is selected
if ($selected_config_site_id !== null) {
     try {
         // Fetch site details
         $stmt_site_read = $pdo->prepare("SELECT id, name, email_collection_desc, is_active FROM sites WHERE id = :id");
         $stmt_site_read->execute([':id' => $selected_config_site_id]);
         $selected_site_details = $stmt_site_read->fetch(PDO::FETCH_ASSOC);

         if ($selected_site_details) {
             $selected_site_details['is_active'] = (int)$selected_site_details['is_active']; // Cast for consistency

             // Fetch boolean config settings
             $stmt_config_read = $pdo->prepare("SELECT config_key, config_value FROM site_configurations WHERE site_id = :id AND config_key IN ('allow_email_collection', 'allow_notifier')");
             $stmt_config_read->execute([':id' => $selected_config_site_id]);
             $configs = $stmt_config_read->fetchAll(PDO::FETCH_KEY_PAIR);
             $site_allow_email = isset($configs['allow_email_collection']) ? (int)$configs['allow_email_collection'] : 0;
             $site_allow_notifier = isset($configs['allow_notifier']) ? (int)$configs['allow_notifier'] : 0;
             // error_log("READING settings for Site ID {$selected_config_site_id}: Active={$selected_site_details['is_active']}, AllowEmail={$site_allow_email}, AllowNotify={$site_allow_notifier}");

             // Fetch Site Questions & Calculate Available
             $sql_sq = "SELECT sq.id as site_question_id, sq.global_question_id, sq.display_order, sq.is_active, gq.question_text, gq.question_title FROM site_questions sq JOIN global_questions gq ON sq.global_question_id = gq.id WHERE sq.site_id = :site_id ORDER BY sq.display_order ASC";
             $stmt_sq = $pdo->prepare($sql_sq); $stmt_sq->execute([':site_id' => $selected_config_site_id]);
             $site_questions_assigned = $stmt_sq->fetchAll(PDO::FETCH_ASSOC);
             $assigned_gq_ids = array_column($site_questions_assigned, 'global_question_id');
             $site_questions_available = [];
             if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { if (!in_array($gq_data['id'], $assigned_gq_ids)) { $site_questions_available[$gq_data['id']] = $gq_data; } } }

             // Fetch Notifiers
             $stmt_n = $pdo->prepare("SELECT id, staff_name, staff_email, is_active FROM staff_notifications WHERE site_id = :site_id ORDER BY staff_name ASC");
             $stmt_n->execute([':site_id' => $selected_config_site_id]);
             $notifiers = $stmt_n->fetchAll(PDO::FETCH_ASSOC);

             // --- Fetch Site Ad Assignments ---
             try {
                   $sql_sa = "SELECT sa.id as site_ad_id, sa.global_ad_id, sa.display_order, sa.is_active as site_is_active, ga.ad_type, ga.ad_title, ga.ad_text, ga.image_path, ga.is_active as global_is_active FROM site_ads sa JOIN global_ads ga ON sa.global_ad_id = ga.id WHERE sa.site_id = :site_id ORDER BY sa.display_order ASC";
                   $stmt_sa = $pdo->prepare($sql_sa);
                   $stmt_sa->execute([':site_id' => $selected_config_site_id]);
                   $site_ads_assigned = $stmt_sa->fetchAll(PDO::FETCH_ASSOC);
             } catch (PDOException $e_sa) { error_log("Config Error - Fetching Site Ads for site {$selected_config_site_id}: " . $e_sa->getMessage()); $config_error_message .= " Error loading site ad assignments."; $site_ads_assigned = []; }

             // Determine Available Global Ads (only list ACTIVE global ads)
             $assigned_ga_ids = array_column($site_ads_assigned, 'global_ad_id');
             $site_ads_available = [];
             if (!empty($global_ads_list)) { foreach ($global_ads_list as $ga_data) { if ($ga_data['is_active'] && !in_array($ga_data['id'], $assigned_ga_ids)) { $site_ads_available[$ga_data['id']] = $ga_data; } } }
             // --- End Fetch Site Ad Assignments ---

         } else { // Site details not found
             error_log("Config Error - Site details not found for ID {$selected_config_site_id}");
             $config_error_message .= " Could not load details for the selected site."; $selected_config_site_id = null; $selected_site_details = null; $site_allow_email = 0; $site_allow_notifier = 0; $site_questions_assigned = []; $notifiers = []; $site_ads_assigned = [];
             // If site invalid, all global Qs/Ads are 'available' conceptually
             if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { $site_questions_available[$gq_data['id']] = $gq_data; } }
             if (!empty($global_ads_list)) { foreach ($global_ads_list as $ga_data) { if ($ga_data['is_active']) { $site_ads_available[$ga_data['id']] = $ga_data; } } }
         }
     } catch (PDOException $e_outer) { // Catch outer errors fetching site details, etc.
          error_log("Config Error - Fetching site-specific data for site {$selected_config_site_id}: " . $e_outer->getMessage());
          $config_error_message .= " Error loading data for the selected site.";
          // Reset all site-specific data on error
          $selected_site_details = null; $site_allow_email = 0; $site_allow_notifier = 0; $site_questions_assigned = []; $notifiers = []; $site_ads_assigned = [];
          if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { $site_questions_available[$gq_data['id']] = $gq_data; } }
          if (!empty($global_ads_list)) { foreach ($global_ads_list as $ga_data) { if ($ga_data['is_active']) { $site_ads_available[$ga_data['id']] = $ga_data; } } }
     }
} else { // No site selected - Populate available lists from global
     if (!empty($global_questions_list)) { foreach ($global_questions_list as $gq_data) { $site_questions_available[$gq_data['id']] = $gq_data; } }
     if (!empty($global_ads_list)) { foreach ($global_ads_list as $ga_data) { if ($ga_data['is_active']) { $site_ads_available[$ga_data['id']] = $ga_data; } } }
}
// =========================================================================
// --- END: Fetch Data for Display ---
// =========================================================================


// --- Page Setup & Header ---
$pageTitle = "Configurations";
require_once 'includes/header.php';
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
                    <div class="tab <?php echo ($activeTab === 'ads_management') ? 'active' : ''; ?>" data-tab="ads-management">Ads Management</div>
                 </div>

                 <!-- Tab Content Panes -->
                 <div id="tab-content">
                     <!-- Site Settings Tab Pane -->
                     <div class="tab-pane <?php echo ($activeTab === 'site_settings') ? 'active' : ''; ?>" id="site-settings">
                         <?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
                             <div class="settings-section">
                                 <h3 class="settings-section-title">Settings for <?php echo htmlspecialchars($selected_site_details['name']); ?></h3>
                                 <form method="POST" action="configurations.php"> <!-- Action handled by main POST handler -->
                                     <input type="hidden" name="action" value="update_site_settings">
                                     <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                     <input type="hidden" name="active_tab_on_submit" value="site_settings">
                                     <div class="settings-form two-column">
                                         <!-- Toggles using $selected_site_details, $site_allow_email, $site_allow_notifier -->
                                         <div class="form-group"><label class="form-label">Site Status</label><div class="toggle-switch"><?php $site_active_flag = ($selected_site_details['is_active'] == 1); ?><input type="checkbox" id="site_is_active" name="site_is_active" value="1" <?php echo $site_active_flag ? 'checked' : ''; ?>><label for="site_is_active" class="toggle-label"><span class="toggle-button"></span></label><span class="toggle-text"><?php echo $site_active_flag ? 'Active' : 'Inactive'; ?></span></div></div>
                                         <div class="form-group"><label class="form-label">Allow Client Email Collection?</label><div class="toggle-switch"><?php $email_enabled_flag = ($site_allow_email == 1); ?><input type="checkbox" id="allow_email_collection" name="allow_email_collection" value="1" <?php echo $email_enabled_flag ? 'checked' : ''; ?>><label for="allow_email_collection" class="toggle-label"><span class="toggle-button"></span></label><span class="toggle-text"><?php echo $email_enabled_flag ? 'Enabled' : 'Disabled'; ?></span></div></div>
                                         <div class="form-group"><label class="form-label">Allow Staff Notifier Selection?</label><div class="toggle-switch"><?php $notifier_enabled_flag = ($site_allow_notifier == 1); ?><input type="checkbox" id="allow_notifier" name="allow_notifier" value="1" <?php echo $notifier_enabled_flag ? 'checked' : ''; ?>><label for="allow_notifier" class="toggle-label"><span class="toggle-button"></span></label><span class="toggle-text"><?php echo $notifier_enabled_flag ? 'Enabled' : 'Disabled'; ?></span></div></div>
                                         <div class="form-group full-width" id="email-desc-group" style="<?php echo !$email_enabled_flag ? 'display: none;' : ''; ?>"><label for="email_desc_site" class="form-label">Email Collection Description</label><textarea id="email_desc_site" name="email_collection_description_site" class="form-control" rows="2"><?php echo htmlspecialchars($selected_site_details['email_collection_desc'] ?? ''); ?></textarea><p class="form-description">Text displayed above the optional email input on the check-in form.</p></div>
                                     </div>
                                     <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Site Settings</button></div>
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
                                 <form method="POST" action="configurations.php">
                                     <input type="hidden" name="action" value="add_global_question">
                                     <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                                     <input type="hidden" name="active_tab_on_submit" value="global_questions">
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
                                                         <form method="POST" action="configurations.php" onsubmit="return confirm('WARNING: Deleting question \'<?php echo htmlspecialchars(addslashes(format_base_name_for_display($gq['question_title']))); ?>\' removes it from ALL sites and attempts to delete its data column `q_<?php echo htmlspecialchars($gq['question_title']); ?>`. Are you sure?');">
                                                             <input type="hidden" name="action" value="delete_global_question">
                                                             <input type="hidden" name="item_id" value="<?php echo $gq['id']; ?>">
                                                             <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                                                             <input type="hidden" name="active_tab_on_submit" value="global_questions">
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
                                      <form method="POST" action="configurations.php">
                                          <input type="hidden" name="action" value="assign_site_question"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                          <input type="hidden" name="active_tab_on_submit" value="site_questions">
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
                                                              <?php if ($i > 0): ?><form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="move_site_question_up"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="site_questions"><button type="submit" class="btn btn-outline btn-sm" title="Move Up"><i class="fas fa-arrow-up"></i></button></form><?php endif; ?>
                                                              <?php if ($i < $sq_count - 1): ?><form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="move_site_question_down"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="site_questions"><button type="submit" class="btn btn-outline btn-sm" title="Move Down"><i class="fas fa-arrow-down"></i></button></form><?php endif; ?>
                                                              <form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="toggle_site_question_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="site_questions"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $sq['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $sq['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
                                                              <form method="POST" action="configurations.php" style="display: inline-block;" onsubmit="return confirm('Remove this question from this site?');"><input type="hidden" name="action" value="remove_site_question"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sq['site_question_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="site_questions"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Remove from Site"><i class="fas fa-unlink"></i></button></form>
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
                                         <form method="POST" action="configurations.php">
                                              <input type="hidden" name="action" value="add_notifier"> <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                              <input type="hidden" name="active_tab_on_submit" value="notifiers">
                                             <div class="settings-form two-column">
                                                 <div class="form-group"><label for="add_n_name" class="form-label">Staff Name:</label><input type="text" id="add_n_name" name="staff_name" class="form-control" required></div>
                                                 <div class="form-group"><label for="add_n_email" class="form-label">Staff Email:</label><input type="email" id="add_n_email" name="staff_email" class="form-control" required></div>
                                             </div>
                                             <div class="form-actions"> <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Notifier</button> <a href="configurations.php?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers" class="btn btn-outline">Cancel</a> </div>
                                         </form>
                                     </div>
                                <?php elseif($view_state === 'edit_notifier' && $edit_notifier_data): // Changed variable name here ?>
                                      <div class="admin-form-container form-section">
                                          <h4 class="subsection-title">Edit Notifier</h4>
                                          <form method="POST" action="configurations.php">
                                               <input type="hidden" name="action" value="edit_notifier"> <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"> <input type="hidden" name="item_id" value="<?php echo $edit_notifier_data['id']; ?>">
                                               <input type="hidden" name="active_tab_on_submit" value="notifiers">
                                              <div class="settings-form two-column">
                                                  <div class="form-group"><label for="edit_n_name" class="form-label">Staff Name:</label><input type="text" id="edit_n_name" name="staff_name_edit" class="form-control" required value="<?php echo htmlspecialchars($edit_notifier_data['staff_name']); ?>"></div>
                                                  <div class="form-group"><label for="edit_n_email" class="form-label">Staff Email:</label><input type="email" id="edit_n_email" name="staff_email_edit" class="form-control" required value="<?php echo htmlspecialchars($edit_notifier_data['staff_email']); ?>"></div>
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
                                                              <form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="toggle_notifier_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><input type="hidden" name="active_tab_on_submit" value="notifiers"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $n['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $n['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
                                                              <form method="POST" action="configurations.php" style="display: inline-block;" onsubmit="return confirm('Delete this notifier?');"><input type="hidden" name="action" value="delete_notifier"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><input type="hidden" name="active_tab_on_submit" value="notifiers"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Delete"><i class="fas fa-trash"></i></button></form>
                                                         </td>
                                                     </tr>
                                                 <?php endforeach; ?>
                                             <?php else: ?> <tr><td colspan="4" style="text-align: center;">No notifiers defined.</td></tr> <?php endif; ?>
                                         </tbody>
                                     </table>
                                 </div>
                                 <div class="form-actions"> <a href="?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers&view=add_notifier" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Notifier</a> </div>
                                 <?php endif; // End view=list condition ?>
                             </div>
                          <?php else: ?> <div class="message-area message-info">Please select a site to manage its notifiers.</div> <?php endif; ?>
                     </div>

                     <!-- Ads Management Tab Pane -->
                     <div class="tab-pane <?php echo ($activeTab === 'ads_management') ? 'active' : ''; ?>" id="ads-management">
                         <div class="settings-section">
                             <h3 class="settings-section-title">Global Ads Library</h3>
                             <p>Manage advertisements that can be displayed on the check-in page sidebars. Ads must be active here AND assigned/activated per site to display.</p>

                             <!-- Add/Edit Global Ad Form Section -->
                             <div class="admin-form-container form-section">
                                 <?php if ($view_state === 'edit_global_ad' && $edit_ad_data && $activeTab === 'ads_management'): // Only show edit form if relevant data exists and on correct tab ?>
                                     <!-- == EDIT GLOBAL AD FORM == -->
                                     <h4 class="form-section-title">Edit Global Ad</h4>
                                     <form method="POST" action="configurations.php" enctype="multipart/form-data">
                                         <input type="hidden" name="action" value="update_global_ad">
                                         <input type="hidden" name="edit_ad_id" value="<?php echo $edit_ad_data['id']; ?>">
                                         <input type="hidden" name="edit_ad_type" value="<?php echo htmlspecialchars($edit_ad_data['ad_type']); ?>">
                                         <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                                         <input type="hidden" name="active_tab_on_submit" value="ads_management">
                                         <div class="settings-form two-column">
                                             <div class="form-group"><label for="edit_ad_title" class="form-label">Ad Title (for Admin):</label><input type="text" id="edit_ad_title" name="edit_ad_title" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($edit_ad_data['ad_title'] ?? ''); ?>"></div>
                                             <div class="form-group"><label class="form-label">Ad Type:</label><p style="padding-top: 0.5rem;"><strong><?php echo htmlspecialchars(ucfirst($edit_ad_data['ad_type'])); ?> Ad</strong></p></div>
                                             <div class="form-group full-width" id="edit-ad-text-group" style="<?php echo $edit_ad_data['ad_type'] !== 'text' ? 'display: none;' : ''; ?>"><label for="edit_ad_text" class="form-label">Ad Text Content:</label><textarea id="edit_ad_text" name="edit_ad_text" class="form-control" rows="4" <?php echo $edit_ad_data['ad_type'] === 'text' ? 'required' : ''; ?>><?php echo htmlspecialchars($edit_ad_data['ad_text'] ?? ''); ?></textarea></div>
                                             <div id="edit-ad-image-group" style="<?php echo $edit_ad_data['ad_type'] !== 'image' ? 'display: none;' : ''; ?>">
                                                 <div class="form-group full-width"><label class="form-label">Current Image:</label><?php $preview = '<em>No current image.</em>'; if (!empty($edit_ad_data['image_path'])) { $url=htmlspecialchars($edit_ad_data['image_path']); if (!filter_var($url, FILTER_VALIDATE_URL) && $url[0] !== '/' && strpos($url, '://') === false) { $url=rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($url); } $preview='<img src="' . $url . '" alt="Current Ad Image" style="max-height: 60px; max-width: 150px; border: 1px solid #ccc; margin-bottom: 5px;">'; } echo $preview; ?><?php if (!empty($edit_ad_data['image_path'])): ?><br><label class="form-check-label"><input type="checkbox" name="delete_current_image" value="1" class="form-check-input"> Delete current image?</label><?php endif; ?></div>
                                                 <div class="form-group full-width"><label for="edit_ad_image" class="form-label">Upload New Image (Optional):</label><input type="file" id="edit_ad_image" name="edit_ad_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp"><p class="form-description">Replace current image. Max 2MB.</p></div>
                                             </div>
                                             <div class="form-group"> <label class="form-check-label"><input type="checkbox" name="edit_is_active" value="1" <?php echo ($edit_ad_data['is_active'] == 1) ? 'checked' : ''; ?> class="form-check-input"> Active?</label> </div>
                                         </div>
                                         <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Global Ad</button><a href="configurations.php?tab=ads_management<?php echo $selected_config_site_id ? '&site_id='.$selected_config_site_id : ''; ?>" class="btn btn-outline">Cancel</a></div>
                                     </form>
                                     <!-- == END EDIT GLOBAL AD FORM == -->
                                 <?php else: ?>
                                     <!-- == ADD GLOBAL AD FORM == -->
                                     <h4 class="form-section-title">Add New Global Ad</h4>
                                     <form method="POST" action="configurations.php" enctype="multipart/form-data">
                                         <input type="hidden" name="action" value="add_global_ad">
                                         <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                                         <input type="hidden" name="active_tab_on_submit" value="ads_management">
                                         <div class="settings-form two-column">
                                             <div class="form-group"><label for="ad_title" class="form-label">Ad Title (for Admin):</label><input type="text" id="ad_title" name="ad_title" class="form-control" maxlength="150"><p class="form-description">Optional identifier.</p></div>
                                             <div class="form-group"><label for="ad_type" class="form-label">Ad Type:</label><select id="ad_type" name="ad_type" class="form-control" required onchange="toggleAdFields()"><option value="text" selected>Text Ad</option><option value="image">Image Ad</option></select></div>
                                             <div class="form-group full-width" id="ad-text-group"><label for="ad_text" class="form-label">Ad Text Content:</label><textarea id="ad_text" name="ad_text" class="form-control" rows="4"></textarea><p class="form-description">Basic HTML allowed.</p></div>
                                             <div class="form-group full-width" id="ad-image-group" style="display: none;"><label for="ad_image" class="form-label">Upload Ad Image:</label><input type="file" id="ad_image" name="ad_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp"><p class="form-description">Max 2MB. JPG, PNG, GIF, WEBP.</p></div>
                                             <div class="form-group"> <label class="form-check-label"><input type="checkbox" name="is_active" value="1" checked class="form-check-input"> Active?</label> </div>
                                         </div>
                                         <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Global Ad</button></div>
                                     </form>
                                      <!-- == END ADD GLOBAL AD FORM == -->
                                 <?php endif; ?>
                             </div> <!-- End .admin-form-container -->

                             <!-- List Global Ads -->
                             <h4 class="form-section-title">Existing Global Ads</h4>
                             <div class="table-container">
                                 <table>
                                     <thead><tr><th>Title</th><th>Type</th><th>Preview</th><th>Status</th><th>Actions</th></tr></thead>
                                     <tbody>
                                         <?php if (!empty($global_ads_list)): ?>
                                             <?php foreach ($global_ads_list as $ad):
                                                   $preview = ''; if ($ad['ad_type'] === 'text' && !empty($ad['ad_text'])) { $preview = htmlspecialchars(substr($ad['ad_text'], 0, 75)) . (strlen($ad['ad_text']) > 75 ? '...' : ''); } elseif ($ad['ad_type'] === 'image' && !empty($ad['image_path'])) { $url=htmlspecialchars($ad['image_path']); if (!filter_var($url, FILTER_VALIDATE_URL) && $url[0] !== '/' && strpos($url, '://') === false) { $url=rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($url); } $preview='<img src="' . $url . '" alt="Ad Preview" style="max-height: 40px; max-width: 100px; vertical-align: middle;">'; } else { $preview='<em>N/A</em>'; } ?>
                                                 <tr>
                                                     <td><?php echo htmlspecialchars($ad['ad_title'] ?: '<em>Untitled</em>'); ?></td><td><?php echo htmlspecialchars(ucfirst($ad['ad_type'])); ?></td><td><?php echo $preview; ?></td><td><span class="status-badge <?php echo $ad['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $ad['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                                     <td class="actions-cell">
                                                         <a href="configurations.php?tab=ads_management&view=edit_global_ad&edit_ad_id=<?php echo $ad['id']; ?><?php echo $selected_config_site_id ? '&site_id='.$selected_config_site_id : ''; ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                                         <form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="toggle_global_ad_active"><input type="hidden" name="item_id" value="<?php echo $ad['id']; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management"><?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $ad['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $ad['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
                                                         <form method="POST" action="configurations.php" style="display: inline-block;" onsubmit="return confirm('Delete this global ad? This cannot be undone.');"><input type="hidden" name="action" value="delete_global_ad"><input type="hidden" name="item_id" value="<?php echo $ad['id']; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management"><?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?><button type="submit" class="btn btn-outline btn-sm delete-button" title="Delete"><i class="fas fa-trash"></i></button></form>
                                                     </td>
                                                 </tr>
                                             <?php endforeach; ?>
                                         <?php else: ?><tr><td colspan="5" style="text-align: center;">No global ads defined yet.</td></tr><?php endif; ?>
                                     </tbody>
                                 </table>
                             </div>
                         </div> <!-- End Global Ads Library Section -->

                         <!-- Site Ad Assignment Section -->
                         <div class="settings-section">
                              <h3 class="settings-section-title">Site Ad Assignments for: <?php echo htmlspecialchars($selected_site_details['name'] ?? 'N/A'); ?></h3>
                              <?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
                                  <p>Select active global ads to display on this site's check-in page. Order affects potential display sequence.</p>
                                  <!-- Assign Ad Form -->
                                  <div class="admin-form-container form-section">
                                       <h4 class="form-section-title">Assign Global Ad</h4>
                                       <form method="POST" action="configurations.php">
                                           <input type="hidden" name="action" value="assign_site_ad"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management">
                                           <div class="settings-form two-column">
                                               <div class="form-group"><label for="assign_ga_id" class="form-label">Available Active Global Ads:</label><select id="assign_ga_id" name="global_ad_id" class="form-control" required <?php echo empty($site_ads_available) ? 'disabled' : ''; ?>><option value="">-- Choose Ad --</option><?php foreach ($site_ads_available as $ga_id => $ga_data): ?><option value="<?php echo $ga_id; ?>">[<?php echo htmlspecialchars(ucfirst($ga_data['ad_type'])); ?>] <?php echo htmlspecialchars($ga_data['ad_title'] ?: 'Untitled Ad ID '.$ga_id); ?></option><?php endforeach; ?><?php if (empty($site_ads_available)): ?><option value="" disabled>All active global ads assigned or none exist</option><?php endif; ?></select></div>
                                               <div class="form-group" style="align-self: end;"><label class="form-check-label"><input type="checkbox" name="assign_is_active" value="1" checked class="form-check-input"> Active on this site?</label></div>
                                           </div>
                                           <div class="form-actions"><button type="submit" class="btn btn-primary" <?php echo empty($site_ads_available) ? 'disabled' : ''; ?>><i class="fas fa-plus"></i> Assign Ad to Site</button></div>
                                       </form>
                                  </div>
                                  <!-- List Assigned Site Ads -->
                                  <h4 class="form-section-title">Ads Currently Assigned to Site (Order affects display)</h4>
                                  <div class="table-container">
                                      <table>
                                          <thead><tr><th>Order</th><th>Title</th><th>Type</th><th>Preview</th><th>Site Status</th><th>Global Status</th><th>Actions</th></tr></thead>
                                          <tbody>
                                              <?php $sa_count = count($site_ads_assigned); if ($sa_count > 0): ?>
                                                  <?php foreach ($site_ads_assigned as $i => $sa):
                                                       $preview = ''; if ($sa['ad_type'] === 'text' && !empty($sa['ad_text'])) { $preview = htmlspecialchars(substr($sa['ad_text'], 0, 50)) . (strlen($sa['ad_text']) > 50 ? '...' : ''); } elseif ($sa['ad_type'] === 'image' && !empty($sa['image_path'])) { $url=htmlspecialchars($sa['image_path']); if (!filter_var($url, FILTER_VALIDATE_URL) && $url[0] !== '/' && strpos($url, '://') === false) { $url=rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . AD_UPLOAD_URL_BASE . basename($url); } $preview='<img src="' . $url . '" alt="Ad Preview" style="max-height: 30px; max-width: 80px; vertical-align: middle;">'; } else { $preview='<em>N/A</em>'; } ?>
                                                      <tr>
                                                          <td><?php echo htmlspecialchars($sa['display_order']); ?></td><td><?php echo htmlspecialchars($sa['ad_title'] ?: '<em>Untitled</em>'); ?></td><td><?php echo htmlspecialchars(ucfirst($sa['ad_type'])); ?></td><td><?php echo $preview; ?></td>
                                                          <td><span class="status-badge <?php echo $sa['site_is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $sa['site_is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                                          <td><span class="status-badge <?php echo $sa['global_is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $sa['global_is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                                          <td class="actions-cell">
                                                              <?php if ($i > 0): ?><form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="move_site_ad_up"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management"><button type="submit" class="btn btn-outline btn-sm" title="Move Up"><i class="fas fa-arrow-up"></i></button></form><?php endif; ?>
                                                              <?php if ($i < $sa_count - 1): ?><form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="move_site_ad_down"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management"><button type="submit" class="btn btn-outline btn-sm" title="Move Down"><i class="fas fa-arrow-down"></i></button></form><?php endif; ?>
                                                              <form method="POST" action="configurations.php" style="display: inline-block;"><input type="hidden" name="action" value="toggle_site_ad_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $sa['site_is_active'] ? 'Deactivate for Site' : 'Activate for Site'; ?>"><i class="fas <?php echo $sa['site_is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
                                                              <form method="POST" action="configurations.php" style="display: inline-block;" onsubmit="return confirm('Remove this ad assignment from this site?');"><input type="hidden" name="action" value="remove_site_ad"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="active_tab_on_submit" value="ads_management"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Remove from Site"><i class="fas fa-unlink"></i></button></form>
                                                          </td>
                                                      </tr>
                                                  <?php endforeach; ?>
                                              <?php else: ?><tr><td colspan="7" style="text-align: center;">No ads assigned to this site yet.</td></tr><?php endif; ?>
                                          </tbody>
                                      </table>
                                  </div>
                              <?php else: ?>
                                  <div class="message-area message-info">Please select a site from the dropdown above to manage its ad assignments.</div>
                              <?php endif; ?>
                         </div> <!-- End Site Ad Assignment Section -->
                     </div> <!-- End #ads-management -->

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

                // Update URL without reloading page
                try {
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('tab', targetPaneId);
                    const currentSiteId = siteSelector ? siteSelector.value : null;
                    // Site ID is needed for site_settings, site_questions, notifiers, ads_management (assignments)
                    if (['site_settings', 'site_questions', 'notifiers', 'ads_management'].includes(targetPaneId)) {
                        if (currentSiteId) {
                            currentUrl.searchParams.set('site_id', currentSiteId);
                        } else {
                             // If no site selected but on a site-specific tab, maybe default or remove? Removing for now.
                             currentUrl.searchParams.delete('site_id');
                        }
                    } else { // Global tabs don't need site ID in URL usually
                         currentUrl.searchParams.delete('site_id');
                    }
                    // Clean up view/edit params when switching tabs
                    currentUrl.searchParams.delete('view');
                    currentUrl.searchParams.delete('edit_item_id');
                    currentUrl.searchParams.delete('edit_ad_id');
                    window.history.replaceState({ path: currentUrl.toString() }, '', currentUrl.toString());
                } catch (e) { console.error("Error updating URL:", e); }
            }

            tabs.forEach(tab => { tab.addEventListener('click', function(e) { e.preventDefault(); activateTab(this); }); });

             // Site selector change reloads the page with new site ID and current tab
             if (siteSelector) {
                 siteSelector.onchange = function() {
                     const selectedSiteId = this.value;
                     const activeTabLink = document.querySelector('.tabs .tab.active');
                     const activeTabId = activeTabLink ? activeTabLink.getAttribute('data-tab') : 'site_settings';
                     // Always include site_id when changing via selector
                     location.href = `configurations.php?site_id=${selectedSiteId}&tab=${activeTabId}`;
                 };
             }
             // Activate the correct tab on initial load
            const initialActiveTab = document.querySelector(`.tabs .tab[data-tab="<?php echo htmlspecialchars($activeTab); ?>"]`);
            if (initialActiveTab) { activateTab(initialActiveTab); }
            else if (tabs.length > 0) { activateTab(tabs[0]); } // Fallback to first tab
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
             if (emailToggle) { emailToggle.addEventListener('change', toggleDescriptionVisibility); toggleDescriptionVisibility(); /* Initial check */ }
             document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(toggle => {
                 toggle.addEventListener('change', function() { updateToggleText(this); });
                 updateToggleText(toggle); // Initial text update
             });
         });
     </script>

     <!-- ADD JavaScript for conditional Ad Fields -->
     <script>
         function toggleAdFields() {
             // Function needed for both Add and potentially Edit forms if type change was allowed
             const adTypeSelect = document.getElementById('ad_type'); // Assumes Add form has this ID
             if (!adTypeSelect) return; // Only run if Add form's select exists

             const adType = adTypeSelect.value;
             const textGroup = document.getElementById('ad-text-group');
             const imageGroup = document.getElementById('ad-image-group');
             const textInput = document.getElementById('ad_text');
             const imageInput = document.getElementById('ad_image');

             if (adType === 'text') {
                 if(textGroup) textGroup.style.display = 'block';
                 if(imageGroup) imageGroup.style.display = 'none';
                 if(textInput) textInput.required = true;
                 if(imageInput) imageInput.required = false;
                 if(imageInput) imageInput.value = '';
             } else if (adType === 'image') {
                 if(textGroup) textGroup.style.display = 'none';
                 if(imageGroup) imageGroup.style.display = 'block';
                 if(textInput) textInput.required = false;
                 if(imageInput) imageInput.required = true;
                 if(textInput) textInput.value = '';
             }
         }
         // Add listener to Add form's type selector
         const addAdTypeSelect = document.getElementById('ad_type');
         if (addAdTypeSelect) {
            addAdTypeSelect.addEventListener('change', toggleAdFields);
         }
         // Run on page load to set initial state for Add form
         document.addEventListener('DOMContentLoaded', toggleAdFields);
         // Note: Edit form fields visibility is currently handled by inline PHP styles based on $edit_ad_data['ad_type']
     </script>

<?php require_once 'includes/footer.php'; ?>