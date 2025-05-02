<?php
/*
 * File: configurations.php
 * Path: /configurations.php
 * Created: 2024-08-01 13:30:00 MST
 * Updated: 2025-04-14 - Refactored into controller/panel structure.
 * Description: Administrator page controller for managing system configurations.
 *              Handles site selection, tab navigation, and includes panel files.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Authentication Check ---
// This MUST happen before database connection or CSRF generation if CSRF depends on user data.
// Assuming auth.php handles session start and redirects if not logged in.
require_once 'includes/auth.php';       // Ensures user is logged in and redirects if not.

// --- CSRF Token Generation ---
// Generate a CSRF token if one doesn't exist for the session
// Moved here from auth.php to ensure token exists before panel POST processing
// Now placed *after* auth check.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) { // Session is guaranteed active by auth.php
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        error_log("CSRF token generation failed in configurations.php: " . $e->getMessage());
        // Consider dying or redirecting based on security policy
        die("A critical security token error occurred. Please try again later.");
    }
}
// --- End CSRF Token Generation ---

// --- Database Connection and Further Includes (Only if authenticated) ---
require_once 'includes/db_connect.php'; // Provides $pdo
require_once 'includes/utils.php';      // Utility functions like sanitizers
// Include data access files needed by panels (panels will use $pdo passed from here)
require_once 'includes/data_access/site_data.php';
require_once 'includes/data_access/question_data.php';
require_once 'includes/data_access/notifier_data.php';
require_once 'includes/data_access/ad_data.php';

// --- Get Session Context ---
$session_role = $_SESSION['active_role'] ?? null;
$is_site_admin = isset($_SESSION['is_site_admin']) ? (int)$_SESSION['is_site_admin'] : 0;
$session_site_id = $_SESSION['active_site_id'] ?? null; // User's assigned site ID or the one they selected if admin/director

// --- Role Check ---
// Allow administrators, directors, and site admins
$allowed_roles = ['administrator', 'director'];
$has_config_access = in_array($session_role, $allowed_roles) || $is_site_admin === 1;

if (!$has_config_access) {
    $_SESSION['flash_message'] = "Access denied. You do not have permission to view configurations.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Determine Active Tab ---
// Standardized tab names using hyphens
$allowed_tabs = ['site-settings', 'questions', 'notifiers', 'ads-management', 'departments', 'api-keys']; // Consolidated questions tab, Added departments, Added api-keys
// Determine active tab: Prioritize GET, then session, then default.
if (isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs)) {
    $active_tab = $_GET['tab'];
} else {
    // Fallback to session or default if GET tab is missing or invalid
    $active_tab = $_SESSION['selected_config_tab'] ?? 'site-settings';
    // Ensure the fallback value is also valid
    if (!in_array($active_tab, $allowed_tabs)) {
        $active_tab = 'site-settings';
    }
}
$_SESSION['selected_config_tab'] = $active_tab; // Store the determined active tab for persistence

// --- Determine Selected Site ID ---
$selected_config_site_id = null; // Initialize
$config_error = ''; // Initialize potential error messages
$can_select_site = ($session_role === 'administrator' || $session_role === 'director') && !$is_site_admin; // Admins/Directors can select, unless they are ALSO a site admin (edge case?)

// Fetch sites based on user's role and context
$sites_list_for_dropdown = getAllSitesWithStatus($pdo, $session_role, $session_site_id);

if ($sites_list_for_dropdown === false) {
    // Function returns false on PDO error, logs it internally.
    $config_error .= " Error loading site list.";
    $sites_list_for_dropdown = []; // Ensure it's an array
} elseif (empty($sites_list_for_dropdown)) {
    // No sites available for this user's context
    if ($is_site_admin === 1 && $session_site_id !== null) {
         $config_error .= " Your assigned site (ID: {$session_site_id}) could not be found or is inactive.";
    } elseif ($session_role === 'administrator' || $session_role === 'director') {
         $config_error .= " No sites found in the system or accessible to you.";
    } else {
         $config_error .= " No accessible sites found."; // Generic
    }
} else {
    // Sites are available
    if ($is_site_admin === 1 && $session_site_id !== null) {
        // Site Admin: Force selection to their assigned site ID
        $found_assigned = false;
        foreach ($sites_list_for_dropdown as $site) {
            if ($site['id'] == $session_site_id) {
                $selected_config_site_id = (int)$session_site_id;
                $found_assigned = true;
                break;
            }
        }
        if (!$found_assigned) {
             $config_error .= " Your assigned site (ID: {$session_site_id}) is not in the accessible list (possibly inactive?).";
             $selected_config_site_id = null; // Ensure it's null if not found
        }
    } elseif ($can_select_site) {
        // Admin/Director: Allow selection via dropdown
        $site_id_from_get = filter_input(INPUT_GET, 'site_id', FILTER_VALIDATE_INT);
        $site_id_from_session = $_SESSION['selected_config_site_id'] ?? null;
        $default_site_id = $sites_list_for_dropdown[0]['id']; // First site in their list as default

        // Prioritize GET param, then session, then the first site in the list
        $target_site_id = $site_id_from_get ?? $site_id_from_session ?? $default_site_id;

        // Validate that the target_site_id exists in their accessible list
        $found = false;
        foreach ($sites_list_for_dropdown as $site) {
            if ($site['id'] == $target_site_id) {
                $selected_config_site_id = (int)$target_site_id; // Cast to int and assign
                $found = true;
                break;
            }
        }

        // If the target ID wasn't found (e.g., invalid GET param or stale session), default to the first site in their list
        if (!$found) {
            $selected_config_site_id = (int)$default_site_id;
            // Optionally set a warning if an invalid ID was explicitly provided via GET
            if ($site_id_from_get && $site_id_from_get != $selected_config_site_id) {
                 if (!isset($_SESSION['flash_message'])) { // Avoid overwriting POST messages
                     $_SESSION['flash_message'] = "Invalid or inaccessible site ID specified. Defaulting to first available site.";
                     $_SESSION['flash_type'] = 'warning';
                 }
            }
        }
    } else {
         // Should not happen if role check passed, but handle defensively
         $config_error .= " Cannot determine site selection logic for your role.";
         $selected_config_site_id = null;
    }
}

// Store the final selected/validated site ID in the session for persistence (only if user can select)
if ($can_select_site) {
    $_SESSION['selected_config_site_id'] = $selected_config_site_id;
} elseif ($is_site_admin === 1) {
     // Site admins don't use the session persistence for selection, it's fixed
     unset($_SESSION['selected_config_site_id']); // Clear potentially stale session value
}



// --- START: Process Panel Logic & Handle POST Redirect ---
// This block includes the relevant panel which might process POST data and set flash messages.
// It also handles the redirect if a POST request was processed.
// This MUST happen before any HTML output (like the header).

// Define panel file mapping
$panel_files = [
    'site-settings' => 'includes/config_panels/site_settings_panel.php',
    'questions' => 'includes/config_panels/questions_panel.php',       // Consolidated questions panel
    'notifiers' => 'includes/config_panels/notifiers_panel.php',
    'ads-management' => 'includes/config_panels/ads_panel.php',
    'departments' => 'includes/config_panels/departments_panel.php', // Added departments panel
    'api-keys' => 'includes/config_panels/api_keys_panel.php' // Added API Keys panel
];

// Initialize variable to hold potential panel output for GET requests
$panel_output = '';

// Determine the tab to process, prioritizing POST data
$tab_to_process = $active_tab; // Default to the tab determined by GET/session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submitted_tab']) && isset($panel_files[$_POST['submitted_tab']])) {
    $tab_to_process = $_POST['submitted_tab']; // Override with submitted tab if valid
    error_log("DEBUG configurations.php (POST): Prioritizing submitted_tab '{$tab_to_process}' for panel inclusion."); // Debug log
}

// Process panel logic only if it's a valid tab AND user has access to it
if (isset($panel_files[$tab_to_process])) {
    $panel_path = $panel_files[$tab_to_process];

    // Additional check: Site Admins cannot access the 'departments' tab
    if ($is_site_admin === 1 && $tab_to_process === 'departments') {
         $_SESSION['flash_message'] = "Access denied. Site Administrators cannot manage global departments.";
         $_SESSION['flash_type'] = 'error';
         // Redirect or just prevent panel load? Prevent load for now.
         $panel_output = "<div class='message-area message-error'>Access denied to this section.</div>";
         // Force active tab back to default if they tried to access departments directly
         if ($active_tab === 'departments') {
             $active_tab = 'site-settings';
             $_SESSION['selected_config_tab'] = $active_tab;
         }
    } elseif (file_exists($panel_path)) {
        error_log("DEBUG configurations.php ({$_SERVER['REQUEST_METHOD']}): Including panel '{$panel_path}'. Selected Site ID = {$selected_config_site_id}, Role = {$session_role}, IsSiteAdmin = {$is_site_admin}"); // Enhanced Debug log
error_log("DEBUG configurations.php: Before panel include. Tab='{$tab_to_process}', Selected Site ID=" . var_export($selected_config_site_id, true) . ", Role='{$session_role}', IsSiteAdmin={$is_site_admin}");

        // Make necessary variables available to the panel scope
        // $pdo is already global via db_connect.php
        // $selected_config_site_id is determined above
        // Pass session context: $session_role, $is_site_admin, $session_site_id
        $panel_session_role = $session_role;
        $panel_is_site_admin = $is_site_admin;
        $panel_session_site_id = $session_site_id; // The user's *actual* site ID from session
        $panel_selected_config_site_id = $selected_config_site_id; // The site currently being configured

        // $selected_site_details might be needed by the panel for display logic (GET) or processing (POST)
        $selected_site_details = ($selected_config_site_id) ? getSiteDetailsById($pdo, $selected_config_site_id) : null;

        // Check if the panel requires a site ID and if one is selected (relevant for GET display)
        // Departments tab does not require a site ID
        $requires_site_id = in_array($tab_to_process, ['site-settings', 'questions', 'notifiers', 'ads-management']);

        // Enhanced site ID validation for both GET and POST requests
        if ($requires_site_id && $selected_config_site_id === null) {
            // Log the specific scenario for debugging
            error_log("Configuration Panel Access Blocked: Tab={$tab_to_process}, Method={$_SERVER['REQUEST_METHOD']}, Selected Site ID=null");

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // For POST requests, set a more specific error message
                $_SESSION['flash_message'] = "A valid site must be selected before performing actions on this tab.";
                $_SESSION['flash_type'] = 'error';

                // Redirect back to the configurations page (handled later)
            } else {
                // For GET requests, set an info message
                // Avoid overwriting POST messages
                 if (!isset($_SESSION['flash_message'])) {
                    if ($can_select_site) {
                        $_SESSION['flash_message'] = "Please select a site using the dropdown above to view this configuration section.";
                    } else {
                         $_SESSION['flash_message'] = "No site is currently selected or accessible for configuration."; // More generic if site admin's site failed
                    }
                    $_SESSION['flash_type'] = 'info';
                 }
                // $panel_output remains empty
            }
        } else {
            // For POST requests OR valid GET requests, include the panel file.
            // Make session variables available within the included file's scope
            $session_role = $panel_session_role;
            $is_site_admin = $panel_is_site_admin;
            $session_site_id = $panel_session_site_id;
            // Also pass the specifically selected site ID for configuration
            $selected_config_site_id = $panel_selected_config_site_id;

            ob_start();
            require_once $panel_path;
            $panel_output = ob_get_clean(); // Capture output for later display on GET requests
        }
    } else {
        // Panel file missing
        error_log("Configuration Error: Panel file not found: " . $panel_path);
         $_SESSION['flash_message'] = "Error: The content for this tab could not be loaded. File missing: " . htmlspecialchars(basename($panel_path));
         $_SESSION['flash_type'] = 'error';
    }
} else {
    // Invalid tab selected
     $_SESSION['flash_message'] = "Error: Invalid tab selected.";
     $_SESSION['flash_type'] = 'error';
}

// --- Handle POST Actions Redirect ---
// Now that the panel has potentially processed the POST and set flash messages, handle the redirect.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine the tab to redirect back to, prioritizing the submitted tab if available
    $submitted_tab = $_POST['submitted_tab'] ?? null;
    // Use submitted tab if it's set and corresponds to a valid panel file, otherwise fallback to the active tab determined earlier
    $redirect_tab = (isset($panel_files[$submitted_tab])) ? $submitted_tab : $active_tab;

    // Determine redirect site ID - Prioritize POST, fallback to selected
    $posted_site_id_for_redirect = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
    // Use the posted site ID if it's valid and not null, otherwise use the one determined earlier
    $redirect_site_id = ($posted_site_id_for_redirect !== false && $posted_site_id_for_redirect !== null)
                        ? $posted_site_id_for_redirect
                        : $selected_config_site_id;

    // Construct the redirect URL
    $redirect_url = "configurations.php?tab=" . urlencode($redirect_tab);
    if ($redirect_site_id !== null) {
        if (in_array($redirect_tab, ['site-settings', 'questions', 'notifiers', 'ads-management'])) {
             $redirect_url .= "&site_id=" . $redirect_site_id;
        }
    }

    // Prevent redirecting back to edit views after processing update/delete/toggle etc.
    $redirect_url = preg_replace('/&view=[^&]*/', '', $redirect_url);
    $redirect_url = preg_replace('/&edit_item_id=[^&]*/', '', $redirect_url);
    $redirect_url = preg_replace('/&edit_ad_id=[^&]*/', '', $redirect_url);

    // Perform the redirect using 303 See Other for POST-redirect-GET pattern
    header("Location: " . $redirect_url, true, 303);
    exit; // IMPORTANT: Stop script execution after redirect header
}
// --- END: Process Panel Logic & Handle POST Redirect ---


