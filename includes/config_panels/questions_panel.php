<?php
// Prevent direct access
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    exit('Direct access is not allowed');
}

// Ensure required variables are available from configurations.php
if (!isset($pdo) || !isset($selected_config_site_id)) { // Also check for selected_config_site_id existence
     error_log("Config Panel Error: Required variable \$pdo or \$selected_config_site_id not set in questions_panel.php");
     echo "<div class='message-area message-error'>Configuration error: Required variables not available.</div>";
     return; // Stop further execution
}
if (!isset($_SESSION['csrf_token'])) { // Ensure CSRF token exists in session
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include necessary data access functions
require_once __DIR__ . '/../data_access/question_data.php';
require_once __DIR__ . '/../data_access/site_data.php';
require_once __DIR__ . '/../utils.php'; // For sanitize_title_to_base_name, format_base_name_for_display, reorder_items


// --- START: Logic for Edit View (Fetching data before display) ---
$view_state = $_GET['view'] ?? 'list'; // Default to 'list' view
$edit_item_id = filter_input(INPUT_GET, 'edit_item_id', FILTER_VALIDATE_INT); // Used for Global Question edit
$edit_question_data = null;

// Fetch Global Question Edit Data only if view state and ID are correct
if ($view_state === 'edit_global_question' && $edit_item_id) {
    try {
        // Fetch using data access function (to be created)
        $edit_question_data = getGlobalQuestionById($pdo, $edit_item_id);
        if(!$edit_question_data) {
             // Set flash message and redirect from configurations.php if needed
             $_SESSION['flash_message'] = "Global Question not found for editing.";
             $_SESSION['flash_type'] = 'warning';
             // Clear edit state variables
             $view_state = 'list';
             $edit_item_id = null;
             // No header() call here, let configurations.php redirect after includes
        }
    } catch (PDOException $e) {
        error_log("Config Panel Error (Questions) - Fetching edit data for item {$edit_item_id}: " . $e->getMessage());
        $panel_error_message = "Database error loading global question for editing.";
        $view_state = 'list'; // Revert to list view on error
        $edit_item_id = null;
    }
}
// --- END: Logic for Edit View ---

// --- START: Fetch Data for Display ---
$panel_error_message = '';
$global_questions = [];
$site_questions_data = []; // Will hold full data for assigned questions
$site_questions_lookup = []; // Still useful for POST handling lookups
$assigned_question_ids = [];
$site_details = null;
$site_name = 'Selected Site'; // Default

try {
    // Fetch all global questions
    $global_questions = getAllGlobalQuestions($pdo) ?: [];

    // Fetch site details and assigned questions only if a site is selected
    if ($selected_config_site_id !== null) {
        $site_details = getSiteDetailsById($pdo, $selected_config_site_id);
        $site_name = $site_details['name'] ?? 'Selected Site';

        // Fetch questions assigned to this site, ordered by display_order
        // Assuming getSiteQuestionsAssigned returns comprehensive data
        $site_questions_data = getSiteQuestionsAssigned($pdo, $selected_config_site_id) ?: [];

        // Process assigned questions for lookup and ID list
        if (!empty($site_questions_data)) {
            foreach ($site_questions_data as $sq) {
                // Ensure expected keys exist
                // Get the site question primary key, trying 'site_question_id' first, then 'id'
                $site_question_pk = $sq['site_question_id'] ?? $sq['id'] ?? null;
                // Ensure expected keys exist
                if (isset($sq['global_question_id'], $sq['is_active'], $sq['display_order']) && $site_question_pk !== null) {
                    $site_questions_lookup[$sq['global_question_id']] = [
                        'is_active' => $sq['is_active'],
                        'display_order' => $sq['display_order'],
                        'site_question_id' => $site_question_pk // Store the site_questions PK
                    ];
                    $assigned_question_ids[] = $sq['global_question_id'];
                } else {
                     error_log("Warning: Unexpected structure from getSiteQuestionsAssigned for site ID {$selected_config_site_id}. Row: " . print_r($sq, true));
                }
            }
        }
    }
} catch (PDOException $e) {
    $panel_error_message .= " Database error loading question data.";
    error_log("Error fetching question data: " . $e->getMessage());
} catch (Exception $e) {
    $panel_error_message .= " General error loading question data.";
    error_log("Error loading question data: " . $e->getMessage());
}
$assigned_count = count($site_questions_data);
// --- END: Fetch Data for Display ---


// --- START: Handle POST Actions for Questions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['csrf_token'])) {
    // --- CSRF Token Verification ---
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_message'] = 'CSRF token validation failed. Request blocked.';
        $_SESSION['flash_type'] = 'error';
        $session_token_debug = $_SESSION['csrf_token'] ?? 'SESSION TOKEN NOT SET'; // Get token for logging
        $post_token_debug = $_POST['csrf_token'] ?? 'POST TOKEN NOT SET'; // Get token for logging
        error_log("CSRF token validation failed for questions_panel.php from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . " | Session Token: " . $session_token_debug . " | POST Token: " . $post_token_debug);
        // Regenerate token on failure? Or just block? Blocking for now.
        // Do not proceed further in the panel script after CSRF failure
        echo "<div class='message-area message-error'>".$_SESSION['flash_message']."</div>"; // Show message immediately
        unset($_SESSION['flash_message']); // Clear flash
        return; // Stop processing this panel script entirely
    }

    // Proceed with action handling only if CSRF is valid
    $action = $_POST['action'];
    // Use $selected_config_site_id directly for site context checks
    $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT); // Still useful to check if it matches selected
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT); // Used for global_question_id in most site/global actions
    $global_question_id_assign = filter_input(INPUT_POST, 'global_question_id_to_assign', FILTER_VALIDATE_INT); // Specifically for assign dropdown action
    $success = false;
    $message = "An error occurred performing the question action.";
    $message_type = 'error';



    try {
        switch ($action) {
            case 'add_global_question':
                $q_text = trim($_POST['question_text'] ?? '');
                $raw_title_input = trim($_POST['question_title'] ?? '');
                $base_title_sanitized = sanitize_title_to_base_name($raw_title_input);

                if (!empty($q_text) && !empty($base_title_sanitized)) {
                     // Sanitization is handled by sanitize_title_to_base_name
                     // Check if the title already exists
                     if (!globalQuestionTitleExists($pdo, $base_title_sanitized)) {
                          $new_gq_id = addGlobalQuestion($pdo, $q_text, $base_title_sanitized);
                          if ($new_gq_id !== false) {
                               $success = true;
                               $message = "Global question added successfully (Internal Title: '{$base_title_sanitized}').";
                               $message_type = 'success';
                          } else { $message = "Failed to add global question or create its data column."; }
                     } else {
                         $message = "A question generating the internal title '{$base_title_sanitized}' already exists.";
                         $message_type = 'warning';
                     }
                } else {
                    $message = "Invalid input. Question Text and a valid Title are required.";
                }
                break;

            case 'update_global_question':
                // item_id here refers to global_question_id
                $q_text_edit = trim($_POST['edit_question_text'] ?? '');
                if ($item_id && !empty($q_text_edit)) {
                    // Call the data access function (to be created)
                    if (updateGlobalQuestionText($pdo, $item_id, $q_text_edit)) {
                        $success = true;
                        $message = "Global question text updated successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Failed to update global question text."; // Error logged in function
                    }
                } else {
                    $message = "Invalid input. Question ID and non-empty text are required.";
                }
                break;


                break;

            case 'delete_global_question':
                // item_id here refers to global_question_id
                $base_title_to_delete = null;
                if ($item_id) {
                    $base_title_to_delete = getGlobalQuestionTitleById($pdo, $item_id);
                    if ($base_title_to_delete) {
                        if (deleteGlobalQuestion($pdo, $item_id)) { // Handles deleting from global_questions and site_questions
                            $success = true;
                            $column_deleted = delete_question_column($pdo, $base_title_to_delete);
                            $display_name = htmlspecialchars(format_base_name_for_display($base_title_to_delete));
                            $message = "Global question '{$display_name}' deleted.";
                            if (!$column_deleted) {
                                $message .= " <strong class='text-warning'>Warning:</strong> Could not drop column 'q_{$base_title_to_delete}'. Manual check required.";
                            }
                            $message_type = 'success';
                        } else { $message = "Failed to delete global question record."; }
                    } else {
                        $message = "Global question not found for deletion.";
                        $message_type = 'warning';
                    }
                } else {
                    $message = "Invalid item ID for deletion.";
                }
                break;

            case 'assign_site_question':
                // Check if the action is relevant for the currently selected site
                if ($selected_config_site_id === null || !$posted_site_id || $posted_site_id != $selected_config_site_id) {
                    $message = "Invalid or mismatched site ID for assigning question.";
                    break;
                }
                // Use $global_question_id_assign from the dropdown
                if ($selected_config_site_id && $global_question_id_assign) {
                    // Check if already assigned (shouldn't happen if dropdown is correct, but good failsafe)
                    if (!in_array($global_question_id_assign, $assigned_question_ids)) {
                        if (assignQuestionToSite($pdo, $selected_config_site_id, $global_question_id_assign, 1)) { // Assign as active by default
                            $success = true;
                            $message = "Question assigned to site.";
                            $message_type = 'success';
                        } else { $message = "Failed to assign question (DB error)."; }
                    } else {
                         $message = "Question is already assigned to this site.";
                         $message_type = 'warning';
                    }
                } else {
                    $message = "Invalid site or question ID selected for assignment.";
                }
                break;

            case 'remove_site_question':
                 // item_id here refers to global_question_id
                if ($selected_config_site_id === null || !$posted_site_id || $posted_site_id != $selected_config_site_id) {
                    $message = "Invalid or mismatched site ID for removing question.";
                    break;
                }
                if ($selected_config_site_id && $item_id) {
                    // Need site_question_id (PK) for removal and reordering
                    $site_q_id_to_remove = $site_questions_lookup[$item_id]['site_question_id'] ?? null;

                    if ($site_q_id_to_remove && removeQuestionFromSite($pdo, $site_q_id_to_remove, $selected_config_site_id)) {
                        // Reorder remaining items for the site
                        if (reorder_items($pdo, 'site_questions', 'display_order', 'site_id', $selected_config_site_id)) {
                            $success = true;
                            $message = "Question unassigned & remaining reordered.";
                            $message_type = 'success';
                        } else {
                            $message = "Question unassigned, but failed to reorder remaining items.";
                            $message_type = 'warning'; // Partial success
                        }
                    } else { $message = "Failed to unassign question (SiteQ ID: {$site_q_id_to_remove}). Maybe already removed or invalid ID?"; }
                } else {
                    $message = "Invalid site/item ID for removal.";
                }
                break;

            case 'toggle_site_question': // Renamed action for clarity
                 // item_id here refers to global_question_id
                if ($selected_config_site_id === null || !$posted_site_id || $posted_site_id != $selected_config_site_id) {
                    $message = "Invalid or mismatched site ID for toggling question status.";
                    break;
                }
                if ($selected_config_site_id && $item_id) {
                     // toggleSiteQuestionActive function expects site_question_id (PK)
                     $site_q_id_to_toggle = $site_questions_lookup[$item_id]['site_question_id'] ?? null;
                    if ($site_q_id_to_toggle && toggleSiteQuestionActive($pdo, $site_q_id_to_toggle, $selected_config_site_id)) {
                        $success = true;
                        $message = "Question status toggled.";
                        $message_type = 'success';
                    } else { $message = "Failed to toggle question status (SiteQ ID: {$site_q_id_to_toggle})."; }
                } else {
                    $message = "Invalid site/item ID for toggling.";
                }
                break;

            case 'reorder_site_question': // Consolidated reorder action
                 // item_id here refers to global_question_id
                if ($selected_config_site_id === null || !$posted_site_id || $posted_site_id != $selected_config_site_id) {
                    $message = "Invalid or mismatched site ID for reordering question.";
                    break;
                }
                $direction = filter_input(INPUT_POST, 'direction', FILTER_SANITIZE_STRING); // Get direction ('up' or 'down')
                if ($selected_config_site_id && $item_id && ($direction === 'up' || $direction === 'down')) {
                    // Retrieve the site_question_id (PK) from the lookup array
                    $site_q_id_to_move = $site_questions_lookup[$item_id]['site_question_id'] ?? null;

                    if ($site_q_id_to_move && reorder_items($pdo, 'site_questions', 'display_order', 'site_id', $selected_config_site_id, $site_q_id_to_move, $direction)) {
                        $success = true;
                        $message = "Question reordered.";
                        $message_type = 'success';
                    } else { $message = "Failed reorder (SiteQ ID: {$site_q_id_to_move})."; }
                } else {
                    $message = "Invalid site/item ID or direction for reorder.";
                }
                break;
        }

        // Set flash message if an action was processed
        if (in_array($action, ['add_global_question', 'delete_global_question', 'assign_site_question',
                              'remove_site_question', 'toggle_site_question', 'reorder_site_question'])) {
            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_type'] = $message_type;

            // The main configurations.php script will handle the redirect after this panel finishes processing.
            // We just need to ensure the flash message is set.
        } else {
             // If action wasn't one of the main ones, or failed early, set flash anyway
             $_SESSION['flash_message'] = $message;
             $_SESSION['flash_type'] = $message_type;
             // No redirect here, allow page to render with the error message
        }

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Database error processing question action '{$action}'. Details logged.";
        $_SESSION['flash_type'] = 'error';
        error_log("PDOException in questions_panel.php: " . $e->getMessage());
    } catch (Exception $e) {
        $_SESSION['flash_message'] = "General error processing question action '{$action}'. Details logged.";

        $_SESSION['flash_type'] = 'error';
        error_log("Exception in questions_panel.php: " . $e->getMessage());
    }

    // Regenerate CSRF token after successful POST processing to prevent reuse
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

} // End of POST handling block
// --- END: Handle POST Actions for Questions ---

