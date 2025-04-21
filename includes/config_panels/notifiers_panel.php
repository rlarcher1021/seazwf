<?php
// Prevent direct access
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    exit('Direct access is not allowed');
}

// Ensure required variables are available from configurations.php
if (!isset($pdo) || !isset($selected_config_site_id)) {
     error_log("Config Panel Error: Required variables (\$pdo, \$selected_config_site_id) not set in notifiers_panel.php");
     echo "<div class='message-area message-error'>Configuration error: Required variables not available.</div>";
     return; // Stop further execution
}

// --- START: Handle POST Actions for Notifiers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_message'] = 'CSRF token validation failed. Request blocked.';
        $_SESSION['flash_type'] = 'error';
        error_log("CSRF token validation failed for notifiers_panel.php from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        // Let configurations.php handle the redirect after setting the flash message
        return; // Stop processing this panel
    }
    // --- End CSRF Token Verification ---


    $action = $_POST['action'];
    $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT); // Used for edit, delete, toggle
    $success = false;
    $message = "An error occurred performing the notifier action.";
    $message_type = 'error';
    $transaction_started = false; // Track transaction state if needed

    // Ensure the action is relevant to this panel and site ID matches
    if (in_array($action, ['add_notifier', 'edit_notifier', 'delete_notifier', 'toggle_notifier_active'])) {
        if (!$posted_site_id || $posted_site_id != $selected_config_site_id) {
            $_SESSION['flash_message'] = "Invalid or mismatched site ID for notifier action.";
            $_SESSION['flash_type'] = 'error';
            // Let configurations.php handle redirect
        } else {
            try {
                switch ($action) {
                    case 'add_notifier':
                         $name = trim($_POST['staff_name'] ?? ''); $email = filter_input(INPUT_POST, 'staff_email', FILTER_VALIDATE_EMAIL);
                         if ($posted_site_id && !empty($name) && $email) {
                              // Add using data access function (handles transaction)
                              if (addNotifier($pdo, $posted_site_id, $name, $email)) {
                                   $success = true; $message = "Notifier added."; $message_type = 'success';
                              } else { $message = "Failed to add notifier."; } // Error logged in function
                         } else { $message = "Invalid input (Name and valid Email required)."; }
                         break;
                    case 'edit_notifier':
                         $name = trim($_POST['staff_name_edit'] ?? ''); $email = filter_input(INPUT_POST, 'staff_email_edit', FILTER_VALIDATE_EMAIL);
                         if ($posted_site_id && $item_id && !empty($name) && $email) {
                              // Update using data access function (handles transaction)
                              if (updateNotifier($pdo, $item_id, $posted_site_id, $name, $email)) {
                                   $success = true; $message = "Notifier updated."; $message_type = 'success';
                              } else { $message = "Failed to update notifier."; } // Error logged in function
                         } else { $message = "Invalid input (Name and valid Email required)."; }
                         break;
                    case 'delete_notifier':
                         if ($posted_site_id && $item_id) {
                              // Delete using data access function (handles transaction)
                              if (deleteNotifier($pdo, $item_id, $posted_site_id)) {
                                   $success = true; $message = "Notifier deleted."; $message_type = 'success';
                              } else { $message = "Failed to delete notifier."; } // Error logged in function
                         } else { $message = "Invalid site/item ID."; }
                         break;
                    case 'toggle_notifier_active':
                         if ($posted_site_id && $item_id) {
                              // Toggle using data access function (handles transaction)
                              if (toggleNotifierActive($pdo, $item_id, $posted_site_id)) {
                                   $success = true; $message = "Notifier status toggled."; $message_type = 'success';
                              } else { $message = "Failed to toggle notifier status."; } // Error logged in function
                         } else { $message = "Invalid site/item ID."; }
                         // No explicit transaction management needed here as toggleNotifierActive handles it.
                         break;
                } // End switch

                // Set flash message after processing
                $_SESSION['flash_message'] = $message;
                $_SESSION['flash_type'] = $message_type;

            } catch (PDOException $e) {
                 if ($transaction_started && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to PDOException in notifier action '{$action}'."); }
                 $success = false; $_SESSION['flash_message'] = "Database error processing notifier action '{$action}'. Details logged."; $_SESSION['flash_type'] = 'error';
                 error_log("PDOException processing notifier action '{$action}' for site {$posted_site_id}, item {$item_id}: " . $e->getMessage());
            } catch (Exception $e) {
                 if ($transaction_started && $pdo->inTransaction()) { $pdo->rollBack(); error_log("Transaction rolled back due to general Exception in notifier action '{$action}'."); }
                 $success = false; $_SESSION['flash_message'] = "General error processing notifier action '{$action}'. Details logged."; $_SESSION['flash_type'] = 'error';
                 error_log("Exception processing notifier action '{$action}' for site {$posted_site_id}, item {$item_id}: " . $e->getMessage());
            }
            // Let configurations.php handle the redirect
        }
    } // End check for relevant action
}
// --- END: Handle POST Actions for Notifiers ---


// --- START: Logic for Edit View (Fetching data before display) ---
$view_state = $_GET['view'] ?? 'list'; // Default to 'list' view
$edit_item_id = filter_input(INPUT_GET, 'edit_item_id', FILTER_VALIDATE_INT); // Used for Notifier edit
$edit_notifier_data = null;
$panel_error_message = ''; // Panel specific error

