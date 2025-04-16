<?php
// Prevent direct access
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    exit('Direct access is not allowed');
}


// Ensure required variables are available from configurations.php
if (!isset($pdo)) {
     error_log("Config Panel Error: Required variable \$pdo not set in ads_panel.php");
     echo "<div class='message-area message-error'>Configuration error: Required variable \$pdo not available.</div>";
     return; // Stop further execution
}
// $selected_config_site_id might be null, which is okay for global actions, but check where needed.

// Define Ad Upload Constants if not already defined (might be better in a central config file)
if (!defined('AD_UPLOAD_PATH')) {
    define('AD_UPLOAD_PATH', __DIR__ . '/../../assets/uploads/ads/'); // Physical path
}
if (!defined('AD_UPLOAD_URL_BASE')) {
    // Calculate relative URL path from web root dynamically
    $doc_root = $_SERVER['DOCUMENT_ROOT'];
    $script_dir = __DIR__; // Directory of this panel file
    // Go up two levels from includes/config_panels to the web root
    $web_root_path = dirname(dirname($script_dir));
    // Construct the relative path
    $relative_path = str_replace($doc_root, '', $web_root_path);
    $relative_path = str_replace('\\', '/', $relative_path); // Normalize slashes
    $relative_path = rtrim($relative_path, '/'); // Remove trailing slash if any
    define('AD_UPLOAD_URL_BASE', $relative_path . '/assets/uploads/ads/'); // Relative URL path
}


