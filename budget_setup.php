<?php
require_once 'includes/auth.php'; // Handles session start and authentication
require_once 'includes/db_connect.php';
require_once 'includes/utils.php'; // Include utils EARLIER to ensure isDirector() is defined
require_once 'includes/data_access/grants_dal.php';
require_once 'includes/data_access/budgets_dal.php';
require_once 'includes/data_access/user_data.php'; // For getActiveUsersByDepartment
require_once 'includes/data_access/department_data.php'; // For getAllDepartments


// 1. Permission Check: Ensure user is a Director
if (!isDirector()) {
    $_SESSION['error_message'] = "Access denied. You must be a Director to manage budgets.";
    header("Location: dashboard.php");
    exit;
}

// Initialize variables
$pageTitle = "Budget Setup";
$budgets = [];
$grants = [];
$departments = [];
$azWorkUsers = [];
$azWorkDeptId = null;
$error_message = $_SESSION['error_message'] ?? null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['error_message'], $_SESSION['success_message']); // Clear flash messages

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Fetch Data for Display and Forms ---
try {
    // Fetch all non-deleted budgets with related names
    $budgets = getAllBudgets($pdo); // Using the comprehensive getAllBudgets

    // Fetch data for dropdowns
    $grants = getAllGrants($pdo);
    $departments = getAllDepartments($pdo); // Fetch all departments

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
        // Handle case where AZ@Work department isn't found (log error, display message)
        error_log("Budget Setup: 'Arizona@Work' department ID not found.");
        $error_message = ($error_message ? $error_message . " " : "") . "Could not load users: 'Arizona@Work' department not found.";
    }

} catch (Exception $e) {
    $error_message = "Error fetching data: " . $e->getMessage();
    error_log("Error in budget_setup.php: " . $e->getMessage());
}

// --- Placeholder for POST request handling (Add/Edit/Delete Budget) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "CSRF token validation failed. Please try again.";
    } else {
        // 2. Determine action
        $action = $_POST['action'] ?? '';
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
        $notes = $notes === '' ? null : $notes; // Convert empty notes to NULL

        // Basic Validation
        $validation_errors = [];
        if (empty($name)) $validation_errors[] = "Budget Name is required.";
        if (empty($user_id)) $validation_errors[] = "Assigned User is required.";
        if (empty($grant_id)) $validation_errors[] = "Grant is required.";
        if (empty($department_id)) $validation_errors[] = "Department is required.";
        if (empty($fiscal_year_start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fiscal_year_start)) $validation_errors[] = "Valid Fiscal Year Start date (YYYY-MM-DD) is required.";
        if (empty($fiscal_year_end) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fiscal_year_end)) $validation_errors[] = "Valid Fiscal Year End date (YYYY-MM-DD) is required.";
        if (!in_array($budget_type, ['Staff', 'Admin'])) $validation_errors[] = "Invalid Budget Type selected.";
        if ($fiscal_year_start && $fiscal_year_end && $fiscal_year_start >= $fiscal_year_end) $validation_errors[] = "Fiscal Year End date must be after the Start date.";

        if (!empty($validation_errors)) {
            $_SESSION['error_message'] = implode("<br>", $validation_errors);
        } else {
            // 4. Call appropriate DAL function
            try {
                if ($action === 'add') {
                    $result = addBudget($pdo, $name, $user_id, $grant_id, $department_id, $fiscal_year_start, $fiscal_year_end, $budget_type, $notes);
                    if ($result) {
                        $_SESSION['success_message'] = "Budget added successfully.";
                    } else {
                        $_SESSION['error_message'] = "Error adding budget. Check logs for details (e.g., invalid IDs).";
                    }
                } elseif ($action === 'edit') {
                    if (!$budget_id) {
                        $_SESSION['error_message'] = "Invalid Budget ID for edit.";
                    } else {
                        $result = updateBudget($pdo, $budget_id, $name, $user_id, $grant_id, $department_id, $fiscal_year_start, $fiscal_year_end, $budget_type, $notes);
                        if ($result) {
                            $_SESSION['success_message'] = "Budget updated successfully.";
                        } else {
                            $_SESSION['error_message'] = "Error updating budget or no changes made. Check logs for details (e.g., invalid IDs).";
                        }
                    }
                } elseif ($action === 'delete') {
                     if (!$budget_id) {
                        $_SESSION['error_message'] = "Invalid Budget ID for delete.";
                    } else {
                        $result = softDeleteBudget($pdo, $budget_id);
                        if ($result) {
                            $_SESSION['success_message'] = "Budget deleted successfully.";
                        } else {
                            $_SESSION['error_message'] = "Error deleting budget. Check logs for details.";
                        }
                    }
                } else {
                    $_SESSION['error_message'] = "Invalid action specified.";
                }
            } catch (Exception $e) {
                 error_log("Error processing budget action '{$action}': " . $e->getMessage());
                 $_SESSION['error_message'] = "A system error occurred. Please try again later.";
            }
        }
    }

    // 6. Redirect back to the budget setup page
    header("Location: budget_setup.php");
    exit;
}


