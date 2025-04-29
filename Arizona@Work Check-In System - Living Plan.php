Arizona@Work Check-In System - Living Project Plan
Version: 1.36
Date: 2025-04-29
(This Living Plan document is the "Single Source of Truth". User Responsibility: Maintain locally, provide current code, request plan updates. Developer (AI) Responsibility: Use plan/code context, focus on single tasks, provide code, assist plan updates.)
1. Project Goal:
Develop a web-based check-in and tracking system for Arizona@Work sites. Implement role-based access control (RBAC) using User Roles in combination with Department assignments to manage feature access. Incorporate a comprehensive Budget tracking module. Develop a secure API layer to enable programmatic access for external systems (e.g., AI agents) and future features, with initial endpoints tested and verified. Enhance features like Forum, AI Resume analysis, Notifications, and Reporting.
2. System Roles and Access Control (Web UI - Budget Module Focus):
Core Roles Involved: azwk_staff, Director, Administrator (as defined in users.role enum).
Core Departments Involved: AZ@Work operational departments, Finance Department (referenced by users.department_id FK to departments.id).
Permission Logic: Access and actions within the Budget Module (via Web UI) are determined by the user's assigned Role AND their assigned Department (from the users table, stored in session).
Kiosk: Limited access role. (No budget module access).
AZ@Work Staff (azwk_staff) assigned to an AZ@Work Department: Permissions as defined previously (Web UI - Add/Edit assigned Staff budgets, staff fields only, see core columns).
AZ@Work Staff (azwk_staff) assigned to the Finance Department: Permissions as defined previously (Web UI - Add Admin allocations, Edit Staff fin_* fields, Edit Admin all fields, Delete Admin allocations, see all columns).
Outside Staff: Role exists, permissions TBC. Likely no budget access.
Director (director): Permissions as defined previously (Web UI - Full Budget Setup, Add/Edit Staff allocations staff fields, Void allocations, see all columns).
Administrator (administrator): System-wide configuration, user management. Full access.
3. Authentication System:
Web UI:
Functionality: Login, Hashing (password_hash field in users), Sessions, RBAC (Role+Dept), last_login. Login filters u.deleted_at IS NULL.
Session Data: Stores user_id, role, site_context, department_id, department_slug. Critical for permissions.
Status: Session Timeout, CSRF Protection IMPLEMENTED.
API (NEW): See Section 5 - API Specification. Requires separate token-based authentication (API Keys). Authentication and initial authorization layers tested and confirmed functional.
4. Site Context Handling for Admin/Director:
Implemented via session context variable (site_context) potentially linking to users.site_id. Allows Directors/Admins with appropriate roles/permissions to switch between site views if the application supports multiple AZ@Work sites and the user has privileges across them. Affects filtering and data visibility in the Web UI. (Note: API data scoping uses API Key permissions and associated IDs, see Section 5).
5. API Specification (V1 - Initial Scope):
Goal: Provide secure, documented, programmatic access to application functions and data for authorized external systems (e.g., AI agents).
Base URL: /api/v1/ (Tested on https://seazwf.com/api/v1/)
Data Format: JSON (Content-Type: application/json).
Authentication: API Keys (Hashed in api_keys table). Sent via Authorization: Bearer <key> or X-API-Key: <key> header. Server validates using password_verify(). Validation confirmed functional.
Authorization: Based on associated_permissions (TEXT field storing comma-separated or JSON list) stored with the API key. Principle of least privilege. Each endpoint verifies key permissions. Data scope for some endpoints (e.g., reports) may be further limited by associated_user_id or associated_site_id on the api_keys table based on the permissions granted (e.g., read:site_checkin_data vs read:all_checkin_data). Initial endpoint authorization confirmed functional.
Error Handling: Standard HTTP status codes (200, 201, 400, 401, 403, 404, 500). JSON error body {"error": {"code": "...", "message": "..."}}. Confirmed functional.
API Endpoints:
Fetch Check-in Details: (Successfully Tested & Verified)
Method: GET
Path: /checkins/{checkin_id}
Auth Required: Yes. Permission Needed: read:checkin_data (Verified)
Response (200 OK): JSON object of the specific check-in record.
Response (404 Not Found): If checkin_id does not exist.
Add Check-in Note: (Successfully Tested & Verified)
Method: POST
Path: /checkins/{checkin_id}/notes
Auth Required: Yes. Permission Needed: create:checkin_note (Verified)
Request Body: {"note_text": "Text of the note..."}
Response (201 Created): JSON object of the created note (from checkin_notes table, including created_by_api_key_id).
Response (400 Bad Request): If note_text missing/invalid.
Response (404 Not Found): If checkin_id does not exist.
Query Allocations: (Successfully Tested & Verified)
Method: GET
Path: /allocations
Auth Required: Yes. Permission Needed: read:budget_allocations (Verified)
Query Parameters (Examples): fiscal_year=YYYY, grant_id=X, department_id=Y, budget_id=Z, page=1, limit=50.
Response (200 OK): JSON object containing an array of allocation objects and pagination details.
Create Forum Post: (Successfully Tested & Verified)
Method: POST
Path: /forum/posts
Auth Required: Yes. Permission Needed: create:forum_post (Verified)
Request Body: {"topic_id": 123, "post_body": "Content of the post..."}
Response (201 Created): JSON object of created post (including created_by_api_key_id).
Response (400 Bad Request): Missing/invalid fields.
Response (404 Not Found): If topic_id does not exist.
Generate Reports: (NEW Specification - Implementation Pending)
Method: GET
Path: /reports
Auth Required: Yes. Base Permission Needed: generate:reports. Additional scope permissions required based on report type.
Mandatory Query Parameter:
type={report_type}: Specifies the report to generate. Initial types: checkin_detail, allocation_detail.
Common Optional Query Parameters:
start_date=YYYY-MM-DD
end_date=YYYY-MM-DD
limit=50 (Default TBD)
page=1 (Default 1)
Report Type: checkin_detail
Description: Returns detailed list of check-in records.
Scope Permissions Required:
read:all_checkin_data: Allows access to all check-ins. Optional site_id={site_id} query parameter can be used to filter.
read:site_checkin_data: Restricts access to check-ins for the specific site defined in the API key's associated_site_id field. API automatically applies this filter; site_id query parameter is ignored/rejected. Key must have associated_site_id set.
Response (200 OK): JSON object with pagination and array of check-in objects matching the scope and filters.
Report Type: allocation_detail
Description: Returns detailed list of budget allocation records.
Scope Permissions Required:
read:all_allocation_data: Allows access to all allocations. Optional query parameters (site_id, department_id, grant_id, budget_id, user_id - filtering by budget owner) can be used.
read:own_allocation_data: Restricts access to allocations for budgets directly assigned to the user defined in the API key's associated_user_id field (budgets.user_id). API automatically applies this filter; user_id query parameter is ignored/rejected. Key must have associated_user_id set. Other optional filters like budget_id (within their scope) can be used.
Response (200 OK): JSON object with pagination and array of allocation objects matching the scope and filters.
Error Responses: 400 (Missing/invalid type), 403 (Missing generate:reports or required scope permission, or required associated ID not set on key), 500 (Server error during report generation).
6. Page Specifications & Functionality (Web UI):
General: Ongoing UI Refactoring for consistency. Standardize on Soft Deletes (deleted_at timestamp) for relevant data. Use Bootstrap v4.x (CSS and JS) consistently until a dedicated v5 upgrade project.
Settings Consolidation (budget_settings.php):
Access: Restricted to users with the 'Director' role.
UI: Single page using Bootstrap Tabs for navigation ("Grants", "Budgets", "Vendors"). Content loaded dynamically via includes.
Functionality: Provides interface for Directors to perform CRUD operations on Grants, Budgets (including setting type 'Staff'/'Admin', linking to Departments including Finance, assigning 'Staff' type to users), and Vendors. All operations via AJAX.
budget_settings_panels/ Directory: Contains include files for the tabs (grants_panel.php, budgets_panel.php, vendors_panel.php). budgets_panel.php handles Admin budget type (NULL user_id).
Budget Allocations Page (budgets.php):
Access: Accessible by Director and azwk_staff roles. Data visibility and actions vary based on Role + Department.
Filtering: Dropdowns for Fiscal Year, Grant, Department, Budget. Options populated dynamically via AJAX based on user permissions (Role + Department). azwk_staff (Finance Dept) and Directors can filter across all departments. azwk_staff (AZ@Work Dept) filtering may be limited to their assigned budgets/context.
Display Table: HTML table showing allocation records. Fetches data via PHP joining relevant tables. Query MUST JOIN users table twice (using aliases) on created_by_user_id and updated_by_user_id. Columns for "Created By" and "Last Updated By" MUST display the user's full name (users.full_name) instead of the user ID. Includes client_name, visually distinct 'Void' rows, 'Total' excludes voided.
Column Visibility: Dynamically shown based on Role+Dept (Director/azwk_staff-Finance Dept see ALL; azwk_staff-AZ@Work Dept see CORE).
Action Buttons (Edit/Delete/Void): Visibility depends on Role+Dept+Budget Type.
Allocation Modals (includes/modals/add_allocation_modal.php, includes/modals/edit_allocation_modal.php):
Select2 Vendor dropdown (vendor_id), conditional client_name, Void option (Director only).
Field Editability: Dynamically controlled based on User Role + User Department + Budget Type (per Section 2). Enforced server-side.
AJAX Handlers (Internal Web UI Use):
ajax_get_budgets.php: Fetch budget list for filters.
ajax_allocation_handler.php: Handle Add/Edit/Delete/Void. Enforces Role+Department+Budget Type permissions strictly (per Section 2). Validation, DAL calls, JSON response, CSRF protected.
ajax_handlers/vendor_handler.php: Vendor CRUD AJAX.
Includes: header.php, footer.php, modals/. JS in footer/assets for AJAX, Select2, conditional logic, modal field editability.
Other Pages: configurations.php, checkin.php, account.php, users.php, index.php, reports.php, notifications.php, alerts.php, ajax_report_handler.php, dashboard.php, config_panels/*.php, ajax_chat_handler.php. Standardized UI where applicable. Permissions based on Role/Dept as needed.
Web UI Reporting (reports.php): Review required to ensure conceptual alignment with data scoping/filtering capabilities defined for the API Reports (See Section 13 - Remaining Tasks).
7. Database Schema (MySQL):
(Structure based on user-provided schema dump dated approx 2025-04-22, planned API additions, and applied modifications. AUTO_INCREMENT details omitted. Standard indexes like PRIMARY assumed unless noted. Foreign key relationships described.)
ai_resume: (id, client_name, email, user_id (FK->users), job_applied, threadsID, request, status, request_status (ENUM), created_at)
ai_resume_logs: (id, resume_id (FK->ai_resume), event, details, created_at)
ai_resume_val: (id, name, email (Unique), user_id (FK->users), site, signup_time, created_at)
api_keys: (MODIFIED)
Comments: Stores API keys. associated_user_id / associated_site_id support scoped permissions.
Columns: id (Primary), key_hash (Unique), description, associated_permissions (TEXT), created_at, last_used_at (Timestamp, Null), is_active (TINYINT, Def: 1), associated_user_id (INT, Null, FK -> users), associated_site_id (INT, Null, FK -> sites).
Indexes: PRIMARY, idx_key_hash_unique, idx_api_keys_active, fk_api_key_user, fk_api_key_site.
Foreign Keys: associated_user_id -> users(id) (ON DELETE SET NULL), associated_site_id -> sites(id) (ON DELETE SET NULL).
budgets: (id, name, user_id (FK->users, Null), grant_id (FK->grants), department_id (FK->departments), fiscal_year_start, fiscal_year_end, budget_type (ENUM 'Staff','Admin'), notes, created_at, updated_at, deleted_at)
budget_allocations: (id, budget_id (FK->budgets), transaction_date, vendor_id (FK->vendors, Null), client_name, voucher_number, enrollment_date, class_start_date, purchase_date, payment_status (ENUM 'P','U','Void'), program_explanation, funding_, fin_, fin_processed_by_user_id (FK->users, Null), fin_processed_at, created_by_user_id (FK->users), updated_by_user_id (FK->users, Null), created_at, updated_at, deleted_at)
check_ins: (id, site_id (FK->sites), first_name, last_name, check_in_time, notified_staff_id (FK->users, Null), client_email, q_* fields...)
checkin_notes: (Confirmed Structure)
Comments: Stores notes for check-ins. created_by_api_key_id tracks API creation.
Columns: id (Primary), check_in_id (FK->check_ins), note_text (TEXT), created_by_user_id (FK->users, Null), created_by_api_key_id (FK->api_keys, Null), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, idx_checkin_notes_checkin_id, fk_checkin_note_user_creator_idx, fk_checkin_note_api_creator_idx.
Foreign Keys: check_in_id -> check_ins(id), created_by_user_id -> users(id), created_by_api_key_id -> api_keys(id).
departments: (id, name (Unique), slug (Unique), created_at, deleted_at)
finance_department_access: (id, finance_user_id (FK->users), accessible_department_id (FK->departments), created_at) - Legacy? Budget access now Role+Dept.
forum_categories: (id, name, description, view_role (ENUM), post_role (ENUM), reply_role (ENUM), display_order, created_at)
forum_posts: (Confirmed Structure)
Comments: Forum messages. created_by_api_key_id tracks API creation.
Columns: id (Primary), topic_id (FK->forum_topics), user_id (FK->users, Null), created_by_api_key_id (FK->api_keys, Null), content (TEXT), created_at, updated_at, updated_by_user_id (FK->users, Null).
Indexes: PRIMARY, fk_forum_posts_topic_idx, fk_forum_posts_user_idx, fk_forum_posts_api_creator_idx.
Foreign Keys: topic_id -> forum_topics(id), user_id -> users(id), updated_by_user_id -> users(id), created_by_api_key_id -> api_keys(id).
forum_topics: (id, category_id (FK->forum_categories), user_id (FK->users, Null), title, is_sticky, is_locked, created_at, last_post_at, last_post_user_id (FK->users, Null))
global_ads: (id, ad_type (ENUM), ad_title, ad_text, image_path, is_active, created_at, updated_at)
global_questions: (id, question_text (TEXT), question_title (Unique), created_at)
grants: (id, name (Unique), grant_code (Unique), description, start_date, end_date, created_at, updated_at, deleted_at)
sites: (id, name, email_collection_desc, is_active)
site_ads: (id, site_id (FK->sites), global_ad_id (FK->global_ads), display_order, is_active, created_at, updated_at)
site_configurations: (site_id (Primary, FK->sites), config_key (Primary), config_value (TEXT), created_at, updated_at)
site_questions: (id, site_id (FK->sites), global_question_id (FK->global_questions), display_order, is_active, created_at)
staff_notifications: (id, site_id (FK->sites), staff_name, staff_email, is_active)
users: (id, username (Unique), full_name, email (Unique), job_title, department_id (FK->departments, Null), password_hash, role (ENUM 'kiosk','azwk_staff','outside_staff','director','administrator'), site_id (FK->sites, Null), last_login, is_active, created_at, deleted_at)
vendors: (id, name (Unique), client_name_required, is_active, created_at, deleted_at)
8. File Management Structure:
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
api/v1/: Directory for API entry point (index.php), includes (auth_functions.php), data access (allocation_data_api.php, forum_data_api.php etc.), potentially handlers (report_handler.php).
9. Design Specification (Web UI):
Core layout uses Bootstrap v4.x. UI Refactoring ongoing for consistency. budget_settings.php uses Bootstrap Tabs. Allocation modals use Select2 for searchable vendor dropdown. Allocation table visually distinguishes 'Void' rows. Allocation modals dynamically control field editability based on Role+Department+Budget Type. Allocation table (budgets.php) dynamically shows/hides columns based on Role+Department.
10. Technical Specifications:
PHP 8.4, MySQL/MariaDB on GoDaddy. JavaScript (ES6+), Select2 library, AJAX (Fetch API or jQuery). HTML5, CSS3, Bootstrap 4.x. Case-Insensitive Checks: Required for comparisons involving user roles, department identifiers, site identifiers. API uses a basic routing mechanism within api/v1/index.php.
11. Security Considerations:
Strict Role+Department permissions enforced server-side for Web UI actions.
API Key + Associated Permissions (including scope via associated_user_id/site_id where applicable) enforced server-side for API actions. Permissions confirmed matching plan for initial endpoints.
Server-side validation for all form submissions and API inputs.
CSRF protection implemented on all Web UI forms and relevant AJAX handlers.
Use of prepared statements (PDO or MySQLi) for all database queries. API confirmed using PDO, connection issues resolved.
Soft delete logic (deleted_at column) implemented for relevant data.
Secure Session Management practices for Web UI.
API Security Best Practices: Hashing API keys, use of HTTPS, potential rate limiting, input sanitization, output encoding. HTTPS confirmed necessary for API function.
API Readiness & Security Focus: Top priority for all current and future development efforts.
Remaining Review Items: Upload Directory Hardening, Cross-Site Scripting (XSS) review (especially forums, user-generated content), Foreign Key error handling (implement try/catch in PHP data access layer).
12. Compliance and Legal:
Consider data retention policies and their implementation via soft deletes or archival processes. Review compliance requirements (e.g., PII handling if client emails/names are stored) related to data storage and access.
13. Current Status & Next Steps:
Completed/Resolved Since v1.35:
API endpoint testing successfully completed for: GET /checkins/{id}, POST /checkins/{id}/notes, GET /allocations, POST /forum/posts.
API 500 Internal Server Error (Database connection issue) resolved by developer via dependency injection (passing $pdo object).
API permission strings checked by the developer confirmed to match Living Plan specifications for the tested endpoints.
SQL command executed to add associated_user_id and associated_site_id (with Foreign Keys) to the api_keys table.
Specification for GET /api/v1/reports endpoint (including types checkin_detail, allocation_detail, new permissions, and scoping logic) defined.
Current Status: API Foundation and initial Endpoints are implemented, tested, and verified functional. Database schema updated to support scoped API permissions for reports. Report API specification is defined.
Known Issues: Potential inconsistencies in updated_at column definitions noted in schema dump (low priority).
Required User Action:
Update the test API key record in the api_keys table:
Ensure associated_permissions includes generate:reports and the desired scope permissions (e.g., read:all_checkin_data, read:all_allocation_data or read:site_checkin_data, read:own_allocation_data).
Populate associated_user_id and/or associated_site_id if testing scoped permissions (read:site_checkin_data, read:own_allocation_data).
Test the GET /api/v1/reports endpoint using n8n/Postman once the developer indicates implementation is complete.
Required Developer Action:
Implement the server-side logic for the GET /api/v1/reports endpoint based on the specification in Section 5. This includes:
Handling the type parameter.
Checking for generate:reports permission.
Implementing logic for type=checkin_detail and type=allocation_detail.
Checking for relevant scope permissions (read:all_*, read:site_*, read:own_*).
Applying data filtering based on permissions and associated_user_id/associated_site_id from the api_keys table.
Applying query parameter filters (start_date, end_date, site_id, department_id, user_id, etc.) as allowed by the scope.
Fetching data from the database.
Returning paginated JSON responses.
Implementing appropriate error handling (400, 403, 500).
Remaining Tasks (General):
Formalize API Key Permissions: Define the complete list of potential permissions needed for future API expansion.
Web UI Report Parity: Review reports.php (Web UI) for conceptual alignment with API report capabilities.
Address potential updated_at inconsistencies in DB schema.
Security Reviews (Upload Dir, Code Review, FK Errors, etc.).
Documentation (API Docs, User Manual updates).
Immediate Next Step: Developer implements the GET /api/v1/reports endpoint logic. User prepares the test API key data. User tests the reports endpoint once developer confirms completion.
14. Future Enhancements (Post-MVP):
AI Agent Integration (utilizing the API). Admin UI for API Key Management. Budget Excel Upload/Import. UI for managing roles/permissions/department assignments. Bootstrap 5 Upgrade. AI Summarization/Analysis. Microphone Input for Check-ins. Advanced Reporting (Web UI). Notifications/Alerts enhancements. User Profile Picture Uploads.
15. Implementation Plan & Process:
Use Living Plan as Single Source of Truth. Priority: Security (including secure API design and implementation) is the highest priority principle guiding all development and review. Focus AI Developer on manageable tasks (API build will be iterative). Prioritize backend logic then UI. Require clear code comments, especially around permission logic. User (Robert) responsible for providing context, testing, reporting results, and requesting plan updates. PM (AI) responsible for plan maintenance, context, task breakdown based on the plan.