// --- START: Handle POST Actions for Ads ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_message'] = 'CSRF token validation failed. Request blocked.';
        $_SESSION['flash_type'] = 'error';
        error_log("CSRF token validation failed for ads_panel.php from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown')); // Keep basic failure log
        // Let configurations.php handle the redirect after setting the flash message
        return; // Stop processing this panel
    }

    // --- End CSRF Token Verification ---


    $action = $_POST['action'];
    $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT); // May be null for global actions
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT); // Used for deletes, toggles, moves, site_ad_id
    $edit_ad_id = filter_input(INPUT_POST, 'edit_ad_id', FILTER_VALIDATE_INT); // Used for global ad update
    $success = false;
    $message = "An error occurred performing the ad action.";
    $message_type = 'error';
    $transaction_started = false; // Track transaction state if needed
    // Initialize variables for potential file cleanup
    $destination_path_physical = null;
    $unique_filename = null;
    $uploaded_image_path_db = null;

    // Check if the action is relevant to this panel
    if (in_array($action, [
        'add_global_ad', 'delete_global_ad', 'toggle_global_ad_active', 'update_global_ad',
        'assign_site_ad', 'remove_site_ad', 'toggle_site_ad_active', 'move_site_ad_up', 'move_site_ad_down'
    ])) {
        // Validate site ID for site-specific actions
        if (in_array($action, ['assign_site_ad', 'remove_site_ad', 'toggle_site_ad_active', 'move_site_ad_up', 'move_site_ad_down'])) {
            // Detailed logging for site ID validation
            error_log("DEBUG ads_panel.php POST: Validating site ID. Action: {$action},
                Posted Site ID: " . var_export($posted_site_id, true) . ",
                Selected Config Site ID: " . var_export($selected_config_site_id, true) . ",
                Session Site ID: " . var_export($_SESSION['selected_config_site_id'] ?? 'Not Set', true) . ",
                GET Site ID: " . var_export($_GET['site_id'] ?? 'Not Set', true));

            // More robust site ID validation
            $sites_list = getAllSitesWithStatus($pdo);
            $valid_site_ids = array_column($sites_list, 'id');
            $valid_site_ids[] = null; // Allow null for global actions

            if ($posted_site_id !== null && !in_array($posted_site_id, $valid_site_ids)) {
                $_SESSION['flash_message'] = "Invalid site ID. Please select a valid site.";
                $_SESSION['flash_type'] = 'error';
                goto end_ad_post_handling;
            }

            // Additional check for site-specific actions
            if ($posted_site_id !== $selected_config_site_id) {
                $_SESSION['flash_message'] = "Site ID mismatch. Please ensure you're on the correct site configuration page.";
                $_SESSION['flash_type'] = 'error';
                goto end_ad_post_handling;
            }
        }

        try {
            switch ($action) {
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
                          if (!in_array($image_file['type'], $allowed_types)) { $message = "Invalid image type (Allowed: JPG, PNG, GIF, WEBP)."; break; }
                          if ($image_file['size'] > $max_size) { $message = "Image size exceeds 2MB limit."; break; }
                          $extension = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
                          $safe_filename_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($image_file['name'], PATHINFO_FILENAME));
                          $unique_filename = uniqid($safe_filename_base . '_', true) . '.' . $extension;
                          $destination_path_physical = AD_UPLOAD_PATH . $unique_filename;
                          // Ensure directory exists and is writable
                          if (!is_dir(AD_UPLOAD_PATH)) { if (!mkdir(AD_UPLOAD_PATH, 0755, true)) { error_log("Config ERROR: Failed to create ad upload directory: " . AD_UPLOAD_PATH); $message = "Server error: Cannot create upload directory."; break; } }
                          if (!is_writable(AD_UPLOAD_PATH)) { error_log("Config ERROR: Ad upload directory not writable: " . AD_UPLOAD_PATH); $message = "Server error: Cannot write to upload directory."; break; }
                          // Move the file
                          if (move_uploaded_file($image_file['tmp_name'], $destination_path_physical)) {
                               $uploaded_image_path_db = AD_UPLOAD_URL_BASE . $unique_filename; // Store relative URL path
                               error_log("Admin Action: Uploaded Ad Image '{$unique_filename}' to '{$destination_path_physical}'");
                          } else { error_log("Config ERROR: Failed move_uploaded_file for ad '{$image_file['name']}' to '{$destination_path_physical}'. Check permissions/path."); $message = "Failed to save uploaded image."; break; }
                     } elseif ($ad_type === 'image' && $image_file && $image_file['error'] !== UPLOAD_ERR_OK && $image_file['error'] !== UPLOAD_ERR_NO_FILE) {
                          $upload_errors = [ UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive.', UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive.', UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.', UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.', UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.', UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.', ];
                          $message = "Upload error: " . ($upload_errors[$image_file['error']] ?? "Unknown error code {$image_file['error']}."); error_log("Config upload error: {$message}"); break;
                     }

                     // Add global ad using data access function (handles transaction)
                     $new_ad_id = addGlobalAd($pdo, $ad_type, $ad_title, $ad_text, $uploaded_image_path_db, $is_active);
                     if ($new_ad_id !== false) {
                          $success = true; $message = "Global ad added."; $message_type = 'success'; error_log("Admin Action: Added Global Ad ID {$new_ad_id}.");
                     } else {
                          $message = "DB error saving ad."; // Error logged in function
                          // Cleanup uploaded file if DB insert failed
                          if ($uploaded_image_path_db && $destination_path_physical && file_exists($destination_path_physical)) {
                               unlink($destination_path_physical); error_log("Config Warning: Deleted orphaned ad image '{$unique_filename}' (DB fail).");
                          }
                     }
                     break;

                case 'delete_global_ad':
                     if ($item_id) { // item_id is global_ad_id here
                          // Get image path first for potential deletion
                          $image_path = getGlobalAdImagePath($pdo, $item_id);
                          // Delete ad using data access function (handles transaction)
                          if (deleteGlobalAd($pdo, $item_id)) {
                               $success = true; $message = "Global ad deleted."; $message_type = 'success'; error_log("Admin Action: Deleted Global Ad ID {$item_id}.");
                               // Attempt file deletion if path exists
                               if ($image_path) {
                                    $physical_path = AD_UPLOAD_PATH . basename($image_path); // Use basename to prevent path traversal
                                    if (file_exists($physical_path)) {
                                         if (!unlink($physical_path)) { $message .= " <strong style='color:orange;'>Warning:</strong> Failed to delete image file."; error_log("Config ERROR: Failed to delete image '{$physical_path}'."); }
                                         else { error_log("Admin Action: Deleted image '{$physical_path}'."); }
                                    } else { error_log("Config Warning: Image file '{$physical_path}' not found for deleted ad ID {$item_id}."); }
                               }
                          } else { $message = "DB error deleting ad."; } // Error logged in function
                     } else { $message = "Invalid ID."; }
                     // No explicit transaction management needed here as deleteGlobalAd handles it.
                     break;

                case 'toggle_global_ad_active':
                      if ($item_id) { // item_id is global_ad_id here
                           // Toggle status using data access function (handles transaction)
                           if (toggleGlobalAdActive($pdo, $item_id)) {
                                $success = true; $message = "Global ad status toggled."; $message_type = 'success';
                           } else { $message = "DB error toggling status."; } // Error logged in function
                      } else { $message = "Invalid ID."; }
                      // No explicit transaction management needed here as toggleGlobalAdActive handles it.
                      break;

                case 'update_global_ad':
                    if (!$edit_ad_id) { $message = "Invalid Ad ID."; break; } // Use $edit_ad_id from POST
                    $ad_type = $_POST['edit_ad_type'] ?? null; $ad_title = trim($_POST['edit_ad_title'] ?? ''); $ad_text = trim($_POST['edit_ad_text'] ?? ''); $is_active = isset($_POST['edit_is_active']) ? 1 : 0; $image_file = $_FILES['edit_ad_image'] ?? null; $delete_current_image = isset($_POST['delete_current_image']) ? 1 : 0;
                     if (!in_array($ad_type, ['text', 'image'])) { $message = "Invalid ad type."; break; }
                     if ($ad_type === 'text' && empty($ad_text)) { $message = "Text content required."; break; }

                     // Fetch old ad data using data access function
                     $old = getGlobalAdById($pdo, $edit_ad_id);
                     if (!$old) { $message = "Ad not found."; break; }
                     $old_image_path = $old['image_path'];
                     $image_path_to_save = $old_image_path; // Start with current path

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

                    // Update Database using data access function (handles transaction)
                    if (updateGlobalAd($pdo, $edit_ad_id, $ad_type, $ad_title, $ad_text, $image_path_to_save, $is_active)) {
                        $success = true; $message = "Global ad updated."; $message_type = 'success'; error_log("Admin Action: Updated Global Ad ID {$edit_ad_id}.");
                        // Determine if old image needs deleting AFTER successful DB update
                          $image_to_delete = null;
                          if ($uploaded_image_path_db && $old_image_path && $old_image_path !== $uploaded_image_path_db) { $image_to_delete = $old_image_path; } // Replaced
                          elseif ($old_image_path && $image_path_to_save === null) { $image_to_delete = $old_image_path; } // Deleted or type change
                          // Attempt deletion
                          if ($image_to_delete) {
                               $physical_path = AD_UPLOAD_PATH . basename($image_to_delete); // Use basename
                               if (file_exists($physical_path)) {
                                    if (!unlink($physical_path)) { error_log("Config ERROR: Failed to delete old image '{$physical_path}' after ad update."); }
                                    else { error_log("Admin Action: Deleted old image '{$physical_path}' after ad update."); }
                               } else { error_log("Config Warning: Old image '{$physical_path}' not found for deletion after ad update."); }
                          }
                     } else {
                        $message = "DB error updating ad."; // Error logged in function
                        // Cleanup newly uploaded file if DB update failed
                          if ($uploaded_image_path_db && $destination_path_physical && file_exists($destination_path_physical)) {
                               unlink($destination_path_physical); error_log("Config Warning: Deleted new image '{$unique_filename}' (DB update fail).");
                          }
                     }
                    break;

                // ========================
                // Site Ad Assignment Actions
                // ========================
                case 'assign_site_ad':
                     $ga_id = filter_input(INPUT_POST, 'global_ad_id', FILTER_VALIDATE_INT); $active = isset($_POST['assign_is_active']) ? 1 : 0;
                     if ($posted_site_id && $ga_id) {
                          // Assign using data access function (handles transaction and order)
                          if (assignAdToSite($pdo, $posted_site_id, $ga_id, $active)) {
                               $success = true; $message = "Ad assigned."; $message_type = 'success';
                          } else { $message = "Failed to assign ad (already assigned or DB error)."; } // Error logged in function
                     } else { $message = "Invalid site/ad ID."; }
                     break;
                case 'remove_site_ad':
                     if ($posted_site_id && $item_id) { // item_id is site_ad_id
                          // Remove using data access function
                          if (removeAdFromSite($pdo, $item_id, $posted_site_id)) {
                               // Reorder separately using the function from ad_data.php
                               if (reorder_items($pdo, 'site_ads', 'display_order', 'site_id', $posted_site_id)) {
                                    $success = true; $message = "Ad removed & reordered."; $message_type = 'success';
                               } else { $message = "Ad removed, but failed to reorder items."; $message_type = 'warning'; } // Reorder error logged in function
                          } else { $message = "Failed to remove ad assignment."; } // Error logged in function
                     } else { $message = "Invalid site/item ID."; }
                     break;
                case 'toggle_site_ad_active':
                     if ($posted_site_id && $item_id) { // item_id is site_ad_id
                          // Toggle using data access function (handles transaction)
                          if (toggleSiteAdActive($pdo, $item_id, $posted_site_id)) {
                               $success = true; $message = "Site ad status toggled."; $message_type = 'success';
                          } else { $message = "Failed to toggle site ad status."; } // Error logged in function
                     } else { $message = "Invalid site/item ID."; }
                     // No explicit transaction management needed here as toggleSiteAdActive handles it.
                     break;
                case 'move_site_ad_up':
                case 'move_site_ad_down':
                    if ($posted_site_id && $item_id) { // item_id is site_ad_id
                         $direction = ($action === 'move_site_ad_up') ? 'up' : 'down';
                         // reorder_items handles its own transaction internally
                         if(reorder_items($pdo, 'site_ads', 'display_order', 'site_id', $posted_site_id, $item_id, $direction)) {
                             $success = true; $message = "Site ad reordered."; $message_type = 'success';
                         } else { $message = "Failed reorder."; } // Error logged in function
                    } else { $message = "Invalid site/item ID."; }
                    break;

            } // End switch ($action)

            // Set flash message after processing
            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_type'] = $message_type;

        } catch (PDOException $e) {
             if ($transaction_started && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to PDOException in ad action '{$action}'."); }
             // Cleanup uploaded ad file on generic DB error if applicable
              if (($action === 'add_global_ad' || $action === 'update_global_ad') && $destination_path_physical && file_exists($destination_path_physical)) {
                   unlink($destination_path_physical); error_log("Config Warning: Deleted orphaned ad image '{$unique_filename}' due to PDOException during '{$action}'.");
              }
             $success = false; $_SESSION['flash_message'] = "Database error processing ad action '{$action}'. Details logged."; $_SESSION['flash_type'] = 'error';
             error_log("PDOException processing ad action '{$action}' for site {$posted_site_id}, item {$item_id}/{$edit_ad_id}: " . $e->getMessage());
        } catch (Exception $e) {
             if ($transaction_started && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to general Exception in ad action '{$action}'."); }
             // Cleanup uploaded ad file on generic error if applicable
             if (($action === 'add_global_ad' || $action === 'update_global_ad') && $destination_path_physical && file_exists($destination_path_physical)) {
                  unlink($destination_path_physical); error_log("Config Warning: Deleted orphaned ad image '{$unique_filename}' due to Exception during '{$action}'.");
             }
             $success = false; $_SESSION['flash_message'] = "General error processing ad action '{$action}'. Details logged."; $_SESSION['flash_type'] = 'error';
             error_log("Exception processing ad action '{$action}' for site {$posted_site_id}, item {$item_id}/{$edit_ad_id}: " . $e->getMessage());
        }
        // Let configurations.php handle the redirect
    } // End check for relevant action
}
end_ad_post_handling: // Label for goto jump
// --- END: Handle POST Actions for Ads ---


