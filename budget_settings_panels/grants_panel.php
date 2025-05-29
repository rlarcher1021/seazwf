<?php
// budget_settings_panels/grants_panel.php

// Ensure this panel is included by budget_settings.php which handles main auth and DB connection ($pdo)
// Required files for this panel's functionality:
require_once __DIR__ . '/../includes/db_connect.php'; // Ensure $pdo is available
require_once __DIR__ . '/../includes/auth.php';      // For check_permission, session data
require_once __DIR__ . '/../includes/data_access/grants_dal.php'; // Grant specific functions
require_once __DIR__ . '/../includes/utils.php';     // For CSRF, flash messages, formatting

// --- Permission Check ---
// Double-check permission specifically for grant management within the panel
if (!check_permission(['director', 'administrator'])) {
    echo '<div class="alert alert-danger">Access Denied to Grant Management.</div>';
    return; // Stop rendering this panel
}

// --- Initialize variables for this panel ---
$grants = [];
$panel_error_message = null; // Use panel-specific variable names
$panel_success_message = null;
// Note: Flash messages set by POST handler below will be displayed by the main budget_settings.php

// --- CSRF Token ---
// Assumes generateCsrfToken() is available via utils.php included above
$csrf_token = generateCsrfToken();



// --- Fetch Grant Data for Display ---
// Always fetch fresh data after potential POST actions
try {
    // Fetch only non-deleted grants for the main view
    $grants = getAllGrants($pdo); // Assuming getAllGrants fetches non-deleted by default or add flag if needed
} catch (Exception $e) {
    $panel_error_message = "Error fetching grants: " . $e->getMessage();
    error_log("Error fetching grants for panel: " . $e->getMessage());
}

?>

<!-- Grant Management Panel HTML -->
<div class="card mb-4">
    <div class="card-header">
        <i class="fas fa-landmark me-1"></i>
        Manage Grants
        <button type="button" class="btn btn-primary btn-sm float-end" data-toggle="modal" data-target="#addGrantModalPanel">
             <i class="fas fa-plus me-1"></i> Add Grant
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
            <table class="table table-striped table-bordered table-hover" id="grantsTable">
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
                            <td colspan="6" class="text-center">No active grants found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($grants as $grant): ?>
                             <?php // Skip deleted grants in the main view ?>
                            <?php if (!empty($grant['deleted_at'])) continue; // Check if deleted_at exists and is truthy ?>
                            <tr>
                                <td><?php echo htmlspecialchars($grant['name']); ?></td>
                                <td><?php echo htmlspecialchars($grant['grant_code'] ?? 'N/A'); ?></td>
                                <td class="table-cell-truncate-300" title="<?php echo htmlspecialchars($grant['description'] ?? ''); ?>">
                                    <?php echo nl2br(htmlspecialchars($grant['description'] ?? '')); ?>
                                </td>
                                <td><?php echo htmlspecialchars($grant['start_date'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($grant['end_date'] ?? 'N/A'); ?></td>
                                <td>
                                    <!-- Edit Button -->
                                    <button type="button" class="btn btn-sm btn-warning me-1 edit-grant-btn-panel"
                                            data-toggle="modal" data-target="#editGrantModalPanel"
                                            data-grant-id="<?php echo $grant['id']; ?>"
                                            data-grant-name="<?php echo htmlspecialchars($grant['name'], ENT_QUOTES); ?>"
                                            data-grant-code="<?php echo htmlspecialchars($grant['grant_code'] ?? '', ENT_QUOTES); ?>"
                                            data-grant-description="<?php echo htmlspecialchars($grant['description'] ?? '', ENT_QUOTES); ?>"
                                            data-grant-start="<?php echo htmlspecialchars($grant['start_date'] ?? ''); ?>"
                                            data-grant-end="<?php echo htmlspecialchars($grant['end_date'] ?? ''); ?>"
                                            title="Edit Grant">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <!-- Delete Button (triggers form submission) -->
                                    <form action="budget_settings.php#grants-tab" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete the grant \'<?php echo htmlspecialchars(addslashes($grant['name'])); ?>\'?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="grant_action" value="delete">
                                        <input type="hidden" name="grant_id" value="<?php echo $grant['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Grant">
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


<!-- Add Grant Modal -->
<div class="modal fade" id="addGrantModalPanel" tabindex="-1" aria-labelledby="addGrantModalPanelLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="budget_settings.php#grants-tab" method="POST" id="addGrantFormPanel">
                <div class="modal-header">
                    <h5 class="modal-title" id="addGrantModalPanelLabel">Add New Grant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="grant_action" value="add">

                    <div class="mb-3">
                        <label for="add_grant_name_panel" class="form-label">Grant Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_grant_name_panel" name="name" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label for="add_grant_code_panel" class="form-label">Grant Code</label>
                        <input type="text" class="form-control" id="add_grant_code_panel" name="grant_code" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label for="add_grant_description_panel" class="form-label">Description</label>
                        <textarea class="form-control" id="add_grant_description_panel" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_grant_start_date_panel" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="add_grant_start_date_panel" name="start_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="add_grant_end_date_panel" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="add_grant_end_date_panel" name="end_date">
                        </div>
                    </div>
                     <div id="addGrantErrorPanel" class="text-danger mt-2 d-none"></div>
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
<div class="modal fade" id="editGrantModalPanel" tabindex="-1" aria-labelledby="editGrantModalPanelLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="budget_settings.php#grants-tab" method="POST" id="editGrantFormPanel">
                <div class="modal-header">
                    <h5 class="modal-title" id="editGrantModalPanelLabel">Edit Grant</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button> <?php // BS4 dismiss ?>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="grant_action" value="edit">
                    <input type="hidden" id="edit_grant_id_panel" name="grant_id" value=""> <!-- Populated by JS -->

                    <div class="mb-3">
                        <label for="edit_grant_name_panel" class="form-label">Grant Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_grant_name_panel" name="name" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label for="edit_grant_code_panel" class="form-label">Grant Code</label>
                        <input type="text" class="form-control" id="edit_grant_code_panel" name="grant_code" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label for="edit_grant_description_panel" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_grant_description_panel" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_grant_start_date_panel" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="edit_grant_start_date_panel" name="start_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_grant_end_date_panel" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="edit_grant_end_date_panel" name="end_date">
                        </div>
                    </div>
                     <div id="editGrantErrorPanel" class="text-danger mt-2 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button> <?php // BS4 dismiss ?>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript for populating Edit Grant Modal -->
<?php // JavaScript moved to footer.php to ensure jQuery is loaded first ?>