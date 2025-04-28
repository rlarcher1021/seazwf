<?php
// budget_settings.php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php'; // Enforces login and page access rules
require_once 'includes/utils.php'; // For flash messages, etc.

// --- Permission Check ---
// Only Directors (and potentially Administrators) should access this page.
// The main auth.php already checks if the role is allowed based on $accessRules.
// We can add an extra explicit check here if needed, but auth.php should handle it.
if (!check_permission(['director', 'administrator'])) {
    // auth.php should have already redirected, but as a fallback:
    set_flash_message('auth_error', 'You do not have permission to access Budget Settings.', 'error');
    header('Location: dashboard.php'); // Redirect to a safe page
    exit;
}


// --- Handle POST Requests for Grant Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_action'])) { // Check for a specific grant action flag

    // 1. Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // Set flash message for the main page to display
        set_flash_message('grant_error', 'CSRF token validation failed. Please try again.', 'danger');
    } else {
        // 2. Determine action
        $action = $_POST['grant_action'] ?? ''; // Use the specific flag
        $grant_id = filter_input(INPUT_POST, 'grant_id', FILTER_VALIDATE_INT);

        // 3. Sanitize and validate common inputs
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) ?? '');
        $grant_code = trim(filter_input(INPUT_POST, 'grant_code', FILTER_SANITIZE_STRING) ?? '');
        $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING) ?? '');
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);

        // Convert empty strings to null
        $grant_code = $grant_code === '' ? null : $grant_code;
        $description = $description === '' ? null : $description;
        $start_date = $start_date === '' ? null : $start_date;
        $end_date = $end_date === '' ? null : $end_date;

        // Basic validation
        $input_error = false;
        if (($action === 'add' || $action === 'edit') && empty($name)) {
            set_flash_message('grant_error', 'Grant Name is required.', 'danger');
            $input_error = true;
        }
        if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            set_flash_message('grant_error', 'Invalid Start Date format. Please use YYYY-MM-DD.', 'danger');
            $input_error = true;
        }
        if ($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            set_flash_message('grant_error', 'Invalid End Date format. Please use YYYY-MM-DD.', 'danger');
            $input_error = true;
        }
        if ($start_date && $end_date && $start_date > $end_date) {
             set_flash_message('grant_error', 'End Date cannot be earlier than Start Date.', 'danger');
             $input_error = true;
        }
        if (($action === 'edit' || $action === 'delete') && !$grant_id) {
             set_flash_message('grant_error', "Invalid Grant ID for {$action}.", 'danger');
             $input_error = true;
        }


        if (!$input_error) {
            try {
                // 4. Call appropriate DAL function based on action
                // Ensure grants_dal.php is included before this point
                require_once __DIR__ . '/includes/data_access/grants_dal.php'; // Assuming grants_dal is in includes/data_access
                $result = false;
                if ($action === 'add') {
                    $result = addGrant($pdo, $name, $grant_code, $description, $start_date, $end_date);
                    if ($result) set_flash_message('grant_success', 'Grant added successfully.', 'success');
                    else set_flash_message('grant_error', 'Error adding grant.', 'danger');

                } elseif ($action === 'edit') {
                    $result = updateGrant($pdo, $grant_id, $name, $grant_code, $description, $start_date, $end_date);
                     if ($result) set_flash_message('grant_success', 'Grant updated successfully.', 'success');
                     // updateGrant might return false if no rows affected, treat as success if no error
                     else if ($pdo->errorInfo()[0] === '00000') { // Check if no SQL error occurred
                         set_flash_message('grant_success', 'Grant details submitted (no changes detected).', 'info');
                     }
                     else set_flash_message('grant_error', 'Error updating grant.', 'danger');


                } elseif ($action === 'delete') {
                    // Add confirmation check if not done client-side
                    $result = softDeleteGrant($pdo, $grant_id);
                     if ($result) set_flash_message('grant_success', 'Grant deleted successfully.', 'success');
                     else set_flash_message('grant_error', 'Error deleting grant.', 'danger');

                } else {
                    set_flash_message('grant_error', 'Invalid grant action specified.', 'danger');
                }

                // If action was successful, redirect to clear POST data and show message
                // Redirect back to the main settings page, potentially focusing the grants tab
                if ($result || ($action === 'edit' && $pdo->errorInfo()[0] === '00000')) {
                     header("Location: budget_settings.php#grants-tab"); // Redirect to the settings page, hash focuses tab
                     exit;
                }


            } catch (PDOException $e) {
                 error_log("PDOException processing grant action '{$action}': " . $e->getMessage());
                 // Check for specific errors like duplicate entry
                 if ($e->getCode() == '23000') { // Integrity constraint violation
                     set_flash_message('grant_error', 'Error: A grant with this name or code might already exist.', 'danger');
                 } else {
                     set_flash_message('grant_error', 'A database error occurred. Please check logs.', 'danger');
                 }
            } catch (Exception $e) {
                 error_log("Exception processing grant action '{$action}': " . $e->getMessage());
                 set_flash_message('grant_error', 'A system error occurred. Please try again later.', 'danger');
            } // End try/catch for grant actions
        } // End if (!$input_error)
    } // End of the 'else' block after grant CSRF check
} // End of the 'if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_action']))' block

