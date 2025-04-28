<?php
require_once 'includes/auth.php'; // Handles session start and authentication
require_once 'includes/db_connect.php';
require_once 'includes/data_access/grants_dal.php';
require_once 'includes/data_access/budgets_dal.php';
require_once 'includes/data_access/user_data.php';
require_once 'includes/data_access/department_data.php';
require_once 'includes/data_access/finance_access_data.php';
require_once 'includes/data_access/budget_allocations_dal.php';
require_once 'includes/utils.php'; // For CSRF, role checks etc.

// 1. Permission Check: Ensure user has an appropriate role
$allowed_roles = ['director', 'azwk_staff', 'finance', 'administrator']; // Use lowercase roles consistent with auth.php and allow admin
$currentActiveRoleLower = isset($_SESSION['active_role']) ? strtolower($_SESSION['active_role']) : null;

if (!$currentActiveRoleLower || !in_array($currentActiveRoleLower, $allowed_roles)) {
    $_SESSION['error_message'] = "Access denied. You do not have permission to view this page.";
    // Redirect to dashboard, as index.php might not be appropriate for logged-in users denied access
    header("Location: dashboard.php?reason=access_denied");
    exit;
}

// Use the already checked and lowercased active role
// $currentActiveRoleLower is defined in the permission check block above (lines 15-16)
$currentUserRole = $currentActiveRoleLower;
$currentUserId = $_SESSION['user_id']; // Assuming user ID is stored in session

// Initialize variables
$pageTitle = "Budget Allocations";
$fiscalYears = [];
$grants = [];
$departments = []; // All departments for filtering (if needed)
$financeAccessibleDeptIds = []; // IDs Finance user can access
$budgetsForFilter = []; // Budgets to show in the budget filter dropdown
$allocations = []; // Allocations for the selected budget
$is_staff_in_finance_here = false; // Initialize flag
$currentUserDeptSlug = null; // Initialize slug

// --- Fetch Current User Details (Department/Slug) ---
try {
    $currentUserData = getUserById($pdo, $currentUserId);
    $currentUserDeptId = $currentUserData['department_id'] ?? null;
    if ($currentUserDeptId) {
        $currentUserDeptDetails = getDepartmentById($pdo, $currentUserDeptId);
        $currentUserDeptSlug = $currentUserDeptDetails['slug'] ?? null;
        if ($currentUserRole === 'azwk_staff' && $currentUserDeptSlug && strtolower($currentUserDeptSlug) === 'finance') {
            $is_staff_in_finance_here = true;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching user/department details in budgets.php: " . $e->getMessage());
    // Continue execution, but finance-related permissions might be affected
}

$selectedFilters = [
    'fiscal_year' => $_GET['fiscal_year'] ?? null,
    'grant_id' => filter_input(INPUT_GET, 'grant_id', FILTER_VALIDATE_INT) ?: null,
    'department_id' => filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT) ?: null,
    // Read budget_id directly to allow for the 'all' value. Validation happens later.
    'budget_id' => $_GET['budget_id'] ?? null,
];
$error_message = $_SESSION['error_message'] ?? null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['error_message'], $_SESSION['success_message']); // Clear flash messages

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Fetch Data for Filters ---
    $financeAccessibleDeptIds = []; // Explicitly initialize here again for clarity/linter
