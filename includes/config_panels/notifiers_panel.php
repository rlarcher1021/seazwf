<?php
// Prevent direct access
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    exit('Direct access is not allowed');
}

// Ensure required variables are available from configurations.php
// $pdo, $selected_config_site_id, $session_role, $is_site_admin, $session_site_id (conditionally)
$required_vars_missing = false;
$error_detail = '';
if (!isset($pdo)) { $required_vars_missing = true; $error_detail = '$pdo'; }
elseif (!isset($session_role)) { $required_vars_missing = true; $error_detail = '$session_role'; }
elseif (!isset($is_site_admin)) { $required_vars_missing = true; $error_detail = '$is_site_admin'; }
elseif ($is_site_admin === 1 && !isset($session_site_id)) {
    // Site admins MUST have their own site ID set
    $required_vars_missing = true;
    $error_detail = '$session_site_id (for Site Admin)';
} elseif (!isset($selected_config_site_id)) {
     // This panel requires a site to be selected for configuration
     $required_vars_missing = true;
     $error_detail = '$selected_config_site_id';
}

if ($required_vars_missing) {
     error_log("Config Panel Error: Required variable {$error_detail} not set in notifiers_panel.php");
     echo "<div class='message-area message-error'>Configuration error: Required context variable ({$error_detail}) not available for this panel.</div>";
     return; // Stop further execution
}
// $selected_config_site_id can be null if no site is selected by admin/director, or if site admin's site is invalid. Check where needed.

// --- Permission Helper ---
// Can the current user manage the currently selected site's notifiers?
$can_manage_selected_site_notifiers = false;
if ($selected_config_site_id !== null) {
    if (in_array($session_role, ['administrator', 'director'])) {
        $can_manage_selected_site_notifiers = true; // Admins/Directors can manage any selected site
    } elseif ($is_site_admin === 1 && $selected_config_site_id === $session_site_id) {
        $can_manage_selected_site_notifiers = true; // Site Admin can manage their own site
    }
}