include 'includes/header.php'; // Include the header
?>

<div class="container mt-4">
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
    <hr>

    <!-- Display Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success" role="alert">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- Add Budget Button -->
    <div class="mb-3">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
            <i class="fas fa-plus"></i> Add New Budget
        </button>
    </div>

    <!-- Budgets Table -->
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
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
                        <td colspan="8" class="text-center">No budgets found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($budgets as $budget): ?>
                        <tr>
                            <td><?= htmlspecialchars($budget['name']) ?></td>
                            <td><?= htmlspecialchars($budget['user_full_name'] ?? '') ?></td> <!-- Use full_name provided by DAL -->
                            <td><?= htmlspecialchars($budget['grant_name']) ?></td>
                            <td><?= htmlspecialchars($budget['department_name']) ?></td>
                            <td><?= htmlspecialchars($budget['fiscal_year_start']) . ' - ' . htmlspecialchars($budget['fiscal_year_end']) ?></td>
                            <td><span class="badge bg-<?= $budget['budget_type'] === 'Admin' ? 'info' : 'secondary' ?>"><?= htmlspecialchars($budget['budget_type']) ?></span></td>
                            <td><?= nl2br(htmlspecialchars($budget['notes'] ?? '')) ?></td>
                            <td>
                                <!-- Edit Button -->
                                <button type="button" class="btn btn-sm btn-warning me-1 edit-budget-btn"
                                        data-bs-toggle="modal" data-bs-target="#editBudgetModal"
                                        data-budget-id="<?= $budget['id'] ?>"
                                        data-budget-name="<?= htmlspecialchars($budget['name']) ?>"
                                        data-user-id="<?= $budget['user_id'] ?>"
                                        data-grant-id="<?= $budget['grant_id'] ?>"
                                        data-department-id="<?= $budget['department_id'] ?>"
                                        data-fy-start="<?= htmlspecialchars($budget['fiscal_year_start']) ?>"
                                        data-fy-end="<?= htmlspecialchars($budget['fiscal_year_end']) ?>"
                                        data-budget-type="<?= htmlspecialchars($budget['budget_type']) ?>"
                                        data-budget-notes="<?= htmlspecialchars($budget['notes'] ?? '') ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>

                                <!-- Delete Button (triggers form submission) -->
                                <form action="budget_setup.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this budget? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="budget_id" value="<?= $budget['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash-alt"></i> Delete
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

<!-- Add Budget Modal -->
<div class="modal fade" id="addBudgetModal" tabindex="-1" aria-labelledby="addBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="budget_setup.php" method="POST" id="addBudgetForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBudgetModalLabel">Add New Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label for="add_name" class="form-label">Budget Name *</label>
                        <input type="text" class="form-control" id="add_name" name="name" required placeholder="e.g., Vickie Smith - WIOA FY24">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_grant_id" class="form-label">Grant *</label>
                            <select class="form-select" id="add_grant_id" name="grant_id" required>
                                <option value="" disabled selected>Select Grant...</option>
                                <?php foreach ($grants as $grant): ?>
                                    <option value="<?= $grant['id'] ?>"><?= htmlspecialchars($grant['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="add_department_id" class="form-label">Department *</label>
                            <select class="form-select" id="add_department_id" name="department_id" required>
                                <option value="" disabled selected>Select Department...</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= ($dept['id'] == $azWorkDeptId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                             <small class="form-text text-muted">Typically 'Arizona@Work' for budget setup.</small>
                        </div>
                    </div>

                     <div class="row">
                         <div class="col-md-6 mb-3">
                            <label for="add_user_id" class="form-label">Assigned User (AZ@Work) *</label>
                            <select class="form-select" id="add_user_id" name="user_id" required>
                                <option value="" disabled selected>Select User...</option>
                                <?php foreach ($azWorkUsers as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($azWorkUsers) && $azWorkDeptId !== null): ?>
                                    <option value="" disabled>No active users found in Arizona@Work</option>
                                <?php elseif ($azWorkDeptId === null): ?>
                                     <option value="" disabled>Could not load users (Dept. not found)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="add_budget_type" class="form-label">Budget Type *</label>
                            <select class="form-select" id="add_budget_type" name="budget_type" required>
                                <option value="Staff" selected>Staff</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_fiscal_year_start" class="form-label">Fiscal Year Start *</label>
                            <input type="date" class="form-control" id="add_fiscal_year_start" name="fiscal_year_start" required>
                             <small class="form-text text-muted">e.g., 2024-07-01</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="add_fiscal_year_end" class="form-label">Fiscal Year End *</label>
                            <input type="date" class="form-control" id="add_fiscal_year_end" name="fiscal_year_end" required>
                             <small class="form-text text-muted">e.g., 2025-06-30</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="add_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="add_notes" name="notes" rows="3"></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Budget Modal -->
<div class="modal fade" id="editBudgetModal" tabindex="-1" aria-labelledby="editBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="budget_setup.php" method="POST" id="editBudgetForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBudgetModalLabel">Edit Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" id="edit_budget_id" name="budget_id" value=""> <!-- Populated by JS -->

                     <div class="mb-3">
                        <label for="edit_name" class="form-label">Budget Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>

                     <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_grant_id" class="form-label">Grant *</label>
                            <select class="form-select" id="edit_grant_id" name="grant_id" required>
                                <?php foreach ($grants as $grant): ?>
                                    <option value="<?= $grant['id'] ?>"><?= htmlspecialchars($grant['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="edit_department_id" class="form-label">Department *</label>
                            <select class="form-select" id="edit_department_id" name="department_id" required>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                     <div class="row">
                         <div class="col-md-6 mb-3">
                            <label for="edit_user_id" class="form-label">Assigned User (AZ@Work) *</label>
                            <select class="form-select" id="edit_user_id" name="user_id" required>
                                <?php foreach ($azWorkUsers as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                                <?php endforeach; ?>
                                 <?php if (empty($azWorkUsers) && $azWorkDeptId !== null): ?>
                                    <option value="" disabled>No active users found in Arizona@Work</option>
                                <?php elseif ($azWorkDeptId === null): ?>
                                     <option value="" disabled>Could not load users (Dept. not found)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="edit_budget_type" class="form-label">Budget Type *</label>
                            <select class="form-select" id="edit_budget_type" name="budget_type" required>
                                <option value="Staff">Staff</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_fiscal_year_start" class="form-label">Fiscal Year Start *</label>
                            <input type="date" class="form-control" id="edit_fiscal_year_start" name="fiscal_year_start" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_fiscal_year_end" class="form-label">Fiscal Year End *</label>
                            <input type="date" class="form-control" id="edit_fiscal_year_end" name="fiscal_year_end" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="edit_notes" name="notes" rows="3"></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php include 'includes/footer.php'; // Include the footer ?>

<!-- Add custom JS for modal population -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editBudgetModal = document.getElementById('editBudgetModal');
    if (editBudgetModal) {
        editBudgetModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget; // Button that triggered the modal

            // Extract info from data-* attributes
            const budgetId = button.getAttribute('data-budget-id');
            const budgetName = button.getAttribute('data-budget-name');
            const userId = button.getAttribute('data-user-id');
            const grantId = button.getAttribute('data-grant-id');
            const departmentId = button.getAttribute('data-department-id');
            const fyStart = button.getAttribute('data-fy-start');
            const fyEnd = button.getAttribute('data-fy-end');
            const budgetType = button.getAttribute('data-budget-type');
            const budgetNotes = button.getAttribute('data-budget-notes');

            // Update the modal's content.
            const modalTitle = editBudgetModal.querySelector('.modal-title');
            const inputBudgetId = editBudgetModal.querySelector('#edit_budget_id');
            const inputName = editBudgetModal.querySelector('#edit_name');
            const selectUser = editBudgetModal.querySelector('#edit_user_id');
            const selectGrant = editBudgetModal.querySelector('#edit_grant_id');
            const selectDept = editBudgetModal.querySelector('#edit_department_id');
            const inputFyStart = editBudgetModal.querySelector('#edit_fiscal_year_start');
            const inputFyEnd = editBudgetModal.querySelector('#edit_fiscal_year_end');
            const selectBudgetType = editBudgetModal.querySelector('#edit_budget_type');
            const textareaNotes = editBudgetModal.querySelector('#edit_notes');

            modalTitle.textContent = 'Edit Budget: ' + budgetName;
            inputBudgetId.value = budgetId;
            inputName.value = budgetName;
            selectUser.value = userId;
            selectGrant.value = grantId;
            selectDept.value = departmentId;
            inputFyStart.value = fyStart;
            inputFyEnd.value = fyEnd;
            selectBudgetType.value = budgetType;
            textareaNotes.value = budgetNotes;
        });
    }

    // Optional: Add client-side validation if desired
});
</script>