try {
    $fiscalYears = getDistinctFiscalYearStarts($pdo); // Get YYYY-MM-DD strings
    $grants = getAllGrants($pdo);
    $departments = getAllDepartments($pdo); // Get all departments for potential filtering
    $financeAccessibleDeptIds = []; // Initialize for staff in finance check

    // If azwk_staff is in Finance dept, get their accessible departments (Finance role now has universal access)
    if ($is_staff_in_finance_here) { // Only fetch for staff in finance
        $financeAccessibleDeptIds = getAccessibleDepartmentIdsForFinanceUser($pdo, $currentUserId);
         error_log("User {$currentUserId} (Role: {$currentUserRole}, IsStaffInFinance: Yes) - Accessible Dept IDs: " . implode(',', $financeAccessibleDeptIds)); // Debug log
    }

    // --- Fetch Budgets for Dropdown based on Role and Filters ---
    $budgetFilterParams = [];
    if ($selectedFilters['fiscal_year']) {
        $yearDate = DateTime::createFromFormat('Y-m-d', $selectedFilters['fiscal_year']);
        if ($yearDate) {
            $budgetFilterParams['fiscal_year'] = $yearDate->format('Y');
        }
    }
    if ($selectedFilters['grant_id']) {
        $budgetFilterParams['grant_id'] = $selectedFilters['grant_id'];
    }

    // Apply department filter if selected (Director/Finance see all, Staff filtered in getBudgetsForUser)
    // We pass the selected department ID, and let getBudgetsForUser handle role-specific logic.
    if ($selectedFilters['department_id']) {
         $budgetFilterParams['department_id'] = $selectedFilters['department_id'];
    }
    // Note: getBudgetsForUser needs to handle filtering for 'azwk_staff' based on their department or ownership,
    // and potentially use $financeAccessibleDeptIds if $is_staff_in_finance_here is true.
    // It should also handle the case where $selectedFilters['department_id'] is set for staff (likely ignore it).

    // Fetch budgets based on role and filters.
    // Pass $financeAccessibleDeptIds which is relevant only if $is_staff_in_finance_here is true.
    $budgetsForFilter = getBudgetsForUser($pdo, $currentUserId, $currentUserRole, $financeAccessibleDeptIds, $budgetFilterParams);


    // --- Fetch Allocations based on selected budget filter ---
    if ($selectedFilters['budget_id'] === 'all') {
        // Fetch allocations for ALL budgets visible in the filter dropdown
        $visibleBudgetIds = array_column($budgetsForFilter, 'id');
        if (!empty($visibleBudgetIds)) {
           // We need a new DAL function for this: getAllocationsByBudgetIds
           // This function should now exist in budget_allocations_dal.php
            try {
                $allocations = getAllocationsByBudgetIds($pdo, $visibleBudgetIds);
            } catch (Exception $e) { // Catch general exceptions during fetch
                $error_message = "Error fetching allocations for all budgets: " . $e->getMessage();
                error_log("Error in budgets.php fetching all allocations: " . $e->getMessage());
                $allocations = [];
            }
        } else {
            $allocations = []; // No visible budgets, so no allocations
        }
    } elseif (is_numeric($selectedFilters['budget_id'])) {
        // Fetch allocations for a SINGLE selected budget ID
        $singleBudgetId = (int)$selectedFilters['budget_id'];
        $canViewSelectedBudget = false;
        // Verify the current user actually has permission to view this specific budget_id
        foreach ($budgetsForFilter as $allowedBudget) {
            if ((int)$allowedBudget['id'] === $singleBudgetId) {
                $canViewSelectedBudget = true;
                break;
            }
        }

        if ($canViewSelectedBudget) {
            $allocations = getAllocationsByBudgetId($pdo, $singleBudgetId);
        } else {
            // Only set error if a numeric budget ID was provided and it wasn't allowed
             $error_message = "Permission denied: You do not have access to the selected budget.";
            $selectedFilters['budget_id'] = null; // Clear invalid selection
            $allocations = []; // Ensure allocations is empty if budget is invalid
        }
    } else {
         // No budget selected, or invalid selection (not 'all' and not numeric)
         $allocations = [];
    }

} catch (Exception $e) {
    $error_message = "Error fetching page data: " . $e->getMessage();
    error_log("Error in budgets.php: " . $e->getMessage());
    // Clear potentially sensitive data on error
    $budgetsForFilter = [];
    $allocations = []; // Ensure allocations is empty on error too
}

