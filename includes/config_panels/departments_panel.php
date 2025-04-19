<?php
/**
 * File: departments_panel.php
 * Path: /includes/config_panels/departments_panel.php
 * Created: 2025-04-19
 * Description: Configuration panel for managing Departments (Admin only).
 *              Handles adding, listing, and deleting departments.
 *              Included by configurations.php when the 'departments' tab is active.
 */

// Ensure this script is being included by configurations.php which handles auth and setup
if (!isset($pdo) || !isset($_SESSION['active_role']) || $_SESSION['active_role'] !== 'administrator') {
    // Prevent direct access or access without proper setup
    die('Access Denied: This panel cannot be accessed directly.');
}

// Include necessary data access functions
require_once __DIR__ . '/../data_access/department_data.php'; // Department functions
require_once __DIR__ . '/../utils.php'; // For sanitizeInput

// --- Panel Logic (Processing POST requests) ---
$panel_flash_message = null;
$panel_flash_type = 'info'; // Default type

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Invalid security token. Please try again.";
        $_SESSION['flash_type'] = 'error';
        // No redirect here, let configurations.php handle it
    } else {
        // --- Add Department Action ---
        if ($_POST['action'] === 'add_department' && isset($_POST['department_name'])) {
            $dept_name = trim($_POST['department_name']);
            if (!empty($dept_name)) {
                if (addDepartment($pdo, $dept_name)) {
                    $_SESSION['flash_message'] = "Department '" . htmlspecialchars($dept_name) . "' added successfully.";
                    $_SESSION['flash_type'] = 'success';
                } else {
                    // Check if it failed because the name exists (optional check, addDepartment handles slug uniqueness)
                    $stmtCheck = $pdo->prepare("SELECT id FROM departments WHERE LOWER(name) = LOWER(?)");
                    $stmtCheck->execute([$dept_name]);
                    if ($stmtCheck->fetch()) {
                         $_SESSION['flash_message'] = "Error: A department with a similar name might already exist, or a database error occurred during slug generation.";
                         $_SESSION['flash_type'] = 'error';
                    } else {
                         $_SESSION['flash_message'] = "Error adding department. Please check logs.";
                         $_SESSION['flash_type'] = 'error';
                    }
                }
            } else {
                $_SESSION['flash_message'] = "Department name cannot be empty.";
                $_SESSION['flash_type'] = 'warning';
            }
        }
        // --- Edit Department Action ---
        elseif ($_POST['action'] === 'edit_department' && isset($_POST['edit_department_id'], $_POST['edit_department_name'])) {
            $dept_id = filter_input(INPUT_POST, 'edit_department_id', FILTER_VALIDATE_INT);
            $dept_name = trim($_POST['edit_department_name']);

            if ($dept_id && !empty($dept_name)) {
                // First, ensure the department has a slug (for older entries)
                $slugResult = ensureDepartmentSlug($pdo, $dept_id);
                if ($slugResult === false) {
                     $_SESSION['flash_message'] = "Error ensuring slug for department ID " . $dept_id . ". Update aborted.";
                     $_SESSION['flash_type'] = 'error';
                } else {
                    // Now update the name
                    if (updateDepartment($pdo, $dept_id, $dept_name)) {
                        $_SESSION['flash_message'] = "Department '" . htmlspecialchars($dept_name) . "' updated successfully.";
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = "Error updating department '" . htmlspecialchars($dept_name) . "'. Name might already exist or DB error.";
                        $_SESSION['flash_type'] = 'error';
                    }
                }
            } else {
                $_SESSION['flash_message'] = "Invalid data provided for editing department.";
                $_SESSION['flash_type'] = 'warning';
            }
        }
        // --- Delete Department Action ---
        elseif ($_POST['action'] === 'delete_department' && isset($_POST['delete_department_id'])) {
            // Note: The actual deletion confirmation happens via modal + JS now.
            // This block processes the final confirmed deletion request.
            $dept_id = filter_input(INPUT_POST, 'delete_department_id', FILTER_VALIDATE_INT);
            if ($dept_id) {
                $dept_details = getDepartmentById($pdo, $dept_id);
                $dept_name_for_msg = $dept_details ? htmlspecialchars($dept_details['name']) : 'ID ' . $dept_id;

                if (deleteDepartment($pdo, $dept_id)) {
                    $_SESSION['flash_message'] = "Department '" . $dept_name_for_msg . "' deleted successfully.";
                    $_SESSION['flash_type'] = 'success';
                } else {
                    if (isDepartmentInUse($pdo, $dept_id)) {
                        $_SESSION['flash_message'] = "Error: Cannot delete department '" . $dept_name_for_msg . "' because it is currently assigned to one or more users.";
                        $_SESSION['flash_type'] = 'error';
                    } else {
                        $_SESSION['flash_message'] = "Error deleting department '" . $dept_name_for_msg . "'. It might not exist or a database error occurred.";
                        $_SESSION['flash_type'] = 'error';
                    }
                }
            } else {
                $_SESSION['flash_message'] = "Invalid department ID specified for deletion.";
                $_SESSION['flash_type'] = 'error';
            }
        }
        // Let configurations.php handle the redirect after processing POST
    }
}

