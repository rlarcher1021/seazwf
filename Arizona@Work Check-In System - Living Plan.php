Arizona@Work Check-In System - Living Project Plan
Version: 1.56
Date: 2025-05-10
(This Living Plan document is the "Single Source of Truth". User Responsibility: Maintain locally, provide current code, request plan updates. Developer (AI) Responsibility: Use plan/code context, focus on single tasks, provide code, assist plan updates.)
1. Project Goal:
Develop a web-based check-in and tracking system for Arizona@Work sites. Implement role-based access control (RBAC) using User Roles (including a site-scoped administrative capability via is_site_admin flag) in combination with Department assignments to manage feature access for staff. Incorporate a comprehensive Budget tracking module. Develop a secure API layer (V1) to enable programmatic access for internal system components and the Unified API Gateway, with initial endpoints and the reports endpoint tested and verified. Implement a Unified API Gateway to serve as a single, secure access point for external systems (e.g., AI agents), simplifying their interaction and enhancing system flexibility. Implement a parallel client check-in system featuring client accounts (associated with a primary site), profile management using dynamic site-specific questions, QR code check-in via authenticated kiosks (where key answers are copied to the check-in record), and an option for continued anonymous manual check-in (also using dynamic site-specific questions), aimed at improving data quality and check-in efficiency.
2. System Roles and Access Control (Web UI - Staff/Admin Focus):
Core Roles Involved (Staff): kiosk, azwk_staff, director, administrator (as defined in users.role enum). users table now also includes is_site_admin flag. (Future: user_outreach_sites table - see Section 15).
Core Departments Involved (Staff): AZ@Work operational departments, Finance Department (referenced by users.department_id FK to departments.id).
Permission Logic (Staff): Access and actions within Staff/Admin modules (e.g., Budget Module) are determined by the user's assigned Role AND their assigned Department (from the users table, stored in session). Permissions for site-level administration (User Mgmt, Site Config, Client Edit) now also check the is_site_admin flag AND compare the user's assigned site_id with the resource's site_id. (Future: Modified to include checks for user_outreach_sites table).
Role Definitions (Staff):
kiosk: Highly restricted role. Can log into the designated Kiosk interface. Can initiate check-ins via QR Scan or Manual Entry only. Cannot access other modules. Crucially, the active kiosk session (including its associated site_id) is required for server-side validation of QR code check-ins and determining questions for manual check-in.
azwk_staff (assigned to an AZ@Work Department): Permissions as defined previously (Web UI - Budget: Add/Edit assigned Staff budgets, staff fields only, see core columns). Access to other standard staff features. Can also have is_site_admin=true (granting additional site-scoped admin powers) or future outreach assignments.
azwk_staff (assigned to the Finance Department): Permissions as defined previously (Web UI - Budget: Add Admin allocations, Edit Staff fin_* fields, Edit Admin all fields, Delete Admin allocations, see all columns). Access to other standard staff features. Can also have is_site_admin=true (granting additional site-scoped admin powers) or future outreach assignments.
outside_staff: Role exists, permissions TBC. Likely no budget access.
director: Permissions as defined previously (Web UI - Budget: Full Budget Setup, Add/Edit Staff allocations staff fields, Void allocations, see all columns). Multi-site access potentially scoped by site_context. Likely 1 user. Access to other standard staff features, potentially site configuration and client editing.
administrator: System-wide configuration, user management. Full access. Can grant/revoke Site Administrator privileges. Has access to the API Keys tab in Configurations (for managing V1 internal API keys).
Client Users: Clients will have separate accounts (see Section 8, clients table) and log in via a distinct Client Portal, not the staff login system. They have no operational roles or permissions within the staff application context.
3. Authentication System:
Staff/Admin Web UI:
Functionality: Login, Hashing (password_hash field in users), Sessions, RBAC (Role+Dept+is_site_admin flag), last_login. Login filters u.deleted_at IS NULL.
Session Data: Stores user_id, role, site_context, department_id, department_slug, is_site_admin status. Critical for permissions.
Status: Session Timeout, CSRF Protection IMPLEMENTED.
Client Portal Web UI:
Functionality: Separate Client Registration, Client Login (username/password), Hashing (password_hash field in clients), Sessions (distinct from staff sessions).
Session Data: Stores client_id, potentially basic info needed for portal display.
Purpose: Allows clients to manage their profile (dynamic questions), retrieve their QR code, and view service information.
Internal V1 API (Section 5): Requires token-based authentication (API Keys hashed in api_keys table). Used by the Unified API Gateway and potentially other internal trusted services. Authentication and authorization layers are implemented and verified.
Unified API Gateway (Section 6):
Agent-to-Gateway: Requires a dedicated API key specific to the AI agent (hashed in agent_api_keys table). Authentication details passed via HTTP headers. Implemented and verified in Phase 1.
Gateway-to-Internal-V1-API: The Gateway uses its own dedicated, permissioned API key (from api_keys table) to authenticate with the internal V1 API endpoints. Implemented and verified in Phase 1.
4. Site Context Handling for Admin/Director:
Implemented via session context variable (site_context) potentially linking to users.site_id. Allows Directors/Admins with appropriate roles/permissions to switch between site views if the application supports multiple AZ@Work sites and the user has privileges across them. Affects filtering and data visibility in the Web UI. Site Admins' powers are strictly scoped by their assigned users.site_id. (Note: API data scoping for V1 APIs uses API Key permissions and associated IDs, see Section 5. The Unified API Gateway uses agent identity for scoping, see Section 6). Kiosk sessions also have an inherent site_id context.
5. Internal API Specification (V1 - Used by Gateway & Internal Services):
Goal: Provide secure, documented, programmatic access to application functions and data for internal system components, primarily the Unified API Gateway. External systems (e.g., AI agents) should interact via the Unified API Gateway (Section 6).
Base URL: /api/v1/ (Tested on https://seazwf.com/api/v1/)
Data Format: JSON (Content-Type: application/json).
Authentication: API Keys (Hashed in api_keys table). Sent via Authorization: Bearer <key> or X-API-Key: <key> header. Server validates using password_verify(). Validation confirmed functional. Authentication correctly handles missing headers and revoked keys.
Authorization: Based on associated_permissions (TEXT field storing comma-separated or JSON list) stored with the API key. Principle of least privilege. Each endpoint verifies key permissions. Data scope for some endpoints (e.g., reports) may be further limited by associated_user_id or associated_site_id on the api_keys table based on the permissions granted (e.g., read:site_checkin_data vs read:all_checkin_data). Authorization confirmed functional for implemented endpoints.
Error Handling: Standard HTTP status codes (200, 201, 400, 401, 403, 404, 500). JSON error body {"error": {"code": "...", "message": "..."}}. Confirmed functional. Specific 500 errors for unauthenticated/revoked keys have been fixed to return 401.
API Endpoints (Implemented & Verified for V1):
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
Query Check-ins (New General Endpoint for V1):
Method: GET
Path: /checkins
Auth Required: Yes. Permissions: read:all_checkin_data or read:site_checkin_data.
Query Parameters: site_id, start_date, end_date, limit, page.
Response (200 OK): JSON with pagination and check-in objects.
Query Allocations:
Method: GET
Path: /allocations
Auth Required: Yes. Permission Needed: read:budget_allocations (Verified). Additional scope via read:all_allocation_data, read:own_allocation_data.
Query Parameters (Examples): fiscal_year, grant_id, department_id, budget_id, user_id, page, limit.
Response (200 OK): JSON object containing an array of allocation objects and pagination details.
Create Forum Post:
Method: POST
Path: /forum/posts
Auth Required: Yes. Permission Needed: create:forum_post (Verified). Validation for topic existence (checkTopicExists) fixed.
Request Body: {"topic_id": 123, "post_body": "Content of the post..."}
Response (201 Created): JSON object of created post (including created_by_api_key_id).
Generate Reports:
Method: GET
Path: /reports
Auth Required: Yes. Base Permission Needed: generate:reports. Additional scope permissions required based on report type.
Mandatory Query Parameter: type={report_type}. Implemented types: checkin_detail, allocation_detail.
Common Optional Query Parameters: start_date, end_date, limit, page, site_id.
Scope Permissions (Examples): read:all_checkin_data, read:site_checkin_data, read:all_allocation_data, read:own_allocation_data.
Response (200 OK): JSON with pagination and report data.
Read All Forum Posts:
Method: GET
Path: /forum/posts
Auth Required: Yes. Permission Needed: read:all_forum_posts.
Query Parameters: page, limit.
Response (200 OK): JSON with pagination and an array of forum post objects.
Read Recent Forum Posts:
Method: GET
Path: /forum/posts/recent
Auth Required: Yes. Permission Needed: read:recent_forum_posts.
Query Parameters: limit.
Response (200 OK): JSON with an array of recent forum post objects.
Fetch Client Details:
Method: GET
Path: /clients/{client_id}
Auth Required: Yes. Permission Needed: read:client_data.
Response (200 OK): JSON object of the specific client record.
Query Clients:
Method: GET
Path: /clients
Auth Required: Yes. Permission Needed: read:client_data.
Query Parameters: name, email, qr_identifier, page, limit, firstName, lastName.
Response (200 OK): JSON object containing an array of client objects and pagination details.
Defined V1 API Permissions: The current list of permissions for V1 API keys (in api_keys.permissions) includes: read:checkin_data, create:checkin_note, read:budget_allocations, create:forum_post, read:all_forum_posts, read:recent_forum_posts, generate:reports, read:all_checkin_data, read:site_checkin_data, read:all_allocation_data, read:own_allocation_data, read:client_data. The gateway's internal API key will require a combination of these.
6. Unified API Gateway (for AI Agent Interaction)
Purpose: To simplify AI agent interaction with the system's backend functionalities by providing a single, centralized API endpoint. This gateway acts as an intermediary, receiving requests from AI agents, invoking the necessary internal V1 API endpoints (Section 5), and returning a unified response.
Benefits: Simplifies AI agent development, increases backend flexibility (internal V1 APIs can evolve with less impact on agents), centralizes AI agent request logic (authentication, logging, potential rate-limiting), allows for data aggregation.
Gateway Endpoint: /api/gateway/index.php (or similar, e.g., /api/gateway) - Primarily using POST method.
Agent-to-Gateway Request Structure:
Format: JSON
Key Parameters:
action (string, required): Descriptive string identifying the desired operation (e.g., "fetchCheckinDetails", "createForumPost", "queryClients").
params (object, optional): JSON object with parameters for the target internal V1 API (e.g., { "checkin_id": 123 }, { "topic_id": 456, "post_body": "..." }, { "lastName": "Smith" }).
Gateway-to-Agent Response Structure:
Format: JSON
Success Body: {"status": "success", "data": (object/array, optional), "message": (string, optional)}
Error Body: {"status": "error", "error": {"code": "GATEWAY_ERROR_CODE", "message": "...", "details": (optional)}}
Uses standard HTTP status codes.
Authentication:
Agent-to-Gateway: Requires a dedicated API key specific to the AI agent (hashed in agent_api_keys table). Authentication details passed via HTTP headers. Implemented and verified in Phase 1.
Gateway-to-Internal-V1-API: The Gateway uses its own dedicated, permissioned API key (from api_keys table) to authenticate with the internal V1 API endpoints. Implemented and verified in Phase 1.
Internal API Mapping & Granular Permissions (Example action mappings in Technical Design Doc):
The gateway maps the action string from the agent's request to specific internal V1 API endpoints and methods (as per Section 5).
For granular permissions (e.g., an agent action corresponding to read:site_checkin_data or read:own:allocation_data), the gateway uses the authenticated agent's associated_user_id and/or associated_site_id to add necessary filtering parameters (e.g., user_id={id}, site_id={id}) to the internal V1 API calls. The V1 APIs are expected to enforce these filters based on the parameters provided by the gateway. Full implementation for all actions and granular permission logic based on agent identity is now complete as of Phase 2.
Error Handling: The gateway handles its own errors (e.g., invalid agent request format, unmappable action) and relays errors from the internal V1 APIs in a standardized format to the agent, without exposing sensitive internal details. Basic error handling implemented and verified in Phase 1. Refined in Phase 2.
Scalability: Designed to be extensible. New agent-accessible functionalities can be added by:
Ensuring the underlying capability exists as an internal V1 API (Section 5).
Defining a new action string for the agent.
Adding the mapping logic within the gateway from the new action to the V1 API call.
Ensuring the gateway's internal API key has the required V1 permission for the new V1 API.
The decoupling allows backend V1 APIs to evolve with minimal impact on the AI agent, as long as the gateway maintains the contract for defined actions.
7. Page Specifications & Functionality (Web UI):
General: Ongoing UI Refactoring for consistency. Standardize on Soft Deletes (deleted_at) for relevant data. Use Bootstrap v4.5.2 (CSS and JS) consistently.
Staff/Admin Pages:
budget_settings.php: Settings Consolidation (Director role). Tabs for Grants, Budgets, Vendors via includes (budget_settings_panels/). AJAX operations.
budgets.php: Budget Allocations Page (Director/azwk_staff). Role+Dept based access/visibility. Filtering, display table (JOIN users twice for created/updated by name), action buttons. AJAX loading.
Allocation Modals (includes/modals/add_allocation_modal.php, includes/modals/edit_allocation_modal.php): Select2 Vendor, conditional client_name, Void option (Director). Field editability based on Role+Dept+Budget Type. Server-side enforcement.
AJAX Handlers (Internal Staff UI): ajax_get_budgets.php, ajax_allocation_handler.php (strict permission enforcement), ajax_handlers/vendor_handler.php. CSRF protected.
users.php: User Management (Administrator). Includes checkbox for granting Site Admin privileges (is_site_admin). Site Admins can view/edit/delete users for their assigned site only (excluding Admins/Directors).
configurations.php: System & Site Configuration. Includes tabs for various settings. Site Admins can access and modify settings specific to their assigned site (site_questions, site_ads, etc.) via relevant panels (config_panels/). Now includes an "API Keys" tab (config_panels/api_keys_panel.php) for Administrators only, allowing viewing, creation, and revocation of internal V1 API keys (used by the Gateway and other services). (Future: May need a separate UI for agent_api_keys or manage them via Admin for now).
client_editor.php: New page/interface for authorized staff (Admin, Director, Site Admin matching client's site) to search for and edit client profile information.
reports.php: Reports page. Uses standard Bootstrap ul.nav-tabs structure. Includes tabs for "Check-in Data" and "Custom Report Builder".
Other Pages: account.php, index.php (staff entry), notifications.php, alerts.php, ajax_report_handler.php, dashboard.php, ajax_chat_handler.php.
Client Facing Portal: (Separate from Staff UI)
client_register.php: Public page for client account creation.
client_login.php: Public page for client login.
client_portal/profile.php: Client landing page for profile/question management.
client_portal/services.php: Displays service information.
client_portal/qr_code.php: Displays client's QR code.
Kiosk Interface (checkins.php - accessed by kiosk role):
Options: "Scan QR Code" and "Manual Check-in".
Handles QR scan via JS library and AJAX to /kiosk/qr_checkin.
Manual check-in form posts to kiosk_manual_handler.php.
Kiosk Handlers:
/kiosk/qr_checkin (PHP): Verifies kiosk session, client QR, creates check_ins record.
kiosk_manual_handler.php (PHP): Handles manual check-in data.
Includes: header.php, footer.php, modals/ (staff modals).
8. Database Schema (MySQL):
(AUTO_INCREMENT/details omitted. Standard indexes assumed. FK relationships described.)
ai_resume, ai_resume_logs, ai_resume_val: Unchanged.
agent_api_keys:
Comments: Stores dedicated API keys for AI agents to access the Unified API Gateway.
Columns:
id (INT NOT NULL AUTO_INCREMENT PRIMARY KEY)
agent_name (VARCHAR(255) NOT NULL COMMENT 'Descriptive name for the AI agent')
key_hash (VARCHAR(255) NOT NULL UNIQUE COMMENT 'Secure hash of the agent API key')
associated_user_id (INT NULL COMMENT 'User ID in users table this agent might operate as or on behalf of')
associated_site_id (INT NULL COMMENT 'Site ID this agent might be primarily associated with for data scoping')
permissions (TEXT NULL COMMENT 'Optional: For future gateway-level permission overrides, if needed. JSON or CSV.')
created_at (TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)
last_used_at (TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of the last successful use of this key')
revoked_at (DATETIME NULL DEFAULT NULL COMMENT 'Timestamp if the key has been revoked')
Foreign Keys: associated_user_id -> users(id) ON DELETE SET NULL, associated_site_id -> sites(id) ON DELETE SET NULL. Table structure created and added to schema documentation.
api_keys: (Stores internal V1 API keys, including the one used by the Gateway)
Columns: id, api_key_hash (UNIQUE), name, permissions, associated_user_id, associated_site_id, created_at, last_used_at, revoked_at.
Foreign Keys: associated_user_id -> users(id), associated_site_id -> sites(id).
budgets, budget_allocations: Unchanged.
check_ins: Includes client_id (FK), q_veteran, q_age, q_interviewing. Unchanged.
checkin_notes: Unchanged.
client_answers: Unchanged.
checkin_answers: Unchanged.
client_profile_audit_log: Unchanged.
clients: Unchanged.
departments: Unchanged.
finance_department_access: Unchanged.
forum_categories, forum_posts, forum_topics: Unchanged.
global_ads, global_questions, grants, sites, site_ads, site_configurations, site_questions, staff_notifications: Unchanged.
users: Includes is_site_admin flag. Unchanged.
vendors: Unchanged.
(Future Table) user_outreach_sites: (See Section 15).
9. File Management Structure:
Root: budget_settings.php, budgets.php, index.php, checkins.php, kiosk_manual_handler.php, client_register.php, client_login.php, client_editor.php, reports.php.
ajax_handlers/: Various AJAX handlers.
api/v1/: Internal V1 API entry point (index.php), includes, handlers. openapi.yaml (for V1).
api/gateway/: Contains index.php (entry point for Unified API Gateway) and any necessary includes/handlers specific to the gateway logic. Directory and index.php created in Phase 1.
budget_settings_panels/: Include files for budget tabs.
client_portal/: Client-facing pages.
config/: DB connection, constants.
includes/: Common includes (header, footer, modals, helpers).
config_panels/: Configuration page tabs (including api_keys_panel.php for V1 keys).
data_access/: DAL classes/functions.
kiosk/: QR check-in handler logic.
assets/: CSS, JS, images.
10. Design Specification (Web UI):
Core staff layout: Bootstrap v4.5.2. Consistent tabbed navigation.
Client Portal: Clean, simple design (Bootstrap v4.5.2 with distinct styling).
Kiosk Interface: Clear, large buttons/targets. Visual feedback.
API Key Admin UI (configurations.php tab): For managing internal V1 API keys. (Management of agent_api_keys for the Gateway is TBD - initially manual DB entries, potentially future UI).
11. Technical Specifications:
PHP 8.4, MySQL/MariaDB on GoDaddy.
JavaScript (ES6+), JS QR Code scanning library, AJAX.
HTML5, CSS3, Bootstrap 4.5.2.
Server-side QR code generation library.
Internal V1 API uses basic routing in api/v1/index.php.
Unified API Gateway uses routing logic in api/gateway/index.php to map action parameters.
PHP cURL extension required for Gateway to call internal V1 APIs.
12. Security Considerations:
Gateway Security:
The Unified API Gateway endpoint (/api/gateway/) must be robustly secured.
Agent-specific API keys (agent_api_keys) must be hashed.
The gateway's internal API key (in api_keys) must have appropriate, broad permissions for V1 APIs but be securely stored and only used by the gateway script.
Input validation for action and params at the gateway level.
V1 API Security: Strict Role+Dept+is_site_admin for Web UI. API Key + Permissions for V1 API.
Server-side validation for all inputs (Web UI, Kiosk, V1 API, Gateway).
CSRF protection for Web UI forms. Prepared statements (PDO/MySQLi). Secure password hashing. Soft deletes.
HTTPS is critical for all API communication (V1 and Gateway).
Ongoing: Upload Directory Hardening, XSS review, Robust FK error handling (Deferred).
Audit Logging for client data changes.
13. Compliance and Legal:
Data Retention, PII Handling, Email Opt-In (email_preference_jobs).
14. Current Status & Next Steps:
Completed/Resolved (Summary from v1.55):
Core Staff features (Budget, User/Site Admin) largely functional.
Client Account / QR Check-in / Service Info system functional.
V1 API Foundation and specified endpoints (incl. /reports, Forum Read, Client Lookup) implemented and verified.
Admin UI for V1 API Key Management implemented.
Multiple API authentication/endpoint bugs resolved. Reports UI refactored.
Unified API Gateway Technical Design Specification completed and approved.
Unified API Gateway (Phase 1 - Core Functionality) Developed and Verified. This includes:
agent_api_keys table created and schema documented.
api/gateway/index.php endpoint setup.
Agent-to-Gateway authentication implemented and verified.
Gateway-to-Internal-V1-API authentication implemented and verified.
Basic routing/mapping for fetchCheckinDetails and queryClients implemented and verified.
Basic error handling implemented and verified.
Database connection issue during agent authentication identified and resolved.
Temporary debugging code removed.
NEW: Unified API Gateway (Phase 2 - Full Mapping & Granular Permissions) Developed. This includes:
Implementation of mapping logic for all remaining actions defined in the technical specification.
Implementation of logic for granular permissions based on authenticated agent identity (associated_user_id, associated_site_id).
Refinement of error handling for all actions.
Known Issues: None critical not listed as pending.
Required User Action:
Perform system backup.
Review and approve this Living Plan v1.56.
TEST Unified API Gateway Phase 2 Functionality: This is critical. Thoroughly test all implemented actions and verify that granular permissions (based on the associated_user_id and associated_site_id of the agent's API key) are correctly enforced. (See detailed testing instructions below).
Continue to manually manage agent_api_keys in the database for testing/setup until a UI is developed (Future Enhancement).
Ensure the dedicated internal API key for the Gateway (in api_keys table) remains configured with necessary V1 permissions.
Required Developer Action (Next Focus - PRIORITY ORDER):
Refinements to existing Staff UI modules as needed or if critical bugs arise (ongoing/as needed).
(Deferred to near Deployment):
Complete Pending Security Items (from Section 12): Upload Directory Hardening, XSS review, Robust FK error handling.
15. Future Enhancements (Post-MVP, Client System & Gateway Stabilization):
Site Outreach Coordinator Capability.
Dedicated Permissions Checking API Endpoint (via Gateway).
Client Password Reset Functionality.
Upgrade QR Codes to Dynamic/Expiring Tokens.
Bulk Email System.
AI Agent Integration (consuming the Unified API Gateway).
Budget Excel Upload/Import.
UI enhancements for managing staff roles/permissions/flags and potentially agent_api_keys.
Bootstrap 5 Upgrade.
AI Summarization/Analysis.
Advanced Reporting.
Develop functionality for "Custom Report Builder" tab.
User Lookup API Endpoints (V1 internal, exposed via Gateway action).
Site Information API Endpoints (V1 internal, exposed via Gateway action).
Expand Gateway capabilities as new internal V1 APIs are developed.
16. Implementation Plan & Process:
Use Living Plan v1.56 as Single Source of Truth.
Priority: User testing and verification of Unified API Gateway Phase 2 functionality is the immediate priority. Developer focus shifts to general refinements and deferred security items once testing is complete.
Iterative development for the Gateway: Phase 1 (core functionality, few actions - COMPLETED), Phase 2 (full action mapping, granular permissions - COMPLETED).
Require clear code comments, especially around gateway logic (action mapping, auth, internal API calls), V1 API permission logic, session checks, data handling, and security measures.
User (Robert) responsible for providing context, testing, reporting results, and requesting plan updates.
PM (AI) responsible for plan maintenance, context, task breakdown based on the plan.