<?php
// Assuming this modal is included by a page where $pdo is available
// and necessary DAL functions are loaded.
require_once __DIR__ . '/../../data_access/vendor_data.php'; // Need getActiveVendors

$active_vendors = [];
if (isset($pdo)) { // Check if $pdo is available in this scope
    $active_vendors = getActiveVendors($pdo);
} else {
    // Log error or handle case where DB connection isn't available
    error_log("PDO object not available in add_allocation_modal.php");
}
?>
<!-- Add Allocation Modal -->
<div class="modal fade" id="addAllocationModal" tabindex="-1" aria-labelledby="addAllocationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl"> <!-- modal-xl for more space -->
        <div class="modal-content">
            <!-- The form action will be handled by JavaScript AJAX submission -->
            <form id="addAllocationForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addAllocationModalLabel">Add New Allocation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- CSRF token will be added via JavaScript if needed for AJAX -->
                    <input type="hidden" name="action" value="add">
                    <!-- budget_id will be set dynamically via JavaScript when the modal opens -->
                    <input type="hidden" id="add_modal_budget_id" name="budget_id" value="">

                    <!-- Form Fields Container -->
                    <div id="add-allocation-form-fields">

                        <h6>Core Information</h6>
                        <hr class="mt-1 mb-3">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="add_transaction_date" class="form-label">Transaction Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" id="add_transaction_date" name="transaction_date" required>
                            </div>
                            <div class="col-md-5 mb-3">
                                <label for="add_vendor_id" class="form-label">Vendor <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm select2-vendor-dropdown w-100" id="add_vendor_id" name="vendor_id" required> <?php // Added class and style for Select2 ?>
                                    <option value="" disabled selected>Select Vendor...</option>
                                    <?php foreach ($active_vendors as $vendor): ?>
                                        <option value="<?php echo $vendor['id']; ?>" data-client-required="<?php echo $vendor['client_name_required']; ?>">
                                            <?php echo htmlspecialchars($vendor['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                     <?php if (empty($active_vendors)): ?>
                                         <option value="" disabled>No active vendors found</option>
                                     <?php endif; ?>
                                </select>
                            </div>
                             <div class="col-md-3 mb-3">
                                <label for="add_client_name" class="form-label">Client Name</label> <?php // Label doesn't have * initially ?>
                                <input type="text" class="form-control form-control-sm" id="add_client_name" name="client_name" maxlength="255">
                                <small id="add_client_name_help" class="form-text text-muted d-none">Required for selected vendor.</small> <?php // Help text, initially hidden ?>
                            </div>
                        </div>
                        <div class="row">
                             <div class="col-md-4 mb-3">
                                <label for="add_voucher_number" class="form-label">Voucher #</label>
                                <input type="text" class="form-control form-control-sm" id="add_voucher_number" name="voucher_number">
                            </div>
                             <div class="col-md-4 mb-3">
                                <label for="add_purchase_date" class="form-label">Purchase Date</label>
                                <input type="date" class="form-control form-control-sm" id="add_purchase_date" name="purchase_date">
                            </div>
                             <div class="col-md-4 mb-3">
                                <label for="add_payment_status" class="form-label">Payment Status</label>
                                <select class="form-select form-select-sm" id="add_payment_status" name="payment_status">
                                    <option value="U" selected>Unpaid</option>
                                    <option value="P">Paid</option>
                                    <option value="Void">Void</option> <?php // Added Void option ?>
                                </select>
                            </div>
                        </div>
                         <div class="row">
                             <div class="col-md-4 mb-3">
                                <label for="add_enrollment_date" class="form-label">Enrollment Date</label>
                                <input type="date" class="form-control form-control-sm" id="add_enrollment_date" name="enrollment_date">
                            </div>
                             <div class="col-md-4 mb-3">
                                <label for="add_class_start_date" class="form-label">Class Start Date</label>
                                <input type="date" class="form-control form-control-sm" id="add_class_start_date" name="class_start_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="add_program_explanation" class="form-label">Program/Explanation <span class="text-danger">*</span></label>
                            <textarea class="form-control form-control-sm" id="add_program_explanation" name="program_explanation" rows="2" required></textarea>
                        </div>

                        <h6>Funding Allocation</h6>
                        <small class="d-block mb-2 text-muted">Enter amounts in the relevant funding categories. At least one amount is typically required.</small>
                        <hr class="mt-1 mb-3">
                        <div class="row">
                            <!-- Dislocated Worker -->
                            <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_dw" class="form-label">DW</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_dw" name="funding_dw" placeholder="0.00">
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_dw_admin" class="form-label">DW Admin</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_dw_admin" name="funding_dw_admin" placeholder="0.00">
                            </div>
                             <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_dw_sus" class="form-label">DW SUS</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_dw_sus" name="funding_dw_sus" placeholder="0.00">
                            </div>
                             <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_rr" class="form-label">RR</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_rr" name="funding_rr" placeholder="0.00">
                            </div>
                        </div>
                         <div class="row">
                             <!-- Adult -->
                            <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_adult" class="form-label">Adult</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_adult" name="funding_adult" placeholder="0.00">
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_adult_admin" class="form-label">Adult Admin</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_adult_admin" name="funding_adult_admin" placeholder="0.00">
                            </div>
                             <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_adult_sus" class="form-label">Adult SUS</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_adult_sus" name="funding_adult_sus" placeholder="0.00">
                            </div>
                             <div class="col-md-3 col-6 mb-3">
                                <label for="add_funding_h1b" class="form-label">H1B</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_h1b" name="funding_h1b" placeholder="0.00">
                            </div>
                        </div>
                         <div class="row">
                             <!-- Youth -->
                            <div class="col-md-4 col-6 mb-3">
                                <label for="add_funding_youth_is" class="form-label">Youth IS</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_youth_is" name="funding_youth_is" placeholder="0.00">
                            </div>
                            <div class="col-md-4 col-6 mb-3">
                                <label for="add_funding_youth_os" class="form-label">Youth OS</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_youth_os" name="funding_youth_os" placeholder="0.00">
                            </div>
                            <div class="col-md-4 col-6 mb-3">
                                <label for="add_funding_youth_admin" class="form-label">Youth Admin</label>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" id="add_funding_youth_admin" name="funding_youth_admin" placeholder="0.00">
                            </div>
                        </div>

                        <!-- Finance Fields Section (Visibility controlled by JS) -->
                        <div id="add-finance-fields" class="d-none"> <!-- Initially hidden -->
                            <h6 class="mt-4">Finance Information</h6>
                            <hr class="mt-1 mb-3">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="add_fin_voucher_received" class="form-label">Voucher Rec'd</label>
                                    <input type="text" class="form-control form-control-sm" id="add_fin_voucher_received" name="fin_voucher_received" maxlength="10">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="add_fin_accrual_date" class="form-label">Accrual Date</label>
                                    <input type="date" class="form-control form-control-sm" id="add_fin_accrual_date" name="fin_accrual_date">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="add_fin_obligated_date" class="form-label">Obligated Date</label>
                                    <input type="date" class="form-control form-control-sm" id="add_fin_obligated_date" name="fin_obligated_date">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="add_fin_expense_code" class="form-label">Expense Code</label>
                                    <input type="text" class="form-control form-control-sm" id="add_fin_expense_code" name="fin_expense_code" maxlength="50">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="add_fin_comments" class="form-label">Finance Comments</label>
                                <textarea class="form-control form-control-sm" id="add_fin_comments" name="fin_comments" rows="2"></textarea>
                            </div>
                        </div> <!-- /add-finance-fields -->

                    </div> <!-- /add-allocation-form-fields -->

                    <div id="add-allocation-error" class="text-danger mt-2"></div> <!-- For displaying AJAX errors -->

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>