// --- Data Fetching for Display (GET request or after POST processing) ---
$departments = getAllDepartments($pdo);
if ($departments === false) { // Check if the function returned an error indicator
    $panel_flash_message = "Error retrieving department list from the database.";
    $panel_flash_type = 'error';
    $departments = []; // Ensure it's an array for the loop
}

// --- Panel HTML Output (Displayed on GET requests) ---
?>

<div class="config-panel departments-panel">
    <h2>Manage Departments</h2>
    <p>Add, view, or remove global departments. Departments can be assigned to users.</p>

    <?php if ($panel_flash_message): ?>
        <div class="message-area message-<?php echo htmlspecialchars($panel_flash_type); ?>"><?php echo $panel_flash_message; ?></div>
    <?php endif; ?>

    <!-- Add Department Form -->
    <div class="form-container add-department-form">
        <h3>Add New Department</h3>
        <form action="configurations.php?tab=departments" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_department">
            <input type="hidden" name="submitted_tab" value="departments"> <!-- Inform controller which tab processed -->

            <div class="form-group">
                <label for="department_name">Department Name:</label>
                <input type="text" id="department_name" name="department_name" required maxlength="255">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Department
                </button>
            </div>
        </form>
    </div>

    <!-- List Departments Table -->
    <div class="table-container department-list">
        <h3>Existing Departments</h3>
        <?php if (empty($departments)): ?>
            <p>No departments found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dept['id']); ?></td>
                            <td><?php echo htmlspecialchars($dept['name']); ?></td>
                            <td><code><?php echo htmlspecialchars($dept['slug'] ?? 'N/A'); ?></code></td>
                            <td><?php echo htmlspecialchars(isset($dept['created_at']) ? date('Y-m-d H:i', strtotime($dept['created_at'])) : 'N/A'); ?></td>
                            <td class="action-buttons">
                                <!-- Edit Button (Bootstrap 4 attributes) -->
                                <button type="button" class="btn btn-outline btn-sm edit-button"
                                        data-toggle="modal" data-target="#editDepartmentModal"
                                        data-dept-id="<?php echo htmlspecialchars($dept['id']); ?>"
                                        data-dept-name="<?php echo htmlspecialchars($dept['name']); ?>"
                                        data-dept-slug="<?php echo htmlspecialchars($dept['slug'] ?? ''); ?>"
                                        title="Edit Department Name">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <!-- Delete Button (triggers modal - Bootstrap 4 attributes) -->
                                <button type="button" class="btn btn-outline btn-sm delete-button"
                                        data-toggle="modal" data-target="#deleteConfirmModal"
                                        data-dept-id="<?php echo htmlspecialchars($dept['id']); ?>"
                                        data-dept-name="<?php echo htmlspecialchars($dept['name']); ?>"
                                        title="Delete Department">
                                    <i class="fas fa-trash"></i>
                                </button>

                                <!-- Hidden Delete Form (submitted by modal JS) -->
                                <form id="deleteForm-<?php echo htmlspecialchars($dept['id']); ?>" action="configurations.php?tab=departments" method="POST" style="display: none;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_department">
                                    <input type="hidden" name="submitted_tab" value="departments">
                                    <input type="hidden" name="delete_department_id" value="<?php echo htmlspecialchars($dept['id']); ?>">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div> <!-- End .departments-panel -->

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-labelledby="editDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="configurations.php?tab=departments" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="edit_department">
                <input type="hidden" name="submitted_tab" value="departments">
                <input type="hidden" id="edit_department_id" name="edit_department_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="editDepartmentModalLabel">Edit Department</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"> <!-- Fixed: data-dismiss -->
                         <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_department_name" class="form-label">Department Name:</label>
                        <input type="text" class="form-control" id="edit_department_name" name="edit_department_name" required maxlength="255">
                    </div>
                     <div class="mb-3">
                        <label for="edit_department_slug_display" class="form-label">Slug (Read-only):</label>
                        <input type="text" class="form-control" id="edit_department_slug_display" name="edit_department_slug_display" readonly disabled>
                        <small class="form-text text-muted">The slug is a unique identifier and cannot be changed after creation.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button> <!-- Fixed: data-dismiss -->
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                 <button type="button" class="close" data-dismiss="modal" aria-label="Close"> <!-- Fixed: data-dismiss -->
                     <span aria-hidden="true">&times;</span>
                 </button>
            </div>
            <div class="modal-body">
                <p>Are you absolutely sure you want to delete the department: <strong id="deleteDeptName"></strong>?</p>
                <p>This action cannot be undone. If this department is in use by users, deletion will fail.</p>
                <p class="text-danger">To confirm, please type the word <strong>DELETE</strong> (case-sensitive) in the box below:</p>
                <div class="mb-3">
                    <label for="deleteConfirmInput" class="form-label visually-hidden">Type DELETE to confirm</label>
                    <input type="text" class="form-control" id="deleteConfirmInput" name="deleteConfirmInput" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button> <!-- Fixed: data-dismiss -->
                <button type="button" class="btn btn-danger" id="confirmDeleteButton" disabled>Confirm Delete</button>
                <input type="hidden" id="deleteDeptIdInput" value=""> <!-- To store the ID for the final submit -->
            </div>
        </div>
    </div>