// --- START: Display Flash Messages ---
if (isset($_SESSION['flash_message'])) {
    echo '<div class="message-area message-' . htmlspecialchars($_SESSION['flash_type']) . '">' . htmlspecialchars($_SESSION['flash_message']) . '</div>';
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}
?>


<!-- 1a. Edit Global Question Form (Conditional) -->
<?php if ($view_state === 'edit_global_question' && $edit_question_data): ?>
<div class="admin-form-container form-section" class="border border-primary border-2 p-3 mb-4">
    <h4 class="form-section-title">Edit Global Question Text</h4>
    <form method="POST" action="configurations.php?tab=questions<?php echo $selected_config_site_id ? '&amp;site_id='.$selected_config_site_id : ''; ?>">
        <input type="hidden" name="action" value="update_global_question">
        <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
        <input type="hidden" name="item_id" value="<?php echo $edit_question_data['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <?php if ($selected_config_site_id): ?>
            <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
        <?php endif; ?>

        <div class="settings-form one-column">
            <div class="mb-3">
                <label class="form-label">Question Title (Read-only):</label>
                <p><code><?php echo htmlspecialchars($edit_question_data['question_title']); ?></code></p>
            </div>
            <div class="mb-3">
                <label for="edit_question_text" class="form-label">Question Text (Displayed to Client):</label>
                <textarea id="edit_question_text" name="edit_question_text" class="form-control" rows="3" required><?php echo htmlspecialchars($edit_question_data['question_text']); ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
            <a href="configurations.php?tab=questions<?php echo $selected_config_site_id ? '&amp;site_id='.$selected_config_site_id : ''; ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>


