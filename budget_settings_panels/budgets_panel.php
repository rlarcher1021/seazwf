<?php
// budget_settings_panels/budgets_panel.php

// Ensure this panel is included by budget_settings.php which handles main auth and DB connection ($pdo)
require_once __DIR__ . '/../includes/db_connect.php'; // Ensure $pdo is available
require_once __DIR__ . '/../includes/auth.php';      // For check_permission, session data
require_once __DIR__ . '/../includes/utils.php';     // For CSRF, flash messages, formatting
require_once __DIR__ . '/../includes/data_access/grants_dal.php'; // Needed for Grant dropdown
require_once __DIR__ . '/../data_access/budget_data.php'; // Budget specific functions
require_once __DIR__ . '/../includes/data_access/user_data.php'; // For User dropdown
require_once __DIR__ . '/../includes/data_access/department_data.php'; // For Department dropdown

// --- Permission Check ---
// Double-check permission specifically for budget setup within the panel
if (!check_permission(['director', 'administrator'])) {
    echo '<div class="alert alert-danger">Access Denied to Budget Setup.</div>';
    return; // Stop rendering this panel
}

// --- Initialize variables for this panel ---
$budgets = [];
$grants = [];
$departments = [];
$azWorkUsers = [];
$azWorkDeptId = null;
$panel_error_message = null; // Use panel-specific variable names
// Note: Flash messages set by POST handler below will be displayed by the main budget_settings.php

// --- CSRF Token ---
$csrf_token = generateCsrfToken();

// --- Fetch Data for Display and Forms ---
try {
    // Fetch all non-deleted budgets with related names
    $budgets = getAllBudgets($pdo); // Using the comprehensive getAllBudgets

    // Fetch data for dropdowns
    $grants = getAllGrants($pdo); // Assuming fetches non-deleted
    $departments = getAllDepartments($pdo); // Assuming fetches non-deleted

    // Find the ID for 'Arizona@Work' department
    foreach ($departments as $dept) {
        if (strtolower($dept['name']) === 'arizona@work') {
            $azWorkDeptId = $dept['id'];
            break;
        }
    }

    // Fetch active users only from the 'Arizona@Work' department if ID found
    if ($azWorkDeptId !== null) {
        $azWorkUsers = getActiveUsersByDepartment($pdo, $azWorkDeptId);
    } else {
        error_log("Budget Setup Panel: 'Arizona@Work' department ID not found.");
        // Set a panel-specific error, though flash message might be better if critical
        $panel_error_message = "Could not load users: 'Arizona@Work' department not found.";
    }

} catch (Exception $e) {
    $panel_error_message = "Error fetching data for budget panel: " . $e->getMessage();
    error_log("Error fetching data for budget_panel.php: " . $e->getMessage());
}




// --- Fetch Budget Data for Display (Fetch again after potential POST) ---
try {
    $budgets = getAllBudgets($pdo); // Fetch fresh data
} catch (Exception $e) {
    $panel_error_message = "Error fetching budgets: " . $e->getMessage();
    error_log("Error fetching budgets for panel: " . $e->getMessage());
}

?>