// --- Flash Message Handling (Retrieve messages set by panels or previous actions) ---
// This now happens AFTER the panel include and potential redirect logic, but BEFORE header output.
$flash_message = $_SESSION['flash_message'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// --- Page Setup & Header (Now safe to output HTML) ---
$pageTitle = "Configurations";
require_once 'includes/header.php'; // Includes session_start() if not already started
?>

            <!-- Page Header Section -->
            <div class="header">
                 <!-- Site Selector Dropdown -->
                 <?php if ($is_site_admin === 1 && $selected_config_site_id !== null && $selected_site_details): ?>
                     <div class="site-selector">
                         <label>Configuring Site:</label>
                         <strong><?php echo htmlspecialchars($selected_site_details['name']); ?></strong>
                         (ID: <?php echo $selected_config_site_id; ?>)
                         <?php echo !$selected_site_details['is_active'] ? '<span class="badge badge-warning">Inactive</span>' : ''; ?>
                     </div>
                 <?php elseif ($can_select_site && !empty($sites_list_for_dropdown)): ?>
                     <div class="site-selector">
                         <label for="config-site-select">Configure Site:</label>
                         <select id="config-site-select" name="site_id_selector"
                                 onchange="location = 'configurations.php?tab=<?php echo urlencode($active_tab); ?>&site_id=' + this.value;">
                             <?php foreach ($sites_list_for_dropdown as $site): ?>
                                <option value="<?php echo $site['id']; ?>" <?php echo ($site['id'] == $selected_config_site_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['name']); ?> <?php echo !$site['is_active'] ? '(Inactive)' : ''; ?>
                                </option>
                             <?php endforeach; ?>
                         </select>
                     </div>
                 <?php elseif ($config_error): // Show error if site list failed or user has no access ?>
                     <div class="message-area message-warning"><?php echo htmlspecialchars($config_error); ?></div>
                 <?php else: // Fallback - should not be reached if logic above is correct ?>
                     <div class="message-area message-warning">Site selection not available.</div>
                 <?php endif; ?>
             </div>

             <!-- Display Flash Messages -->
             <?php if ($flash_message): ?>
                <div class="message-area message-<?php echo htmlspecialchars($flash_type); ?>"><?php echo $flash_message; ?></div>
             <?php endif; ?>
             <?php if ($config_error && empty($sites_list_for_dropdown)): // Show config error if site list failed AND is empty ?>
                 <div class="message-area message-error"><?php echo htmlspecialchars($config_error); ?></div>
             <?php endif; ?>


             <!-- Main Configuration Area with Tabs -->
             <div class="admin-settings">
                 <!-- Tab Links -->
                 <div class="tabs">
                     <?php
                     $tabs_config = [
                         'site-settings' => 'Site Settings',
                         'questions' => 'Questions', // Consolidated tab
                         'notifiers' => 'Email Notifiers',
                         'ads-management' => 'Ads Management',
                         'departments' => 'Departments',
                         'api-keys' => 'API Keys' // Added API Keys tab
                     ];

                     // Filter tabs for Site Admins (remove 'departments')
                     if ($is_site_admin === 1) {
                         unset($tabs_config['departments']);
                     }

                     // Determine base URL for tabs (without site_id initially)
                     $base_tab_url = 'configurations.php?tab=';

                     foreach ($tabs_config as $tab_id => $tab_name):
                         // --- Role Check for API Keys Tab ---
                         if ($tab_id === 'api-keys' && $session_role !== 'administrator') {
                             continue; // Skip rendering this tab if not admin
                         }
                         // --- End Role Check ---
                         $tab_url = $base_tab_url . urlencode($tab_id);
                         // Define tabs that require a site ID
                         $site_specific_tabs = ['site-settings', 'questions', 'notifiers', 'ads-management', 'api-keys']; // Added api-keys

                         // Add site_id only if required by the tab and a site is selected
                         if (in_array($tab_id, $site_specific_tabs) && $selected_config_site_id !== null) {
                             $tab_url .= '&site_id=' . $selected_config_site_id;
                         }
                         $is_active = ($active_tab === $tab_id) ? 'active' : '';

                         // Disable site-specific tabs if no site is selected
                         $is_disabled = !$selected_config_site_id && in_array($tab_id, $site_specific_tabs);
                         $disabled_class = $is_disabled ? 'disabled' : '';
                         $link_attributes = $is_disabled ? 'onclick="return false;" class="disabled"' : 'href="' . htmlspecialchars($tab_url) . '"';
                     ?>
                         <a <?php echo $link_attributes; ?> class="tab <?php echo $is_active; ?> <?php echo $disabled_class; ?>" data-tab="<?php echo $tab_id; ?>">
                             <?php echo htmlspecialchars($tab_name); ?>
                         </a>
                     <?php endforeach; ?>
                 </div>

                 <!-- Tab Content Area - Output Panel Content -->
                 <div id="tab-content">
                     <?php
                     // Output the panel content that was buffered earlier (if any)
                     // This ensures panel HTML is only displayed on GET requests after the header
                     // If $panel_output is empty (e.g., due to missing site ID on GET, or file not found), nothing is echoed.
                     // --- Role Check for API Keys Panel Output ---
                     // Only output the panel content if it's not the API Keys tab OR if it is the API Keys tab AND the user is an admin.
                     if (!($active_tab === 'api-keys' && $session_role !== 'administrator')) {
                          echo $panel_output;
                     } elseif ($active_tab === 'api-keys' && $session_role !== 'administrator') {
                          // Optionally display an access denied message here if needed,
                          // but the tab link shouldn't be visible anyway due to the check above.
                          // echo "<div class='message-area message-error'>Access Denied.</div>";
                     }
                     // --- End Role Check ---
                     ?>
                 </div> <!-- End #tab-content -->
             </div> <!-- End .admin-settings -->

<?php // Conditionally add API Keys JS to footer scripts for administrators ?>
<?php
if (isset($session_role) && $session_role === 'administrator') {
    // Initialize footer_scripts if it doesn't exist
    if (!isset($GLOBALS['footer_scripts'])) {
        $GLOBALS['footer_scripts'] = '';
    }
    // Add the script tag to the footer scripts global variable
    $api_keys_js_path = 'assets/js/api_keys_config.js';
    $version = file_exists($api_keys_js_path) ? filemtime($api_keys_js_path) : time(); // Add cache busting
    $GLOBALS['footer_scripts'] .= '<script src="' . htmlspecialchars($api_keys_js_path) . '?v=' . $version . '"></script>' . "\n";
}
?>

<?php require_once 'includes/footer.php'; ?>