// Include necessary data access functions (already included in configurations.php)
require_once __DIR__ . '/../data_access/notifier_data.php';
require_once __DIR__ . '/../data_access/site_data.php'; // Needed for getSiteDetailsById

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

    // --- Permission Check for POST Action ---
    $action_allowed = false;
    if (in_array($action, ['add_notifier', 'edit_notifier', 'delete_notifier', 'toggle_notifier_active'])) {
        // Check if the user can manage the specific site the action targets
        if ($posted_site_id && $posted_site_id == $selected_config_site_id) {
            if (in_array($session_role, ['administrator', 'director'])) {
                $action_allowed = true;
            } elseif ($is_site_admin === 1 && $posted_site_id === $session_site_id) {
                $action_allowed = true;
            } else {
                 $message = "Access Denied: You do not have permission to manage notifiers for this site (ID: {$posted_site_id}).";
            }
        } else {
             $message = "Site ID mismatch or missing. Cannot perform action.";
             error_log("Notifiers Panel POST Error: Site ID mismatch. Posted: {$posted_site_id}, Selected Config: {$selected_config_site_id}, Session Site: {$session_site_id}");
        }
    } else {
        $message = "Invalid action specified.";
    }

    if (!$action_allowed) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'error';
        // Regenerate CSRF token after failed POST processing
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        // Let configurations.php handle redirect
        return; // Stop processing
    }
    // --- End Permission Check ---


    // Ensure the action is relevant to this panel and site ID matches (Redundant after permission check, but safe)
    if (in_array($action, ['add_notifier', 'edit_notifier', 'delete_notifier', 'toggle_notifier_active'])) {
        if (!$posted_site_id || $posted_site_id != $selected_config_site_id) {
            $_SESSION['flash_message'] = "Invalid or mismatched site ID for notifier action.";
            $_SESSION['flash_type'] = 'error';
            // Let configurations.php handle redirect
        } else {
            // --- Process Allowed Action ---
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

    // Regenerate CSRF token after any POST processing
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// --- END: Handle POST Actions for Notifiers ---


// --- START: Logic for Edit View (Fetching data before display) ---
$view_state = $_GET['view'] ?? 'list'; // Default to 'list' view
$edit_item_id = filter_input(INPUT_GET, 'edit_item_id', FILTER_VALIDATE_INT); // Used for Notifier edit
$edit_notifier_data = null;
$panel_error_message = ''; // Panel specific error

// Fetch Notifier Edit Data only if view state and IDs are correct AND user has permission
if ($view_state === 'edit_notifier' && $edit_item_id && $selected_config_site_id && $can_manage_selected_site_notifiers) {
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
} elseif ($view_state === 'edit_notifier' && !$can_manage_selected_site_notifiers) {
    // User tried to access edit view without permission
    $_SESSION['flash_message'] = "Access Denied: You do not have permission to edit notifiers for this site.";
    $_SESSION['flash_type'] = 'error';
    $view_state = 'list';
    $edit_item_id = null;
}
// --- END: Logic for Edit View ---


// --- START: Fetch Data for List View ---
$notifiers = [];
$selected_site_details = null; // Fetch site details for display title
if ($selected_config_site_id !== null) {
    try {
        // Fetch Site Details
        $selected_site_details = getSiteDetailsById($pdo, $selected_config_site_id);
         if (!$selected_site_details) {
              $panel_error_message .= " Error loading details for selected site ID: {$selected_config_site_id}.";
         }

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
        $selected_site_details = null; // Reset on error
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
        <p>These staff members can be selected on the check-in form to receive an email notification when a client checks in.</p>

        <!-- Add/Edit Forms (Only show if user has permission) -->
       <?php if ($can_manage_selected_site_notifiers): ?>
           <?php if($view_state === 'add_notifier'): ?>
                <div class="admin-form-container form-section">
                    <h4 class="subsection-title">Add New Notifier</h4>
                    <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>">
                         <input type="hidden" name="action" value="add_notifier">
                         <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                         <input type="hidden" name="submitted_tab" value="notifiers"> <!-- Added -->
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
                          <input type="hidden" name="submitted_tab" value="notifiers"> <!-- Added -->
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
       <?php endif; // End check for $can_manage_selected_site_notifiers for forms ?>

        <!-- Notifier List Table -->
        <?php if($view_state === 'list'): ?>
        <h4 class="form-section-title">Existing Notifiers</h4>
        <div class="table-container">
            <table class="table table-striped table-hover">
                <thead><tr><th>Staff Name</th><th>Email Address</th><th>Status</th><?php if ($can_manage_selected_site_notifiers): ?><th>Actions</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if (!empty($notifiers)): ?>
                        <?php foreach ($notifiers as $n): ?>
                             <tr>
                                <td><?php echo htmlspecialchars($n['staff_name']); ?></td>
                                <td><?php echo htmlspecialchars($n['staff_email']); ?></td>
                                <td><span class="status-badge <?php echo $n['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $n['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                <?php if ($can_manage_selected_site_notifiers): ?>
                                <td class="actions-cell">
                                     <a href="?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers&view=edit_notifier&edit_item_id=<?php echo $n['id']; ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                     <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block"><input type="hidden" name="action" value="toggle_notifier_active"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="submitted_tab" value="notifiers"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><button type="submit" class="btn btn-outline btn-sm" title="<?php echo $n['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $n['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"></button></form>
                                     <form method="POST" action="configurations.php?tab=notifiers&site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block" onsubmit="return confirm('Delete this notifier?');"><input type="hidden" name="action" value="delete_notifier"><input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>"><input type="hidden" name="submitted_tab" value="notifiers"><input type="hidden" name="item_id" value="<?php echo $n['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button></form>
                                </td>
                                <?php endif; // End $can_manage_selected_site_notifiers check ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?> <tr><td colspan="<?php echo $can_manage_selected_site_notifiers ? 4 : 3; ?>" class="text-center">No notifiers defined for this site.</td></tr> <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($can_manage_selected_site_notifiers): ?>
        <div class="form-actions"> <a href="?site_id=<?php echo $selected_config_site_id; ?>&tab=notifiers&view=add_notifier" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Notifier</a> </div>
        <?php endif; ?>
        <?php endif; // End view=list condition ?>
    </div>
<?php else: ?>
    <div class="message-area message-info">Please select a site using the dropdown above to manage its notifiers.</div>
<?php endif; ?>