<!-- Budget Setup Panel HTML -->
<div class="card mb-4">
    <div class="card-header">
        <i class="fas fa-file-invoice-dollar me-1"></i>
        Manage Budgets
        <button type="button" class="btn btn-primary btn-sm float-end" data-toggle="modal" data-target="#addBudgetModalPanel">
            <i class="fas fa-plus me-1"></i> Add Budget
        </button>
    </div>
    <div class="card-body">
         <?php if ($panel_error_message): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($panel_error_message); ?>
            </div>
        <?php endif; ?>
        <?php // Flash messages are displayed by the parent budget_settings.php ?>

        <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover" id="budgetsTable">
                 <thead class="table-dark">
                    <tr>
                        <th>Budget Name</th>
                        <th>User</th>
                        <th>Grant</th>
                        <th>Department</th>
                        <th>Fiscal Year</th>
                        <th>Type</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($budgets)): ?>
                        <tr>
                            <td colspan="8" class="text-center">No active budgets found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($budgets as $budget): ?>
                             <?php // Skip deleted budgets in the main view ?>
                            <?php // Skip deleted budgets only if the key exists and is not null/false
                            if (isset($budget['deleted_at']) && $budget['deleted_at']) continue; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($budget['name'] ?? 'N/A'); // Use null coalescing operator ?></td>
                                <td><?php echo htmlspecialchars($budget['user_full_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($budget['grant_name']); ?></td>
                                <td><?php echo htmlspecialchars($budget['department_name']); ?></td>
                                <td><?php echo htmlspecialchars($budget['fiscal_year_start']) . ' - ' . htmlspecialchars($budget['fiscal_year_end']); ?></td>
                                <td><span class="badge bg-<?php echo $budget['budget_type'] === 'Admin' ? 'info' : 'secondary'; ?>"><?php echo htmlspecialchars($budget['budget_type']); ?></span></td>
                                <td class="table-cell-truncate-200" title="<?php echo htmlspecialchars($budget['notes'] ?? ''); ?>">
                                    <?php echo nl2br(htmlspecialchars($budget['notes'] ?? '')); ?>
                                </td>
                                <td>
                                    <!-- Edit Button -->
                                    <button type="button" class="btn btn-sm btn-warning me-1 edit-budget-btn-panel"
                                            data-toggle="modal" data-target="#editBudgetModalPanel"
                                            data-budget-id="<?php echo $budget['id']; ?>"
                                            data-budget-name="<?php echo htmlspecialchars($budget['name'] ?? 'N/A', ENT_QUOTES); ?>"
                                            data-user-id="<?php echo $budget['user_id']; ?>"
                                            data-grant-id="<?php echo $budget['grant_id']; ?>"
                                            data-department-id="<?php echo $budget['department_id']; ?>"
                                            data-fy-start="<?php echo htmlspecialchars($budget['fiscal_year_start']); ?>"
                                            data-fy-end="<?php echo htmlspecialchars($budget['fiscal_year_end']); ?>"
                                            data-budget-type="<?php echo htmlspecialchars($budget['budget_type']); ?>"
                                            data-budget-notes="<?php echo htmlspecialchars($budget['notes'] ?? '', ENT_QUOTES); ?>"
                                            title="Edit Budget">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <!-- Delete Button (triggers form submission) -->
                                     <form action="budget_settings.php#budgets-tab" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete the budget \'<?php echo htmlspecialchars(addslashes($budget['name'] ?? 'N/A')); ?>\'? This will also hide associated allocations.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="budget_action" value="delete">
                                        <input type="hidden" name="budget_id" value="<?php echo $budget['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Budget">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Add Budget Modal -->
<div class="modal fade" id="addBudgetModalPanel" tabindex="-1" aria-labelledby="addBudgetModalPanelLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="budget_settings.php#budgets-tab" method="POST" id="addBudgetFormPanel">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBudgetModalPanelLabel">Add New Budget</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="budget_action" value="add">

                    <div class="mb-3">
                        <label for="add_budget_name_panel" class="form-label">Budget Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_budget_name_panel" name="name" required placeholder="e.g., Vickie Smith - WIOA FY24">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_budget_grant_id_panel" class="form-label">Grant <span class="text-danger">*</span></label>
                            <select class="form-select" id="add_budget_grant_id_panel" name="grant_id" required>
                                <option value="" disabled selected>Select Grant...</option>
                                <?php foreach ($grants as $grant): ?>
                                     <?php if (empty($grant['deleted_at'])): // Only show active grants ?>
                                    <option value="<?php echo $grant['id']; ?>"><?php echo htmlspecialchars($grant['name']); ?></option>
                                     <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="add_budget_department_id_panel" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select" id="add_budget_department_id_panel" name="department_id" required>
                                <option value="" disabled selected>Select Department...</option>
                                <?php foreach ($departments as $dept): ?>
                                     <?php if (empty($dept['deleted_at'])): // Only show active departments ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($dept['id'] == $azWorkDeptId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                     <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                             <small class="form-text text-muted">Typically 'Arizona@Work' for budget setup.</small>
                        </div>
                    </div>

                     <div class="row">
                         <div class="col-md-6 mb-3" id="add_user_id_container_panel"> <!-- Added container ID -->
                            <label for="add_budget_user_id_panel" class="form-label">Assigned User (for Staff Budget)</label> <!-- Removed * initially -->
                            <select class="form-select" id="add_budget_user_id_panel" name="user_id"> <!-- Removed required initially -->
                                <option value="" selected>-- Select User (Required for Staff Budget) --</option> <!-- Modified default option -->
                                <?php foreach ($azWorkUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($azWorkUsers) && $azWorkDeptId !== null): ?>
                                    <option value="" disabled>No active users found in Arizona@Work</option>
                                <?php elseif ($azWorkDeptId === null): ?>
                                     <option value="" disabled>Could not load users (Dept. not found)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="add_budget_type_panel" class="form-label">Budget Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="add_budget_type_panel" name="budget_type" required>
                                <option value="Staff" selected>Staff (Requires Assigned User)</option>
                                <option value="Admin">Admin (No Assigned User)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_budget_fiscal_year_start_panel" class="form-label">Fiscal Year Start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="add_budget_fiscal_year_start_panel" name="fiscal_year_start" required>
                             <small class="form-text text-muted">e.g., 2024-07-01</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="add_budget_fiscal_year_end_panel" class="form-label">Fiscal Year End <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="add_budget_fiscal_year_end_panel" name="fiscal_year_end" required>
                             <small class="form-text text-muted">e.g., 2025-06-30</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="add_budget_notes_panel" class="form-label">Notes</label>
                        <textarea class="form-control" id="add_budget_notes_panel" name="notes" rows="3"></textarea>
                    </div>
                    <div id="addBudgetErrorPanel" class="text-danger mt-2 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Budget Modal -->
<div class="modal fade" id="editBudgetModalPanel" tabindex="-1" aria-labelledby="editBudgetModalPanelLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="budget_settings.php#budgets-tab" method="POST" id="editBudgetFormPanel">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBudgetModalPanelLabel">Edit Budget</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="budget_action" value="edit">
                    <input type="hidden" id="edit_budget_id_panel" name="budget_id" value=""> <!-- Populated by JS -->

                     <div class="mb-3">
                        <label for="edit_budget_name_panel" class="form-label">Budget Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_budget_name_panel" name="name" required>
                    </div>

                     <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_budget_grant_id_panel" class="form-label">Grant <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_budget_grant_id_panel" name="grant_id" required>
                                 <?php foreach ($grants as $grant): ?>
                                     <?php if (empty($grant['deleted_at'])): // Only show active grants ?>
                                    <option value="<?php echo $grant['id']; ?>"><?php echo htmlspecialchars($grant['name']); ?></option>
                                     <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="edit_budget_department_id_panel" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_budget_department_id_panel" name="department_id" required>
                                <?php foreach ($departments as $dept): ?>
                                     <?php if (empty($dept['deleted_at'])): // Only show active departments ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                     <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                     <div class="row">
                         <div class="col-md-6 mb-3" id="edit_user_id_container_panel"> <!-- Added container ID -->
                            <label for="edit_budget_user_id_panel" class="form-label">Assigned User (for Staff Budget)</label> <!-- Removed * initially -->
                            <select class="form-select" id="edit_budget_user_id_panel" name="user_id"> <!-- Removed required initially -->
                                <option value="">-- Select User (Required for Staff Budget) --</option> <!-- Added default/placeholder -->
                                <?php foreach ($azWorkUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                <?php endforeach; ?>
                                 <?php if (empty($azWorkUsers) && $azWorkDeptId !== null): ?>
                                    <option value="" disabled>No active users found in Arizona@Work</option>
                                <?php elseif ($azWorkDeptId === null): ?>
                                     <option value="" disabled>Could not load users (Dept. not found)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="edit_budget_type_panel" class="form-label">Budget Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_budget_type_panel" name="budget_type" required>
                                <option value="Staff">Staff (Requires Assigned User)</option>
                                <option value="Admin">Admin (No Assigned User)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_budget_fiscal_year_start_panel" class="form-label">Fiscal Year Start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_budget_fiscal_year_start_panel" name="fiscal_year_start" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_budget_fiscal_year_end_panel" class="form-label">Fiscal Year End <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_budget_fiscal_year_end_panel" name="fiscal_year_end" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_budget_notes_panel" class="form-label">Notes</label>
                        <textarea class="form-control" id="edit_budget_notes_panel" name="notes" rows="3"></textarea>
                    </div>
                    <div id="editBudgetErrorPanel" class="text-danger mt-2 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- JavaScript for populating Edit Budget Modal & Handling Budget Type Change -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Budget Type Change Handler ---
    function handleBudgetTypeChange(typeSelectId, userSelectId, userContainerId) {
        const typeSelect = document.getElementById(typeSelectId);
        const userSelect = document.getElementById(userSelectId);
        const userContainer = document.getElementById(userContainerId);
        const userLabel = userContainer ? userContainer.querySelector('label') : null;

        if (!typeSelect || !userSelect || !userContainer || !userLabel) {
            console.error("Budget type change handler: Missing elements for", typeSelectId, userSelectId, userContainerId);
            return;
        }

        const updateUserFieldState = () => {
            if (typeSelect.value === 'Admin') {
                userContainer.style.display = 'none'; // Hide container
                userSelect.required = false;         // Make not required
                userSelect.value = '';               // Clear selection
                userLabel.innerHTML = 'Assigned User'; // Reset label text
            } else { // 'Staff' or default
                userContainer.style.display = 'block'; // Show container
                userSelect.required = true;          // Make required
                userLabel.innerHTML = 'Assigned User (for Staff Budget) <span class="text-danger">*</span>'; // Add back required indicator
            }
        };

        typeSelect.addEventListener('change', updateUserFieldState);

        // Initial state check on modal load (for Edit modal)
        if (typeSelectId.startsWith('edit_')) {
            const modalElement = document.getElementById('editBudgetModalPanel');
            if (modalElement) {
                // Use Bootstrap's 'shown.bs.modal' event to ensure elements are ready
                 $(modalElement).on('shown.bs.modal', updateUserFieldState);
            }
        } else {
             // Initial state check for Add modal immediately
             updateUserFieldState();
        }
    }

    // Apply handler to Add Modal
    handleBudgetTypeChange('add_budget_type_panel', 'add_budget_user_id_panel', 'add_user_id_container_panel');

    // Apply handler to Edit Modal
    handleBudgetTypeChange('edit_budget_type_panel', 'edit_budget_user_id_panel', 'edit_user_id_container_panel');

});
</script>