// --- START: Logic for Edit View (Fetching data before display) ---
$view_state = $_GET['view'] ?? 'list'; // Default to 'list' view
$edit_ad_id_get = filter_input(INPUT_GET, 'edit_ad_id', FILTER_VALIDATE_INT); // Used for Ad edit (from GET)
$edit_ad_data = null;
$panel_error_message = ''; // Panel specific error

// Fetch Global Ad Edit Data only if view state and ID are correct
if ($view_state === 'edit_global_ad' && $edit_ad_id_get) {
    try {
        // Fetch using data access function
        $edit_ad_data = getGlobalAdById($pdo, $edit_ad_id_get);
        if(!$edit_ad_data) {
             // Set flash message and redirect from configurations.php if needed
             $_SESSION['flash_message'] = "Global Ad not found for editing.";
             $_SESSION['flash_type'] = 'warning';
             // Clear edit state variables
             $view_state = 'list';
             $edit_ad_id_get = null;
             // No header() call here
        }
    } catch (PDOException $e) {
        error_log("Config Panel Error (Ads) - Fetching edit data for ad {$edit_ad_id_get}: " . $e->getMessage());
        $panel_error_message = "Database error loading global ad for editing.";
        $view_state = 'list'; // Revert to list view on error
        $edit_ad_id_get = null;
    }
}
// --- END: Logic for Edit View ---


