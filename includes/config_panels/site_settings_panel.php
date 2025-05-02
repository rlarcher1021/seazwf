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
     error_log("Config Panel Error: Required variable {$error_detail} not set in site_settings_panel.php");
     echo "<div class='message-area message-error'>Configuration error: Required context variable ({$error_detail}) not available for this panel.</div>";
     return; // Stop further execution
}
// $selected_config_site_id can be null if no site is selected by admin/director, or if site admin's site is invalid. Check where needed.

// --- Permission Helper ---
// Can the current user manage the currently selected site's settings?
$can_manage_selected_site_settings = false;
if ($selected_config_site_id !== null) {
    if (in_array($session_role, ['administrator', 'director'])) {
        $can_manage_selected_site_settings = true; // Admins/Directors can manage any selected site
    } elseif ($is_site_admin === 1 && $selected_config_site_id === $session_site_id) {
        $can_manage_selected_site_settings = true; // Site Admin can manage their own site
    }
}

// Include necessary data access functions (already included in configurations.php, but good practice for clarity)
require_once __DIR__ . '/../data_access/site_data.php';


// --- START: Handle POST request for saving site settings ---
// This logic is now specific to this panel being included
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_site_settings') {
    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_message'] = 'CSRF token validation failed. Request blocked.';
        $_SESSION['flash_type'] = 'error';
        error_log("CSRF token validation failed for site_settings_panel.php from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        // Let configurations.php handle the redirect after setting the flash message
        return; // Stop processing this panel
    }
    // --- End CSRF Token Verification ---


    $posted_site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);

    // --- Permission Check for POST Action ---
    $action_allowed = false;
    if ($posted_site_id && $posted_site_id == $selected_config_site_id) { // Ensure action targets the currently viewed site
        if (in_array($session_role, ['administrator', 'director'])) {
            $action_allowed = true;
        } elseif ($is_site_admin === 1 && $posted_site_id === $session_site_id) {
            $action_allowed = true;
        }
    }

    if (!$action_allowed) {
         $_SESSION['flash_message'] = "Access Denied: You do not have permission to update settings for this site (ID: {$posted_site_id}).";
         $_SESSION['flash_type'] = 'error';
         // Regenerate CSRF token after failed POST processing
         $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
         // Let configurations.php handle redirect
         return; // Stop processing
    }
    // --- End Permission Check ---


    // Double-check the POSTed site_id matches the one currently selected for configuration (redundant after permission check, but safe)
    if ($posted_site_id && $selected_config_site_id && $posted_site_id == $selected_config_site_id) {
        // Get values from form
        $site_is_active = isset($_POST['site_is_active']) ? 1 : 0;
        $allow_email_collection = isset($_POST['allow_email_collection']) ? 1 : 0;
        $allow_notifier = isset($_POST['allow_notifier']) ? 1 : 0;
        $email_collection_description = trim($_POST['email_collection_description_site'] ?? '');
        // --- New AI Agent Settings ---
        $ai_enabled_value = isset($_POST['ai_agent_email_enabled']) ? 1 : 0;
        $ai_agent_email_address = trim($_POST['ai_agent_email_address'] ?? '');
        $ai_agent_email_message = trim($_POST['ai_agent_email_message'] ?? '');
        // --- End New AI Agent Settings ---

        $pdo->beginTransaction();
        $success = true; $error_info = '';

        // 1. Update sites table using data access function, passing session role
        if (!updateSiteStatusAndDesc($pdo, $posted_site_id, $site_is_active, $email_collection_description, $session_role)) {
             $success = false; $error_info = 'Failed site status/description update'; // Error logged in function
        } else {
             error_log("Config Action: Updated site status/desc for Site ID {$posted_site_id}.");
        }

        // 2. Update site_configurations (UPSERT) only if sites update succeeded
        if ($success) {
            // Use data access function for UPSERT for email config, passing session context
            if (!upsertSiteConfiguration($pdo, $posted_site_id, 'allow_email_collection', $allow_email_collection, $session_role, $is_site_admin, $session_site_id)) {
                 $success = false; $error_info = 'Failed email config update'; // Error logged in function
            }
            // Use data access function for UPSERT for notifier config (only if previous succeeded), passing session context
            if ($success) {
                 if (!upsertSiteConfiguration($pdo, $posted_site_id, 'allow_notifier', $allow_notifier, $session_role, $is_site_admin, $session_site_id)) {
                      $success = false; $error_info = 'Failed notifier config update'; // Error logged in function
                 }
            }

            // 3. Update AI Agent configurations (UPSERT) only if previous succeeded, passing session context
            if ($success) {
                if (!upsertSiteConfiguration($pdo, $posted_site_id, 'ai_agent_email_enabled', $ai_enabled_value, $session_role, $is_site_admin, $session_site_id)) {
                    $success = false; $error_info = 'Failed AI agent enabled config update';
                }
            }
            if ($success) {
                if (!upsertSiteConfiguration($pdo, $posted_site_id, 'ai_agent_email_address', $ai_agent_email_address, $session_role, $is_site_admin, $session_site_id)) {
                    $success = false; $error_info = 'Failed AI agent email address config update';
                }
            }
            if ($success) {
                 if (!upsertSiteConfiguration($pdo, $posted_site_id, 'ai_agent_email_message', $ai_agent_email_message, $session_role, $is_site_admin, $session_site_id)) {
                    $success = false; $error_info = 'Failed AI agent message config update';
                }
            }
        }

        // Commit or Rollback
        if ($success) {
            $pdo->commit();
            $_SESSION['flash_message'] = "Site settings updated.";
            $_SESSION['flash_type'] = 'success';
        } else {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $flash_error = "Failed to update site settings.";
            if (!empty($error_info)) { $flash_error .= " (Details logged)."; }
            $_SESSION['flash_message'] = $flash_error;
            $_SESSION['flash_type'] = 'error';
            error_log("ERROR POST HANDLER update_site_settings (Site ID: {$posted_site_id}): Rolled back. Error: {$error_info}");
        }

        // Redirect back to the same tab and site (handled by configurations.php now)
        // We just set the flash message and let the main script handle the redirect after including this panel.
        // No header() call here.

    } else {
         // This case should ideally not happen if configurations.php validates site_id correctly before including panel
         $_SESSION['flash_message'] = "Error: Invalid site selection during update attempt.";
         $_SESSION['flash_type'] = 'error';
         error_log("Config Panel Error: Mismatched site ID in update_site_settings POST. Selected: {$selected_config_site_id}, Posted: {$posted_site_id}");
         // No header() call here.
    }
    // After processing, let configurations.php handle the redirect based on session flash messages.
    // Regenerate CSRF token after any POST processing
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// --- END: Handle POST request for saving site settings ---


