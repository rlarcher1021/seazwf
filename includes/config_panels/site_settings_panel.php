<?php
// Prevent direct access
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    exit('Direct access is not allowed');
}

// Ensure required variables are available from configurations.php
if (!isset($pdo) || !isset($selected_config_site_id)) {
     error_log("Config Panel Error: Required variables (\$pdo, \$selected_config_site_id) not set in site_settings_panel.php");
     echo "<div class='message-area message-error'>Configuration error: Required variables not available.</div>";
     return; // Stop further execution of this panel
}

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

    // Double-check the POSTed site_id matches the one currently selected for configuration
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

        // 1. Update sites table using data access function
        if (!updateSiteStatusAndDesc($pdo, $posted_site_id, $site_is_active, $email_collection_description)) {
             $success = false; $error_info = 'Failed site status/description update'; // Error logged in function
        } else {
             error_log("Config Action: Updated site status/desc for Site ID {$posted_site_id}.");
        }

        // 2. Update site_configurations (UPSERT) only if sites update succeeded
        if ($success) {
            // Use data access function for UPSERT for email config
            if (!upsertSiteConfiguration($pdo, $posted_site_id, 'allow_email_collection', $allow_email_collection)) {
                 $success = false; $error_info = 'Failed email config update'; // Error logged in function
            }
            // Use data access function for UPSERT for notifier config (only if previous succeeded)
            if ($success) {
                 if (!upsertSiteConfiguration($pdo, $posted_site_id, 'allow_notifier', $allow_notifier)) {
                      $success = false; $error_info = 'Failed notifier config update'; // Error logged in function
                 }
            }

            // 3. Update AI Agent configurations (UPSERT) only if previous succeeded
            if ($success) {
                if (!upsertSiteConfiguration($pdo, $posted_site_id, 'ai_agent_email_enabled', $ai_enabled_value)) {
                    $success = false; $error_info = 'Failed AI agent enabled config update';
                }
            }
            if ($success) {
                if (!upsertSiteConfiguration($pdo, $posted_site_id, 'ai_agent_email_address', $ai_agent_email_address)) {
                    $success = false; $error_info = 'Failed AI agent email address config update';
                }
            }
            if ($success) {
                 if (!upsertSiteConfiguration($pdo, $posted_site_id, 'ai_agent_email_message', $ai_agent_email_message)) {
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
            <!-- active_tab_on_submit is not strictly needed if action URL includes tab -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <div class="settings-form two-column">
                <!-- Toggles using $selected_site_details, $site_allow_email, $site_allow_notifier -->
                <div class="form-group">
                    <label class="form-label">Site Status</label>
                    <div class="toggle-switch">
                        <?php $site_active_flag = ($selected_site_details['is_active'] == 1); ?>
                        <input type="checkbox" id="site_is_active" name="site_is_active" value="1" <?php echo $site_active_flag ? 'checked' : ''; ?>>
                        <label for="site_is_active" class="toggle-label"><span class="toggle-button"></span></label>
                        <span class="toggle-text"><?php echo $site_active_flag ? 'Active' : 'Inactive'; ?></span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Allow Client Email Collection?</label>
                    <div class="toggle-switch">
                        <?php $email_enabled_flag = ($site_allow_email == 1); ?>
                        <input type="checkbox" id="allow_email_collection" name="allow_email_collection" value="1" <?php echo $email_enabled_flag ? 'checked' : ''; ?>>
                        <label for="allow_email_collection" class="toggle-label"><span class="toggle-button"></span></label>
                        <span class="toggle-text"><?php echo $email_enabled_flag ? 'Enabled' : 'Disabled'; ?></span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Allow Staff Notifier Selection?</label>
                    <div class="toggle-switch">
                        <?php $notifier_enabled_flag = ($site_allow_notifier == 1); ?>
                        <input type="checkbox" id="allow_notifier" name="allow_notifier" value="1" <?php echo $notifier_enabled_flag ? 'checked' : ''; ?>>
                        <label for="allow_notifier" class="toggle-label"><span class="toggle-button"></span></label>
                        <span class="toggle-text"><?php echo $notifier_enabled_flag ? 'Enabled' : 'Disabled'; ?></span>
                    </div>
                </div>
                <div class="form-group full-width" id="email-desc-group" style="<?php echo !$email_enabled_flag ? 'display: none;' : ''; ?>">
                    <label for="email_desc_site" class="form-label">Email Collection Description</label>
                    <textarea id="email_desc_site" name="email_collection_description_site" class="form-control" rows="2"><?php echo htmlspecialchars($selected_site_details['email_collection_desc'] ?? ''); ?></textarea>
                    <p class="form-description">Text displayed above the optional email input on the check-in form.</p>
                </div>

                <!-- AI Agent Email Toggle -->
                <div class="form-group full-width">
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
                <div class="form-group full-width" id="ai-agent-email-group" style="<?php echo !$ai_agent_enabled_flag ? 'display: none;' : ''; ?>">
                    <label for="ai_agent_email_address" class="form-label">AI Agent Email Address:</label>
                    <input type="email" class="form-control" id="ai_agent_email_address" name="ai_agent_email_address" value="<?php echo htmlspecialchars($ai_agent_email_address ?? ''); ?>">
                </div>

                <!-- AI Agent Email Message -->
                <div class="form-group full-width" id="ai-agent-message-group" style="<?php echo !$ai_agent_enabled_flag ? 'display: none;' : ''; ?>">
                    <label for="ai_agent_email_message" class="form-label">AI Agent Email Message Template:</label>
                    <textarea class="form-control" id="ai_agent_email_message" name="ai_agent_email_message" rows="5"><?php echo htmlspecialchars($ai_agent_email_message ?? ''); ?></textarea>
                    <p class="form-description">This message will be sent to the AI Agent. Client details (Name, Email) will be appended.</p>
                </div>

            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Site Settings</button>
            </div>
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
            }
        }

        function toggleAiAgentFieldsVisibility() {
            const show = aiAgentToggle && aiAgentToggle.checked;
            if (aiAgentEmailGroup) {
                aiAgentEmailGroup.style.display = show ? 'block' : 'none';
            }
             if (aiAgentMessageGroup) {
                aiAgentMessageGroup.style.display = show ? 'block' : 'none';
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
        const panelElement = document.getElementById('site-settings'); // Assuming the parent div has this ID now
        if (panelElement) {
             panelElement.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(toggle => {
                toggle.addEventListener('change', function() { updateToggleText(this); });
                updateToggleText(toggle); // Initial text update
            });
        } else {
            // Fallback if panel ID isn't set yet, might apply globally but better than nothing
             document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(toggle => {
                toggle.addEventListener('change', function() { updateToggleText(this); });
                updateToggleText(toggle); // Initial text update
            });
        }
    });
</script>