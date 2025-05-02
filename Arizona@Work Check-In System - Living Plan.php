Arizona@Work Check-In System - Living Project Plan
Version: 1.50
Date: 2025-05-02
(This Living Plan document is the "Single Source of Truth". User Responsibility: Maintain locally, provide current code, request plan updates. Developer (AI) Responsibility: Use plan/code context, focus on single tasks, provide code, assist plan updates.)
1. Project Goal:
Develop a web-based check-in and tracking system for Arizona@Work sites. Implement role-based access control (RBAC) using User Roles (including a site-scoped administrative capability via is_site_admin flag) in combination with Department assignments to manage feature access for staff. Incorporate a comprehensive Budget tracking module. Develop a secure API layer to enable programmatic access for external systems (e.g., AI agents) and future features, with initial endpoints and the reports endpoint tested and verified. Implement a parallel client check-in system featuring client accounts (associated with a primary site), profile management using dynamic site-specific questions, QR code check-in via authenticated kiosks (where key answers are copied to the check-in record), and an option for continued anonymous manual check-in (also using dynamic site-specific questions), aimed at improving data quality and check-in efficiency.
2. System Roles and Access Control (Web UI - Staff/Admin Focus):
Core Roles Involved (Staff): kiosk, azwk_staff, director, administrator (as defined in users.role enum). users table now also includes is_site_admin flag. (Future: user_outreach_sites table - see Section 14).
Core Departments Involved (Staff): AZ@Work operational departments, Finance Department (referenced by users.department_id FK to departments.id).
Permission Logic (Staff): Access and actions within Staff/Admin modules (e.g., Budget Module) are determined by the user's assigned Role AND their assigned Department (from the users table, stored in session). Permissions for site-level administration (User Mgmt, Site Config, Client Edit) now also check the is_site_admin flag AND compare the user's assigned site_id with the resource's site_id. (Future: Modified to include checks for user_outreach_sites table).
Role Definitions (Staff):
kiosk: Highly restricted role. Can log into the designated Kiosk interface. Can initiate check-ins via QR Scan or Manual Entry only. Cannot access other modules. Crucially, the active kiosk session (including its associated site_id) is required for server-side validation of QR code check-ins and determining questions for manual check-in.
azwk_staff (assigned to an AZ@Work Department): Permissions as defined previously (Web UI - Budget: Add/Edit assigned Staff budgets, staff fields only, see core columns). Access to other standard staff features. Can also have is_site_admin=true (granting additional site-scoped admin powers) or future outreach assignments.
azwk_staff (assigned to the Finance Department): Permissions as defined previously (Web UI - Budget: Add Admin allocations, Edit Staff fin_* fields, Edit Admin all fields, Delete Admin allocations, see all columns). Access to other standard staff features. Can also have is_site_admin=true (granting additional site-scoped admin powers) or future outreach assignments.
outside_staff: Role exists, permissions TBC. Likely no budget access.
director: Permissions as defined previously (Web UI - Budget: Full Budget Setup, Add/Edit Staff allocations staff fields, Void allocations, see all columns). Multi-site access potentially scoped by site_context. Likely 1 user. Access to other standard staff features, potentially site configuration and client editing.
administrator: System-wide configuration, user management. Full access. Can grant/revoke Site Administrator privileges.
Client Users: Clients will have separate accounts (see Section 7, clients table) and log in via a distinct Client Portal, not the staff login system. They have no operational roles or permissions within the staff application context.
3. Authentication System:
Staff/Admin Web UI:
Functionality: Login, Hashing (password_hash field in users), Sessions, RBAC (Role+Dept+is_site_admin flag), last_login. Login filters u.deleted_at IS NULL.
Session Data: Stores user_id, role, site_context, department_id, department_slug, is_site_admin status. Critical for permissions.
Status: Session Timeout, CSRF Protection IMPLEMENTED.
Client Portal Web UI:
Functionality: Separate Client Registration, Client Login (username/password), Hashing (password_hash field in clients), Sessions (distinct from staff sessions).
Session Data: Stores client_id, potentially basic info needed for portal display.
Purpose: Allows clients to manage their profile (dynamic questions), retrieve their QR code, and view service information.
API: See Section 5 - API Specification. Requires separate token-based authentication (API Keys). Authentication and authorization layers tested and confirmed functional for implemented endpoints.
4. Site Context Handling for Admin/Director:
Implemented via session context variable (site_context) potentially linking to users.site_id. Allows Directors/Admins with appropriate roles/permissions to switch between site views if the application supports multiple AZ@Work sites and the user has privileges across them. Affects filtering and data visibility in the Web UI. Site Admins' powers are strictly scoped by their assigned users.site_id. (Note: API data scoping uses API Key permissions and associated IDs, see Section 5). Kiosk sessions also have an inherent site_id context.
5. API Specification (V1 - Current Scope):
Goal: Provide secure, documented, programmatic access to application functions and data for authorized external systems (e.g., AI agents).
Base URL: /api/v1/ (Tested on https://seazwf.com/api/v1/)
Data Format: JSON (Content-Type: application/json).
Authentication: API Keys (Hashed in api_keys table). Sent via Authorization: Bearer <key> or X-API-Key: <key> header. Server validates using password_verify(). Validation confirmed functional.
Authorization: Based on associated_permissions (TEXT field storing comma-separated or JSON list) stored with the API key. Principle of least privilege. Each endpoint verifies key permissions. Data scope for some endpoints (e.g., reports) may be further limited by associated_user_id or associated_site_id on the api_keys table based on the permissions granted (e.g., read:site_checkin_data vs read:all_checkin_data). Authorization confirmed functional for implemented endpoints.
Error Handling: Standard HTTP status codes (200, 201, 400, 401, 403, 404, 500). JSON error body {"error": {"code": "...", "message": "..."}}. Confirmed functional.
API Endpoints (Implemented & Verified):
Fetch Check-in Details:
Method: GET
Path: /checkins/{checkin_id}
Auth Required: Yes. Permission Needed: read:checkin_data (Verified)
Response (200 OK): JSON object of the specific check-in record.
Response (404 Not Found): If checkin_id does not exist.
Add Check-in Note:
Method: POST
Path: /checkins/{checkin_id}/notes
Auth Required: Yes. Permission Needed: create:checkin_note (Verified)
Request Body: {"note_text": "Text of the note..."}
Response (201 Created): JSON object of the created note (from checkin_notes table, including created_by_api_key_id).
Response (400 Bad Request): If note_text missing/invalid.
Response (404 Not Found): If checkin_id does not exist.
Query Allocations:
Method: GET
Path: /allocations
Auth Required: Yes. Permission Needed: read:budget_allocations (Verified)
Query Parameters (Examples): fiscal_year=YYYY, grant_id=X, department_id=Y, budget_id=Z, page=1, limit=50.
Response (200 OK): JSON object containing an array of allocation objects and pagination details.
Create Forum Post:
Method: POST
Path: /forum/posts
Auth Required: Yes. Permission Needed: create:forum_post (Verified)
Request Body: {"topic_id": 123, "post_body": "Content of the post..."}
Response (201 Created): JSON object of created post (including created_by_api_key_id).
Response (400 Bad Request): Missing/invalid fields.
Response (404 Not Found): If topic_id does not exist.
Generate Reports:
Method: GET
Path: /reports
Auth Required: Yes. Base Permission Needed: generate:reports. Additional scope permissions required based on report type.
Mandatory Query Parameter: type={report_type}: Specifies the report to generate. Implemented types: checkin_detail, allocation_detail.
Common Optional Query Parameters: start_date=YYYY-MM-DD, end_date=YYYY-MM-DD, limit=50, page=1.
Report Type: checkin_detail
Description: Returns detailed list of check-in records.
Scope Permissions Required:
read:all_checkin_data: Access all check-ins. Optional site_id filter. (Verified)
read:site_checkin_data: Access check-ins for site in api_keys.associated_site_id. API applies filter. (Verified)
Response (200 OK): JSON with pagination and check-in objects. (Verified)
Report Type: allocation_detail
Description: Returns detailed list of budget allocation records.
Scope Permissions Required:
read:all_allocation_data: Access all allocations. Optional filters (site, dept, grant, budget, user). (Verified)
read:own_allocation_data: Access allocations for budgets assigned to user in api_keys.associated_user_id. API applies filter. (Verified)
Response (200 OK): JSON with pagination and allocation objects. (Verified)
Error Responses: 400 (Missing/invalid type), 403 (Missing permission/scope/associated ID), 500 (Server error - resolved). (400, 403 verified).
6. Page Specifications & Functionality (Web UI):
General: Ongoing UI Refactoring for consistency. Standardize on Soft Deletes (deleted_at) for relevant data. Use Bootstrap v4.5.2 (CSS and JS) consistently.
Staff/Admin Pages:
budget_settings.php: Settings Consolidation (Director role). Tabs for Grants, Budgets, Vendors via includes (budget_settings_panels/). AJAX operations.
budgets.php: Budget Allocations Page (Director/azwk_staff). Role+Dept based access/visibility. Filtering, display table (JOIN users twice for created/updated by name), action buttons. AJAX loading.
Allocation Modals (includes/modals/add_allocation_modal.php, includes/modals/edit_allocation_modal.php): Select2 Vendor, conditional client_name, Void option (Director). Field editability based on Role+Dept+Budget Type. Server-side enforcement.
AJAX Handlers (Internal Staff UI): ajax_get_budgets.php, ajax_allocation_handler.php (strict permission enforcement), ajax_handlers/vendor_handler.php. CSRF protected.
users.php: User Management (Administrator). Includes checkbox for granting Site Admin privileges (is_site_admin). Site Admins can view/edit/delete users for their assigned site only (excluding Admins/Directors).
configurations.php: System & Site Configuration. Site Admins can access and modify settings specific to their assigned site (site_questions, site_ads, etc.) via relevant panels (config_panels/).
client_editor.php: New page/interface for authorized staff (Admin, Director, Site Admin matching client's site) to search for and edit client profile information (Name, Site ID, Email Pref, Dynamic Answers). Displays accessible clients by default (scoped by site for Site Admins) and allows filtering via search. Editing form closes automatically upon successful save. Includes audit logging.
Other Pages: account.php, index.php (staff entry point/main public page), reports.php, notifications.php, alerts.php, ajax_report_handler.php, dashboard.php, ajax_chat_handler.php. Standardized UI where applicable. Permissions based on Role/Dept/is_site_admin flag.
NEW - Client Facing Portal: (Separate from Staff UI)
client_register.php: Public-facing page for clients to create an account. Requires selection of a primary site (site_id). Dynamically loads site-specific Yes/No questions. Collects username, email, password, first/last name, and opt-in preference for job emails (email_preference_jobs). Saves answers to client_answers table. Performs validation, hashing.
client_login.php: Public-facing page for clients to log in with username/password. Establishes client session.
client_portal/profile.php: This page serves as the primary landing page for clients after login. Allows logged-in clients to view their primary site (read-only), view/edit answers to site-specific Yes/No questions (saved to client_answers), and edit their job email preference (email_preference_jobs). Contains navigation links to other client portal pages (qr_code.php and services.php).
client_portal/services.php: Displays information about the services offered to clients by Arizona@Work. Content is primarily static information.
client_portal/qr_code.php: Displays the client's static QR code (containing client_qr_identifier). Requires server to generate QR image embedding the correct URL (/kiosk/qr_checkin?cid=...). Clients can capture this image on their phones.
Requires transactional email capability for future features like password resets.
Kiosk Interface (checkins.php - accessed by logged-in kiosk role user):
Presents two clear options: "Scan QR Code" and "Manual Check-in".
Scan QR Code:
Uses JavaScript library (e.g., html5-qrcode) to access device camera and scan QR codes.
On successful scan of a valid URL format (/kiosk/qr_checkin?cid=...), extracts the client_qr_identifier.
Sends the identifier via AJAX to /kiosk/qr_checkin handler. Request inherently includes the kiosk's session cookie (containing kiosk site_id).
Displays success ("Welcome [Name], Checked In!") or error message returned from handler.
Manual Check-in:
Displays form for first name, last name, email. Dynamically loads and displays site-specific Yes/No questions based on the kiosk's associated site_id.
On submission, posts data (including answers) to kiosk_manual_handler.php.
Displays success or error message.
NEW - Kiosk Handlers:
/kiosk/qr_checkin (PHP handler):
CRITICAL: Verifies request comes from an active session with the kiosk role. Rejects if not.
Validates the received client_qr_identifier.
Looks up client in clients table using client_qr_identifier.
Creates a check_ins record including client_id (FK), first_name, last_name, client_email from the client record, and the site_id from the kiosk's session. It then queries the client_answers table for this client_id (potentially joining site_questions or global_questions to identify specific questions like 'veteran', 'age', 'interviewing') and maps the retrieved answers to the corresponding q_veteran, q_age, q_interviewing columns for the check_ins insertion.
Triggers automatic AI enrollment based on client email.
Triggers enhanced notifications.
Returns JSON success/error to the kiosk AJAX call. (Ensures no redirect happens here).
kiosk_manual_handler.php (PHP handler):
Receives form data (name, email, dynamic question answers) from manual kiosk submission.
Validates input.
Creates a basic check_ins record (first_name, last_name, email, site_id from kiosk, client_id is NULL).
Saves the dynamic question answers to the checkin_answers table.
Includes logic to ask about AI enrollment.
Triggers standard notifications.
Redirects back to kiosk page with success/error message.
Includes: header.php, footer.php, modals/ (staff modals). Client portal will need its own simplified header/footer/styling. JS in footer/assets for AJAX, Select2, conditional logic (staff UI). New JS needed for QR scanning on kiosk page. JS needed for dynamic question loading.
7. Database Schema (MySQL):
(Structure based on v1.49. AUTO_INCREMENT/details omitted. Standard indexes assumed. FK relationships described.)
ai_resume, ai_resume_logs, ai_resume_val: Unchanged from v1.37.
api_keys: Unchanged from v1.37 (Schema Updated previously for API).
budgets, budget_allocations: Unchanged from v1.37.
check_ins: (Modified)
Columns: id (Primary), site_id (FK -> sites), first_name, last_name, check_in_time, notified_staff_id (FK -> users, Null), client_email, q_veteran (ENUM('Yes', 'No', 'Not Answered')), q_age (ENUM('18-24', '25-44', '45-64', '65+', 'Not Answered')), q_interviewing (ENUM('Yes', 'No', 'Not Answered')), client_id (INT, Null, FK -> clients). (ENUM values assumed updated, confirm actual values if different).
Indexes: PRIMARY, check_ins_site_id_fk, check_ins_notified_staff_id_fk, idx_checkins_site_time (site_id, check_in_time), fk_checkins_client_idx (client_id).
Foreign Keys: site_id -> sites(id), notified_staff_id -> users(id), client_id -> clients(id) (ON DELETE SET NULL ON UPDATE CASCADE).
Comment: Stores core check-in details. During QR check-ins, the q_* columns (e.g., q_veteran, q_age, q_interviewing) are populated by fetching the corresponding answers from the client_answers table for the associated client_id. For manual check-ins, answers to dynamic questions are stored in checkin_answers, and the q_* columns in check_ins would likely be NULL or 'Not Answered' unless populated by other means.
checkin_notes: Unchanged from v1.37.
NEW - client_answers:
Comments: Stores client answers to site-specific dynamic questions.
Columns:
id (Primary)
client_id (INT, NOT NULL, FK -> clients)
question_id (INT, NOT NULL, FK -> site_questions)
answer (ENUM('Yes', 'No'), NOT NULL)
created_at (TIMESTAMP, Default: CURRENT_TIMESTAMP)
updated_at (TIMESTAMP, Null, ON UPDATE CURRENT_TIMESTAMP)
Indexes: PRIMARY, fk_clientanswers_client_idx (client_id), fk_clientanswers_question_idx (question_id), unique_client_question (client_id, question_id).
Foreign Keys: client_id -> clients(id) ON DELETE CASCADE, question_id -> site_questions(id) ON DELETE CASCADE.
NEW - checkin_answers:
Comments: Stores answers to site-specific dynamic questions asked during manual kiosk check-in.
Columns:
id (Primary)
checkin_id (INT, NOT NULL, FK -> check_ins)
question_id (INT, NOT NULL, FK -> site_questions)
answer (ENUM('Yes', 'No'), NOT NULL)
created_at (TIMESTAMP, Default: CURRENT_TIMESTAMP)
Indexes: PRIMARY, fk_checkinanswers_checkin_idx (checkin_id), fk_checkinanswers_question_idx (question_id), unique_checkin_question (checkin_id, question_id).
Foreign Keys: checkin_id -> check_ins(id) ON DELETE CASCADE, question_id -> site_questions(id) ON DELETE CASCADE.
NEW - client_profile_audit_log:
Comments: Logs changes made to client profile fields by staff.
Columns:
id (INT NOT NULL AUTO_INCREMENT PRIMARY KEY)
client_id (INT NOT NULL)
changed_by_user_id (INT NOT NULL)
timestamp (TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)
field_name (VARCHAR(255) NOT NULL COMMENT 'e.g., ''first_name'', ''site_id'', ''question_id_X''')
old_value (TEXT NULL)
new_value (TEXT NULL)
Indexes: idx_audit_client (client_id), idx_audit_user (changed_by_user_id), idx_audit_timestamp (timestamp).
Foreign Keys: fk_audit_client (client_id) -> clients(id) ON DELETE CASCADE, fk_audit_user (changed_by_user_id) -> users(id) ON DELETE RESTRICT.
NEW - clients:
Comments: Stores client account information for portal login and QR check-in.
Columns:
id (Primary)
username (VARCHAR, Unique)
email (VARCHAR, Unique)
password_hash (VARCHAR)
first_name (VARCHAR)
last_name (VARCHAR)
site_id (INT, Null, FK -> sites, Comment: Client's primary site selection)
client_qr_identifier (VARCHAR, Unique, Comment: Persistent UUID/ID for static QR code)
email_preference_jobs (TINYINT(1), NOT NULL, Default: 0, Comment: 0=OptOut, 1=OptIn for job/event emails)
created_at (TIMESTAMP, Default: CURRENT_TIMESTAMP)
updated_at (TIMESTAMP, Null, ON UPDATE CURRENT_TIMESTAMP)
deleted_at (DATETIME, Null)
Indexes: PRIMARY, username_UNIQUE, email_UNIQUE, qr_identifier_UNIQUE, idx_clients_deleted_at, fk_clients_site_idx (site_id).
Foreign Keys: site_id -> sites(id) ON DELETE SET NULL ON UPDATE CASCADE.
departments: Unchanged from v1.37.
finance_department_access: Unchanged from v1.37.
forum_categories, forum_posts, forum_topics: Unchanged from v1.37 (posts already modified for API).
global_ads, global_questions, grants, sites, site_ads, site_configurations, site_questions, staff_notifications: Unchanged from v1.37. (Note: site_questions now used for dynamic client/checkin questions).
users: (Modified)
Columns: id (Primary), username, full_name, email, job_title, department_id (FK -> departments), site_id (FK -> sites), password_hash, role (ENUM('kiosk','azwk_staff','director','administrator')), is_site_admin (TINYINT(1) NOT NULL DEFAULT 0), last_login, created_at, updated_at, deleted_at.
Comment: Stores Staff/Admin/Kiosk users. is_site_admin flag grants site-scoped admin privileges.
vendors: Unchanged from v1.37.
(Future Table) user_outreach_sites: (See Section 14)
Columns: id, user_id (FK -> users), site_id (FK -> sites), created_at.
Unique Constraint: (user_id, site_id).
8. File Management Structure:
Root: budget_settings.php, budgets.php, index.php (staff entry point/main public page), checkins.php (Kiosk Interface), kiosk_manual_handler.php, client_register.php, client_login.php, client_editor.php (Staff Client Edit UI).
ajax_handlers/: vendor_handler.php, get_site_questions.php. Consider moving ajax_allocation_handler.php, ajax_get_budgets.php here.
api/v1/: API entry point, includes, handlers, data access.
budget_settings_panels/: Include files for budget tabs.
client_portal/: Contains profile.php (Primary Client Page), qr_code.php, services.php, potentially includes specific to client view.
config/: DB connection, constants.
includes/: Header, footer, modals (staff), helper functions. May need separate client includes.
config_panels/: Include files for system configuration page. (questions_panel.php, ads_panel.php, site_settings_panel.php modified for Site Admin role).
data_access/: PHP classes/functions for DB interaction (DAL). Includes client_data.php, checkin_data.php, question_data.php, user_data.php, site_data.php, ad_data.php, audit_log_data.php (New).
kiosk/: Contains qr_checkin handler logic.
assets/: CSS, JS, images.
css/main.css: Custom styles. May need client.css.
js/budgets.js: Specific JS for budget pages.
js/kiosk.js: JS for QR scanning logic and AJAX calls on checkins.php.
js/client.js: New JS for dynamic question loading on registration/profile pages.
9. Design Specification (Web UI):
Core staff layout uses Bootstrap v4.5.2. UI Refactoring ongoing.
Client Portal should have a clean, simple design, possibly using Bootstrap v4.5.2 for consistency but with distinct styling. Focus on usability for profile updates (dynamic questions), accessing QR code, and viewing services. client_portal/profile.php serves as the primary view for clients upon login and contains navigation to qr_code.php and services.php.
Kiosk Interface (checkins.php) must be very clear, with large buttons/targets for "Scan QR Code" and "Manual Check-in". Provide clear visual feedback on scan success/failure and check-in status. Manual check-in section dynamically displays site questions.
Budget module UI remains as specified in v1.37 (Tabs, Modals, Select2, dynamic visibility).
User Management UI (users.php) updated with checkbox for is_site_admin flag (Admin only).
Client Editor UI (client_editor.php) provides search and editing capabilities scoped by permissions (Admin, Director, Site Admin). Displays accessible clients by default and filters on search input. Edit form closes automatically on save.
10. Technical Specifications:
PHP 8.4, MySQL/MariaDB on GoDaddy.
JavaScript (ES6+), JS QR Code scanning library (e.g., html5-qrcode or similar), AJAX (Fetch API or jQuery).
HTML5, CSS3, Bootstrap 4.5.2.
Server-side QR code generation library (for displaying QR in client portal).
Case-Insensitive Checks: Required for comparisons involving user roles, identifiers where appropriate.
API uses a basic routing mechanism within api/v1/index.php.
Transactional Email Sending Capability required for future password reset functionality (PHP Mailer or external service integration) - Deferred Implementation.
11. Security Considerations:
Critical: Server-side validation of the kiosk role session on the /kiosk/qr_checkin handler is the primary mechanism preventing unauthorized QR check-ins. Kiosk session site_id is used for manual check-in question context.
Strict Role+Department+is_site_admin flag permissions enforced server-side for Staff Web UI actions.
API Key + Associated Permissions enforced server-side for API actions.
Server-side validation for all form submissions (Client registration, Client profile, Manual Kiosk, Staff forms, Client Editor) and API inputs. Validate dynamic question IDs against the site.
CSRF protection implemented on all Web UI forms and relevant AJAX handlers (Staff UI confirmed; ensure for Client Portal/Editor forms too).
Use of prepared statements (PDO or MySQLi) for all database queries.
Secure password hashing (password_hash) for both users and clients tables.
Soft delete logic (deleted_at) implemented for relevant data.
Secure Session Management practices for both Staff and Client Web UI.
API Security Best Practices: Hashing API keys, use of HTTPS, potential rate limiting, input sanitization, output encoding. HTTPS confirmed necessary for API function.
Remaining Review Items: Upload Directory Hardening, Cross-Site Scripting (XSS) review (forums, client profile fields), Foreign Key error handling (try/catch in DAL). (These items require developer action - See Section 13).
Audit Logging implemented for staff changes to client data via client_editor.php.
12. Compliance and Legal:
Data Retention: Consider policies for clients, check_ins, client_answers, checkin_answers, client_profile_audit_log data.
PII Handling: Client accounts store PII. Staff access (especially Site Admin role) increases exposure. Audit logs track modifications. Ensure compliance with relevant privacy regulations. Provide clarity on data usage.
Email Opt-In: email_preference_jobs flag must be strictly adhered to for non-transactional communications.
13. Current Status & Next Steps:
Completed/Resolved Since v1.37:
API Foundation and specified endpoints (incl. /reports) remain implemented and verified.
API Configuration Manual Drafted.
Strategic Pivot: Project direction shifted to prioritize the implementation of the Parallel Client Account / QR Check-in system.
Scope Change & Implementation: Implemented dynamic questions, client accounts, QR check-in, associated handlers, logic to copy key answers to check_ins during QR scans, and client portal pages (profile.php, qr_code.php, services.php).
Bug Fixes: Resolved dynamic question/registration bugs and /kiosk/qr_checkin redirect issue. (COMPLETED)
Completed Tasks Since v1.42:
Navigation Links Implementation (index.php to client pages, client_register.php to client_login.php, client portal links). (COMPLETED)
Initial Security Reviews & Remediation (Significant work done, specific items pending finalization - see Required Dev Action). (PARTIALLY COMPLETED - PENDING ITEMS)
Comprehensive API Documentation Generation (OpenAPI spec). (COMPLETED)
Web UI Report Enhancements (reports.php parity for Check-in and Allocation, including dynamic questions). (COMPLETED)
Formalize complete list of potential future API permissions. (COMPLETED)
QR Scanner Integration and check-in data storage update (mapping client_answers to check_ins.q_* columns). (COMPLETED)
Completed Tasks Since v1.47:
Site Administrator Capability Implementation: Added is_site_admin flag to users, updated User Mgmt UI/backend, implemented site-scoped permissions for User Mgmt & Site Config, created client_editor.php with site-scoped client editing, implemented basic audit logging (client_profile_audit_log table and logic). (COMPLETED)
Client Editor Enhancements & Fixes: Fixed Site Admin search bug, implemented default client list display (scoped), implemented auto-close of edit form on save. (COMPLETED)
Completed Tasks Since v1.49:
Resolution of Dashboard Link Issue: Replaced concept/link for dashboard.php with client_portal/services.php containing service info. (COMPLETED)
Current Status: Staff-side features (Budget module, API) are largely functional. Client Account / QR Check-in / Service Info system is functional. Site Administrator capability (flag-based) is implemented, including client editing and audit logging. Navigation links, API docs, and report enhancements largely completed. Security reviews conducted, with specific follow-up actions pending.
Known Issues: None critical identified.
Required User Action:
Perform system backup before any further major development or deployment.
Review and approve this Living Plan v1.50.
Configure production API key(s) when ready for deployment (related to API usage, e.g., n8n).
Required Developer Action (Next Focus):
Ensure stability and correct functioning of client_portal/services.php and associated navigation links.
Complete Pending Security Items (from Section 11):
Finalize Upload Directory Hardening.
Complete Cross-Site Scripting (XSS) review/remediation (forums, client profile fields).
Implement robust Foreign Key error handling (try/catch in DAL).
Refinements to existing Staff UI modules as needed or if critical bugs arise.
14. Future Enhancements (Post-MVP & Client System Stabilization):
Site Outreach Coordinator Capability:
Create new linking table user_outreach_sites (id, user_id, site_id).
Update User Management UI (users.php) to allow Global Admins to assign users to multiple sites via this table (e.g., using a multi-select box).
Define and implement specific features/permissions based on these assignments (e.g., outreach campaign management, targeted communication - TBD).
Admin UI for API Key Management:
Provide interface (api_keys.php?) for Administrators to Create, View (partial key), Manage Permissions, and Revoke API keys stored in the api_keys table. Include secure key generation and one-time display.
Dedicated Permissions Checking API Endpoint:
Create endpoint (e.g., /api/v1/permissions/check) for agents to verify user permissions before acting. Requires agent key with check:permissions. Takes user_id, action, resource_id. Executes internal RBAC logic. Returns {"allowed": true/false}.
Client Password Reset Functionality: Implement secure password reset flow via email. Requires Transactional Email capability.
Upgrade QR Codes to Dynamic/Expiring Tokens.
Phase out Manual Kiosk Entry.
Bulk Email System for Job/Event Notifications (using email_preference_jobs opt-in).
AI Agent Integration (utilizing the API).
Budget Excel Upload/Import.
UI enhancements for managing staff roles/permissions/flags (is_site_admin, outreach assignments).
Bootstrap 5 Upgrade.
AI Summarization/Analysis (Resumes, Check-in data, Dynamic Question Answers, Audit Logs).
Microphone Input for Check-ins.
Advanced Reporting (Web UI - Incorporating dynamic answer data, audit logs).
Notifications/Alerts enhancements (Staff & potentially Client).
User Profile Picture Uploads (Staff).
Develop enhanced functionality for client_portal/services.php if desired beyond static information.
Refine Site Admin client edit permissions (e.g., consider allowing username/email edits with safeguards).
Enhance Audit Logging (e.g., cover more areas, provide UI for viewing logs).
15. Implementation Plan & Process:
Use Living Plan v1.50 as Single Source of Truth.
Priority: 1) Ensure stability of the client_portal/services.php integration. 2) Address the pending Security Items (Upload Dir, XSS, FK Errors). 3) Address Staff UI refinements as needed. 4) Implement features from Section 14 based on future prioritization.
Focus AI Developer on manageable tasks from the "Required Developer Action (Next Focus)" list above, implemented iteratively in order.
Prioritize backend logic then UI for new features.
Require clear code comments, especially around permission logic (Role, Dept, is_site_admin), session checks, data handling (including QR->check_ins logic and audit logging), and security measures.
User (Robert) responsible for providing context, testing, reporting results, and requesting plan updates.
PM (AI) responsible for plan maintenance, context, task breakdown based on the plan.
