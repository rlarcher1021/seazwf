Arizona@Work Check-In System - Living Project Plan
Version: 1.32
Date: 2025-04-25
(This Living Plan document is the "Single Source of Truth". User Responsibility: Maintain locally, provide current code, request plan updates. Developer (AI) Responsibility: Use plan/code context, focus on single tasks, provide code, assist plan updates.)
1. Project Goal:
Develop a web-based check-in and tracking system for Arizona@Work sites.
Implement role-based access control (RBAC) using User Roles in combination with Department assignments to manage feature access.
Incorporate a comprehensive Budget tracking module to replace manual spreadsheets for AZ@Work and integrate Finance Department processing, including Vendor management.
Enhance features like Forum, AI Resume analysis, Notifications, and Reporting.
2. System Roles and Access Control (Budget Module Focus):
Core Roles Involved: azwk_staff, Director, Administrator.
Core Departments Involved: AZ@Work operational departments, Finance Department.
Permission Logic: Access and actions within the Budget Module are determined by the user's assigned Role AND their assigned Department (from the users table, stored in session).
Kiosk: Limited access for client self-check-in. (No budget module access).
AZ@Work Staff (azwk_staff) assigned to an AZ@Work Department:
General: Check-in processing, client interaction logging, forum participation.
Budget Setup: No access to budget_settings.php.
Budget Allocations View (budgets.php): Can view allocations only for 'Staff' type budgets explicitly assigned to them via budgets.user_id. Sees 'core' staff-related columns only.
Budget Allocations Actions: Add (assigned Staff), Edit (assigned Staff - staff fields only), No Delete, No Void.
AZ@Work Staff (azwk_staff) assigned to the Finance Department:
General: Forum participation, potentially other non-budget tasks.
Budget Setup: No access to budget_settings.php.
Budget Allocations View (budgets.php): Can view allocations across ALL Departments. Sees ALL columns (staff-side and fin_*).
Budget Allocations Actions: Add (Admin only), Edit (Staff - fin_* only; Admin - all fields), Delete (Admin only), No Void.
Outside Staff: Permissions TBC. Likely no budget access.
Director (AZ@Work):
General: Full oversight within AZ@Work context.
Budget Setup (budget_settings.php): Full CRUD access.
Budget Allocations View (budgets.php): Can view allocations across AZ@Work context. Sees ALL columns (staff-side and fin_*).
Budget Allocations Actions: Add (Staff), Edit (Staff - staff fields only), No Delete, Can Void.
Administrator: System-wide configuration, user management. Full access.
3. Authentication System:
Functionality: Login, Hashing, Sessions, RBAC (Role+Dept), last_login. Login filters u.deleted_at IS NULL.
Session Data: Stores user_id, role, site_context, department_id, department_slug. Critical for permissions.
Status: Session Timeout, CSRF Protection IMPLEMENTED.
4. Site Context Handling for Admin/Director:
Implemented via session context variable (site_context).
5. Page Specifications & Functionality:
General: UI Refactoring. Soft Deletes (deleted_at). Bootstrap v4.x.
Settings Consolidation (budget_settings.php): Director access. Tabs for Grants, Budgets, Vendors. CRUD via AJAX.
budget_settings_panels/: Includes for tabs (grants_panel.php, budgets_panel.php, vendors_panel.php). budgets_panel.php handles Admin type (NULL user_id).
Budget Allocations Page (budgets.php):
Access: Director, azwk_staff (permissions vary by Role+Dept).
Filtering: Dynamic dropdowns (FY, Grant, Dept, Budget) via AJAX based on permissions.
Display Table: HTML table showing allocations. Data query MUST JOIN users table twice (using aliases) on created_by_user_id and updated_by_user_id to fetch user names. Columns for "Created By" and "Last Updated By" (or "Processed By") MUST display the user's full name (e.g., "First Last") instead of the user ID. Includes client_name, visually distinct 'Void' rows, 'Total' excludes voided.
Column Visibility: Dynamically shown based on Role+Dept (Director/azwk_staff-Finance Dept see ALL; azwk_staff-AZ@Work Dept see CORE).
Action Buttons (Edit/Delete/Void): Visibility depends on Role+Dept+Budget Type.
Allocation Modals (includes/modals/add_allocation_modal.php, includes/modals/edit_allocation_modal.php):
Select2 Vendor dropdown (vendor_id), conditional client_name, Void option (Director only).
Field Editability: Dynamically controlled based on User Role + User Department + Budget Type (per Section 2). Enforced server-side.
AJAX Handlers:
ajax_get_budgets.php: Fetch budget list for filters.
ajax_allocation_handler.php: Handle Add/Edit/Delete/Void. Enforces Role+Department+Budget Type permissions strictly (per Section 2). Validation, DAL calls, JSON response, CSRF protected.
ajax_handlers/vendor_handler.php: Vendor CRUD AJAX.
Includes: header.php, footer.php, modals/. JS in footer/assets for AJAX, Select2, conditional logic, modal field editability.
Other Pages: Standardized UI where applicable. Permissions based on Role/Dept.
6. Database Schema (MySQL):
roles: Contains 'azwk_staff', 'Director', 'Administrator'. No 'finance' role used for budgets.
departments: Includes AZ@Work depts and 'Finance' dept. Has slug, deleted_at.
users: Includes deleted_at, FK role_id, FK department_id. Requires first_name, last_name columns for display.
grants: Standard grant info, deleted_at.
budgets: Links Grant, Dept, FY. Includes budget_type ('Staff'/'Admin'), user_id (allows NULL), deleted_at.
finance_department_access: Not used for budget control.
vendors: Includes client_name_required, is_active, deleted_at.
budget_allocations: Links to budgets. Includes vendor_id, client_name, payment_status, fin_* fields, created_by_user_id, updated_by_user_id, deleted_at.
Other Tables: As previously defined.
Views: active_departments view.
7. File Management Structure:
Root: budget_settings.php, budgets.php, ajax_get_budgets.php, ajax_allocation_handler.php.
ajax_handlers/: Contains vendor_handler.php. Consider moving ajax_allocation_handler.php, ajax_get_budgets.php here.
budget_settings_panels/: grants_panel.php, budgets_panel.php, vendors_panel.php.
config/: Database connection, constants.
includes/: Header, footer, modals, helper functions.
data_access/: PHP classes/functions for DB interaction (DAL). E.g., budget_data.php, user_data.php, department_data.php, vendor_data.php, grant_data.php.
config_panels/: Include files for system configuration page.
assets/: CSS, JS, images.
css/main.css: Custom styles, including voided rows.
js/budgets.js: Specific JS for budget pages (AJAX, Select2, conditional logic, modal field editability based on Role+Dept).
8. Design Specification:
Core layout uses Bootstrap v4.x. UI Refactoring ongoing for consistency.
budget_settings.php uses Bootstrap Tabs.
Allocation modals use Select2 for searchable vendor dropdown.
Allocation table visually distinguishes 'Void' rows.
Allocation modals dynamically control field editability based on Role+Department+Budget Type.
Allocation table (budgets.php) dynamically shows/hides columns based on Role+Department.
9. Technical Specifications:
PHP 8.4, MySQL on GoDaddy.
JavaScript (ES6+), Select2 library, AJAX.
HTML5, CSS3, Bootstrap 4.x.
Case-Insensitive Checks: Apply to roles, department identifiers, site identifiers.
10. Security Considerations:
Strict Role+Department permissions enforced server-side.
Server-side validation. CSRF protection. Prepared statements. Soft deletes. Session management.
API Readiness: All new development and changes must prioritize security and consider secure API design principles (e.g., robust validation, clear permission scoping, potential for token-based auth) to facilitate future AI agent integration.
Remaining: Upload Dir Hardening review, XSS review (forums), FK error handling.
11. Compliance and Legal:
Consider data retention policies, especially regarding soft-deleted vs. hard-deleted data.
Review compliance requirements for storing/managing financial and potentially PII data (client names if linked).
12. Current Status & Next Steps:
Completed/Resolved Since v1.31:
User Name Display implemented: Allocations table (budgets.php) now joins users table and displays user names instead of IDs for "Created By" / "Last Updated By". (Includes fix for blank columns reported by dev).
Previous Completions (v1.30 & earlier):
Role+Department permission model (v1.30) implemented.
Initial testing of v1.30 logic reported as "looks good".
XAMPP environment issue resolved (DB table repaired).
Admin Budget creation fixed (DB user_id allows NULL).
Current Status: User name display enhancement is implemented. Awaiting final testing of this feature and comprehensive testing of all budget module functionality.
Known Issues: None reported currently, pending further testing.
Remaining Tasks (Budget Feature - Developer):
No outstanding development tasks currently assigned. Awaiting testing feedback.
Remaining Tasks (General):
Test User Name Display: Verify names appear correctly in "Created By" / "Last Updated By" columns on budgets.php.
Complete Comprehensive Testing: Perform final, thorough manual testing of all roles/departments/workflows/permissions/UI elements related to the entire Budget Module (Grants, Budgets setup, Vendors, Allocations - including column visibility, actions, modal editability).
Report any bugs or discrepancies found during testing.
Human Visual Design Review (Post UI Refactoring).
Functionality Testing (Post UI Refactoring).
Security - Confirm Upload Dir Hardening.
FK Error Handling (PHP - try/catch).
Code Review/Refinement.
Documentation Updates (User Manual reflecting final workflows).
Immediate Next Step: User (Robert) to perform comprehensive testing of the Budget Module, including the new user name display, and report findings.
13. Future Enhancements (Post-MVP):
Secure API Layer for AI Integration (Prerequisite).
Budget Excel Upload/Import.
UI for managing roles/permissions/department assignments.
Bootstrap 5 Upgrade.
AI Summarization/Analysis. Microphone Input. Advanced Reporting. Notifications/Alerts. Profile Pictures.
14. Implementation Plan & Process:
Use Living Plan as Single Source of Truth.
Priority: Security and API readiness principles guide all development.
Focus AI Developer on single tasks. Prioritize backend logic then UI.
Require clear code comments.
User (Robert) responsible for context, testing, reporting, plan requests.
PM (AI) responsible for plan maintenance, context, task breakdown.
This comprehensive v1.32 plan reflects the latest status. 