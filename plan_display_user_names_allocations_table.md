# Plan: Display User Names in Allocations Table

**Objective:** Modify the budgets.php page to display the full names of the users who created and last updated each budget allocation, instead of their numeric user IDs.

**Current State:**
- The `budget_allocations` table stores `created_by_user_id` and `updated_by_user_id`.
- The `users` table stores user details, including `full_name`.
- The data access functions `getAllocationsByBudget` and `getAllocationsByBudgetList` in `data_access/budget_data.php` already join the `users` table and select the `full_name` with aliases `created_by_name` and `updated_by_name`.
- The `budgets.php` file currently iterates through the fetched allocations and displays user IDs in the table.

**Proposed Changes:**

1.  **Modify `budgets.php`:**
    *   Locate the HTML table body (`<tbody>`) within `budgets.php`.
    *   Find the table cells (`<td>`) that currently display the `created_by_user_id` and `updated_by_user_id`.
    *   Update these table cells to display the values from the `created_by_name` and `updated_by_name` keys of the allocation data array (`$alloc`).

**Detailed Steps:**

1.  **Read `budgets.php`:** Use the `read_file` tool to get the content of `budgets.php`. (Already done in previous step).
2.  **Identify Table Body and User ID Display:** Analyze the content of `budgets.php` to find the `<tbody>` section and the specific lines where `created_by_user_id` and `updated_by_user_id` are echoed or printed within table cells.
3.  **Construct `apply_diff`:** Create an `apply_diff` tool request to replace the lines displaying the user IDs with lines displaying the `created_by_name` and `updated_by_name` values.
4.  **Apply Changes:** Execute the `apply_diff` tool request.
5.  **Verify Implementation:** Confirm that the changes were applied successfully.
6.  **Update Living Plan:** Update the "Arizona@Work Check-In System - Living Plan.php" file to reflect that the "Implement User Name Display" task (Section 12, Remaining Tasks) is completed.
7.  **Attempt Completion:** Use the `attempt_completion` tool to inform the user that the task is complete and the table should now display user names.

**Mermaid Diagram:**

```mermaid
graph TD
    A[Start Task] --> B{Read Living Plan and DB Schema};
    B --> C{Analyze data_access/budget_data.php};
    C --> D{Confirm user names are fetched};
    D --> E{Read budgets.php};
    E --> F{Identify table body and user ID display};
    F --> G{Construct apply_diff for budgets.php};
    G --> H{Apply changes to budgets.php};
    H --> I{Verify changes};
    I --> J{Update Living Plan};
    J --> K{Attempt Completion};
    K --> L[End Task];