// --- Handle POST Requests for Budget Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['budget_action'])) { // Check for a specific budget action flag

    // 1. Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        set_flash_message('budget_error', 'CSRF token validation failed. Please try again.', 'danger');
    } else {
        // 2. Determine action
        $action = $_POST['budget_action'] ?? '';
        $budget_id = filter_input(INPUT_POST, 'budget_id', FILTER_VALIDATE_INT);

        // 3. Sanitize and validate inputs
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) ?? '');
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $grant_id = filter_input(INPUT_POST, 'grant_id', FILTER_VALIDATE_INT);
        $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
        $fiscal_year_start = filter_input(INPUT_POST, 'fiscal_year_start', FILTER_SANITIZE_STRING);
        $fiscal_year_end = filter_input(INPUT_POST, 'fiscal_year_end', FILTER_SANITIZE_STRING);
        $budget_type = filter_input(INPUT_POST, 'budget_type', FILTER_SANITIZE_STRING);
        $notes = trim(filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING) ?? '');
        $notes = $notes === '' ? null : $notes;

        // Basic Validation (Only for Add/Edit)
        $validation_errors = [];
        if ($action === 'add' || $action === 'edit') {
            if (empty($name)) $validation_errors[] = "Budget Name is required.";
            // User ID is only required if budget type is 'Staff'
            if ($budget_type === 'Staff' && empty($user_id)) $validation_errors[] = "Assigned User is required for Staff budgets.";
            if (empty($grant_id)) $validation_errors[] = "Grant is required.";
            if (empty($department_id)) $validation_errors[] = "Department is required.";
            if (empty($fiscal_year_start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fiscal_year_start)) $validation_errors[] = "Valid Fiscal Year Start date (YYYY-MM-DD) is required.";
            if (empty($fiscal_year_end) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fiscal_year_end)) $validation_errors[] = "Valid Fiscal Year End date (YYYY-MM-DD) is required.";
            if (!in_array($budget_type, ['Staff', 'Admin'])) $validation_errors[] = "Invalid Budget Type selected.";
            if ($fiscal_year_start && $fiscal_year_end && $fiscal_year_start >= $fiscal_year_end) $validation_errors[] = "Fiscal Year End date must be after the Start date.";
        }
        // ID validation needed for edit/delete
        if (($action === 'edit' || $action === 'delete') && !$budget_id) {
             $validation_errors[] = "Invalid Budget ID for {$action}.";
        }


        if (!empty($validation_errors)) {
            set_flash_message('budget_error', implode("<br>", $validation_errors), 'danger');
        } else {
            // 4. Call appropriate DAL function
            try {
                 // Ensure required DAL files are included
                 require_once __DIR__ . '/data_access/budget_data.php';
                 // require_once __DIR__ . '/data_access/user_data.php'; // File does not exist
                 // require_once __DIR__ . '/data_access/department_data.php'; // File does not exist

                 $result = false;
                 if ($action === 'add') {
                    $result = addBudget($pdo, $name, $user_id, $grant_id, $department_id, $fiscal_year_start, $fiscal_year_end, $budget_type, $notes);
                    if ($result) set_flash_message('budget_success', 'Budget added successfully.', 'success');
                    else set_flash_message('budget_error', 'Error adding budget.', 'danger');

                } elseif ($action === 'edit') {
                    $result = updateBudget($pdo, $budget_id, $name, $user_id, $grant_id, $department_id, $fiscal_year_start, $fiscal_year_end, $budget_type, $notes);
                     if ($result) set_flash_message('budget_success', 'Budget updated successfully.', 'success');
                     else if ($pdo->errorInfo()[0] === '00000') {
                         set_flash_message('budget_success', 'Budget details submitted (no changes detected).', 'info');
                     }
                     else set_flash_message('budget_error', 'Error updating budget.', 'danger');

                } elseif ($action === 'delete') {
                    $result = softDeleteBudget($pdo, $budget_id);
                     if ($result) set_flash_message('budget_success', 'Budget deleted successfully.', 'success');
                     else set_flash_message('budget_error', 'Error deleting budget.', 'danger');

                } else {
                    set_flash_message('budget_error', 'Invalid budget action specified.', 'danger');
                }

                 // Set flash message based on result (already done above for add/edit/delete)

                 // Redirect logic moved outside try...catch
            } catch (PDOException $e) {
                 error_log("PDOException processing budget action '{$action}': " . $e->getMessage());
                 set_flash_message('budget_error', 'A database error occurred. Please check logs.', 'danger');
            } catch (Exception $e) {
                 error_log("Exception processing budget action '{$action}': " . $e->getMessage());
                 set_flash_message('budget_error', 'A system error occurred. Please try again later.', 'danger');
            } // End try/catch for budget actions
        } // End else (after validation check)
            // ALWAYS redirect back to the budgets tab after attempting an action.
            // Flash messages set within the try/catch will indicate the outcome.
            header("Location: budget_settings.php#budgets-tab"); // Use hash to target the tab
            exit;

    } // End of the 'else' block after budget CSRF check
} // End of the 'if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['budget_action']))' block
// Removed comment end marker