// --- START: Fetch Data for Display (List Views & Dropdowns) ---
$global_ads_list = [];
$site_ads_assigned = [];
$site_ads_available = [];

// 1. Fetch Global Ads (always needed for this panel)
$global_ads_list = getAllGlobalAds($pdo);
if ($global_ads_list === false) { // Check for false explicitly on error
    $panel_error_message .= " Error loading global ads.";
    $global_ads_list = []; // Ensure it's an array
}

// 2. Fetch Site-Specific Ad Data if a site is selected
if ($selected_config_site_id !== null) {
     try {
         // Fetch Assigned Site Ads using data access function
         $site_ads_assigned = getSiteAdsAssigned($pdo, $selected_config_site_id);
         if ($site_ads_assigned === false) { // Check for false explicitly on error
             $panel_error_message .= " Error loading site ad assignments.";
             $site_ads_assigned = []; // Ensure it's an array
         }

         // Determine Available Global Ads (only list ACTIVE global ads)
         $assigned_ga_ids = array_column($site_ads_assigned, 'global_ad_id');
         $site_ads_available = [];
         if (!empty($global_ads_list)) {
             foreach ($global_ads_list as $ga_data) {
                 // Available only if global ad is active AND not already assigned to this site
                 if ($ga_data['is_active'] && !in_array($ga_data['id'], $assigned_ga_ids)) {
                     $site_ads_available[$ga_data['id']] = $ga_data;
                 }
             }
         }

     } catch (PDOException $e) {
          error_log("Config Panel Error (Ads) - Fetching site data for site {$selected_config_site_id}: " . $e->getMessage());
          $panel_error_message .= " Error loading site-specific ad data.";
          $site_ads_assigned = []; // Reset on error
          $site_ads_available = []; // Reset on error
     }
} else {
    // No site selected, determine available based only on global active status
    $site_ads_available = [];
     if (!empty($global_ads_list)) {
         foreach ($global_ads_list as $ga_data) {
             if ($ga_data['is_active']) {
                 $site_ads_available[$ga_data['id']] = $ga_data;
             }
         }
     }
}
// --- END: Fetch Data for Display ---

