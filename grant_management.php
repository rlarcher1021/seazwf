<?php
require_once 'includes/auth.php'; // Handles session start and authentication
require_once 'includes/db_connect.php';
require_once 'includes/data_access/grants_dal.php';
require_once 'includes/utils.php'; // For CSRF functions, etc.

// 1. Permission Check: Ensure user is a Director
if (!isDirector()) { // Assuming isDirector() is defined and checks $_SESSION['active_role'] now
    $_SESSION['error_message'] = "Access denied. You must be a Director to manage grants.";
    header("Location: dashboard.php?reason=access_denied"); // Redirect to a safe page
    exit;
}

// Initialize variables
$pageTitle = "Grant Management";
$grants = [];
$error_message = $_SESSION['error_message'] ?? null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['error_message'], $_SESSION['success_message']); // Clear flash messages

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


// 4. Fetch Data (GET request part)
try {
    $grants = getAllGrants($pdo);
} catch (Exception $e) {
    $error_message = "Error fetching grants: " . $e->getMessage();
    // Log the detailed error message for debugging
    error_log("Error in grant_management.php: " . $e->getMessage());
}

// --- Placeholder for POST request handling (Add/Edit/Delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "CSRF token validation failed. Please try again.";
    } else {
        // 2. Determine action
        $action = $_POST['action'] ?? '';
        $grant_id = filter_input(INPUT_POST, 'grant_id', FILTER_VALIDATE_INT);

        // 3. Sanitize and validate common inputs
        // Use FILTER_DEFAULT for potentially empty strings, handle NULL later if needed
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) ?? '');
        $grant_code = trim(filter_input(INPUT_POST, 'grant_code', FILTER_SANITIZE_STRING) ?? '');
        $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING) ?? '');
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING); // Basic sanitize, further validation if needed
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);

        // Convert empty strings to null for optional fields where DB expects NULL
        $grant_code = $grant_code === '' ? null : $grant_code;
        $description = $description === '' ? null : $description;
        $start_date = $start_date === '' ? null : $start_date;
        $end_date = $end_date === '' ? null : $end_date;

        // Basic validation for dates (can be enhanced)
        $date_error = false;
        if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $_SESSION['error_message'] = "Invalid Start Date format. Please use YYYY-MM-DD.";
            $date_error = true;
        }
        if ($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            $_SESSION['error_message'] = "Invalid End Date format. Please use YYYY-MM-DD.";
            $date_error = true;
        }
        if ($start_date && $end_date && $start_date > $end_date) {
             $_SESSION['error_message'] = "End Date cannot be earlier than Start Date.";
             $date_error = true;
        }

        if (!$date_error) {
            try {
                // 4. Call appropriate DAL function based on action
                if ($action === 'add') {
                    if (empty($name)) {
                        $_SESSION['error_message'] = "Grant Name is required.";
                    } else {
                        $result = addGrant($pdo, $name, $grant_code, $description, $start_date, $end_date);
                        if ($result) {
                            $_SESSION['success_message'] = "Grant added successfully.";
                        } else {
                            $_SESSION['error_message'] = "Error adding grant. Check logs for details.";
                        }
                    }
                } elseif ($action === 'edit') {
                    if (!$grant_id) {
                         $_SESSION['error_message'] = "Invalid Grant ID for edit.";
                    } elseif (empty($name)) {
                        $_SESSION['error_message'] = "Grant Name is required.";
                    } else {
                        $result = updateGrant($pdo, $grant_id, $name, $grant_code, $description, $start_date, $end_date);
                        if ($result) {
                            $_SESSION['success_message'] = "Grant updated successfully.";
                        } else {
                            $_SESSION['error_message'] = "Error updating grant or no changes made. Check logs for details.";
                        }
                    }
                } elseif ($action === 'delete') {
                    if (!$grant_id) {
                         $_SESSION['error_message'] = "Invalid Grant ID for delete.";
                    } else {
                        $result = softDeleteGrant($pdo, $grant_id);
                        if ($result) {
                            $_SESSION['success_message'] = "Grant deleted successfully.";
                        } else {
                            $_SESSION['error_message'] = "Error deleting grant. Check logs for details.";
                        }
                    }
                } else {
                    $_SESSION['error_message'] = "Invalid action specified.";
                }
            } catch (Exception $e) {
                 error_log("Error processing grant action '{$action}': " . $e->getMessage());
                 $_SESSION['error_message'] = "A system error occurred. Please try again later.";
            }
        } // end date_error check
    }

    // 6. Redirect back to the grant management page
    header("Location: grant_management.php");
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

    <!-- Add Grant Button -->
    <div class="mb-3">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGrantModal">
            <i class="fas fa-plus"></i> Add New Grant
        </button>
    </div>

    <!-- Grants Table -->
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Grant Code</th>
                    <th>Description</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr>
                        <td colspan="6" class="text-center">No grants found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($grants as $grant): ?>
                        <tr>
                            <td><?= htmlspecialchars($grant['name']) ?></td>
                            <td><?= htmlspecialchars($grant['grant_code'] ?? 'N/A') ?></td>
                            <td><?= nl2br(htmlspecialchars($grant['description'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($grant['start_date'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($grant['end_date'] ?? 'N/A') ?></td>
                            <td>
                                <!-- Edit Button -->
                                <button type="button" class="btn btn-sm btn-warning me-1 edit-grant-btn"
                                        data-bs-toggle="modal" data-bs-target="#editGrantModal"
                                        data-grant-id="<?= $grant['id'] ?>"
                                        data-grant-name="<?= htmlspecialchars($grant['name']) ?>"
                                        data-grant-code="<?= htmlspecialchars($grant['grant_code'] ?? '') ?>"
                                        data-grant-description="<?= htmlspecialchars($grant['description'] ?? '') ?>"
                                        data-grant-start="<?= htmlspecialchars($grant['start_date'] ?? '') ?>"
                                        data-grant-end="<?= htmlspecialchars($grant['end_date'] ?? '') ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>

                                <!-- Delete Button (triggers form submission) -->
                                <form action="grant_management.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this grant?');">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="grant_id" value="<?= $grant['id'] ?>">
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

<!-- Add Grant Modal -->
<div class="modal fade" id="addGrantModal" tabindex="-1" aria-labelledby="addGrantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="grant_management.php" method="POST" id="addGrantForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addGrantModalLabel">Add New Grant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label for="add_name" class="form-label">Grant Name *</label>
                        <input type="text" class="form-control" id="add_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="add_grant_code" class="form-label">Grant Code</label>
                        <input type="text" class="form-control" id="add_grant_code" name="grant_code">
                    </div>
                    <div class="mb-3">
                        <label for="add_description" class="form-label">Description</label>
                        <textarea class="form-control" id="add_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="add_start_date" name="start_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="add_end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="add_end_date" name="end_date">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Grant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Grant Modal -->
<div class="modal fade" id="editGrantModal" tabindex="-1" aria-labelledby="editGrantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="grant_management.php" method="POST" id="editGrantForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="editGrantModalLabel">Edit Grant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" id="edit_grant_id" name="grant_id" value=""> <!-- Populated by JS -->

                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Grant Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_grant_code" class="form-label">Grant Code</label>
                        <input type="text" class="form-control" id="edit_grant_code" name="grant_code">
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="edit_start_date" name="start_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date">
                        </div>
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

<!-- Add custom JS for modal population if needed -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editGrantModal = document.getElementById('editGrantModal');
    if (editGrantModal) {
        editGrantModal.addEventListener('show.bs.modal', function (event) {
            // Button that triggered the modal
            const button = event.relatedTarget;

            // Extract info from data-* attributes
            const grantId = button.getAttribute('data-grant-id');
            const grantName = button.getAttribute('data-grant-name');
            const grantCode = button.getAttribute('data-grant-code');
            const grantDescription = button.getAttribute('data-grant-description');
            const grantStart = button.getAttribute('data-grant-start');
            const grantEnd = button.getAttribute('data-grant-end');

            // Update the modal's content.
            const modalTitle = editGrantModal.querySelector('.modal-title');
            const modalBodyInputId = editGrantModal.querySelector('#edit_grant_id');
            const modalBodyInputName = editGrantModal.querySelector('#edit_name');
            const modalBodyInputCode = editGrantModal.querySelector('#edit_grant_code');
            const modalBodyInputDesc = editGrantModal.querySelector('#edit_description');
            const modalBodyInputStart = editGrantModal.querySelector('#edit_start_date');
            const modalBodyInputEnd = editGrantModal.querySelector('#edit_end_date');

            modalTitle.textContent = 'Edit Grant: ' + grantName; // Use fetched name for title
            modalBodyInputId.value = grantId;
            modalBodyInputName.value = grantName;
            modalBodyInputCode.value = grantCode;
            modalBodyInputDesc.value = grantDescription;
            modalBodyInputStart.value = grantStart;
            modalBodyInputEnd.value = grantEnd;
        });
    }

    // Optional: Add client-side validation if desired
    // const addForm = document.getElementById('addGrantForm');
    // addForm.addEventListener('submit', function(event) { ... });
    // const editForm = document.getElementById('editGrantForm');
    // editForm.addEventListener('submit', function(event) { ... });
});
</script>