</div>

<?php
// Add JavaScript for Modals at the end of the panel or in a separate JS file included later
// Ensure Bootstrap's JS is loaded for this to work
ob_start(); // Start output buffering to capture JS
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Edit Modal Logic ---
    // --- Edit Modal Logic (Using jQuery for Bootstrap 4 events) ---
    var editModal = $('#editDepartmentModal'); // Use jQuery selector
    if (editModal.length) { // Check if element exists
        editModal.on('show.bs.modal', function (event) { // Use jQuery event binding
            var button = $(event.relatedTarget); // Button that triggered the modal (jQuery object)
            var deptId = button.data('dept-id'); // Use jQuery .data()
            var deptName = button.data('dept-name'); // Use jQuery .data()
            var deptSlug = button.data('dept-slug'); // Use jQuery .data()

            // Update the modal's content using jQuery methods consistently.
            var modal = $(this); // Reference the modal itself
            modal.find('.modal-title').text('Edit Department: ' + deptName); // Use jQuery .find() and .text()
            modal.find('#edit_department_id').val(deptId); // Use jQuery .find() and .val()
            modal.find('#edit_department_name').val(deptName); // Use jQuery .find() and .val()
            modal.find('#edit_department_slug_display').val(deptSlug ? deptSlug : '(Will be generated on save)'); // Use jQuery .find() and .val()
        });
    }

    // --- Delete Modal Logic (Using jQuery for Bootstrap 4 events) ---
    var deleteModal = $('#deleteConfirmModal');
    var confirmInput = $('#deleteConfirmInput');
    var confirmButton = $('#confirmDeleteButton');
    var deleteDeptIdInput = $('#deleteDeptIdInput'); // Hidden input to store ID

    if (deleteModal.length && confirmInput.length && confirmButton.length && deleteDeptIdInput.length) {
        deleteModal.on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var deptId = button.data('dept-id');
            var deptName = button.data('dept-name');

            // Update modal content using jQuery
            $(this).find('#deleteDeptName').text(deptName);
            deleteDeptIdInput.val(deptId); // Store the ID in the hidden input

            // Reset state on modal show
            confirmInput.val('');
            confirmButton.prop('disabled', true); // Use jQuery prop() for disabled property
        });

        // Enable/disable confirm button based on input
        confirmInput.on('input', function () {
            if ($(this).val() === 'DELETE') {
                confirmButton.prop('disabled', false);
            } else {
                confirmButton.prop('disabled', true);
            }
        });

        // Handle the final confirmed delete action
        confirmButton.on('click', function () {
            var deptId = deleteDeptIdInput.val();
            var deleteForm = $('#deleteForm-' + deptId); // Use jQuery selector
            if (deleteForm.length) {
                deleteForm.submit(); // Submit the hidden form for this department
            } else {
                console.error('Could not find delete form for department ID:', deptId);
                alert('An error occurred. Could not submit deletion request.');
            }
        });
    }
});
</script>
<?php
// Append the captured JS to the footer scripts or output directly
// If using a template system, register this script block
$page_specific_js = ob_get_clean();
// Assuming a global variable $footer_scripts exists or output directly
// For simplicity here, we'll assume it gets handled by the main layout file
// If not, you might need to echo $page_specific_js; here or pass it up.
// Storing it for potential inclusion in footer.php:
if (!isset($GLOBALS['footer_scripts'])) {
    $GLOBALS['footer_scripts'] = '';
}
$GLOBALS['footer_scripts'] .= $page_specific_js;
?>