<!-- END: Display Flash Messages -->



<!-- Display Panel Specific Errors (e.g., data loading errors) -->
<?php if ($panel_error_message): ?>
    <div class="message-area message-error"><?php echo htmlspecialchars($panel_error_message); ?></div>
<?php endif; ?>

<div class="settings-section">
    <h3 class="settings-section-title">Question Management</h3>

    <!-- 1. Add New Global Question Form -->
    <div class="admin-form-container form-section">
        <h4 class="form-section-title">Add New Global Question</h4>
        <form method="POST" action="configurations.php?tab=questions<?php echo $selected_config_site_id ? '&amp;site_id='.$selected_config_site_id : ''; ?>">
            <input type="hidden" name="action" value="add_global_question">
            <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <?php if ($selected_config_site_id): ?>
                <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
            <?php endif; ?>

            <div class="settings-form two-column">
                <div class="mb-3">
                    <label for="question_title" class="form-label">Question Title / Short Name:</label>
                    <input type="text" id="question_title" name="question_title" class="form-control" required>
                    <p class="form-description">Enter a short, descriptive title (e.g., "Reason for Visit"). The system will automatically format it for database use (e.g., to "reason_for_visit").</p>
                </div>
                <div class="mb-3 full-width">
                    <label for="question_text" class="form-label">Question Text (Displayed to Client):</label>
                    <textarea id="question_text" name="question_text" class="form-control" rows="2" required></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Global Question
                </button>
            </div>
        </form>
    </div>

    <!-- 2. Available Global Questions & Assignment -->
    <div class="content-section">
        <h4 class="form-section-title">Available Global Questions</h4>
        <div class="table-container">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Question Text</th>
                        <th>Question Title</th>
                        <th>Global Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($global_questions)): ?>
                        <?php foreach ($global_questions as $gq): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($gq['question_text']); ?></td>
                                <td><code><?php echo htmlspecialchars($gq['question_title']); ?></code></td>
                                <td class="actions-cell">
                                    <!-- Edit Globally Link -->
                                    <a href="configurations.php?tab=questions&view=edit_global_question&edit_item_id=<?php echo $gq['id']; ?><?php echo $selected_config_site_id ? '&amp;site_id='.$selected_config_site_id : ''; ?>" class="btn btn-outline btn-sm" title="Edit Question Text">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <!-- Delete Globally Form -->
                                    <form method="POST" action="configurations.php?tab=questions<?php echo $selected_config_site_id ? '&amp;site_id='.$selected_config_site_id : ''; ?>"
                                          class="d-inline-block"
                                          onsubmit="return confirm('WARNING: Deleting globally will remove this question for ALL sites and DELETE the corresponding data column [q_<?php echo htmlspecialchars($gq['question_title']); ?>] from check-ins. This cannot be undone. Proceed?');">
                                       <input type="hidden" name="action" value="delete_global_question">
                                       <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
                                       <input type="hidden" name="item_id" value="<?php echo $gq['id']; ?>"> <!-- Pass global_id -->
                                       <?php if ($selected_config_site_id): ?>
                                           <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Globally">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="text-center">No global questions defined yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($selected_config_site_id !== null): // Only show assignment if a site is selected ?>
            <div class="admin-form-container form-section" class="mt-4">
                <h4 class="form-section-title">Assign Question to <?php echo htmlspecialchars($site_name); ?></h4>
                <form method="POST" action="configurations.php?tab=questions&amp;site_id=<?php echo $selected_config_site_id; ?>">
                    <input type="hidden" name="action" value="assign_site_question">
                    <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
                    <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                    <div class="settings-form one-column">
                        <div class="mb-3">
                            <label for="global_question_id_to_assign" class="form-label">Select Question to Assign:</label>
                            <select id="global_question_id_to_assign" name="global_question_id_to_assign" class="form-select" required>
                                <option value="" disabled selected>-- Select an unassigned question --</option>
                                <?php
                                $unassigned_found = false;
                                foreach ($global_questions as $gq) {
                                    if (!in_array($gq['id'], $assigned_question_ids)) {
                                        echo '<option value="' . $gq['id'] . '">' . htmlspecialchars($gq['question_text']) . ' (' . htmlspecialchars($gq['question_title']) . ')</option>';
                                        $unassigned_found = true;
                                    }
                                }
                                ?>
                            </select>
                            <?php if (!$unassigned_found && !empty($global_questions)): ?>
                                <p class="form-description">All available global questions are already assigned to this site.</p>
                            <?php elseif (empty($global_questions)): ?>
                                <p class="form-description">No global questions available to assign.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" <?php echo !$unassigned_found ? 'disabled' : ''; ?>>
                            <i class="fas fa-link"></i> Assign to Site
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <p class="mt-4">Select a site from the dropdown above to manage its assigned questions.</p>
        <?php endif; ?>
    </div> <!-- End Section 2 -->


    <!-- 3. Questions Assigned to [Site Name] -->
    <?php if ($selected_config_site_id !== null): // Only show assigned list if a site is selected ?>
        <div class="content-section" class="mt-5">
            <h4 class="form-section-title">Questions Assigned to <?php echo htmlspecialchars($site_name); ?></h4>
            <div class="table-container">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th style="width: 5rem;">Order</th>
                            <th>Question Text</th>
                            <th style="width: 6.25rem;">Status</th>
                            <th style="width: 9.375rem;">Site Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($site_questions_data)): ?>
                            <?php foreach ($site_questions_data as $index => $sq):
                                $global_id = $sq['global_question_id'];
                                $site_q_id = $sq['site_question_id'] ?? $sq['id'] ?? null; // site_questions PK
                                $is_active = $sq['is_active'];
                                $is_first = ($index === 0);
                                $is_last = ($index === $assigned_count - 1);
                            ?>
                                <tr>
                                    <td class="actions-cell">
                                        <!-- Reordering buttons -->
                                        <?php if (!$is_first): ?>
                                            <form method="POST" action="configurations.php?tab=questions&amp;site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block">
                                                <input type="hidden" name="action" value="reorder_site_question">
                                                <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
                                                <input type="hidden" name="item_id" value="<?php echo $global_id; ?>"> <!-- Pass global_id -->
                                                <input type="hidden" name="direction" value="up">
                                                <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" title="Move Up">
                                                    <i class="fas fa-arrow-up"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="d-inline-block" style="width: 1.875rem;"></span> <!-- Placeholder for alignment -->
                                        <?php endif; ?>

                                        <?php if (!$is_last): ?>
                                            <form method="POST" action="configurations.php?tab=questions&amp;site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block">
                                                <input type="hidden" name="action" value="reorder_site_question">
                                                <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
                                                <input type="hidden" name="item_id" value="<?php echo $global_id; ?>"> <!-- Pass global_id -->
                                                <input type="hidden" name="direction" value="down">
                                                <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" title="Move Down">
                                                    <i class="fas fa-arrow-down"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                             <span class="d-inline-block" style="width: 1.875rem;"></span> <!-- Placeholder for alignment -->
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($sq['question_text']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="actions-cell">
                                        <!-- Toggle Active/Inactive -->
                                        <form method="POST" action="configurations.php?tab=questions&amp;site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block">
                                            <input type="hidden" name="action" value="toggle_site_question">
                                            <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
                                            <input type="hidden" name="item_id" value="<?php echo $global_id; ?>"> <!-- Pass global_id -->
                                            <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="fas <?php echo $is_active ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Unassign from Site -->
                                        <form method="POST" action="configurations.php?tab=questions&amp;site_id=<?php echo $selected_config_site_id; ?>" class="d-inline-block" onsubmit="return confirm('Unassign this question from <?php echo htmlspecialchars($site_name); ?>?');">
                                            <input type="hidden" name="action" value="remove_site_question">
                                            <input type="hidden" name="submitted_tab" value="questions"> <!-- Added -->
                                            <input type="hidden" name="item_id" value="<?php echo $global_id; ?>"> <!-- Pass global_id -->
                                            <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                            <button type="submit" class="btn btn-outline btn-sm delete-button" title="Unassign from Site">
                                                <i class="fas fa-unlink"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center">No questions assigned to this site yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div> <!-- End Section 3 -->
    <?php endif; // End check for selected site for assigned list ?>

</div> <!-- End settings-section -->
