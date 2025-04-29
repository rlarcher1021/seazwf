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

// --- Role Check ---
if (!isset($_SESSION['active_role']) || $_SESSION['active_role'] !== 'administrator') {
    $_SESSION['flash_message'] = "Access denied. Administrator privileges required.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Determine Active Tab ---
// Standardized tab names using hyphens
$allowed_tabs = ['site-settings', 'questions', 'notifiers', 'ads-management', 'departments']; // Consolidated questions tab, Added departments
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
$sites_list_for_dropdown = getAllSitesWithStatus($pdo); // Fetch all sites for the dropdown

// Logic to determine and validate the site ID for configuration
if ($sites_list_for_dropdown === false) {
    // Function returns false on PDO error, logs it internally.
    $config_error .= " Error loading site list for dropdown.";
    $sites_list_for_dropdown = []; // Ensure it's an array
} elseif (empty($sites_list_for_dropdown)) {
    $config_error .= " No sites found in the system.";
} else {
    // We have sites, determine which one should be selected
    $site_id_from_get = filter_input(INPUT_GET, 'site_id', FILTER_VALIDATE_INT);
    $site_id_from_session = $_SESSION['selected_config_site_id'] ?? null;
    $default_site_id = $sites_list_for_dropdown[0]['id']; // First site as default

    // Prioritize GET param, then session, then the first site in the list
    $target_site_id = $site_id_from_get ?? $site_id_from_session ?? $default_site_id;

    // Validate that the target_site_id exists in our fetched list
    $found = false;
    foreach ($sites_list_for_dropdown as $site) {
        if ($site['id'] == $target_site_id) {
            $selected_config_site_id = (int)$target_site_id; // Cast to int and assign
            $found = true;
            break;
        }
    }

    // If the target ID wasn't found (e.g., invalid GET param or stale session), default to the first site
    if (!$found) {
        $selected_config_site_id = (int)$default_site_id;
        // Optionally set a warning if an invalid ID was explicitly provided via GET
        if ($site_id_from_get && $site_id_from_get != $selected_config_site_id) {
             // Avoid overwriting POST success/error messages
             if (!isset($_SESSION['flash_message'])) {
                 $_SESSION['flash_message'] = "Invalid or non-existent site ID specified. Defaulting to first available site.";
                 $_SESSION['flash_type'] = 'warning';
             }
        }
    }
}
// Store the final selected/validated site ID in the session for persistence
$_SESSION['selected_config_site_id'] = $selected_config_site_id;



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
    'departments' => 'includes/config_panels/departments_panel.php' // Added departments panel
];

// Initialize variable to hold potential panel output for GET requests
$panel_output = '';

// Determine the tab to process, prioritizing POST data
$tab_to_process = $active_tab; // Default to the tab determined by GET/session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submitted_tab']) && isset($panel_files[$_POST['submitted_tab']])) {
    $tab_to_process = $_POST['submitted_tab']; // Override with submitted tab if valid
    error_log("DEBUG configurations.php (POST): Prioritizing submitted_tab '{$tab_to_process}' for panel inclusion."); // Debug log
}

// Process panel logic only if it's a valid tab
if (isset($panel_files[$tab_to_process])) {
    $panel_path = $panel_files[$tab_to_process];
    if (file_exists($panel_path)) {
        error_log("DEBUG configurations.php ({$_SERVER['REQUEST_METHOD']}): Including panel '{$panel_path}'. Selected Site ID = {$selected_config_site_id}"); // Enhanced Debug log
        // Make $pdo and $selected_config_site_id available to the panel scope
        // $selected_site_details might be needed by the panel for display logic (GET) or processing (POST)
        $selected_site_details = ($selected_config_site_id) ? getSiteDetailsById($pdo, $selected_config_site_id) : null;

        // Check if the panel requires a site ID and if one is selected (relevant for GET display)
        // Departments tab does not require a site ID
        $requires_site_id = in_array($active_tab, ['site-settings', 'questions', 'notifiers', 'ads-management']);
        
        // Enhanced site ID validation for both GET and POST requests
        if ($requires_site_id && $selected_config_site_id === null) {
            // Log the specific scenario for debugging
            error_log("Configuration Panel Access Blocked: Tab={$active_tab}, Method={$_SERVER['REQUEST_METHOD']}, Site ID=null");
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // For POST requests, set a more specific error message
                $_SESSION['flash_message'] = "A valid site must be selected before performing actions on this tab.";
                $_SESSION['flash_type'] = 'error';
                
                // Redirect back to the configurations page
                header("Location: configurations.php?tab={$active_tab}");
                exit;
            } else {
                // For GET requests, set an info message
                $_SESSION['flash_message'] = "Please select a site using the dropdown above to view this configuration section.";
                $_SESSION['flash_type'] = 'info';
                // $panel_output remains empty
            }
        } else {
            // For POST requests OR valid GET requests, include the panel file.
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
                 <?php if (!empty($sites_list_for_dropdown)): ?>
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
                 <?php elseif ($config_error): // Show error only if dropdown couldn't be populated ?>
                    <div class="message-area message-warning"><?php echo htmlspecialchars($config_error); ?></div>
                 <?php else: // No sites exist ?>
                    <div class="message-area message-warning">No sites found in the system. Please add a site first.</div>
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
                         'departments' => 'Departments' // Added Departments tab
                     ];

                     // Determine base URL for tabs (without site_id initially)
                     $base_tab_url = 'configurations.php?tab=';

                     foreach ($tabs_config as $tab_id => $tab_name):
                         $tab_url = $base_tab_url . urlencode($tab_id);
                         // Define tabs that require a site ID
                         $site_specific_tabs = ['site-settings', 'questions', 'notifiers', 'ads-management'];

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
                     echo $panel_output;
                     ?>
                 </div> <!-- End #tab-content -->
             </div> <!-- End .admin-settings -->

<?php require_once 'includes/footer.php'; ?>