$page_title = "Budget Settings";

// --- Determine Active Tab from Cookie ---
$valid_tabs = ['grants-tab', 'budgets-tab', 'vendors-tab'];
$active_tab_id = 'grants-tab'; // Default tab

// DEBUG: Check the raw cookie value received by PHP
$cookie_value = $_COOKIE['activeBudgetSettingsTab'] ?? 'Not Set';
error_log("Budget Settings DEBUG: Cookie 'activeBudgetSettingsTab' value received by PHP: " . $cookie_value);

if (isset($_COOKIE['activeBudgetSettingsTab']) && in_array($_COOKIE['activeBudgetSettingsTab'], $valid_tabs)) {
    $active_tab_id = $_COOKIE['activeBudgetSettingsTab'];
    error_log("Budget Settings DEBUG: Setting active tab from cookie: " . $active_tab_id); // DEBUG
} else {
    error_log("Budget Settings DEBUG: Cookie not set or invalid ('" . $cookie_value . "'). Defaulting to: " . $active_tab_id); // DEBUG
}
// --- End Determine Active Tab ---

include 'includes/header.php';

?>

<div class="container-fluid px-4">
    <h1 class="mt-4"><?php echo $page_title; ?></h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
    </ol>

    <?php
    // Display any flash messages
    display_flash_messages('vendor_success', 'success');
    display_flash_messages('vendor_error', 'danger');
    display_flash_messages('grant_success', 'success');
    display_flash_messages('grant_error', 'danger');
    display_flash_messages('budget_success', 'success');
    display_flash_messages('budget_error', 'danger');
    display_flash_messages('auth_error', 'danger'); // Display auth errors if redirected here
    ?>

    <!-- Nav tabs -->
    <ul class="nav nav-tabs mb-3" id="budgetSettingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab_id === 'grants-tab') ? 'active' : ''; ?>" id="grants-tab" data-toggle="tab" data-target="#grants-panel" type="button" role="tab" aria-controls="grants-panel" aria-selected="<?php echo ($active_tab_id === 'grants-tab') ? 'true' : 'false'; ?>">Grants</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab_id === 'budgets-tab') ? 'active' : ''; ?>" id="budgets-tab" data-toggle="tab" data-target="#budgets-panel" type="button" role="tab" aria-controls="budgets-panel" aria-selected="<?php echo ($active_tab_id === 'budgets-tab') ? 'true' : 'false'; ?>">Budgets</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab_id === 'vendors-tab') ? 'active' : ''; ?>" id="vendors-tab" data-toggle="tab" data-target="#vendors-panel" type="button" role="tab" aria-controls="vendors-panel" aria-selected="<?php echo ($active_tab_id === 'vendors-tab') ? 'true' : 'false'; ?>">Vendors</button>
        </li>
    </ul>

    <!-- Tab panes -->
    <div class="tab-content" id="budgetSettingsTabsContent">
        <div class="tab-pane fade <?php echo ($active_tab_id === 'grants-tab') ? 'show active' : ''; ?>" id="grants-panel" role="tabpanel" aria-labelledby="grants-tab" tabindex="0">
            <?php
            // Check if panel file exists before including
            $grants_panel_path = 'budget_settings_panels/grants_panel.php';
            if (file_exists($grants_panel_path)) {
                include $grants_panel_path;
            } else {
                echo '<div class="alert alert-warning">Grants panel content is not available.</div>';
                error_log("Missing include file: " . $grants_panel_path);
            }
            ?>
        </div>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'budgets-tab') ? 'show active' : ''; ?>" id="budgets-panel" role="tabpanel" aria-labelledby="budgets-tab" tabindex="0">
            <?php
            $budgets_panel_path = 'budget_settings_panels/budgets_panel.php';
            if (file_exists($budgets_panel_path)) {
                include $budgets_panel_path;
            } else {
                echo '<div class="alert alert-warning">Budgets panel content is not available.</div>';
                 error_log("Missing include file: " . $budgets_panel_path);
            }
            ?>
        </div>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'vendors-tab') ? 'show active' : ''; ?>" id="vendors-panel" role="tabpanel" aria-labelledby="vendors-tab" tabindex="0">
            <?php
            $vendors_panel_path = 'budget_settings_panels/vendors_panel.php';
            if (file_exists($vendors_panel_path)) {
                include $vendors_panel_path;
            } else {
                echo '<div class="alert alert-warning">Vendors panel content is not available.</div>';
                 error_log("Missing include file: " . $vendors_panel_path);
            }
            ?>
        </div>
    </div>

</div><!-- /.container-fluid -->

<?php
// Include any necessary JavaScript for modals or interactions specific to settings panels
// This might be better placed within the panels themselves or in a dedicated settings JS file
?>

<?php include 'includes/footer.php'; ?>