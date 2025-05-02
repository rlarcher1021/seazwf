<?php
/*
 * File: header.php
 * Path: /includes/header.php
 * Created: [Original Date]
 * Author: Robert Archer
 * Updated: 2025-04-05 - Added dynamic manual check-in link logic, CSP handling etc.
 *
 * Description: Site header, navigation, session checks, Admin impersonation controls.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure auth.php is included first to check login status and set session vars
require_once __DIR__ . '/auth.php'; // Use __DIR__ for reliability

// Database connection needed for fetching sites and other header data
require_once __DIR__ . '/db_connect.php';

// Utility functions (CSRF, flash messages, etc.)
require_once __DIR__ . '/utils.php';

// Verify $pdo exists after includes
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("FATAL: PDO connection object not established after including db_connect.php in header.php");
    die("A critical database connection error occurred. Please notify the system administrator.");
}

// --- Page Variables & Checks ---
$impersonation_sites = [];
$current_page_basename = basename($_SERVER['PHP_SELF']);
$is_admin = isset($_SESSION['real_role']) && $_SESSION['real_role'] === 'administrator';

// --- Admin Reset View Logic ---
if ($is_admin && isset($_GET['reset_view'])) {
    $_SESSION['active_role'] = $_SESSION['real_role']; // Reset to Admin
    $_SESSION['active_site_id'] = $_SESSION['real_site_id']; // Reset to Admin's site (null)
    $_SESSION['active_site_name'] = 'All Sites'; // Reset name
    $_SESSION['dashboard_site_filter'] = 'all';
    error_log("Admin '{$_SESSION['username']}' (ID: {$_SESSION['user_id']}) reset view to Administrator (All Sites).");
    // Redirect to remove the query parameter
    $redirect_url = strtok($_SERVER["REQUEST_URI"], '?') ?: $current_page_basename; // Handle case with no query string
    header("Location: " . $redirect_url);
    exit;
}

// Determine if currently impersonating
$is_impersonating = $is_admin && (
    ($_SESSION['active_role'] !== $_SESSION['real_role']) ||
    ($_SESSION['active_site_id'] !== $_SESSION['real_site_id']) // Compare active to real
);

// --- Fetch Data Needed for Header (e.g., Impersonation Sites) ---
if ($is_admin) {
    try {
        $stmt_imp_sites = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
        $impersonation_sites = $stmt_imp_sites->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching sites for impersonation: " . $e->getMessage());
        // Handle error gracefully - maybe disable impersonation controls if site list fails
    }

    // --- Handle Impersonation Form Submission ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['impersonate_switch'])) {
        // --- CSRF Token Verification ---
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            error_log("CSRF token validation failed during impersonation attempt by User ID: " . ($_SESSION['user_id'] ?? 'Unknown'));
            // Redirect or display an error, preventing further processing
            header("Location: " . ($_SERVER['PHP_SELF'] ?? 'dashboard.php') . "?error=csrf"); // Redirect back
            exit;
        }

        // ... (Impersonation POST handling logic - remains the same as your provided code) ...
        // Verify again it's really an admin posting
        if ($_SESSION['real_role'] === 'administrator') {
            $new_role = $_POST['impersonate_role'] ?? $_SESSION['real_role'];
            $new_site_id_str = $_POST['impersonate_site_id'] ?? 'all';

            // Validate Role
            $allowed_roles = ['administrator', 'director', 'azwk_staff', 'outside_staff'];
            if (!in_array($new_role, $allowed_roles)) {
                $new_role = $_SESSION['real_role'];
            }

            // Validate Site ID
            $new_site_id = null; // Default for Admin/Director/All Sites
            if ($new_site_id_str !== 'all') {
                $valid_site_ids = array_column($impersonation_sites, 'id');
                if (in_array($new_site_id_str, $valid_site_ids)) {
                    $new_site_id = (int)$new_site_id_str;
                } else {
                    // Invalid site selected, revert based on role
                     if (in_array($new_role, ['azwk_staff', 'outside_staff'])) {
                          $new_role = 'administrator'; // Revert role too
                     }
                     // Keep $new_site_id as null (All Sites)
                }
            }

             // Site Supervisor MUST have a site ID and cannot be 'All Sites'
             if (in_array($new_role, ['azwk_staff', 'outside_staff'])) {
                 if ($new_site_id === null) { // If site became null or was 'all'
                     // Find the first available site for the supervisor or revert
                     if (!empty($impersonation_sites)) {
                         $new_site_id = $impersonation_sites[0]['id']; // Assign first active site
                         error_log("Admin Impersonation Warning: Assigning first available site ID {$new_site_id} to Supervisor role.");
                     } else {
                         $new_role = 'administrator'; // Revert role if no sites exist
                         $new_site_id = null;
                         error_log("Admin Impersonation Error: Tried to set Supervisor role without available sites.");
                     }
                 }
             }

            // Update Session
            $_SESSION['active_role'] = $new_role;
            $_SESSION['active_site_id'] = $new_site_id;

            // Update site name in session
             $_SESSION['active_site_name'] = 'All Sites'; // Default
             if ($new_site_id !== null) {
                 foreach ($impersonation_sites as $site) {
                     if ($site['id'] == $new_site_id) {
                         $_SESSION['active_site_name'] = $site['name'];
                         break;
                     }
                 }
             }


            // Log the switch
            error_log("Admin '{$_SESSION['username']}' (ID: {$_SESSION['user_id']}) switched view to Role: {$new_role}, Site ID: " . ($new_site_id ?? 'All'));

            // Redirect to the same page to clear POST data and apply view
            $redirect_url_imp = $_SERVER['PHP_SELF'];
            $query_string_imp = $_SERVER['QUERY_STRING'] ?? '';
            // Remove reset_view if present
            $query_string_imp = preg_replace('/&?reset_view=1/', '', $query_string_imp);
             // Remove site_id if impersonating all sites or role doesn't use it
             if ($new_site_id === null || !in_array($new_role, ['azwk_staff', 'outside_staff'])) {
                  $query_string_imp = preg_replace('/&?site_id=[^&]+/', '', $query_string_imp);
             }
            header("Location: " . $redirect_url_imp . (empty($query_string_imp) ? '' : '?' . ltrim($query_string_imp, '&')));
            exit;
        } else {
             error_log("Security Alert: Non-admin attempted impersonation POST. User ID: {$_SESSION['user_id']}");
             header("Location: dashboard.php");
             exit;
        }
    } // End Impersonation POST handling
} // End if ($is_admin)


// Fetch current site name for display (use session value which reflects impersonation)
$active_site_name_display = $_SESSION['active_site_name'] ?? 'All Sites';
// If site ID is set but name isn't (e.g., supervisor login), fetch it
if (isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== null && empty($_SESSION['active_site_name'])) {
     try {
         $stmt_name = $pdo->prepare("SELECT name FROM sites WHERE id = :id");
         $stmt_name->execute([':id' => $_SESSION['active_site_id']]);
         $_SESSION['active_site_name'] = $stmt_name->fetchColumn();
         $active_site_name_display = $_SESSION['active_site_name'] ?: 'Error: Site Not Found';
     } catch (PDOException $e) {
         error_log("Error fetching site name for header display: " . $e->getMessage());
         $active_site_name_display = 'Error';
     }
}


// --- Determine Manual Check-in Link Behavior for Sidebar ---
$manual_checkin_href = '#'; // Default href
$manual_checkin_action_attr = ''; // Default data attributes

if (isset($_SESSION['active_role'])) {
    $current_role_for_link = $_SESSION['active_role'];
    $session_site_id_for_link = $_SESSION['active_site_id'] ?? null;

    if (in_array($current_role_for_link, ['kiosk', 'azwk_staff', 'outside_staff'])) {
        // Direct link for Kiosk/Supervisor (session site ID determines their context)
        $manual_checkin_href = 'checkin.php';
    } elseif (in_array($current_role_for_link, ['administrator', 'director'])) {
        // Admin/Director initial state: Default to triggering modal.
        // JS on dashboard/config will update if a specific site is selected there.
        $manual_checkin_href = '#'; // Start with #
        $manual_checkin_action_attr = 'data-toggle="modal" data-target="#selectSiteModal"'; // Trigger modal
    }
}
// --- End Manual Check-in Link ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'AZ@Work Check-In'; ?> - SEAZWF</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/flaviconlogo.png">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="assets/css/main.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/main.css'); // Cache busting ?>">
    <!-- Select2 CSS (Add after Bootstrap CSS) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Select2 Bootstrap 5 Theme (Optional, but recommended) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />


    <!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

    <!-- Chart.js (Include only if needed globally or move to specific pages) -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> -->

  
    </head>
<body>
    <div class="app-container">
        <?php // Sidebar Exclusions
        if ($current_page_basename !== 'index.php' && $current_page_basename !== 'checkin.php'):
        ?>
        <aside class="sidebar">
            <div class="logo-container">
                <img src="assets/img/logo.jpg" alt="Arizona@Work Logo" class="sidebar-logo">
            </div>
            <div class="user-panel">
                <i class="fas fa-user-circle user-avatar"></i>
                <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
                <span class="user-role">
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['active_role']))); ?>
                    <?php // Show site context if not 'All Sites'
                        if (isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== null) {
                            echo " (" . htmlspecialchars($active_site_name_display) . ")";
                        } elseif ($is_admin || $_SESSION['active_role'] === 'director') {
                             echo " (All Sites)";
                        }
                    ?>
                </span>
            </div>
            <nav class="navigation">
                <ul>
                    <?php // Menu items based on ACTIVE role ?>
                    <li><a href="dashboard.php" <?php echo $current_page_basename === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>

                    <?php // Manual Check-in Link (using variables determined above)
                    if (isset($_SESSION['active_role']) && $_SESSION['active_role'] !== 'kiosk'): ?>
                    <li>
                        <a href="<?php echo $manual_checkin_href; ?>"
                           id="manual-checkin-link-sidebar"
                           class="manual-checkin-trigger <?php echo $current_page_basename === 'checkin.php' ? 'active' : ''; ?>"
                           <?php echo $manual_checkin_action_attr; ?>>
                            <i class="fas fa-clipboard-check"></i> Manual Check-in
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (in_array($_SESSION['active_role'], ['azwk_staff', 'outside_staff', 'director', 'administrator'])): ?>
                        <li><a href="reports.php" <?php echo $current_page_basename === 'reports.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <?php endif; ?>
                    <?php // Account Link (Exclude Kiosk)
                    if (isset($_SESSION['active_role']) && $_SESSION['active_role'] !== 'kiosk'): ?>
                        <li><a href="account.php" <?php echo $current_page_basename === 'account.php' ? 'class="active"' : ''; ?>><i class="fas fa-user-cog"></i> Account</a></li>
                    <?php endif; ?>

                    <?php // Forum Link
                    if (in_array($_SESSION['active_role'], ['azwk_staff', 'outside_staff', 'director', 'administrator'])): 
                        $is_forum_page = in_array($current_page_basename, ['forum_index.php', 'view_category.php', 'view_topic.php', 'create_topic.php']);
                    ?>
                        <li><a href="forum_index.php" <?php echo $is_forum_page ? 'class="active"' : ''; ?>><i class="fas fa-comments"></i> Forum</a></li>
                    <?php endif; ?>

                    <?php // --- START: Budget Feature Links --- ?>
                    <?php
                        // Make role check case-insensitive
                        $currentActiveRoleLower = isset($_SESSION['active_role']) ? strtolower($_SESSION['active_role']) : null;
                        $allowedBudgetRoles = ['director', 'azwk_staff', 'finance']; // Corrected role name
                    ?>
                    <?php if ($currentActiveRoleLower && in_array($currentActiveRoleLower, $allowedBudgetRoles)):
                        $is_budget_page = in_array($current_page_basename, ['budgets.php', 'budget_setup.php', 'grant_management.php']);
                        // Determine active class more broadly for the budget section
                        $budget_section_active = $is_budget_page ? 'active' : '';
                    ?>
                        <?php // Budget Allocations (Main Page) - Staff, Director, Finance ?>
                        <li><a href="budgets.php" <?php echo $current_page_basename === 'budgets.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Budget Allocations</a></li>

                        <?php // Budget Settings (Consolidated) - Director Only ?>
                        <?php if ($currentActiveRoleLower === 'director'): // Compare with lowercase ?>
                            <li><a href="budget_settings.php" <?php echo $current_page_basename === 'budget_settings.php' ? 'class="active"' : ''; ?>><i class="fas fa-cogs"></i> Budget Settings</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php // --- END: Budget Feature Links --- ?>


                      <?php // --- START: Notifications (Supervisor ONLY) --- ?>
                    <?php if (isset($_SESSION['active_role']) && in_array($_SESSION['active_role'], ['azwk_staff', 'outside_staff'])): ?>
                        <li><a href="notifications.php" <?php echo $current_page_basename === 'notifications.php' ? 'class="active"' : ''; ?>><i class="fas fa-bell"></i> Notifications</a></li>
                    <?php endif; ?>
                    <?php // --- END: Notifications --- ?>
                    <?php // Configurations Link: Admin, Director, Site Admin
                    if (
                        (isset($_SESSION['active_role']) && ($_SESSION['active_role'] === 'administrator' || $_SESSION['active_role'] === 'director')) ||
                        (isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1)
                    ): ?>
                        <li><a href="configurations.php" <?php echo $current_page_basename === 'configurations.php' ? 'class="active"' : ''; ?>><i class="fas fa-cog"></i> Configurations</a></li>
                    <?php endif; ?>

                    <?php // User Management Link: Admin, Site Admin
                    if (
                        (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'administrator') ||
                        (isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1)
                    ): ?>
                        <li><a href="users.php" <?php echo $current_page_basename === 'users.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> User Management</a></li>
                    <?php endif; ?>

                    <?php
                    // Client Editor Link: Director, Site Admin, Administrator
                    if (
                        (isset($_SESSION['active_role']) && in_array($_SESSION['active_role'], ['director', 'administrator'])) || // Added 'administrator' back
                        (isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1)
                    ) { // Use standard curly brace
                        ?>
                        <li><a href="client_editor.php" <?php echo $current_page_basename === 'client_editor.php' ? 'class="active"' : ''; ?>><i class="fas fa-address-card"></i> Client Editor</a></li>
                    <?php
                    } // End if with standard curly brace
                    ?>

                    <?php // System Alerts Link: Admin Only (Remains unchanged)
                    if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'administrator'): ?>
                        <li><a href="alerts.php" <?php echo $current_page_basename === 'alerts.php' ? 'class="active"' : ''; ?>><i class="fas fa-exclamation-triangle"></i> System Alerts</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
             <div class="sidebar-footer">
                  <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>
        <main class="main-content">
             <header class="main-header">
                <!-- Page Title is now optional in header, main title comes from $pageTitle -->
                 <h1><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'AZ@Work Check-In'; ?></h1>

                <?php // --- Impersonation Controls ---
                if ($is_admin): ?>
                <div class="impersonation-controls">
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?<?php echo htmlspecialchars($_SERVER['QUERY_STRING']); ?>" method="POST" class="d-inline-block">
                        <input type="hidden" name="impersonate_switch" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <label for="impersonate_role">View as:</label>
                        <select name="impersonate_role" id="impersonate_role" class="form-select form-select-sm">
                            <option value="administrator" <?php echo ($_SESSION['active_role'] === 'administrator') ? 'selected' : ''; ?>>Admin</option>
                            <option value="director" <?php echo ($_SESSION['active_role'] === 'director') ? 'selected' : ''; ?>>Director</option>
                            <option value="azwk_staff" <?php echo ($_SESSION['active_role'] === 'azwk_staff') ? 'selected' : ''; ?>>AZWK Staff</option>
                            <option value="outside_staff" <?php echo ($_SESSION['active_role'] === 'outside_staff') ? 'selected' : ''; ?>>Outside Staff</option>
                        </select>

                        <label for="impersonate_site_id">Site:</label>
                        <select name="impersonate_site_id" id="impersonate_site_id" class="form-select form-select-sm">
                            <option value="all" <?php echo ($_SESSION['active_site_id'] === null) ? 'selected' : ''; ?>>All</option>
                            <?php foreach ($impersonation_sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>" <?php echo ($_SESSION['active_site_id'] == $site['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                         <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                         <?php if ($is_impersonating): ?>
                            <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?reset_view=1" class="btn btn-secondary btn-sm">Reset</a>
                         <?php endif; ?>
                    </form>
                </div>
                <?php endif; // end is_admin ?>

             </header>

             <?php // --- Impersonation Indicator ---
             if ($is_impersonating):
                $impersonation_role_display = htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['active_role'])));
             ?>
                <div class="impersonation-indicator">
                    <i class="fas fa-user-secret"></i> Viewing as <strong><?php echo $impersonation_role_display; ?></strong>
                    <?php
                        $site_context_display = ' (All Sites)'; // Default
                        if (isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== null) {
                             $site_context_display = " for site: <strong>" . htmlspecialchars($active_site_name_display) . "</strong>";
                        }
                        echo $site_context_display;
                    ?>
                     - <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?reset_view=1">Reset View</a>
                </div>
             <?php endif; // end is_impersonating ?>

             <div class="content-wrapper"> <?php // Start content wrapper for main page content ?>

        <?php else: // If login or checkin page, structure is different ?>
        </main> <!-- Close main-content early if sidebar not shown -->
        <div class="content-wrapper-full"> <?php // Use a different wrapper for login/checkin ?>
        <?php endif; ?>

 <?php // Styles can be moved to main.css ?>
 <style>
    .impersonation-controls { background-color: #f8f9fa; padding: 5px 10px; border-radius: 4px; margin-left: auto; font-size: 0.85em; border: 1px solid #dee2e6; }
    .impersonation-controls label { margin: 0 3px 0 8px; font-weight: 500;}
    .impersonation-controls select, .impersonation-controls button { padding: 3px 6px; font-size: 0.85em; margin: 0 2px; height: auto; line-height: 1.4; border-radius: 0.2rem; }
    .impersonation-indicator { background-color: #fff3cd; color: #664d03; border: 1px solid #ffecb5; padding: 10px 15px; margin-bottom: 15px; margin-top: -10px; border-radius: 4px; text-align: center; font-size: 0.9em; }
    .impersonation-indicator i { margin-right: 5px; }
    .button.button-small { padding: 4px 8px; font-size: 0.85em; }
    .button.button-secondary { background-color: #6c757d; border-color: #6c757d; color: white; }
    .button.button-secondary:hover { background-color: #5a6268; border-color: #545b62;}
    .main-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
 </style>