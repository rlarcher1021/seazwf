<?php
// budget_settings_panels/vendors_panel.php

// Ensure this panel is included by budget_settings.php which handles main auth and DB connection
// However, we might need specific DAL functions here.
// db_connect.php and auth.php are already included by the parent budget_settings.php
require_once __DIR__ . '/../data_access/vendor_data.php';
require_once __DIR__ . '/../includes/utils.php'; // For formatTimestamp, generateCsrfToken etc.

// Double-check permission - belt and suspenders approach
if (!check_permission(['director', 'administrator'])) {
    echo '<div class="alert alert-danger">Access Denied.</div>';
    return; // Stop rendering this panel
}

// Fetch all vendors for the table
$vendors = getAllVendorsForAdmin($pdo); // Assumes $pdo is available from budget_settings.php context
$csrf_token = generateCsrfToken(); // Generate CSRF token

?>

<div class="card mb-4">
    <div class="card-header">
        <i class="fas fa-store me-1"></i>
        Manage Vendors
        <button type="button" class="btn btn-primary btn-sm float-end" data-toggle="modal" data-target="#addVendorModal">
            <i class="fas fa-plus me-1"></i> Add Vendor
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover" id="vendorsTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Client Name Required?</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vendors)): ?>
                        <tr>
                            <td colspan="4" class="text-center">No vendors found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vendors as $vendor): ?>
                            <tr class="<?php echo $vendor['deleted_at'] ? 'table-secondary text-muted' : ''; ?>" id="vendor-row-<?php echo $vendor['id']; ?>">
                                <td><?php echo htmlspecialchars($vendor['name']); ?></td>
                                <td><?php echo $vendor['client_name_required'] ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <?php if ($vendor['deleted_at']): ?>
                                        <span class="badge bg-danger">Deleted</span>
                                        <small>(<?php echo formatTimestamp($vendor['deleted_at'], 'Y-m-d'); ?>)</small>
                                    <?php elseif ($vendor['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$vendor['deleted_at']): ?>
                                        <button type="button" class="btn btn-warning btn-sm edit-vendor-btn"
                                                data-toggle="modal" data-target="#editVendorModal"
                                                data-vendor-id="<?php echo $vendor['id']; ?>"
                                                data-vendor-name="<?php echo htmlspecialchars($vendor['name'], ENT_QUOTES); ?>"
                                                data-client-required="<?php echo $vendor['client_name_required']; ?>"
                                                data-is-active="<?php echo $vendor['is_active']; ?>"
                                                title="Edit Vendor">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm delete-vendor-btn"
                                                data-vendor-id="<?php echo $vendor['id']; ?>"
                                                data-vendor-name="<?php echo htmlspecialchars($vendor['name'], ENT_QUOTES); ?>"
                                                title="Delete Vendor">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    <?php else: ?>
                                         <button type="button" class="btn btn-success btn-sm restore-vendor-btn"
                                                data-vendor-id="<?php echo $vendor['id']; ?>"
                                                data-vendor-name="<?php echo htmlspecialchars($vendor['name'], ENT_QUOTES); ?>"
                                                title="Restore Vendor">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Vendor Modal -->
<div class="modal fade" id="addVendorModal" tabindex="-1" aria-labelledby="addVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="addVendorForm" method="POST" action="ajax_handlers/vendor_handler.php"> <?php // Action points to a future AJAX handler ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="addVendorModalLabel">Add New Vendor</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_vendor">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label for="add_vendor_name" class="form-label">Vendor Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_vendor_name" name="vendor_name" required maxlength="255">
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="add_client_name_required" name="client_name_required" value="1">
                        <label class="form-check-label" for="add_client_name_required">Client Name Required?</label>
                        <small class="form-text text-muted d-block">Check this if a client's name must be entered when selecting this vendor for an allocation.</small>
                    </div>
                     <div id="addVendorError" class="text-danger mt-2" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Add Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Vendor Modal -->
<div class="modal fade" id="editVendorModal" tabindex="-1" aria-labelledby="editVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editVendorForm" method="POST" action="ajax_handlers/vendor_handler.php"> <?php // Action points to a future AJAX handler ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="editVendorModalLabel">Edit Vendor</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_vendor">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" id="edit_vendor_id" name="vendor_id">

                    <div class="mb-3">
                        <label for="edit_vendor_name" class="form-label">Vendor Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_vendor_name" name="vendor_name" required maxlength="255">
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_client_name_required" name="client_name_required" value="1">
                        <label class="form-check-label" for="edit_client_name_required">Client Name Required?</label>
                         <small class="form-text text-muted d-block">Check this if a client's name must be entered when selecting this vendor for an allocation.</small>
                    </div>

                     <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit_is_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">Is Active?</label>
                         <small class="form-text text-muted d-block">Inactive vendors cannot be selected for new allocations.</small>
                    </div>
                    <div id="editVendorError" class="text-danger mt-2" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php // JavaScript for Vendor Panel AJAX operations ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const vendorTableBody = document.querySelector('#vendorsTable tbody');
    const addVendorModalEl = document.getElementById('addVendorModal');
    const editVendorModalEl = document.getElementById('editVendorModal');
    const addVendorForm = document.getElementById('addVendorForm');
    const editVendorForm = document.getElementById('editVendorForm');
    const addVendorErrorDiv = document.getElementById('addVendorError');
    const editVendorErrorDiv = document.getElementById('editVendorError');
    const csrfToken = '<?php echo $csrf_token; ?>'; // Get CSRF token from PHP

    // --- Helper Functions ---
    const showModalError = (modalErrorDiv, message) => {
        modalErrorDiv.textContent = message;
        modalErrorDiv.style.display = 'block';
    };
    const clearModalError = (modalErrorDiv) => {
        modalErrorDiv.textContent = '';
        modalErrorDiv.style.display = 'none';
    };
    const escapeHTML = (str) => {
        if (str === null || typeof str === 'undefined') return '';
        return str.toString().replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>').replace(/"/g, '"').replace(/'/g, '&#039;');
    };
    const formatDate = (dateStr) => { // Basic YYYY-MM-DD formatter
        if (!dateStr) return '';
        try {
            const date = new Date(dateStr);
            // Adjust for potential timezone issues if just displaying date part
            const year = date.getFullYear();
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const day = date.getDate().toString().padStart(2, '0');
            return `${year}-${month}-${day}`;
        } catch (e) {
            return 'Invalid Date';
        }
    };

    // --- Render/Update Table Row ---
    const renderVendorRow = (vendor) => {
        const isDeleted = vendor.deleted_at !== null;
        const clientReqText = vendor.client_name_required == '1' ? 'Yes' : 'No';
        let statusHtml = '';
        if (isDeleted) {
            statusHtml = `<span class="badge bg-danger">Deleted</span> <small>(${formatDate(vendor.deleted_at)})</small>`;
        } else if (vendor.is_active == '1') {
            statusHtml = `<span class="badge bg-success">Active</span>`;
        } else {
            statusHtml = `<span class="badge bg-warning text-dark">Inactive</span>`;
        }

        let actionsHtml = '';
        if (!isDeleted) {
            actionsHtml = `
                <button type="button" class="btn btn-warning btn-sm edit-vendor-btn"
                        data-toggle="modal" data-target="#editVendorModal"
                        data-vendor-id="${vendor.id}"
                        data-vendor-name="${escapeHTML(vendor.name)}"
                        data-client-required="${vendor.client_name_required}"
                        data-is-active="${vendor.is_active}"
                        title="Edit Vendor">
                    <i class="fas fa-edit"></i>
                </button>
                <button type="button" class="btn btn-danger btn-sm delete-vendor-btn"
                        data-vendor-id="${vendor.id}"
                        data-vendor-name="${escapeHTML(vendor.name)}"
                        title="Delete Vendor">
                    <i class="fas fa-trash-alt"></i>
                </button>
            `;
        } else {
            actionsHtml = `
                <button type="button" class="btn btn-success btn-sm restore-vendor-btn"
                        data-vendor-id="${vendor.id}"
                        data-vendor-name="${escapeHTML(vendor.name)}"
                        title="Restore Vendor">
                    <i class="fas fa-undo"></i>
                </button>
            `;
        }

        return `
            <tr class="${isDeleted ? 'table-secondary text-muted' : ''}" id="vendor-row-${vendor.id}">
                <td>${escapeHTML(vendor.name)}</td>
                <td>${clientReqText}</td>
                <td>${statusHtml}</td>
                <td>${actionsHtml}</td>
            </tr>
        `;
    };

    const updateVendorTableRow = (vendor) => {
        const row = document.getElementById(`vendor-row-${vendor.id}`);
        if (row) {
            row.outerHTML = renderVendorRow(vendor); // Replace entire row
            attachActionListenersForRow(document.getElementById(`vendor-row-${vendor.id}`)); // Re-attach listeners
        }
    };

    const addVendorTableRow = (vendor) => {
         // Remove 'No vendors found' row if present
        const noVendorsRow = vendorTableBody.querySelector('td[colspan="4"]');
        if (noVendorsRow) noVendorsRow.closest('tr').remove();

        vendorTableBody.insertAdjacentHTML('beforeend', renderVendorRow(vendor));
        attachActionListenersForRow(document.getElementById(`vendor-row-${vendor.id}`)); // Attach listeners
    };

    // --- AJAX Form Submission Handler ---
    const submitVendorForm = async (form, errorDiv) => {
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        clearModalError(errorDiv);
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        try {
            const response = await fetch('ajax_handlers/vendor_handler.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                // Use Bootstrap 4 jQuery method to hide modal
                $(form.closest('.modal')).modal('hide');

                // Update table
                if (formData.get('action') === 'add_vendor' && data.vendor) {
                    addVendorTableRow(data.vendor);
                } else if (formData.get('action') === 'update_vendor' && data.vendor) {
                    updateVendorTableRow(data.vendor);
                }
                // Optionally show a success toast/alert here instead of page reload
                alert(data.message || 'Operation successful!'); // Simple alert for now
                // Consider a more robust notification system later
                // showFlashMessageJS(data.message || 'Operation successful!', 'success');

            } else {
                showModalError(errorDiv, data.message || 'An unknown error occurred.');
            }

        } catch (error) {
            console.error('Vendor form submission error:', error);
            showModalError(errorDiv, `Submission failed: ${error.message}. Please try again.`);
        } finally {
            submitButton.disabled = false;
             // Restore button text based on action
            if (formData.get('action') === 'add_vendor') {
                 submitButton.innerHTML = 'Add Vendor';
            } else {
                 submitButton.innerHTML = 'Save Changes';
            }
        }
    };

    // --- AJAX Action Handler (Delete/Restore) ---
     const handleVendorAction = async (button, action) => {
        const vendorId = button.dataset.vendorId;
        const vendorName = button.dataset.vendorName;
        const confirmMessage = action === 'delete_vendor'
            ? `Are you sure you want to delete the vendor "${vendorName}"? This action cannot be easily undone.`
            : `Are you sure you want to restore the vendor "${vendorName}"?`;

        if (!vendorId || !confirm(confirmMessage)) {
            return;
        }

        button.disabled = true;
        const originalIcon = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        const formData = new FormData();
        formData.append('action', action);
        formData.append('vendor_id', vendorId);
        formData.append('csrf_token', csrfToken);

         try {
            const response = await fetch('ajax_handlers/vendor_handler.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                 alert(data.message || 'Action successful!'); // Simple alert
                 // Update the specific row
                 if (data.vendor) { // If handler returns updated vendor data
                     updateVendorTableRow(data.vendor);
                 } else { // Fallback: just visually change the row for delete
                     const row = document.getElementById(`vendor-row-${vendorId}`);
                     if (row) {
                         if (action === 'delete_vendor') {
                             row.classList.add('table-secondary', 'text-muted');
                             // Ideally, replace buttons, but updateVendorTableRow is better
                             row.querySelector('td:last-child').innerHTML = 'Deleted (Refresh to restore)';
                         } else { // Restore might not return data, just refresh row state visually
                              row.classList.remove('table-secondary', 'text-muted');
                              // Need to re-render buttons properly - updateVendorTableRow is preferred
                              row.querySelector('td:last-child').innerHTML = 'Restored (Buttons appear on refresh)';
                         }
                     }
                 }
            } else {
                alert(`Error: ${data.message || 'An unknown error occurred.'}`);
                button.disabled = false; // Re-enable on error
                button.innerHTML = originalIcon;
            }

        } catch (error) {
            console.error(`Vendor ${action} error:`, error);
            alert(`Action failed: ${error.message}. Please try again.`);
            button.disabled = false; // Re-enable on error
            button.innerHTML = originalIcon;
        }
    };


    // --- Event Listeners ---

    // Form Submissions
    if (addVendorForm) {
        addVendorForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitVendorForm(addVendorForm, addVendorErrorDiv);
        });
    }
    if (editVendorForm) {
        editVendorForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitVendorForm(editVendorForm, editVendorErrorDiv);
        });
    }

    // Modal Population & Clearing (Keep existing logic)
    if (editVendorModalEl) {
        editVendorModalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const vendorId = button.dataset.vendorId;
            const vendorName = button.dataset.vendorName;
            const clientRequired = button.dataset.clientRequired;
            const isActive = button.dataset.isActive;

            const modalTitle = editVendorModalEl.querySelector('.modal-title');
            const vendorIdInput = editVendorModalEl.querySelector('#edit_vendor_id');
            const vendorNameInput = editVendorModalEl.querySelector('#edit_vendor_name');
            const clientRequiredInput = editVendorModalEl.querySelector('#edit_client_name_required');
            const isActiveInput = editVendorModalEl.querySelector('#edit_is_active');

            modalTitle.textContent = 'Edit Vendor: ' + vendorName;
            vendorIdInput.value = vendorId;
            vendorNameInput.value = vendorName;
            clientRequiredInput.checked = (clientRequired == '1');
            isActiveInput.checked = (isActive == '1');
            clearModalError(editVendorErrorDiv);
        });
    }
    if (addVendorModalEl) {
        addVendorModalEl.addEventListener('show.bs.modal', function(event) {
            if(addVendorForm) addVendorForm.reset();
            clearModalError(addVendorErrorDiv);
        });
    }

    // Attach listeners to initial Delete/Restore buttons
    const attachActionListenersForRow = (rowElement) => {
        const deleteBtn = rowElement.querySelector('.delete-vendor-btn');
        const restoreBtn = rowElement.querySelector('.restore-vendor-btn');

        if (deleteBtn) {
            deleteBtn.addEventListener('click', function() { handleVendorAction(this, 'delete_vendor'); });
        }
        if (restoreBtn) {
            restoreBtn.addEventListener('click', function() { handleVendorAction(this, 'restore_vendor'); });
        }
    };

    // Initial attachment for existing rows
    if (vendorTableBody) {
        vendorTableBody.querySelectorAll('tr').forEach(row => {
            attachActionListenersForRow(row);
        });
    }

});
</script>