// Helper function to generate correct image URL for previews
function get_ad_image_preview_url($image_path) {
    if (empty($image_path)) return '';
    // Check if it's already a full URL
    if (filter_var($image_path, FILTER_VALIDATE_URL)) {
        return htmlspecialchars($image_path);
    }
    // Check if it's an absolute path (less likely but possible)
    if ($image_path[0] === '/') {
         return htmlspecialchars($image_path);
    }
    // Assume it's relative to the AD_UPLOAD_URL_BASE
    // Ensure AD_UPLOAD_URL_BASE ends with a slash and image_path doesn't start with one
    $base = rtrim(AD_UPLOAD_URL_BASE, '/') . '/';
    $image_name = ltrim(basename($image_path), '/'); // Use basename for safety
    return htmlspecialchars($base . $image_name);
}

?>

<!-- Display Panel Specific Errors -->
<?php if ($panel_error_message): ?>
    <div class="message-area message-error"><?php echo htmlspecialchars($panel_error_message); ?></div>
<?php endif; ?>


<!-- Global Ads Library Section -->
<div class="settings-section">
    <h3 class="settings-section-title">Global Ads Library</h3>
    <p>Manage advertisements that can be displayed on the check-in page sidebars. Ads must be active here AND assigned/activated per site to display.</p>

    <!-- Add/Edit Global Ad Form Section -->
    <div class="admin-form-container form-section">
        <?php if ($view_state === 'edit_global_ad' && $edit_ad_data): // Use $edit_ad_data fetched earlier ?>
            <!-- == EDIT GLOBAL AD FORM == -->
            <h4 class="form-section-title">Edit Global Ad</h4>
            <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id ?? 'all'; ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_global_ad">
                <input type="hidden" name="submitted_tab" value="ads-management">
                <input type="hidden" name="edit_ad_id" value="<?php echo $edit_ad_data['id']; ?>">
                <input type="hidden" name="edit_ad_type" value="<?php echo htmlspecialchars($edit_ad_data['ad_type']); ?>">
                <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="settings-form two-column">
                    <div class="form-group"><label for="edit_ad_title" class="form-label">Ad Title (for Admin):</label><input type="text" id="edit_ad_title" name="edit_ad_title" class="form-control" maxlength="150" value="<?php echo htmlspecialchars($edit_ad_data['ad_title'] ?? ''); ?>"></div>
                    <div class="form-group"><label class="form-label">Ad Type:</label><p style="padding-top: 0.5rem;"><strong><?php echo htmlspecialchars(ucfirst($edit_ad_data['ad_type'])); ?> Ad</strong></p></div>
                    <div class="form-group full-width" id="edit-ad-text-group" style="<?php echo $edit_ad_data['ad_type'] !== 'text' ? 'display: none;' : ''; ?>"><label for="edit_ad_text" class="form-label">Ad Text Content:</label><textarea id="edit_ad_text" name="edit_ad_text" class="form-control" rows="4" <?php echo $edit_ad_data['ad_type'] === 'text' ? 'required' : ''; ?>><?php echo htmlspecialchars($edit_ad_data['ad_text'] ?? ''); ?></textarea></div>
                    <div id="edit-ad-image-group" style="<?php echo $edit_ad_data['ad_type'] !== 'image' ? 'display: none;' : ''; ?>">
                        <div class="form-group full-width"><label class="form-label">Current Image:</label><?php $preview_url = get_ad_image_preview_url($edit_ad_data['image_path']); $preview = '<em>No current image.</em>'; if (!empty($preview_url)) { $preview='<img src="' . $preview_url . '" alt="Current Ad Image" style="max-height: 60px; max-width: 150px; border: 1px solid #ccc; margin-bottom: 5px;">'; } echo $preview; ?><?php if (!empty($edit_ad_data['image_path'])): ?><br><label class="form-check-label"><input type="checkbox" name="delete_current_image" value="1" class="form-check-input"> Delete current image?</label><?php endif; ?></div>
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
            <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id ?? 'all'; ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_global_ad">
                <input type="hidden" name="submitted_tab" value="ads-management">
                <?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
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
                          $preview_url = get_ad_image_preview_url($ad['image_path']);
                          $preview = '';
                          if ($ad['ad_type'] === 'text' && !empty($ad['ad_text'])) { $preview = htmlspecialchars(substr(strip_tags($ad['ad_text']), 0, 75)) . (strlen(strip_tags($ad['ad_text'])) > 75 ? '...' : ''); }
                          elseif ($ad['ad_type'] === 'image' && !empty($preview_url)) { $preview='<img src="' . $preview_url . '" alt="Ad Preview" style="max-height: 40px; max-width: 100px; vertical-align: middle;">'; }
                          else { $preview='<em>N/A</em>'; } ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ad['ad_title'] ?: '<em>Untitled</em>'); ?></td><td><?php echo htmlspecialchars(ucfirst($ad['ad_type'])); ?></td><td><?php echo $preview; ?></td><td><span class="status-badge <?php echo $ad['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $ad['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                            <td class="actions-cell">
                                <a href="configurations.php?tab=ads_management&view=edit_global_ad&edit_ad_id=<?php echo $ad['id']; ?><?php echo $selected_config_site_id ? '&site_id='.$selected_config_site_id : ''; ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id ?? 'all'; ?>" style="display: inline-block;"><input type="hidden" name="action" value="toggle_global_ad_active">
                <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="item_id" value="<?php echo $ad['id']; ?>"><?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $ad['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $ad['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"></button></form>
                                <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id ?? 'all'; ?>" style="display: inline-block;" onsubmit="return confirm('Delete this global ad? This cannot be undone.');"><input type="hidden" name="action" value="delete_global_ad">
                <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="item_id" value="<?php echo $ad['id']; ?>"><?php if ($selected_config_site_id): ?><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><?php endif; ?><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Delete"><i class="fas fa-trash"></i></button></form>
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
              <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id; ?>">
                  <input type="hidden" name="action" value="assign_site_ad">
               <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                   <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
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
                               $preview_url = get_ad_image_preview_url($sa['image_path']);
                               $preview = '';
                               if ($sa['ad_type'] === 'text' && !empty($sa['ad_text'])) { $preview = htmlspecialchars(substr(strip_tags($sa['ad_text']), 0, 50)) . (strlen(strip_tags($sa['ad_text'])) > 50 ? '...' : ''); }
                               elseif ($sa['ad_type'] === 'image' && !empty($preview_url)) { $preview='<img src="' . $preview_url . '" alt="Ad Preview" style="max-height: 30px; max-width: 80px; vertical-align: middle;">'; }
                               else { $preview='<em>N/A</em>'; } ?>
                              <tr>
                                  <td><?php echo htmlspecialchars($sa['display_order']); ?></td><td><?php echo htmlspecialchars($sa['ad_title'] ?: '<em>Untitled</em>'); ?></td><td><?php echo htmlspecialchars(ucfirst($sa['ad_type'])); ?></td><td><?php echo $preview; ?></td>
                                  <td><span class="status-badge <?php echo $sa['site_is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $sa['site_is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                  <td><span class="status-badge <?php echo $sa['global_is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $sa['global_is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                  <td class="actions-cell">
                                      <?php if ($i > 0): ?><form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id; ?>" style="display: inline-block;"><input type="hidden" name="action" value="move_site_ad_up">
               <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><button type="submit" class="btn btn-outline btn-sm" title="Move Up"><i class="fas fa-arrow-up"></i></button></form><?php endif; ?>
                                      <?php if ($i < $sa_count - 1): ?><form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id; ?>" style="display: inline-block;"><input type="hidden" name="action" value="move_site_ad_down">
               <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><button type="submit" class="btn btn-outline btn-sm" title="Move Down"><i class="fas fa-arrow-down"></i></button></form><?php endif; ?>
                                      <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id; ?>" style="display: inline-block;"><input type="hidden" name="action" value="toggle_site_ad_active">
               <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $sa['site_is_active'] ? 'Deactivate for Site' : 'Activate for Site'; ?>"><i class="fas <?php echo $sa['site_is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></button></form>
               <form method="POST" action="configurations.php?tab=ads_management&site_id=<?php echo $selected_config_site_id; ?>" style="display: inline-block;" onsubmit="return confirm('Remove this ad assignment from this site?');"><input type="hidden" name="action" value="remove_site_ad">
                <input type="hidden" name="submitted_tab" value="ads-management"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $sa['site_ad_id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><button type="submit" class="btn btn-outline btn-sm delete-button" title="Remove from Site"><i class="fas fa-unlink"></i></button></form>
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


 <!-- JS for conditional Ad Fields -->
 <script>
     function toggleAdFields() {
         // Function needed for Add form
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
             if(imageInput) imageInput.value = ''; // Clear file input
         } else if (adType === 'image') {
             if(textGroup) textGroup.style.display = 'none';
             if(imageGroup) imageGroup.style.display = 'block';
             if(textInput) textInput.required = false;
             // Requirement for image is handled by POST validation, not strictly required on front-end
             // if(imageInput) imageInput.required = true;
             if(textInput) textInput.value = ''; // Clear text input
         }
     }
     // Add listener to Add form's type selector
     const addAdTypeSelect = document.getElementById('ad_type');
     if (addAdTypeSelect) {
        addAdTypeSelect.addEventListener('change', toggleAdFields);
     }
     // Run on page load to set initial state for Add form
     document.addEventListener('DOMContentLoaded', toggleAdFields);
     // Note: Edit form fields visibility is handled by inline PHP styles based on $edit_ad_data['ad_type']
 </script>