// --- START: Fetch Data for Display ---
// This data is needed to populate the form fields below.
// It's assumed configurations.php already fetched the list of sites ($sites_list_for_dropdown)
// and determined $selected_config_site_id.
$selected_site_details = null;
$site_allow_email = 0;
$site_allow_notifier = 0;
// --- New AI Agent Settings Variables ---
$ai_agent_email_enabled = 0;
$ai_agent_email_address = '';
$ai_agent_email_message = '';
// --- End New AI Agent Settings Variables ---
$panel_error_message = ''; // Use a panel-specific error variable

if ($selected_config_site_id !== null) {
    try {
        // Fetch site details using data access function
        $selected_site_details = getSiteDetailsById($pdo, $selected_config_site_id);

        if ($selected_site_details) {
            // Fetch boolean config settings using data access function
            $checkin_configs = getSiteCheckinConfigFlags($pdo, $selected_config_site_id);
            $site_allow_email = $checkin_configs['allow_email_collection'] ? 1 : 0; // Convert bool back to int for form
            $site_allow_notifier = $checkin_configs['allow_notifier'] ? 1 : 0; // Convert bool back to int for form

            // --- Fetch New AI Agent Settings ---
            $ai_agent_email_enabled = (int)(getSiteConfigurationValue($pdo, $selected_config_site_id, 'ai_agent_email_enabled') ?? 0);
            $ai_agent_email_address = getSiteConfigurationValue($pdo, $selected_config_site_id, 'ai_agent_email_address') ?? '';
            $ai_agent_email_message = getSiteConfigurationValue($pdo, $selected_config_site_id, 'ai_agent_email_message') ?? '';
            // --- End Fetch New AI Agent Settings ---

        } else {
            // This case should be handled by configurations.php before including the panel
            error_log("Config Panel Error (Site Settings): Site details not found for ID {$selected_config_site_id} within panel.");
            $panel_error_message = "Could not load details for the selected site (ID: {$selected_config_site_id}).";
            $selected_config_site_id = null; // Prevent form display
        }
    } catch (PDOException $e) {
        error_log("Config Panel Error (Site Settings) - Fetching data for site {$selected_config_site_id}: " . $e->getMessage());
        $panel_error_message = "Database error loading site settings.";
        $selected_config_site_id = null; // Prevent form display
    }
}
// --- END: Fetch Data for Display ---

