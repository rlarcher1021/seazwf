document.addEventListener('DOMContentLoaded', function() {
    console.log("budgets.js loaded"); // For debugging initialization

    // --- DOM Element References ---
    const addAllocationModalEl = document.getElementById('addAllocationModal');
    const editAllocationModalEl = document.getElementById('editAllocationModal');
    const addForm = document.getElementById('addAllocationForm');
    const editForm = document.getElementById('editAllocationForm');
    const budgetFilterDropdown = document.getElementById('filter_budget_id');
    const allocationTable = document.getElementById('allocationsTable');
    const allocationTableBody = allocationTable ? allocationTable.querySelector('tbody') : null;
    const addAllocationButton = document.getElementById('addAllocationBtn');
    const addModalBudgetIdInput = document.getElementById('add_modal_budget_id');
const staffFields = [
    'edit_vendor_id',
    'edit_client_name',
    'edit_transaction_date',
    'edit_voucher_number',
    'edit_enrollment_date',
    'edit_class_start_date',
    'edit_purchase_date',
    'edit_payment_status', // Note: Server-side should still restrict 'Void' to Director
    'edit_program_explanation',
    'edit_funding_dw',
    'edit_funding_dw_admin',
    'edit_funding_dw_sus',
    'edit_funding_adult',
    'edit_funding_adult_admin',
    'edit_funding_adult_sus',
    'edit_funding_rr',
    'edit_funding_h1b',
    'edit_funding_youth_is',
    'edit_funding_youth_os',
    'edit_funding_youth_admin'
];

    const addAllocationErrorDiv = document.getElementById('add-allocation-error');
    const editAllocationErrorDiv = document.getElementById('edit-allocation-error');

    // --- PHP Variables to JS (Assume these are available globally or via data attributes) ---
    // It's better practice to pass these via data attributes on a relevant element
    // or within a dedicated <script> tag in budgets.php setting these JS variables.
    // Example: const currentUserRole = document.body.dataset.userRole;
    // For now, assuming they exist in the global scope as they did in the inline script.
    // Note: csrfToken is handled separately below by reading the meta tag.
    const currentUserRole = window.APP_DATA?.currentUserRole || 'unknown'; // Example access
    const currentUserId = window.APP_DATA?.currentUserId || null;       // Example access
    const financeAccessibleDeptIds = window.APP_DATA?.financeAccessibleDeptIds || []; // Example access

    // --- Utility Functions ---
    const escapeHtml = (unsafe) => {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe.toString()
                 .replace(/&/g, '&')
                 .replace(/</g, '<')
                 .replace(/>/g, '>')
                 .replace(/"/g, '"') // Use single quotes for the replacement string
                 .replace(/'/g, "&#039;");
    };

    const formatNumber = (num) => num ? parseFloat(num).toFixed(2) : '0.00';

    const formatDate = (dateString) => {
        if (!dateString) return '';
        // Basic check if it's already YYYY-MM-DD
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
            return dateString;
        }
        // Attempt to parse other formats if necessary, otherwise return empty
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return ''; // Invalid date
            const year = date.getFullYear();
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const day = date.getDate().toString().padStart(2, '0');
            return `${year}-${month}-${day}`;
        } catch (e) {
            return '';
        }
    };

    // --- CSRF Token ---
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!csrfToken) {
        console.error("CSRF token meta tag not found!");
        // Optionally disable forms or show a persistent error
    }

    // --- Add Allocation Modal Logic ---
    if (addAllocationModalEl && addForm) {
        // Event listener for PREVENTING Modal Show if invalid (using Bootstrap 4/5 event syntax)
        $(addAllocationModalEl).on('show.bs.modal', function (event) {
            console.log("Add modal 'show.bs.modal' event triggered.");
            const currentBudgetDropdown = document.getElementById('filter_budget_id'); // Re-fetch inside listener
            const addModalBudgetIdInput = document.getElementById('add_modal_budget_id'); // Re-fetch inside listener

            if (!currentBudgetDropdown) {
                console.error("Budget filter dropdown (#filter_budget_id) not found!");
                alert("Error: Could not find the budget selection dropdown.");
                event.preventDefault(); return;
            }
            const selectedBudgetId = currentBudgetDropdown.value;
            if (!selectedBudgetId || selectedBudgetId === '') {
                alert('Please select a budget from the filter dropdown before adding an allocation.');
                event.preventDefault(); return;
            }
            if (!addModalBudgetIdInput) {
                console.error("Hidden budget ID input (#add_modal_budget_id) not found in the modal!");
                alert("Error: Modal is missing a critical component. Cannot add allocation.");
                event.preventDefault(); return;
            }

            // Clear previous errors
            if (addAllocationErrorDiv) addAllocationErrorDiv.textContent = '';

            // Reset form and set hidden ID
            addForm.reset();
            addModalBudgetIdInput.value = selectedBudgetId;
            console.log(`Set hidden budget ID in add modal to: ${selectedBudgetId}`);

            // Reset Select2 dropdown and trigger conditional logic
            if (typeof $ !== 'undefined' && typeof $.fn.select2 === 'function') {
                $('#add_vendor_id').val(null).trigger('change');
            } else {
                 // Manually trigger change for non-select2 dropdowns if needed
                 const vendorSelect = document.getElementById('add_vendor_id');
                 if(vendorSelect) vendorSelect.dispatchEvent(new Event('change'));
            }

            // Control finance field visibility based on selected budget type
            const selectedOption = currentBudgetDropdown.querySelector(`option[value="${selectedBudgetId}"]`);
            const budgetType = selectedOption ? selectedOption.dataset.budgetType : null;
            controlFinanceFieldsVisibility('add', budgetType);
        });

        // Add Form Submission Handler
        addForm.addEventListener('submit', function(event) {
            event.preventDefault();
            submitAllocationForm(addForm, 'add-allocation-error', 'add');
        });
    } else {
        console.warn("Add Allocation Modal or Form not found.");
    }

    // --- Edit Allocation Modal Logic ---
    if (editAllocationModalEl && editForm && allocationTableBody) {
        // Use event delegation on the table body for edit buttons
        allocationTableBody.addEventListener('click', function(event) {
            const editButton = event.target.closest('.edit-alloc-btn');
            if (!editButton) return; // Click wasn't on an edit button

            console.log("Edit button clicked.");
            const allocationId = editButton.dataset.allocationId;
            if (!allocationId) {
                console.error("Edit button clicked, but allocation ID not found in data attributes.");
                return;
            }

            // --- Fetch Allocation Data via AJAX ---
            const editModal = $(editAllocationModalEl); // Use jQuery for easier Select2 interaction
            const spinner = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
            const originalButtonHtml = editButton.innerHTML;
            editButton.innerHTML = spinner;
            editButton.disabled = true;

            // Clear previous errors and reset form before fetching
            if (editAllocationErrorDiv) editAllocationErrorDiv.textContent = '';
            editForm.reset(); // Reset form fields
            // Reset Select2 dropdown
            const vendorSelect = $('#edit_vendor_id');
            if (vendorSelect.length && typeof vendorSelect.select2 === 'function') {
                vendorSelect.val(null).trigger('change');
            }

            // Prepare data for the request body, including CSRF token
            const requestBody = new URLSearchParams();
            requestBody.append('action', 'get_allocation_details');
            requestBody.append('allocation_id', allocationId);
            if (csrfToken) {
                requestBody.append('csrf_token', csrfToken);
            } else {
                 console.error("CSRF token missing for get_allocation_details request!");
                 alert("Error: Security token missing. Cannot load details.");
                 // Restore button state
                 editButton.innerHTML = originalButtonHtml;
                 editButton.disabled = false;
                 return; // Stop the fetch request
            }

            fetch('ajax_allocation_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    // Removed X-CSRF-Token header
                },
                body: requestBody // Send data in the body
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => { throw new Error(`HTTP error! Status: ${response.status}, Body: ${text}`) });
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.allocation) {
                    const allocData = data.allocation; // Use fetched data
                    const budgetType = data.budget_type || ''; // Get budget type from response

                    // --- Populate Edit Modal with Fetched Data ---
                    editModal.find('#edit_allocation_id').val(allocationId); // Keep the ID
                    editModal.find('#edit_modal_budget_id').val(allocData.budget_id || ''); // Hidden field for budget context

                    // Set standard input values
                    editModal.find('#edit_transaction_date').val(formatDate(allocData.transaction_date));
                    editModal.find('#edit_client_name').val(allocData.client_name || '');
                    editModal.find('#edit_voucher_number').val(allocData.voucher_number || '');
                    editModal.find('#edit_enrollment_date').val(formatDate(allocData.enrollment_date));
                    editModal.find('#edit_class_start_date').val(formatDate(allocData.class_start_date));
                    editModal.find('#edit_purchase_date').val(formatDate(allocData.purchase_date));
                    editModal.find('#edit_payment_status').val(allocData.payment_status || 'U');
                    editModal.find('#edit_program_explanation').val(allocData.program_explanation || '');

                    // Set funding amounts
                    editModal.find('#edit_funding_dw').val(formatNumber(allocData.funding_dw));
                    editModal.find('#edit_funding_dw_admin').val(formatNumber(allocData.funding_dw_admin));
                    editModal.find('#edit_funding_dw_sus').val(formatNumber(allocData.funding_dw_sus));
                    editModal.find('#edit_funding_adult').val(formatNumber(allocData.funding_adult));
                    editModal.find('#edit_funding_adult_admin').val(formatNumber(allocData.funding_adult_admin));
                    editModal.find('#edit_funding_adult_sus').val(formatNumber(allocData.funding_adult_sus));
                    editModal.find('#edit_funding_rr').val(formatNumber(allocData.funding_rr));
                    editModal.find('#edit_funding_h1b').val(formatNumber(allocData.funding_h1b));
                    editModal.find('#edit_funding_youth_is').val(formatNumber(allocData.funding_youth_is));
                    editModal.find('#edit_funding_youth_os').val(formatNumber(allocData.funding_youth_os));
                    editModal.find('#edit_funding_youth_admin').val(formatNumber(allocData.funding_youth_admin));

                    // Set finance fields
                    editModal.find('#edit_fin_voucher_received').val(allocData.fin_voucher_received || '');
                    editModal.find('#edit_fin_accrual_date').val(formatDate(allocData.fin_accrual_date));
                    editModal.find('#edit_fin_obligated_date').val(formatDate(allocData.fin_obligated_date));
                    editModal.find('#edit_fin_comments').val(allocData.fin_comments || '');
                    editModal.find('#edit_fin_expense_code').val(allocData.fin_expense_code || '');

                    // Set Vendor Dropdown (using Select2)
                    const vendorId = allocData.vendor_id || '';
                    if (vendorSelect.length && typeof vendorSelect.select2 === 'function') {
                        vendorSelect.val(vendorId).trigger('change'); // Set value and trigger change for Select2 & conditional logic
                    } else if (vendorSelect.length) {
                         vendorSelect.val(vendorId); // Set value for standard select
                         vendorSelect[0].dispatchEvent(new Event('change')); // Trigger change for conditional logic
                    } else {
                        console.warn("Edit modal vendor dropdown not found.");
                    }

                    // Control field visibility and editability using fetched budget type
                    controlFinanceFieldsVisibility('edit', budgetType);
                    controlFieldEditability(budgetType);

                    // Show the modal AFTER populating and setting permissions
                    if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
                        editModal.modal('show');
                    } else {
                        console.error("jQuery or Bootstrap Modal JS not found, cannot show edit modal.");
                    }

                } else {
                    alert(`Error fetching allocation details: ${data.message || 'Unknown error'}`);
                    if (editAllocationErrorDiv) editAllocationErrorDiv.textContent = `Error: ${data.message || 'Could not load allocation data.'}`;
                }
            })
            .catch(error => {
                console.error('Error fetching allocation details:', error);
                alert(`Failed to load allocation details: ${error.message}. Please check the console.`);
                if (editAllocationErrorDiv) editAllocationErrorDiv.textContent = `Error: ${error.message}`;
            })
            .finally(() => {
                // Restore button state
                editButton.innerHTML = originalButtonHtml;
                editButton.disabled = false;
            });
        });

        // Edit Form Submission Handler
        editForm.addEventListener('submit', function(event) {
            event.preventDefault();
            submitAllocationForm(editForm, 'edit-allocation-error', 'edit');
        });

    } else {
        console.warn("Edit Allocation Modal, Form, or Table Body not found.");
    }

     // --- Delete Allocation Logic ---
     if (allocationTableBody) {
         allocationTableBody.addEventListener('click', function(event) {
             const deleteButton = event.target.closest('.delete-alloc-btn');
             if (!deleteButton) return; // Exit if the click wasn't on a delete button or its icon

             event.preventDefault(); // Prevent default link behavior if icon is wrapped in <a>
             console.log('Delete button click listener fired.');

             const allocationId = deleteButton.dataset.allocationId;
             if (!allocationId) {
                 console.error('Delete button clicked but allocation ID not found.');
                 alert('Error: Could not get allocation ID to delete.');
                 return;
             }

             if (confirm(`Are you sure you want to delete allocation ID: ${allocationId}?`)) {
                 performDelete(allocationId, deleteButton);
             }
         });
     }

     function performDelete(allocationId, deleteButton) {
         console.log(`Attempting to delete allocation ID: ${allocationId}`);
         // Disable button temporarily
         deleteButton.disabled = true;
         deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; // Show loading indicator

         const formData = new FormData();
         formData.append('action', 'delete');
         formData.append('allocation_id', allocationId);
         if (csrfToken) {
             formData.append('csrf_token', csrfToken);
         } else {
              console.error("CSRF token missing for delete request!");
              alert("Error: Security token missing. Cannot delete.");
              deleteButton.disabled = false;
              deleteButton.innerHTML = '<i class="fas fa-trash-alt"></i>';
              return;
         }


         fetch('ajax_allocation_handler.php', {
             method: 'POST',
             body: formData
         })
         .then(response => {
             if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
             return response.json();
         })
         .then(data => {
             if (data.success) {
                 // Find the table row and remove it
                 const rowToRemove = deleteButton.closest('tr');
                 if (rowToRemove) {
                     rowToRemove.remove();
                 }
                 alert(data.message || 'Allocation deleted successfully.');
                 // Optionally, re-fetch or update totals if needed
                 // Example: fetchAllocations(budgetFilterDropdown.value);
             } else {
                 alert(`Error deleting allocation: ${data.message || 'Unknown error'}`);
             }
         })
         .catch(error => {
             console.error('Delete allocation error:', error);
             alert(`Failed to delete allocation: ${error.message}. Please check the console and try again.`);
         })
         .finally(() => {
              // Re-enable button on failure or success (unless row removed)
              if (!deleteButton.closest('tr')) return; // Row already removed
              deleteButton.disabled = false;
              deleteButton.innerHTML = '<i class="fas fa-trash-alt"></i>';
         });
     }


    // --- Common AJAX Form Submission Function ---
    function submitAllocationForm(formElement, errorElementId, action) {
        const errorDiv = document.getElementById(errorElementId);
        const submitButton = formElement.querySelector('button[type="submit"]');
        if (errorDiv) errorDiv.textContent = '';
        if (submitButton) submitButton.disabled = true;

        const formData = new FormData(formElement);
        // Ensure action is set correctly (it's already in hidden input, but double-check)
        formData.set('action', action); // Explicitly set action ('add' or 'edit')

        // Add CSRF token if available
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        } else {
            console.error("CSRF token missing for form submission!");
            if (errorDiv) errorDiv.textContent = 'Error: Security token missing. Please refresh.';
            if (submitButton) submitButton.disabled = false;
            return;
        }

        // Log FormData contents for debugging
        console.log(`FormData for ${action}:`);
        for (let [key, value] of formData.entries()) {
            console.log(`${key}: ${value}`);
        }


        fetch('ajax_allocation_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`HTTP error! Status: ${response.status}, Body: ${text}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log(`${action === 'add' ? 'Add' : 'Edit'} Allocation Response:`, data);
            if (data.success) {
                const modalElement = formElement.closest('.modal');
                 // Use BS4 jQuery method to hide
                 if (modalElement && typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
                     $(modalElement).modal('hide');
                 } else if (modalElement) {
                      console.error("jQuery or Bootstrap 4 Modal JS not found, cannot close modal.");
                 }
                alert(`Allocation ${action === 'add' ? 'added' : 'updated'} successfully!`);
                window.location.reload(); // Reload page to see changes (simplest approach for now)
                // TODO: Implement dynamic table update instead of reload
            } else {
                if (errorDiv) errorDiv.textContent = 'Error: ' + (data.message || `Could not ${action} allocation.`);
            }
        })
        .catch(error => {
            console.error(`Error submitting ${action} allocation form:`, error);
            if (errorDiv) errorDiv.textContent = `An unexpected error occurred: ${error.message}. Please try again.`;
        })
        .finally(() => {
            if (submitButton) submitButton.disabled = false;
        });
    }



    // --- Dynamic Add Allocation Button Logic ---
    const addAllocButtonContainer = document.getElementById('addAllocButtonContainer'); // Container holding the button
    const budgetFilterSelect = document.getElementById('filter_budget_id');

    function updateAddAllocationButtonState() {
        if (!budgetFilterSelect || !addAllocButtonContainer) {
            console.warn('Budget filter or Add button container not found for dynamic update.');
            return;
        }

        const selectedOption = budgetFilterSelect.options[budgetFilterSelect.selectedIndex];
        const selectedBudgetId = budgetFilterSelect.value;
        const addAllocButton = addAllocButtonContainer.querySelector('button'); // Find the button inside the container

        // Clear existing button first
        addAllocButtonContainer.innerHTML = '';

        if (!selectedBudgetId || selectedBudgetId === '' || selectedBudgetId === 'all') {
            // No specific budget selected or 'all' selected, button should be hidden or disabled
            // Let's create a disabled button placeholder
            const disabledButton = document.createElement('button');
            disabledButton.type = 'button';
            disabledButton.className = 'btn btn-success disabled';
            disabledButton.title = 'Select a specific budget to add an allocation.';
            disabledButton.innerHTML = '<i class="fas fa-plus"></i> Add Allocation';
            addAllocButtonContainer.appendChild(disabledButton);
            return;
        }

        // Get data from the selected option
        const budgetType = selectedOption.dataset.budgetType;
        const budgetOwner = selectedOption.dataset.budgetOwner;
        const budgetDept = selectedOption.dataset.budgetDept;
        const budgetName = selectedOption.dataset.budgetName;

        // Get user data (ensure window.APP_DATA is populated in PHP)
        const userRole = window.APP_DATA?.currentUserRole?.toLowerCase() || 'unknown';
        const userDeptSlug = window.APP_DATA?.currentUserDeptSlug?.toLowerCase() || null;
        const userId = window.APP_DATA?.currentUserId || null;
        const isStaffInFinance = userRole === 'azwk_staff' && userDeptSlug === 'finance';

        let showAddButton = false;

        // --- Replicate PHP Permission Logic --- 
        if (userRole === 'director') {
            showAddButton = true; // Directors can add to any selected budget (Staff or Admin)
        } else if (isStaffInFinance && budgetType === 'Admin') {
            // Staff in Finance can add to 'Admin' budgets
            showAddButton = true;
        } else if (userRole === 'azwk_staff' && !isStaffInFinance && budgetType === 'Staff' && budgetOwner && parseInt(budgetOwner) === parseInt(userId)) {
            // Regular staff can add ONLY to 'Staff' budgets assigned to them
            showAddButton = true;
        } else if (userRole === 'finance' && budgetType === 'Admin') {
             // Dedicated 'finance' role can add to ANY Admin budget
             showAddButton = true;
        }
        // Note: Add administrator logic if needed
        // --- End Permission Logic ---

        // Create and append the button based on permission
        const newButton = document.createElement('button');
        newButton.type = 'button';
        newButton.className = 'btn btn-success';
        newButton.innerHTML = `<i class="fas fa-plus"></i> Add Allocation${budgetName ? ' to Budget: ' + escapeHtml(budgetName) : ''}`;

        if (showAddButton) {
            newButton.id = 'addAllocationBtn'; // Give it the original ID if enabled
            newButton.dataset.toggle = 'modal'; // Use BS4 data attributes
            newButton.dataset.target = '#addAllocationModal';
        } else {
            newButton.classList.add('disabled');
            newButton.title = 'You do not have permission to add allocations to this budget type/owner.';
            // Keep the generic text if disabled
            newButton.innerHTML = '<i class="fas fa-plus"></i> Add Allocation';
        }

        addAllocButtonContainer.appendChild(newButton);
    }

    // Add event listener to the budget filter dropdown
    if (budgetFilterSelect) {
        // Combined listener for budget dropdown changes
        budgetFilterSelect.addEventListener('change', function() {
            updateAddAllocationButtonState(); // Update the add button state/text
            fetchAllocations(); // Fetch and render the allocations table
        });
        // Initial call in case the page loads with a budget pre-selected
        // updateAddAllocationButtonState(); // PHP already handles initial state, so this might be redundant or cause flicker
    } else {
        console.warn('Budget filter dropdown (#filter_budget_id) not found, cannot attach change listener.');
    }



    // --- Filter Change Handling & Allocation Fetching ---
    const fiscalYearFilter = document.getElementById('filter_fiscal_year');
    const grantFilter = document.getElementById('filter_grant_id');
    const departmentFilter = document.getElementById('filter_department_id');
    // budgetFilterDropdown already defined

    function fetchAllocations() {
        if (!allocationTableBody) {
            console.error("Allocation table body not found. Cannot fetch allocations.");
            return;
        }

        const selectedFiscalYear = fiscalYearFilter ? fiscalYearFilter.value : '';
        const selectedGrantId = grantFilter ? grantFilter.value : '';
        const selectedDepartmentId = departmentFilter ? departmentFilter.value : '';
        const selectedBudgetId = budgetFilterDropdown ? budgetFilterDropdown.value : ''; // Can be '', 'all', or a specific ID

        console.log(`Fetching allocations with filters: Year=${selectedFiscalYear}, Grant=${selectedGrantId}, Dept=${selectedDepartmentId}, Budget=${selectedBudgetId}`);

        // Show loading state
        allocationTableBody.innerHTML = `<tr><td colspan="30" class="text-center"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading allocations...</td></tr>`; // Adjust colspan as needed

        const formData = new FormData();
        formData.append('action', 'get_allocations_for_filters'); // New action
        formData.append('fiscal_year_start', selectedFiscalYear);
        formData.append('grant_id', selectedGrantId);
        formData.append('department_id', selectedDepartmentId);
        formData.append('budget_id', selectedBudgetId); // Send 'all' or specific ID
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        } else {
            console.error("CSRF token missing for fetchAllocations request!");
            allocationTableBody.innerHTML = `<tr><td colspan="30" class="text-center text-danger">Error: Security token missing. Cannot load data.</td></tr>`;
            return;
        }

        fetch('ajax_allocation_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => { throw new Error(`HTTP error! Status: ${response.status}, Body: ${text}`) });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && Array.isArray(data.allocations)) {
                renderAllocationsTable(data.allocations);
            } else {
                console.error("Error fetching allocations:", data.message);
                allocationTableBody.innerHTML = `<tr><td colspan="30" class="text-center text-danger">Error loading allocations: ${escapeHtml(data.message || 'Unknown error')}</td></tr>`;
            }
        })
        .catch(error => {
            console.error('Fetch allocations error:', error);
            allocationTableBody.innerHTML = `<tr><td colspan="30" class="text-center text-danger">Failed to load allocations: ${escapeHtml(error.message)}</td></tr>`;
        });
    }

    function renderAllocationsTable(allocations) {
        if (!allocationTableBody) return;
        allocationTableBody.innerHTML = ''; // Clear existing rows

        if (allocations.length === 0) {
            allocationTableBody.innerHTML = `<tr><td colspan="30" class="text-center">No allocations found matching the selected criteria.</td></tr>`;
            // TODO: Clear/update grand total
            return;
        }

        let grandTotalNonVoid = 0;
        // Determine if finance columns should be shown based on role and department context
        const userRole = window.APP_DATA?.currentUserRole?.toLowerCase() || 'unknown';
        const isStaffInFinance = window.APP_DATA?.isStaffInFinance || false;
        const showFinanceColumns = (userRole === 'director' || isStaffInFinance);
        console.log(`Render Table - Role: ${userRole}, IsStaffInFinance: ${isStaffInFinance}, ShowFinanceCols: ${showFinanceColumns}`); // Debug log

        const colspanValue = showFinanceColumns ? 31 : 24; // Adjust colspan based on visibility

        allocations.forEach(alloc => {
            const isVoid = (alloc.payment_status === 'Void');
            let rowTotal = 0;
            if (!isVoid) {
                // Recalculate row total based on actual fields returned
                rowTotal = (parseFloat(alloc.funding_dw_admin || 0) + parseFloat(alloc.funding_dw || 0) +
                            parseFloat(alloc.funding_adult_admin || 0) + parseFloat(alloc.funding_adult || 0) +
                            parseFloat(alloc.funding_rr || 0) + parseFloat(alloc.funding_youth_is || 0) +
                            parseFloat(alloc.funding_youth_os || 0) + parseFloat(alloc.funding_youth_admin || 0) +
                            parseFloat(alloc.funding_dw_sus || 0) + parseFloat(alloc.funding_adult_sus || 0) +
                            parseFloat(alloc.funding_h1b || 0));
                grandTotalNonVoid += rowTotal;
            }

            // TODO: Determine Edit/Delete permissions based on user role and allocation/budget data
            // This requires passing more context or making additional checks.
            // For now, assume permissions based on role (needs refinement)
            let canEditRow = false;
            let canDeleteRow = false;
            const role = window.APP_DATA?.currentUserRole?.toLowerCase();
            // Simplified permission logic - NEEDS TO BE REFINED based on budget owner/type/dept access
            if (role === 'director') { canEditRow = true; canDeleteRow = true; }
            else if (role === 'finance') { canEditRow = true; canDeleteRow = false; }
            // else if (role === 'azwk_staff' && !isFinanceStaff && budgetType === 'Staff' && budgetOwner === currentUserId) { canEditRow = true; canDeleteRow = true; }
            // else if (role === 'azwk_staff' && isFinanceStaff) { canEditRow = true; canDeleteRow = true; } // Needs budget type check
            // Placeholder - enable edit/delete for director for now
            if (role === 'director') { canEditRow = true; canDeleteRow = true; }


            const row = document.createElement('tr');
            row.className = isVoid ? 'allocation-void' : '';

            let statusText = 'Unpaid';
            let badgeClass = 'bg-warning';
            if (alloc.payment_status === 'P') { statusText = 'Paid'; badgeClass = 'bg-success'; }
            else if (isVoid) { statusText = 'Void'; badgeClass = 'bg-secondary'; }

            // Build row HTML (ensure all columns match the PHP version)
            row.innerHTML = `
                <td class="text-nowrap">
                    ${canEditRow && !isVoid ? `<button type="button" class="btn btn-xs btn-warning me-1 edit-alloc-btn" title="Edit Allocation" data-bs-toggle="modal" data-bs-target="#editAllocationModal" data-allocation-id="${alloc.id}"><i class="fas fa-edit"></i></button>` : ''}
                    ${canDeleteRow && !isVoid ? `<button type="button" class="btn btn-xs btn-danger delete-alloc-btn" title="Delete Allocation" data-allocation-id="${alloc.id}"><i class="fas fa-trash-alt"></i></button>` : ''}
                </td>
                <td>${escapeHtml(alloc.transaction_date)}</td>
                <td>${escapeHtml(alloc.vendor_name || 'N/A')}</td>
                <td>${escapeHtml(alloc.client_name)}</td>
                <td>${escapeHtml(alloc.voucher_number)}</td>
                <td>${(alloc.program_explanation || '').replace(/\n/g, '<br>') }</td>
                <td><span class="badge ${badgeClass}">${statusText}</span></td>
                <td class="text-end">${formatNumber(alloc.funding_dw)}</td>
                <td class="text-end">${formatNumber(alloc.funding_dw_admin)}</td>
                <td class="text-end">${formatNumber(alloc.funding_dw_sus)}</td>
                <td class="text-end">${formatNumber(alloc.funding_adult)}</td>
                <td class="text-end">${formatNumber(alloc.funding_adult_admin)}</td>
                <td class="text-end">${formatNumber(alloc.funding_adult_sus)}</td>
                <td class="text-end">${formatNumber(alloc.funding_rr)}</td>
                <td class="text-end">${formatNumber(alloc.funding_h1b)}</td>
                <td class="text-end">${formatNumber(alloc.funding_youth_is)}</td>
                <td class="text-end">${formatNumber(alloc.funding_youth_os)}</td>
                <td class="text-end">${formatNumber(alloc.funding_youth_admin)}</td>
                <td class="text-end fw-bold">${formatNumber(rowTotal)}</td>
                <td>${escapeHtml(alloc.enrollment_date)}</td>
                <td>${escapeHtml(alloc.class_start_date)}</td>
                <td>${escapeHtml(alloc.purchase_date)}</td>
                ${showFinanceColumns ? `
                <td>${escapeHtml(alloc.fin_voucher_received)}</td>
                <td>${escapeHtml(alloc.fin_accrual_date)}</td>
                <td>${escapeHtml(alloc.fin_obligated_date)}</td>
                <td>${(alloc.fin_comments || '').replace(/\n/g, '<br>') }</td>
                <td>${escapeHtml(alloc.fin_expense_code)}</td>
                <td>${escapeHtml(alloc.fin_processed_by_user_name || alloc.fin_processed_by_user_id || '')}</td>
                <td>${escapeHtml(alloc.fin_processed_at)}</td>
                ` : ''}
                <td>${escapeHtml(alloc.created_by_user_name || alloc.created_by_user_id || '')}</td>
                <td>${escapeHtml(alloc.updated_at)}</td>
            `;
            allocationTableBody.appendChild(row);
        });

        // TODO: Update grand total display
        const tfoot = allocationTable.querySelector('tfoot');
        if (tfoot) {
            // Find the cell for the total and update it
            // This assumes the structure remains the same
            const totalCell = tfoot.querySelector('tr td:nth-last-child(' + (colspanValue - 18) + ')'); // Find the total cell based on colspan
             if (totalCell) {
                 totalCell.textContent = formatNumber(grandTotalNonVoid);
             } else {
                  console.warn("Could not find grand total cell in tfoot to update.");
             }
        }
    }

    // Add event listeners to other filters
    if (fiscalYearFilter) fiscalYearFilter.addEventListener('change', fetchAllocations);
    if (grantFilter) grantFilter.addEventListener('change', fetchAllocations);
    if (departmentFilter) departmentFilter.addEventListener('change', fetchAllocations);
    // The budget dropdown listener is now handled above (lines 517-523)

    // Initial fetch on page load if a budget is pre-selected (or 'all')
    // if (budgetFilterDropdown && budgetFilterDropdown.value) {
    //     fetchAllocations();
    // }
    // We'll rely on the PHP initial load for now, and AJAX only for subsequent changes.
    // To make it fully AJAX, remove the PHP allocation loading block and uncomment the line above.


    // --- Initialize Select2 ---
    if (typeof $ !== 'undefined' && typeof $.fn.select2 === 'function') {
        console.log("Initializing Select2 for vendor dropdowns...");
        // Initialize for Add Modal
        $('#add_vendor_id.select2-vendor-dropdown').select2({
            dropdownParent: $('#addAllocationModal .modal-body'),
            placeholder: 'Select Vendor...',
            allowClear: true,
            width: '100%'
        });
        // Initialize for Edit Modal
        $('#edit_vendor_id.select2-vendor-dropdown').select2({
            dropdownParent: $('#editAllocationModal .modal-body'),
            placeholder: 'Select Vendor...',
            allowClear: true,
            width: '100%'
        });
    } else {
        console.warn("jQuery or Select2 not detected. Searchable vendor dropdowns will not be initialized.");
    }

    // --- Conditional Client Name Logic ---
    function handleClientNameRequirement(vendorSelectId, clientNameInputId, helpTextId) {
        const vendorSelect = document.getElementById(vendorSelectId);
        const clientNameInput = document.getElementById(clientNameInputId);
        // Find label more robustly
        const clientNameLabel = clientNameInput?.closest('.mb-3')?.querySelector('label');
        const helpText = document.getElementById(helpTextId);

        if (!vendorSelect || !clientNameInput || !clientNameLabel || !helpText) {
            console.warn(`Elements missing for conditional client name logic: ${vendorSelectId}, ${clientNameInputId}, ${helpTextId}`);
            return;
        }

        const updateClientField = () => {
             const selectedOption = vendorSelect.options[vendorSelect.selectedIndex];
             // Use '==' for comparison as dataset values are strings '0' or '1'
             const requiresClient = selectedOption && selectedOption.dataset.clientRequired == '1';

             if (requiresClient) {
                 clientNameInput.required = true;
                 clientNameLabel.innerHTML = 'Client Name <span class="text-danger">*</span>';
                 helpText.style.display = 'block';
             } else {
                 clientNameInput.required = false;
                 clientNameLabel.innerHTML = 'Client Name';
                 helpText.style.display = 'none';
                 // Do NOT clear the value automatically on change, user might be switching back and forth
                 // clientNameInput.value = '';
             }
        };

        vendorSelect.addEventListener('change', updateClientField);

        // Trigger on modal show for Edit modal to handle pre-filled data
        if (vendorSelectId.startsWith('edit_') && editAllocationModalEl) {
             $(editAllocationModalEl).on('shown.bs.modal', updateClientField); // Use jQuery BS4 event
        } else {
             // Trigger for Add modal immediately
             updateClientField();
        }
    }

    // Apply Conditional Logic
    handleClientNameRequirement('add_vendor_id', 'add_client_name', 'add_client_name_help');
    handleClientNameRequirement('edit_vendor_id', 'edit_client_name', 'edit_client_name_help');


    // --- Modal Field Control Logic ---
    // This function now handles BOTH visibility of the finance section AND
    // initial field editability, especially for the 'add' modal context.
    function controlFinanceFieldsVisibility(modalType, budgetType) {
        const financeFieldsDivId = modalType === 'add' ? 'add-finance-fields' : 'edit-finance-fields';
        const financeFieldsDiv = document.getElementById(financeFieldsDivId);
        const modal = modalType === 'add' ? addAllocationModalEl : editAllocationModalEl;
        const fieldPrefix = modalType === 'add' ? 'add_' : 'edit_';

        if (!financeFieldsDiv || !modal) {
            console.error(`controlFinanceFieldsVisibility: Missing elements for modalType ${modalType}`);
            return;
        }

        const userRoleLower = currentUserRole.toLowerCase();
        let showFinanceSection = false;

        // Define field groups (similar to edit function)
        const coreFields = ['transaction_date', 'vendor_id', 'client_name', 'voucher_number', 'enrollment_date', 'class_start_date', 'purchase_date', 'payment_status', 'program_explanation'];
        const allFundingFields = ['funding_dw', 'funding_dw_admin', 'funding_dw_sus', 'funding_adult', 'funding_adult_admin', 'funding_adult_sus', 'funding_rr', 'funding_h1b', 'funding_youth_is', 'funding_youth_os', 'funding_youth_admin'];
        const staffSideFields = [...coreFields, ...allFundingFields];
        const financeFields = ['fin_voucher_received', 'fin_accrual_date', 'fin_obligated_date', 'fin_comments', 'fin_expense_code'];

        // Helper to set field state (readonly/disabled for inputs, disabled for selects)
        // Adapted for use in this function, takes prefix
        const setFieldState = (fieldName, editable) => {
            const element = modal.querySelector(`#${fieldPrefix}${fieldName}`);
            if (element) {
                const isSelect2 = element.tagName === 'SELECT' && typeof $ !== 'undefined' && typeof $.fn.select2 === 'function' && $(element).hasClass('select2-hidden-accessible');
                const isStandardSelect = element.tagName === 'SELECT' && !isSelect2;

                if (isSelect2) {
                    $(element).prop('disabled', !editable).trigger('change.select2');
                } else if (isStandardSelect) {
                    element.disabled = !editable;
                } else { // Inputs
                    element.readOnly = !editable;
                    element.disabled = !editable;
                }
                if (!editable) element.classList.add('bg-light', 'readonly-field');
                else element.classList.remove('bg-light', 'readonly-field');
            }
        };


        // Determine visibility and initial editability based on modal type and role/budget
        if (modalType === 'add') {
            // Add Modal: Visibility depends on who is adding. Editability is simpler.
            if (userRoleLower === 'finance') {
                // Finance can only add to 'Admin' budgets (server enforces this).
                // So, if Finance is adding, show finance section and enable ALL fields.
                showFinanceSection = true;
                console.log("Add Modal: Finance user detected. Enabling all fields.");
                [...staffSideFields, ...financeFields].forEach(field => setFieldState(field, true));
            } else { // Director or AZ@Work Staff adding (must be to 'Staff' budget)
                showFinanceSection = false; // Hide finance section
                console.log("Add Modal: Non-Finance user detected. Enabling staff-side fields only.");
                staffSideFields.forEach(field => setFieldState(field, true)); // Enable staff-side
                financeFields.forEach(field => setFieldState(field, false)); // Ensure finance fields are disabled (though hidden)
            }
        } else { // Edit Modal: Visibility is simpler, editability handled by controlFieldEditability
             showFinanceSection = ['director', 'finance', 'azwk_staff'].includes(userRoleLower);
             console.log(`Edit Modal: Visibility check for role ${userRoleLower}. Show finance section: ${showFinanceSection}`);
             // Editability for 'edit' modal is handled separately by controlFieldEditability
        }

        // Apply visibility
        financeFieldsDiv.style.display = showFinanceSection ? 'block' : 'none';
        console.log(`Finance fields section display set to: ${financeFieldsDiv.style.display}`);
    }

     function controlFieldEditability(budgetType) { // This function now ONLY handles EDIT modal editability
        const modal = editAllocationModalEl; // Explicitly target Edit modal
        console.log(`controlFieldEditability called. Role: '${currentUserRole}', Budget Type: '${budgetType}'`);

        if (!modal || !budgetType) {
             console.error("controlFieldEditability: Modal element or budgetType missing.");
             return; // Need budget type
        }

        // Define field groups based on Living Plan v1.26
        const coreFields = ['transaction_date', 'vendor_id', 'client_name', 'voucher_number', 'enrollment_date', 'class_start_date', 'purchase_date', 'payment_status', 'program_explanation'];
        const allFundingFields = ['funding_dw', 'funding_dw_admin', 'funding_dw_sus', 'funding_adult', 'funding_adult_admin', 'funding_adult_sus', 'funding_rr', 'funding_h1b', 'funding_youth_is', 'funding_youth_os', 'funding_youth_admin'];
        const staffSideFields = [...coreFields, ...allFundingFields]; // Combined staff-editable fields
        const financeFields = ['fin_voucher_received', 'fin_accrual_date', 'fin_obligated_date', 'fin_comments', 'fin_expense_code']; // Finance-editable fields

        // Helper to set field state (readonly/disabled for inputs, disabled for selects)
        const setEditable = (fieldName, editable) => {
            const element = modal.querySelector(`#edit_${fieldName}`);
            if (element) {
                const isSelect2 = element.tagName === 'SELECT' && typeof $ !== 'undefined' && typeof $.fn.select2 === 'function' && $(element).hasClass('select2-hidden-accessible');
                const isStandardSelect = element.tagName === 'SELECT' && !isSelect2;

                // Reset state first
                element.readOnly = false;
                element.disabled = false;
                element.classList.remove('field-disabled');
                if (isSelect2) $(element).prop('disabled', false).trigger('change.select2');


                if (!editable) {
                    // Apply disabled state and class
                    if (isSelect2) {
                        $(element).prop('disabled', true).trigger('change.select2');
                    } else if (isStandardSelect) {
                        element.disabled = true;
                    } else { // Inputs (text, date, number, textarea)
                        element.readOnly = true; // Use readOnly for inputs
                        // element.disabled = true; // Optionally keep disabled for consistency, but readonly is often preferred
                    }
                    element.classList.add('field-disabled'); // Add the visual class
                }
                // No 'else' needed for enabling, as state was reset at the start
            } else {
                 console.warn(`Element #edit_${fieldName} not found in modal.`);
            }
        };

        // --- Get User Context (Ensure window.APP_DATA is populated in PHP) ---
        const userRoleLower = (window.APP_DATA?.currentUserRole || 'unknown').toLowerCase();
        const currentUserDeptSlug = (window.APP_DATA?.currentUserDeptSlug || 'unknown').toLowerCase(); // Get dept slug
        const isFinanceStaff = userRoleLower === 'azwk_staff' && currentUserDeptSlug === 'finance'; // Determine if finance staff

        console.log(`controlFieldEditability: Checking permissions for Role: ${userRoleLower}, DeptSlug: ${currentUserDeptSlug}, BudgetType: ${budgetType}`);

        // --- Field Groups are defined earlier (around line 745) ---
        // const staffFields = ...; // Defined earlier
        // const financeFields = ...; // Defined earlier
        const paymentStatusField = 'payment_status'; // Define this one if not already defined

        // --- Determine Editability Based on Living Plan v1.30 ---
        let staffEditable = false;
        let financeEditable = false;
        let paymentStatusEditable = false; // Default to not editable

        // Rules:
        // Director (Editing 'Staff' Budget): Staff editable / fin_* read-only. Can Void.
        // azwk_staff (AZ@Work Dept) (Editing assigned 'Staff' Budget): Staff editable / fin_* read-only. Cannot Void.
        // azwk_staff (Finance Dept) (Editing 'Staff' Budget): Staff read-only / fin_* editable. Cannot Void.
        // azwk_staff (Finance Dept) (Editing 'Admin' Budget): ALL editable. Cannot Void.

        if (userRoleLower === 'director') {
            if (budgetType === 'Staff') {
                staffEditable = true;
                financeEditable = false;
                paymentStatusEditable = true; // Director can edit status, including Void
            } else {
                // Director cannot edit Admin budgets (access denied earlier ideally, but set all readonly here too)
                staffEditable = false;
                financeEditable = false;
                paymentStatusEditable = false;
            }
        } else if (userRoleLower === 'azwk_staff') {
            if (isFinanceStaff) { // Finance Department Staff
                if (budgetType === 'Staff') {
                    staffEditable = false;
                    financeEditable = true;
                } else if (budgetType === 'Admin') {
                    staffEditable = true; // All fields editable
                    financeEditable = true;
                }
            } else { // AZ@Work Department Staff
                if (budgetType === 'Staff') {
                    // Assuming access check already verified it's *their* assigned budget
                    staffEditable = true;
                    financeEditable = false;
                }
                // Cannot edit Admin budgets
            }
            // azwk_staff cannot edit payment_status (especially not to Void)
            paymentStatusEditable = false;
        } else {
            // Unknown role or scenario, default to read-only
            console.warn(`Unknown role (${userRoleLower}) or scenario for editability control.`);
            staffEditable = false;
            financeEditable = false;
            paymentStatusEditable = false;
        }

        // --- Apply Editability ---
        console.log(`Applying Editability - Staff: ${staffEditable}, Finance: ${financeEditable}, Status: ${paymentStatusEditable}`);
        // Use the locally defined field array from line 770
        staffSideFields.forEach(field => setEditable(field, staffEditable)); // Corrected variable name
        financeFields.forEach(field => setEditable(field, financeEditable));
        // Explicitly set payment status based on its specific flag, potentially overriding the staffEditable setting for this field
        setEditable(paymentStatusField, paymentStatusEditable);

        // Ensure Select2 dropdowns reflect disabled state if needed
        if (typeof $ !== 'undefined' && typeof $.fn.select2 === 'function') {
            const vendorSelect = $(`#edit_vendor_id`); // Target vendor dropdown
            if (vendorSelect.length) {
                // Vendor should be editable if staff fields are editable OR if finance staff is editing an Admin budget
                const vendorEditable = staffEditable || (isFinanceStaff && budgetType === 'Admin');
                vendorSelect.prop('disabled', !vendorEditable).trigger('change.select2'); // Refresh Select2 visual state
                console.log(`Vendor dropdown (#edit_vendor_id) editable set to: ${vendorEditable}`);
            }
            // Add similar logic for other Select2 fields if they exist
        }
    }

    // --- Initial Page Load ---
    // Trigger initial checks if needed, e.g., if filters are pre-populated by GET params
    // This part might need adjustment based on how the page loads initial data
    const initialBudgetId = budgetFilterDropdown ? budgetFilterDropdown.value : null;
    if (initialBudgetId) {
        console.log("Initial budget selected on load:", initialBudgetId);
        // Potentially trigger fetchAllocations or other setup based on initial state
    }

}); // End DOMContentLoaded