Arizona@Work Check-In System - Living Project Plan
Version: 1.35
Date: 2025-04-29
(This Living Plan document is the "Single Source of Truth". User Responsibility: Maintain locally, provide current code, request plan updates. Developer (AI) Responsibility: Use plan/code context, focus on single tasks, provide code, assist plan updates.)
1. Project Goal:
Develop a web-based check-in and tracking system for Arizona@Work sites.
Implement role-based access control (RBAC) using User Roles in combination with Department assignments to manage feature access.
Incorporate a comprehensive Budget tracking module.
Develop a secure API layer to enable programmatic access for external systems (e.g., AI agents) and future features.
Enhance features like Forum, AI Resume analysis, Notifications, and Reporting.
2. System Roles and Access Control (Web UI - Budget Module Focus):
Core Roles Involved: azwk_staff, Director, Administrator (as defined in users.role enum).
Core Departments Involved: AZ@Work operational departments, Finance Department (referenced by users.department_id FK to departments.id).
Permission Logic: Access and actions within the Budget Module (via Web UI) are determined by the user's assigned Role AND their assigned Department (from the users table, stored in session).
Kiosk: Limited access role. (No budget module access).
AZ@Work Staff (azwk_staff) assigned to an AZ@Work Department: Permissions as defined in v1.32 (Web UI - Add/Edit assigned Staff budgets, staff fields only, see core columns).
AZ@Work Staff (azwk_staff) assigned to the Finance Department: Permissions as defined in v1.32 (Web UI - Add Admin allocations, Edit Staff fin_* fields, Edit Admin all fields, Delete Admin allocations, see all columns).
Outside Staff: Role exists, permissions TBC. Likely no budget access.
Director (director): Permissions as defined in v1.32 (Web UI - Full Budget Setup, Add/Edit Staff allocations staff fields, Void allocations, see all columns).
Administrator (administrator): System-wide configuration, user management. Full access.
3. Authentication System:
Web UI:
Functionality: Login, Hashing (password_hash field in users), Sessions, RBAC (Role+Dept), last_login. Login filters u.deleted_at IS NULL.
Session Data: Stores user_id, role, site_context, department_id, department_slug. Critical for permissions.
Status: Session Timeout, CSRF Protection IMPLEMENTED.
API (NEW): See Section 5 - API Specification. Requires separate token-based authentication.
4. Site Context Handling for Admin/Director:
Implemented via session context variable (site_context) potentially linking to users.site_id. Allows Directors/Admins with appropriate roles/permissions to switch between site views if the application supports multiple AZ@Work sites and the user has privileges across them. Affects filtering and data visibility.
5. API Specification (V1 - Initial Scope):
Goal: Provide secure, documented, programmatic access to application functions and data for authorized external systems (e.g., AI agents).
Base URL (Example): /api/v1/
Data Format: JSON (Content-Type: application/json).
Authentication: API Keys (Hashed in api_keys table). Sent via Authorization: Bearer <key> or X-API-Key: <key> header. Server validates using password_verify().
Authorization: Based on associated_permissions stored with the API key. Principle of least privilege. Each endpoint verifies key permissions.
Error Handling: Standard HTTP status codes (200, 201, 400, 401, 403, 404, 500). JSON error body {"error": {"code": "...", "message": "..."}}.
Initial API Endpoints:
Fetch Check-in Details:
Method: GET
Path: /checkins/{checkin_id}
Auth Required: Yes. Permission Needed: read:checkin_data (example).
Response (200 OK): JSON object of the specific check-in record.
Response (404 Not Found): If checkin_id does not exist.
Add Check-in Note:
Method: POST
Path: /checkins/{checkin_id}/notes
Auth Required: Yes. Permission Needed: create:checkin_note.
Request Body: {"note_text": "Text of the note..."}
Response (201 Created): JSON object of the created note (from checkin_notes table) or success status.
Response (400 Bad Request): If note_text missing/invalid.
Response (404 Not Found): If checkin_id does not exist.
Query Allocations:
Method: GET
Path: /allocations
Auth Required: Yes. Permission Needed: read:budget_allocations.
Query Parameters (Examples): fiscal_year=YYYY, grant_id=X, department_id=Y, budget_id=Z, page=1, limit=50.
Response (200 OK): JSON array of allocation objects, potentially with pagination.
Create Forum Post:
Method: POST
Path: /forum/posts
Auth Required: Yes. Permission Needed: create:forum_post.
Request Body: {"topic_id": 123, "post_body": "Content of the post..."}
Response (201 Created): JSON object of created post or success status. (Should populate created_by_api_key_id).
Response (400 Bad Request): Missing/invalid fields.
Response (404 Not Found): If topic_id does not exist.
Create Reports (Placeholder):
Method: GET
Path: /reports/{report_type} (or /reports?type={report_type})
Auth Required: Yes. Permission Needed: generate:reports.
Query Parameters: Report-specific filters (TBD).
Response (200 OK): JSON object containing structured report data.
Note: Further definition needed for report types, parameters, response structures.
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
7. Database Schema (MySQL):
(Structure based on user-provided schema dump dated approx 2025-04-22 and planned API additions. Note: AUTO_INCREMENT details omitted for brevity, standard indexes like PRIMARY assumed unless noted. Foreign key relationships described below individual table definitions where applicable).
-- Table: ai_resume
Columns: id (Primary), client_name, email, user_id (FK -> users), job_applied, threadsID, request, status, request_status (ENUM), created_at.
Indexes: PRIMARY, idx_threadsID, idx_email, idx_created_at, idx_airesume_user (user_id).
-- Table: ai_resume_logs
Columns: id (Primary), resume_id (FK -> ai_resume), event, details, created_at.
Indexes: PRIMARY, fk_ai_logs_resume (resume_id).
-- Table: ai_resume_val
Columns: id (Primary), name, email (Unique), user_id (FK -> users), site, signup_time, created_at.
Indexes: PRIMARY, idx_unique_email, idx_site, fk_ai_resumeval_user (user_id).
-- Table: api_keys
Comments: Stores API keys for external system access. Created via SQL.
Columns: id (Primary), key_hash (Unique, Comment: Secure hash of API key), description, associated_permissions (TEXT, Comment: JSON/list of permissions), created_at, last_used_at (Timestamp, Null), is_active (TINYINT, Default: 1).
Indexes: PRIMARY, idx_key_hash_unique, idx_api_keys_active (is_active).
-- Table: budgets
Columns: id (Primary), name, user_id (FK -> users, Allows Null), grant_id (FK -> grants), department_id (FK -> departments), fiscal_year_start, fiscal_year_end, budget_type (ENUM 'Staff', 'Admin'), notes, created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, fk_budget_user_idx, fk_budget_grant_idx, fk_budget_department_idx, idx_budgets_fiscal_year_start, idx_budgets_deleted_at.
Foreign Keys: user_id -> users(id), grant_id -> grants(id), department_id -> departments(id).
-- Table: budget_allocations
Columns: id (Primary), budget_id (FK -> budgets), transaction_date, vendor_id (FK -> vendors, Null), client_name (VARCHAR, Null), voucher_number, enrollment_date, class_start_date, purchase_date, payment_status (ENUM 'P', 'U', 'Void'), program_explanation, funding_* (DECIMAL fields), fin_* (VARCHAR/DATE/TEXT fields), fin_processed_by_user_id (FK -> users, Null), fin_processed_at (DATETIME, Null), created_by_user_id (FK -> users), updated_by_user_id (FK -> users, Null), created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, idx_alloc_budget_id, idx_alloc_transaction_date, idx_alloc_deleted_at, fk_alloc_fin_processed_user_idx, fk_alloc_created_user_idx, fk_alloc_updated_user_idx, fk_alloc_vendor (vendor_id).
Foreign Keys: budget_id -> budgets(id), vendor_id -> vendors(id), fin_processed_by_user_id -> users(id), created_by_user_id -> users(id), updated_by_user_id -> users(id).
-- Table: check_ins
Columns: id (Primary), site_id (FK -> sites), first_name, last_name, check_in_time, notified_staff_id (FK -> users, Null), client_email, q_veteran (ENUM), q_age (ENUM), q_interviewing (ENUM).
Indexes: PRIMARY, check_ins_site_id_fk, check_ins_notified_staff_id_fk, idx_checkins_site_time (site_id, check_in_time).
Foreign Keys: site_id -> sites(id), notified_staff_id -> users(id).
-- Table: checkin_notes
Comments: Stores notes associated with specific check-in records. Created via SQL.
Columns: id (Primary), check_in_id (FK -> check_ins), note_text (TEXT), created_by_user_id (FK -> users, Null), created_by_api_key_id (FK -> api_keys, Null), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, idx_checkin_notes_checkin_id, idx_checkin_notes_deleted_at, fk_checkin_note_user_creator_idx, fk_checkin_note_api_creator_idx.
Foreign Keys: check_in_id -> check_ins(id), created_by_user_id -> users(id), created_by_api_key_id -> api_keys(id).
-- Table: departments
Comments: Stores global department names
Columns: id (Primary), name (Unique), slug (Unique), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, name_UNIQUE, slug_UNIQUE.
-- Table: finance_department_access
Comments: Not used for budget control (v1.30+). Maps specific users (historically finance) to departments they can access (potentially for other modules).
Columns: id (Primary), finance_user_id (FK -> users), accessible_department_id (FK -> departments), created_at.
Indexes: PRIMARY, idx_user_dept_unique (finance_user_id, accessible_department_id), fk_fin_access_user_idx, fk_fin_access_dept_idx.
Foreign Keys: finance_user_id -> users(id), accessible_department_id -> departments(id).
-- Table: forum_categories
Comments: Forum categories/sections
Columns: id (Primary), name, description, view_role (ENUM), post_role (ENUM), reply_role (ENUM), display_order, created_at.
Indexes: PRIMARY.
-- Table: forum_posts (MODIFIED)
Comments: Individual forum posts/messages
Columns: id (Primary), topic_id (FK -> forum_topics), user_id (FK -> users, Null), created_by_api_key_id (FK -> api_keys, Null, Comment: Added for API post tracking), content (TEXT), created_at, updated_at (Timestamp, Null), updated_by_user_id (FK -> users, Null).
Indexes: PRIMARY, fk_forum_posts_topic_idx, fk_forum_posts_user_idx, fk_forum_posts_editor_idx, fk_forum_posts_api_creator_idx, idx_posts_topic_created (topic_id, created_at).
Foreign Keys: topic_id -> forum_topics(id), user_id -> users(id), updated_by_user_id -> users(id), created_by_api_key_id -> api_keys(id).
-- Table: forum_topics
Comments: Individual forum discussion threads
Columns: id (Primary), category_id (FK -> forum_categories), user_id (FK -> users, Null), title, is_sticky (TINYINT), is_locked (TINYINT), created_at, last_post_at (Timestamp, Null), last_post_user_id (FK -> users, Null).
Indexes: PRIMARY, fk_forum_topics_category_idx, fk_forum_topics_user_idx, fk_forum_topics_last_poster_idx, idx_topics_lastpost.
Foreign Keys: category_id -> forum_categories(id), user_id -> users(id), last_post_user_id -> users(id).
-- Table: global_ads
Columns: id (Primary), ad_type (ENUM), ad_title, ad_text, image_path, is_active (TINYINT), created_at, updated_at.
Indexes: PRIMARY.
-- Table: global_questions
Columns: id (Primary), question_text (TEXT), question_title (Unique), created_at.
Indexes: PRIMARY, question_title.
-- Table: grants
Columns: id (Primary), name (Unique), grant_code (Unique), description, start_date, end_date, created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, name_UNIQUE, grant_code_UNIQUE, idx_grants_deleted_at.
-- Table: sites
Columns: id (Primary), name, email_collection_desc, is_active (TINYINT).
Indexes: PRIMARY.
-- Table: site_ads
Columns: id (Primary), site_id (FK -> sites), global_ad_id (FK -> global_ads), display_order, is_active (TINYINT), created_at, updated_at.
Indexes: PRIMARY, site_global_ad_unique (site_id, global_ad_id), site_ads_global_ad_id_fk.
Foreign Keys: site_id -> sites(id), global_ad_id -> global_ads(id).
-- Table: site_configurations
Columns: site_id (Primary, FK -> sites), config_key (Primary), config_value (TEXT), created_at, updated_at.
Indexes: PRIMARY (site_id, config_key).
Foreign Keys: site_id -> sites(id).
-- Table: site_questions
Columns: id (Primary), site_id (FK -> sites), global_question_id (FK -> global_questions), display_order, is_active (TINYINT), created_at.
Indexes: PRIMARY, site_global_question_unique (site_id, global_question_id), site_questions_gq_id_fk.
Foreign Keys: site_id -> sites(id), global_question_id -> global_questions(id).
-- Table: staff_notifications
Columns: id (Primary), site_id (FK -> sites), staff_name, staff_email, is_active (TINYINT).
Indexes: PRIMARY, staff_notifications_site_id_fk.
Foreign Keys: site_id -> sites(id).
-- Table: users
Columns: id (Primary), username (Unique), full_name, email (Unique), job_title, department_id (FK -> departments, Null), password_hash, role (ENUM 'kiosk','azwk_staff','outside_staff','director','administrator'), site_id (FK -> sites, Null), last_login (Timestamp, Null), is_active (TINYINT), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, username, email, users_site_id_fk, fk_users_department_idx.
Foreign Keys: department_id -> departments(id), site_id -> sites(id).
-- Table: vendors
Columns: id (Primary), name (Unique), client_name_required (TINYINT), is_active (TINYINT), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, name_UNIQUE, idx_vendors_active, idx_vendors_deleted_at.
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
api/v1/: Directory for API handler files and potentially routing logic.
9. Design Specification (Web UI):
Core layout uses Bootstrap v4.x. UI Refactoring ongoing for consistency.
budget_settings.php uses Bootstrap Tabs.
Allocation modals use Select2 for searchable vendor dropdown.
Allocation table visually distinguishes 'Void' rows.
Allocation modals dynamically control field editability based on Role+Department+Budget Type.
Allocation table (budgets.php) dynamically shows/hides columns based on Role+Department.
10. Technical Specifications:
PHP 8.4, MySQL/MariaDB on GoDaddy.
JavaScript (ES6+), Select2 library, AJAX (Fetch API or jQuery).
HTML5, CSS3, Bootstrap 4.x.
Case-Insensitive Checks: Required for comparisons involving user roles, department identifiers, site identifiers.
Consider a simple PHP routing library/mechanism for the API endpoints if complexity grows.
11. Security Considerations:
Strict Role+Department permissions enforced server-side for Web UI actions.
API Key + Associated Permissions enforced server-side for API actions.
Server-side validation for all form submissions and API inputs.
CSRF protection implemented on all Web UI forms and relevant AJAX handlers.
Use of prepared statements (PDO or MySQLi) for all database queries.
Soft delete logic (deleted_at column) implemented for relevant data.
Secure Session Management practices for Web UI (secure cookies, regeneration of ID on login).
API Security Best Practices: Hashing API keys, use of HTTPS, potential rate limiting, input sanitization, output encoding.
API Readiness & Security Focus: This is the top priority for all current and future development efforts. Design must facilitate secure external integrations.
Remaining Review Items: Upload Directory Hardening, Cross-Site Scripting (XSS) review (especially forums, user-generated content), Foreign Key error handling (implement try/catch in PHP data access layer).
12. Compliance and Legal:
Consider data retention policies and their implementation via soft deletes or archival processes.
Review compliance requirements (e.g., PII handling if client emails/names are stored) related to data storage and access.
13. Current Status & Next Steps:
Completed/Resolved Since v1.34:
Database schema updated to include created_by_api_key_id in forum_posts.
Developer reported completion of API Foundation (Phase 1) and Initial Endpoints (Phase 2).
Required SQL for api_keys, checkin_notes, forum_posts alteration completed by User.
API routing issues (.htaccess, include paths) resolved via debugging.
Previous Completions:
Budget Module functionality (Role+Dept Permissions, User Name Display) tested and confirmed "running smooth".
Database Schema structure clarified via user-provided dump and updated in this plan.
XAMPP test environment recovered.
Admin Budget creation fixed (DB allows NULL user_id).
Current Status: API Foundation and initial Endpoints are implemented. Awaiting API testing information from the developer and subsequent user testing. Debugging revealed and resolved issues with API routing and script execution.
Known Issues: Potential inconsistencies in updated_at column definitions noted in schema dump (may need fixing later).
Required User Action: Generate test API key data (hash plain key, insert into api_keys table with appropriate permissions).
Required Developer Action: Provide API testing details: Base URL, authentication method expected (confirm Authorization: Bearer or X-API-Key), expected permissions names for test key (read:checkin_data, create:checkin_note, read:budget_allocations, create:forum_post, generate:reports).
Remaining Tasks (API Development - Developer):
Awaiting testing feedback on Phase 1 & 2 implementation.
Remaining Tasks (General):
Define API Key Permissions (Formalize): Finalize specific permissions strings and determine how they will be stored/managed long-term in api_keys.associated_permissions.
Define Report Details: Specify requirements for API reports.
API Testing: User (Robert) to test API foundation and endpoints thoroughly using tools (Postman/n8n) once setup details and test key are ready.
Address potential updated_at inconsistencies.
Security Reviews (Upload Dir, Code Review, FK Errors, etc.).
Documentation (API Docs, User Manual updates).
Immediate Next Step: User (Robert) to generate test API key & insert into DB. Request API testing details (Auth Method, Permissions Names) from the developer. Once received, User (Robert) to begin API testing (with PM guidance).
14. Future Enhancements (Post-MVP):
AI Agent Integration (utilizing the API).
Admin UI for API Key Management.
Budget Excel Upload/Import.
UI for managing roles/permissions/department assignments.
Bootstrap 5 Upgrade.
AI Summarization/Analysis.
Microphone Input for Check-ins.
Advanced Reporting (Web UI).
Notifications/Alerts enhancements.
User Profile Picture Uploads.
15. Implementation Plan & Process:
Use Living Plan as Single Source of Truth.
Priority: Security (including secure API design and implementation) is the highest priority principle guiding all development and review.
Focus AI Developer on manageable tasks (API build will be iterative). Prioritize backend logic then UI.
Require clear code comments, especially around permission logic.
User (Robert) responsible for providing context, testing, reporting results, and requesting plan updates.
PM (AI) responsible for plan maintenance, context, task breakdown based on the plan.