?>

<!-- Display Panel Specific Errors -->
<?php if ($panel_error_message): ?>
    <div class="message-area message-error"><?php echo htmlspecialchars($panel_error_message); ?></div>
<?php endif; ?>


<!-- Site Settings Tab Content -->
<?php if ($selected_config_site_id !== null && $selected_site_details !== null): ?>
    <div class="settings-section">
        <h3 class="settings-section-title">Settings for <?php echo htmlspecialchars($selected_site_details['name']); ?></h3>
        <form method="POST" action="configurations.php?tab=site_settings&site_id=<?php echo $selected_config_site_id; ?>">
            <input type="hidden" name="action" value="update_site_settings">
            <input type="hidden" name="site_id" value="<?php echo $selected_config_site_id; ?>">
            <input type="hidden" name="submitted_tab" value="site-settings"> <!-- Added -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <fieldset <?php echo !$can_manage_selected_site_settings ? 'disabled' : ''; ?>> <!-- Disable form elements if no permission -->
                <div class="settings-form two-column">
                    <!-- Toggles using $selected_site_details, $site_allow_email, $site_allow_notifier -->
                    <div class="mb-3">
                        <label class="form-label">Site Status</label>
                        <div class="toggle-switch">
                            <?php $site_active_flag = ($selected_site_details['is_active'] == 1); ?>
                            <input type="checkbox" id="site_is_active" name="site_is_active" value="1" <?php echo $site_active_flag ? 'checked' : ''; ?>>
                            <label for="site_is_active" class="toggle-label"><span class="toggle-button"></span></label>
                            <span class="toggle-text"><?php echo $site_active_flag ? 'Active' : 'Inactive'; ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Allow Client Email Collection?</label>
                        <div class="toggle-switch">
                            <?php $email_enabled_flag = ($site_allow_email == 1); ?>
                            <input type="checkbox" id="allow_email_collection" name="allow_email_collection" value="1" <?php echo $email_enabled_flag ? 'checked' : ''; ?>>
                            <label for="allow_email_collection" class="toggle-label"><span class="toggle-button"></span></label>
                            <span class="toggle-text"><?php echo $email_enabled_flag ? 'Enabled' : 'Disabled'; ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Allow Staff Notifier Selection?</label>
                        <div class="toggle-switch">
                            <?php $notifier_enabled_flag = ($site_allow_notifier == 1); ?>
                            <input type="checkbox" id="allow_notifier" name="allow_notifier" value="1" <?php echo $notifier_enabled_flag ? 'checked' : ''; ?>>
                            <label for="allow_notifier" class="toggle-label"><span class="toggle-button"></span></label>
                            <span class="toggle-text"><?php echo $notifier_enabled_flag ? 'Enabled' : 'Disabled'; ?></span>
                        </div>
                    </div>
                    <div class="mb-3 full-width" id="email-desc-group" <?php echo !$email_enabled_flag ? 'class="d-none"' : ''; ?>>
                        <label for="email_desc_site" class="form-label">Email Collection Description</label>
                        <textarea id="email_desc_site" name="email_collection_description_site" class="form-control" rows="2"><?php echo htmlspecialchars($selected_site_details['email_collection_desc'] ?? ''); ?></textarea>
                        <p class="form-description">Text displayed above the optional email input on the check-in form.</p>
                    </div>

                    <!-- AI Agent Email Toggle -->
                    <div class="mb-3 full-width">
                        <label class="form-label">AI Agent Email Notification</label>
                        <div class="toggle-switch">
                             <?php $ai_agent_enabled_flag = ($ai_agent_email_enabled == 1); ?>
                             <input type="checkbox" id="ai_agent_email_enabled" name="ai_agent_email_enabled" value="1" <?php echo $ai_agent_enabled_flag ? 'checked' : ''; ?>>
                             <label for="ai_agent_email_enabled" class="toggle-label"><span class="toggle-button"></span></label>
                             <span class="toggle-text"><?php echo $ai_agent_enabled_flag ? 'Enabled' : 'Disabled'; ?></span>
                        </div>
                         <p class="form-description">If enabled, and client email collection is on, sends details to the AI Agent below when a client submits their email.</p>
                    </div>

                    <!-- AI Agent Email Address -->
                    <div class="mb-3 full-width" id="ai-agent-email-group" <?php echo !$ai_agent_enabled_flag ? 'class="d-none"' : ''; ?>>
                        <label for="ai_agent_email_address" class="form-label">AI Agent Email Address:</label>
                        <input type="email" class="form-control" id="ai_agent_email_address" name="ai_agent_email_address" value="<?php echo htmlspecialchars($ai_agent_email_address ?? ''); ?>">
                    </div>

                    <!-- AI Agent Email Message -->
                    <div class="mb-3 full-width" id="ai-agent-message-group" <?php echo !$ai_agent_enabled_flag ? 'class="d-none"' : ''; ?>>
                        <label for="ai_agent_email_message" class="form-label">AI Agent Email Message Template:</label>
                        <textarea class="form-control" id="ai_agent_email_message" name="ai_agent_email_message" rows="5"><?php echo htmlspecialchars($ai_agent_email_message ?? ''); ?></textarea>
                        <p class="form-description">This message will be sent to the AI Agent. Client details (Name, Email) will be appended.</p>
                    </div>

                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" <?php echo !$can_manage_selected_site_settings ? 'disabled' : ''; ?>><i class="fas fa-save"></i> Save Site Settings</button>
                    <?php if (!$can_manage_selected_site_settings): ?>
                        <small class="text-danger ml-2">You do not have permission to modify settings for this site.</small>
                    <?php endif; ?>
                </div>
            </fieldset>
        </form>
    </div>