// POST requests for allocations are handled via AJAX (ajax_allocation_handler.php)
// This block is intentionally removed.

include 'includes/header.php';
?>

<div class="container-fluid mt-4"> <!-- Use container-fluid for wider layout -->
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

    <!-- Filter Form -->
    <form action="budgets.php" method="GET" class="row g-3 align-items-end bg-light p-3 mb-4 rounded border">
        <div class="col-md-3">
            <label for="filter_fiscal_year" class="form-label">Fiscal Year</label>
            <select class="form-select" id="filter_fiscal_year" name="fiscal_year">
                <option value="">All Years</option>
                <?php foreach ($fiscalYears as $fy_start_date):
                    $year = date('Y', strtotime($fy_start_date)); // Extract year
                    $display_year = $year . '-' . ($year + 1); // Display as YYYY-YYYY
                ?>
                    <option value="<?= htmlspecialchars($fy_start_date) ?>" <?= ($selectedFilters['fiscal_year'] == $fy_start_date) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($display_year) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="filter_grant_id" class="form-label">Grant</label>
            <select class="form-select" id="filter_grant_id" name="grant_id">
                <option value="">All Grants</option>
                <?php foreach ($grants as $grant): ?>
                    <option value="<?= $grant['id'] ?>" <?= ($selectedFilters['grant_id'] == $grant['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($grant['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php // Department filter shown only for Director and Finance ?>
        <?php if ($currentUserRole === 'Director' || $currentUserRole === 'Finance'): ?>
        <div class="col-md-3">
            <label for="filter_department_id" class="form-label">Department</label>
            <select class="form-select" id="filter_department_id" name="department_id">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <?php
                        // Finance role now sees all departments, like Director. No filtering needed here.
                        // Staff in Finance might have restrictions, but this filter is only shown to Director/Finance anyway.
                    ?>
                    <option value="<?= $dept['id'] ?>" <?= ($selectedFilters['department_id'] == $dept['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['name']) ?>
                    </option>
                <?php endforeach; ?>
                 <?php // Removed check for Finance role having no assigned departments, as they now see all. ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-md-3">
            <label for="filter_budget_id" class="form-label">Budget</label>
            <select class="form-select" id="filter_budget_id" name="budget_id">
                <option value="">-- Select Budget --</option>
                <option value="all" <?= (isset($_GET['budget_id']) && $_GET['budget_id'] === 'all') ? 'selected' : '' ?>>-- All Budgets --</option> <?php // Check GET directly for 'all' selection ?>
                 <?php // Budgets are loaded based on other filters and role ?>
                 <?php foreach ($budgetsForFilter as $budget): ?>
                    <option value="<?= $budget['id'] ?>"
                            <?= ($selectedFilters['budget_id'] == $budget['id']) ? 'selected' : '' ?>
                            data-budget-type="<?= htmlspecialchars($budget['budget_type']) ?>"
                            data-budget-owner="<?= htmlspecialchars($budget['user_id'] ?? '') ?>"
                            data-budget-dept="<?= htmlspecialchars($budget['department_id'] ?? '') ?>"
                            data-budget-name="<?= htmlspecialchars($budget['name']) ?>">
                        <?= htmlspecialchars($budget['name']) ?> (<?= htmlspecialchars($budget['budget_type']) ?>)
                    </option>
                 <?php endforeach; ?>
                 <?php if (empty($budgetsForFilter) && ($selectedFilters['fiscal_year'] || $selectedFilters['grant_id'] || $selectedFilters['department_id'])): ?>
                     <option value="" disabled>No matching budgets found</option>
                 <?php elseif (empty($budgetsForFilter)): ?>
                     <option value="" disabled>Select other filters first</option>
                 <?php endif; ?>
            </select>
        </div>

        <div class="col-12 mt-3">
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
            <a href="budgets.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear Filters</a>
        </div>
    </form>

    <!-- Add Allocation Button (only shown if a budget is selected and user has permission) -->
    <div class="mb-3" id="addAllocButtonContainer"> <!-- Added ID for testing -->
        <?php
            $showAddButton = false;
            if ($selectedFilters['budget_id']) {
                // Re-check permission to ADD to the *selected* budget
                $selectedBudgetData = null;
                 foreach ($budgetsForFilter as $b) {
                     // Explicit type casting for comparison might help if types differ
                     if ((int)$b['id'] === (int)$selectedFilters['budget_id']) {
                         $selectedBudgetData = $b;
                         break;
                     }
                 }

                if ($selectedBudgetData) {
                    $selectedBudgetType = $selectedBudgetData['budget_type'];
                    $selectedBudgetOwner = $selectedBudgetData['user_id'];
                    $selectedBudgetDept = $selectedBudgetData['department_id'];

                    // Grant permission based on role and context
                    // $is_staff_in_finance_here is now determined at the top of the script
                    if ($currentUserRole === 'director') {
                        $showAddButton = true;
                        error_log("Add button enabled for Director."); // Debug log
                    } elseif ($is_staff_in_finance_here && $selectedBudgetType === 'Admin') {
                        // Staff in Finance can add to 'Admin' type budgets (Department check removed based on Living Plan v1.30 for ADD action)
                        $showAddButton = true;
                        error_log("Add button enabled for Staff in Finance on Admin budget."); // Debug log
                    } elseif ($currentUserRole === 'azwk_staff' && !$is_staff_in_finance_here && $selectedBudgetType === 'Staff' && (int)$selectedBudgetOwner === (int)$currentUserId) { // Regular staff
                        $showAddButton = true;
                        error_log("Add button enabled for Staff owning Staff budget."); // Debug log
                    } elseif ($currentUserRole === 'finance' && $selectedBudgetType === 'Admin') { // Finance role can add to ANY Admin budget
                        $showAddButton = true;
                        error_log("Add button enabled for Finance role on Admin budget."); // Debug log
                    } else {
                         error_log("Add button remains disabled. Role: {$currentUserRole}, StaffInFinance: " . ($is_staff_in_finance_here ? 'Yes' : 'No') . ", BudgetType: {$selectedBudgetType}, Owner: {$selectedBudgetOwner}, CurrentUser: {$currentUserId}, BudgetDept: {$selectedBudgetDept}"); // Debug log
                    }
                }
            }
        ?>
        <?php if ($showAddButton): ?>
        <button type="button" class="btn btn-success" id="addAllocationBtn" data-toggle="modal" data-target="#addAllocationModal"> <!-- Corrected to BS4 attributes -->
            <i class="fas fa-plus"></i> Add Allocation to Budget: <?= htmlspecialchars($selectedBudgetData['name'] ?? '') ?>
        </button>
        <?php elseif ($selectedFilters['budget_id']): ?>
         <button type="button" class="btn btn-success disabled" title="You do not have permission to add allocations to this budget type/owner.">
            <i class="fas fa-plus"></i> Add Allocation
        </button>
        <?php endif; ?>
    </div>

    <!-- Allocations Table -->
    <div class="table-responsive">
        <table id="allocationsTable" class="table table-striped table-bordered table-hover table-sm"> <!-- Added id="allocationsTable" -->
            <thead class="table-dark sticky-top"> <!-- sticky-top for header -->
                <tr>
                    <!-- Common Columns -->
                    <th>Actions</th>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th>Client Name</th> <!-- Added Client Name Column -->
                    <th>Voucher #</th>
                    <th>Explanation</th>
                    <th>Status</th>

                    <!-- Funding Columns (All roles see these, but editability varies) -->
                    <th class="text-end">DW</th>
                    <th class="text-end">DW Admin</th>
                    <th class="text-end">DW SUS</th>
                    <th class="text-end">Adult</th>
                    <th class="text-end">Adult Admin</th>
                    <th class="text-end">Adult SUS</th>
                    <th class="text-end">RR</th>
                    <th class="text-end">H1B</th>
                    <th class="text-end">Youth IS</th>
                    <th class="text-end">Youth OS</th>
                    <th class="text-end">Youth Admin</th>
                    <th class="text-end fw-bold">Total</th> <!-- Calculated Total -->

                    <?php // Additional Core Columns (Finance might hide these by default later) ?>
                    <th>Enroll Date</th>
                    <th>Class Start</th>
                    <th>Purch Date</th>

                    <?php // Finance Specific Columns (Shown for director OR staff in finance dept) ?>
                    <?php if ($currentUserRole === 'director' || $is_staff_in_finance_here): ?>
                        <th>Voucher Rec'd</th>
                        <th>Accrual Date</th>
                        <th>Obligated Date</th>
                        <th>Fin Comments</th>
                        <th>Expense Code</th>
                        <th>Processed By</th>
                        <th>Processed At</th>
                    <?php endif; ?>
                     <th>Created By</th>
                     <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    // Adjust colspan based on whether finance/director columns are shown
                    $colspanValue = ($currentUserRole === 'finance' || $currentUserRole === 'director' ? 31 : 24); // Increased by 1 for Client Name
                ?>
                <?php if (!$selectedFilters['budget_id']): ?>
                    <tr><td colspan="<?= $colspanValue ?>" class="text-center">Please select a budget from the filter above to view allocations.</td></tr>
                <?php elseif (empty($allocations)): ?>
                    <tr><td colspan="<?= $colspanValue ?>" class="text-center">No allocations found for the selected budget.</td></tr>
                <?php else: ?>
                    <?php
                        $grandTotalNonVoid = 0; // Initialize grand total for non-voided items
                    ?>
                    <?php foreach ($allocations as $alloc): ?>
                        <?php
                            $isVoid = ($alloc['payment_status'] ?? 'U') === 'Void';
                            // Calculate total for the row, ONLY if not void
                            $rowTotal = 0;
                            if (!$isVoid) {
                                $rowTotal = (float)($alloc['funding_dw_admin'] ?? 0) + (float)($alloc['funding_dw'] ?? 0) +
                                            (float)($alloc['funding_adult_admin'] ?? 0) + (float)($alloc['funding_adult'] ?? 0) +
                                            (float)($alloc['funding_rr'] ?? 0) + (float)($alloc['funding_youth_is'] ?? 0) +
                                            (float)($alloc['funding_youth_os'] ?? 0) + (float)($alloc['funding_youth_admin'] ?? 0) +
                                            (float)($alloc['funding_dw_sus'] ?? 0) + (float)($alloc['funding_adult_sus'] ?? 0) +
                                            (float)($alloc['funding_h1b'] ?? 0);
                                $grandTotalNonVoid += $rowTotal; // Add to grand total only if not void
                            }


                            // Determine if Edit/Delete buttons should be shown for this row
                            $canEditRow = false;
                            $canDeleteRow = false;
                            $parentBudgetType = $selectedBudgetData['budget_type'] ?? null; // Get type of the currently selected budget
                            $parentBudgetOwner = $selectedBudgetData['user_id'] ?? null;
                            $parentBudgetDept = $selectedBudgetData['department_id'] ?? null;

                            // $is_staff_in_finance_here was calculated earlier for the Add button logic
                            // --- Determine Row Action Permissions ---
                            // Logic runs for every row based on user role and the specific allocation's data.
                            if ($currentUserRole === 'director') {
                                $canEditRow = true;
                                $canDeleteRow = true; // Directors can delete
                            } elseif ($currentUserRole === 'azwk_staff') {
                                if ($is_staff_in_finance_here) { // Staff who ARE in Finance Dept
                                     // Staff in Finance Dept can edit both Staff and Admin budgets (field restrictions apply in modal/handler)
                                     $canEditRow = true;
                                     // Allow delete ONLY if the parent budget type is 'Admin' (Living Plan v1.30)
                                     $canDeleteRow = ($parentBudgetType === 'Admin');
                                } else { // Regular AZ@Work Staff (NOT in Finance)
                                    // This check still uses $parentBudgetType and $parentBudgetOwner from the *selected filter*
                                    // Assumes staff should only edit/delete if their own budget is selected in the filter.
                                    if ($parentBudgetType === 'Staff' && (int)$parentBudgetOwner === (int)$currentUserId) {
                                        $canEditRow = true;
                                        $canDeleteRow = true; // Staff can delete allocations on their own Staff budgets
                                    }
                                    // Note: If staff should *always* be able to edit/delete items from their own budgets
                                    // regardless of filter selection, this logic needs changing to fetch the allocation's
                                    // specific budget type and owner.
                                }
                            } elseif ($currentUserRole === 'finance') { // Dedicated 'finance' role
                                 // Finance role now has universal access. Edit allowed, Delete forbidden.
                                 $canEditRow = true;
                                 $canDeleteRow = false; // Cannot delete per Living Plan
                            }
                            // Add administrator role check if needed:
                            // elseif ($currentUserRole === 'administrator') {
                            //     $canEditRow = true;
                            //     $canDeleteRow = true;
                            // }
                            // --- End Determine Row Action Permissions ---
                        ?>
                        <tr class="<?= $isVoid ? 'allocation-void' : '' ?>"> <?php // Add class if void ?>
                            <!-- Actions -->
                            <td class="text-nowrap">
                                <?php if ($canEditRow && !$isVoid): // Disable edit if void? Or allow editing status back? Assuming no edit on void for now. ?>
                                <button type="button" class="btn btn-xs btn-warning me-1 edit-alloc-btn"
                                        title="Edit Allocation"
                                        data-bs-toggle="modal" data-bs-target="#editAllocationModal"
                                        data-allocation-id="<?= $alloc['id'] ?>"
                                        data-budget-id="<?= $alloc['budget_id'] ?>"
                                        <?php // Add all other alloc fields as data-* attributes for modal population ?>
                                        >
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($canDeleteRow && !$isVoid): // Disable delete if void? Assuming no delete on void for now. ?>
                                <button type="button" class="btn btn-xs btn-danger delete-alloc-btn"
                                        title="Delete Allocation"
                                        data-allocation-id="<?= $alloc['id'] ?>">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                <?php endif; ?>
                            </td>

                            <!-- Core Fields -->
                            <td><?= htmlspecialchars($alloc['transaction_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($alloc['vendor_name'] ?? 'N/A') ?></td> <!-- Use vendor_name -->
                            <td><?= htmlspecialchars($alloc['client_name'] ?? '') ?></td> <!-- Added client_name -->
                            <td><?= htmlspecialchars($alloc['voucher_number'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($alloc['program_explanation'] ?? '')) ?></td>
                            <td>
                                <?php
                                    $status = $alloc['payment_status'] ?? 'U';
                                    $badgeClass = 'bg-warning'; // Default Unpaid
                                    $statusText = 'Unpaid';
                                    if ($status === 'P') {
                                        $badgeClass = 'bg-success';
                                        $statusText = 'Paid';
                                    } elseif ($status === 'Void') {
                                        $badgeClass = 'bg-secondary'; // Use secondary for void
                                        $statusText = 'Void';
                                    }
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= $statusText ?></span>
                            </td>

                            <!-- Funding Fields -->
                            <td class="text-end"><?= number_format($alloc['funding_dw'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_dw_admin'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_dw_sus'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_adult'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_adult_admin'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_adult_sus'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_rr'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_h1b'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_youth_is'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_youth_os'] ?? 0, 2) ?></td>
                            <td class="text-end"><?= number_format($alloc['funding_youth_admin'] ?? 0, 2) ?></td>
                            <td class="text-end fw-bold"><?= number_format($rowTotal, 2) ?></td>

                             <!-- Additional Core -->
                            <td><?= htmlspecialchars($alloc['enrollment_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($alloc['class_start_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($alloc['purchase_date'] ?? '') ?></td>

                            <?php // Finance Specific Columns (Shown for director OR staff in finance dept) ?>
                            <?php if ($currentUserRole === 'director' || $is_staff_in_finance_here): ?>
                                <td><?= htmlspecialchars($alloc['fin_voucher_received'] ?? '') ?></td>
                                <td><?= htmlspecialchars($alloc['fin_accrual_date'] ?? '') ?></td>
                                <td><?= htmlspecialchars($alloc['fin_obligated_date'] ?? '') ?></td>
                                <td><?= nl2br(htmlspecialchars($alloc['fin_comments'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($alloc['fin_expense_code'] ?? '') ?></td>
                                <td><?= htmlspecialchars($alloc['processed_by_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($alloc['fin_processed_at'] ?? '') ?></td>
                            <?php endif; ?>
                             <td><?= htmlspecialchars($alloc['created_by_name'] ?? '') ?></td>
                             <td><?= htmlspecialchars($alloc['updated_by_name'] ?? '') ?></td>

                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
             <?php if (!empty($allocations)): ?>
             <tfoot>


<!-- Pass PHP variables to JavaScript -->
<script>
  window.APP_DATA = {
    currentUserRole: <?= json_encode($currentUserRole ?? 'unknown') ?>,
    currentUserId: <?= json_encode($currentUserId ?? null) ?>,
    currentUserDeptSlug: <?= json_encode($currentUserDeptSlug ?? null) ?>,
    isStaffInFinance: <?= json_encode($is_staff_in_finance_here ?? false) ?>
    // Add other necessary data here if needed
  };
</script>

                <tr>
                    <td colspan="18" class="text-end fw-bold">Total (Non-Voided):</td>
                    <td class="text-end fw-bold"><?= number_format($grandTotalNonVoid, 2) ?></td>
                    <td colspan="<?= $colspanValue - 2 ?>"></td> <?php // Corrected colspan: Total columns minus the 2 cells used for label and total value ?>
                </tr>
             </tfoot>
             <?php endif; ?>
        </table>
    </div>

</div> <!-- /container-fluid -->


<?php
// Include Modal Files
include 'includes/modals/add_allocation_modal.php';
include 'includes/modals/edit_allocation_modal.php';
?>


<!-- Pass PHP data to JavaScript -->
<script>
    window.APP_DATA = {
        currentUserRole: <?php echo json_encode($_SESSION['active_role'] ?? 'unknown'); ?>,
        currentUserId: <?php echo json_encode($_SESSION['user_id'] ?? null); ?>,
        // Add other necessary data here, e.g., finance accessible departments if needed directly in JS
        financeAccessibleDeptIds: <?php echo json_encode($financeAccessibleDeptIds ?? []); ?>,
        // Add current user's department slug if available
        currentUserDeptSlug: <?php
            $userDeptSlugForJs = null;
            if (isset($currentUserId)) {
                try {
                    // Ensure user_data.php and department_data.php are included earlier in the file
                    $userDataForJs = getUserById($pdo, $currentUserId);
                    if (!empty($userDataForJs['department_id'])) {
                        $deptDataForJs = getDepartmentById($pdo, $userDataForJs['department_id']);
                        $userDeptSlugForJs = $deptDataForJs['slug'] ?? null;
                    }
                } catch (Exception $e) { /* Ignore error, slug remains null */ error_log("Error fetching user dept slug for JS in budgets.php: " . $e->getMessage()); }
            }
            echo json_encode($userDeptSlugForJs);
        ?>
    };
</script>


<?php include 'includes/footer.php'; ?>

<!-- Add custom JS for modal population and AJAX interactions -->