// Fetch Notifier Edit Data only if view state and IDs are correct
if ($view_state === 'edit_notifier' && $edit_item_id && $selected_config_site_id) {
    try {
        // Fetch using data access function
        $edit_notifier_data = getNotifierByIdAndSite($pdo, $edit_item_id, $selected_config_site_id);
        if(!$edit_notifier_data) {
             // Set flash message and redirect from configurations.php if needed
             $_SESSION['flash_message'] = "Notifier not found or does not belong to this site.";
             $_SESSION['flash_type'] = 'warning';
             // Clear edit state variables
             $view_state = 'list';
             $edit_item_id = null;
             // No header() call here, let configurations.php redirect after includes
        }
    } catch (PDOException $e) {
        error_log("Config Panel Error (Notifiers) - Fetching edit data for item {$edit_item_id}, site {$selected_config_site_id}: " . $e->getMessage());
        $panel_error_message = "Database error loading notifier for editing.";
        $view_state = 'list'; // Revert to list view on error
        $edit_item_id = null;
    }
}
// --- END: Logic for Edit View ---


// --- START: Fetch Data for List View ---
$notifiers = [];
if ($selected_config_site_id !== null) {
    try {
        // Fetch Notifiers using data access function
        $notifiers = getAllNotifiersForSite($pdo, $selected_config_site_id);
        if ($notifiers === false) { // Check for false explicitly on error
            $panel_error_message .= " Error loading notifiers list.";
            $notifiers = []; // Ensure it's an array
        }
    } catch (PDOException $e) {
        error_log("Config Panel Error (Notifiers) - Fetching list for site {$selected_config_site_id}: " . $e->getMessage());
        $panel_error_message .= " Database error loading notifiers list.";
        $notifiers = []; // Ensure it's an array
    }
}
// --- END: Fetch Data for List View ---

?>

<!-- Display Panel Specific Errors -->
<?php if ($panel_error_message): ?>
    <div class="message-area message-error"><?php echo htmlspecialchars($panel_error_message); ?></div>
<?php endif; ?>


<!-- Notifier Management Content -->
<?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
    <div class="settings-section">
        <h3 class="settings-section-title">Manage Email Notifiers for: <?php echo htmlspecialchars($selected_site_details['name']); ?></h3>

        <!-- Add/Edit Forms -->
       <?php if($view_state === 'add_notifier'): ?>
            <div class="admin-form-container form-section">
                <h4 class="subsection-title">Add New Notifier</h4>
                <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>">
                     <input type="hidden" name="action" value="add_notifier">
                     <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                     <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <div class="settings-form two-column">
                        <div class="mb-3"><label for="add_n_name" class="form-label">Staff Name:</label><input type="text" id="add_n_name" name="staff_name" class="form-control" required></div>
                        <div class="mb-3"><label for="add_n_email" class="form-label">Staff Email:</label><input type="email" id="add_n_email" name="staff_email" class="form-control" required></div>
                    </div>
                    <div class="form-actions"> <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Notifier</button> <a href="configurations.php?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers" class="btn btn-outline">Cancel</a> </div>
                </form>
            </div>
       <?php elseif($view_state === 'edit_notifier' && $edit_notifier_data): ?>
             <div class="admin-form-container form-section">
                 <h4 class="subsection-title">Edit Notifier</h4>
                 <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>">
                      <input type="hidden" name="action" value="edit_notifier">
                      <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                      <input type="hidden" name="item_id" value="<?php echo $edit_notifier_data['id']; ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                     <div class="settings-form two-column">
                         <div class="mb-3"><label for="edit_n_name" class="form-label">Staff Name:</label><input type="text" id="edit_n_name" name="staff_name_edit" class="form-control" required value="<?php echo htmlspecialchars($edit_notifier_data['staff_name']); ?>"></div>
                         <div class="mb-3"><label for="edit_n_email" class="form-label">Staff Email:</label><input type="email" id="edit_n_email" name="staff_email_edit" class="form-control" required value="<?php echo htmlspecialchars($edit_notifier_data['staff_email']); ?>"></div>
                     </div>
                     <div class="form-actions"> <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button> <a href="configurations.php?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers" class="btn btn-outline">Cancel</a> </div>
                 </form>
             </div>
       <?php endif; ?>

        <!-- Notifier List Table -->
        <?php if($view_state === 'list'): ?>
        <h4 class="form-section-title">Existing Notifiers</h4>
        <div class="table-container">
            <table class="table table-striped table-hover">
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
                                     <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block"><input type="hidden" name="action" value="toggle_notifier_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $n['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $n['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"></button></form>
                                     <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block" onsubmit="return confirm('Delete this notifier?');"><input type="hidden" name="action" value="delete_notifier"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?> <tr><td colspan="4" class="text-center">No notifiers defined for this site.</td></tr> <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions"> <a href="?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers&view=add_notifier" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Notifier</a> </div>
        <?php endif; // End view=list condition ?>
    </div>
<?php else: ?>
    <div class="message-area message-info">Please select a site using the dropdown above to manage its notifiers.</div>
<?php endif; ?>