<?php else: ?>
    <div class="message-area message-info">Please select a site using the dropdown above to configure its settings.</div>
<?php endif; ?>


<!-- JS for Conditional Email Description & Toggle Text Update -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const emailToggle = document.getElementById('allow_email_collection');
        const descriptionGroup = document.getElementById('email-desc-group');
        const aiAgentToggle = document.getElementById('ai_agent_email_enabled');
        const aiAgentEmailGroup = document.getElementById('ai-agent-email-group');
        const aiAgentMessageGroup = document.getElementById('ai-agent-message-group');


        function toggleDescriptionVisibility() {
            if (emailToggle && descriptionGroup) {
                descriptionGroup.style.display = emailToggle.checked ? 'block' : 'none';
                // Also ensure the textarea inside is enabled/disabled with the fieldset
                const textarea = descriptionGroup.querySelector('textarea');
                if (textarea) {
                    textarea.disabled = emailToggle.closest('fieldset')?.disabled ?? false;
                }
            }
        }

        function toggleAiAgentFieldsVisibility() {
            const show = aiAgentToggle && aiAgentToggle.checked;
            const fieldsetDisabled = aiAgentToggle?.closest('fieldset')?.disabled ?? false;

            if (aiAgentEmailGroup) {
                aiAgentEmailGroup.style.display = show ? 'block' : 'none';
                const input = aiAgentEmailGroup.querySelector('input');
                if (input) input.disabled = fieldsetDisabled;
            }
             if (aiAgentMessageGroup) {
                aiAgentMessageGroup.style.display = show ? 'block' : 'none';
                const textarea = aiAgentMessageGroup.querySelector('textarea');
                if (textarea) textarea.disabled = fieldsetDisabled;
            }
        }

        function updateToggleText(checkboxElement) {
            if (!checkboxElement) return;
            const parentSwitch = checkboxElement.closest('.toggle-switch');
            if (!parentSwitch) return;
            const textSpan = parentSwitch.querySelector('.toggle-text');
            if (!textSpan) return;

            if (checkboxElement.id === 'site_is_active') {
                textSpan.textContent = checkboxElement.checked ? 'Active' : 'Inactive';
            } else {
                textSpan.textContent = checkboxElement.checked ? 'Enabled' : 'Disabled';
            }
        }

        if (emailToggle) {
            emailToggle.addEventListener('change', toggleDescriptionVisibility);
            toggleDescriptionVisibility(); // Initial check
        }

        if (aiAgentToggle) {
            aiAgentToggle.addEventListener('change', toggleAiAgentFieldsVisibility);
            toggleAiAgentFieldsVisibility(); // Initial check
        }

        // Apply to all toggles within this panel
        // Use a more specific selector if possible, e.g., based on the form or section ID
        document.querySelectorAll('.settings-section .toggle-switch input[type="checkbox"]').forEach(toggle => {
            toggle.addEventListener('change', function() { updateToggleText(this); });
            updateToggleText(toggle); // Initial text update
        });

        // Ensure fieldset disabled state is respected on load for dynamic fields
        if (emailToggle) toggleDescriptionVisibility();
        if (aiAgentToggle) toggleAiAgentFieldsVisibility();

    });
</script>