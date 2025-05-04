Database Schema (MySQL):
ai_resume
Comments: None provided in dump
Columns:
id: bigint(20), NOT NULL (Primary)
client_name: varchar(255), NULL
email: varchar(255), NULL
user_id: int(11), NULL
job_applied: varchar(255), NULL
threadsID: varchar(255), NULL - Identifier from the AI interaction/thread
request: text, NULL - Details of the request made
status: varchar(255), NOT NULL - e.g., Success, Fail reason
request_status: enum('queued', 'running', 'done', 'error'), NULL - queued
created_at: datetime, NOT NULL - current_timestamp() - Date and time the record was created
Indexes:
PRIMARY (id)
idx_threadsID (threadsID)
idx_email (email)
idx_created_at (created_at)
idx_airesume_user (user\_id)
Foreign Keys: Implicitly user_id -> users.id
ai_resume_logs
Comments: None provided in dump
Columns:
id: bigint(20), NOT NULL (Primary)
resume_id: bigint(20), NOT NULL
event: varchar(50), NOT NULL
details: text, NULL
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
fk_ai_logs_resume (resume_id)
Foreign Keys: resume_id -> ai_resume.id (Implicit from index name)
ai_resume_val
Comments: None provided in dump
Columns:
id: bigint(20), NOT NULL (Primary)
name: varchar(255), NULL
email: varchar(255), NOT NULL
user_id: int(11), NULL
site: varchar(100), NULL - Identifier for the site/location of signup
signup_time: datetime, NOT NULL - current_timestamp() - Timestamp when the user was added/validated
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
idx_unique_email (email) - Unique
idx_site (site)
fk_ai_resumeval_user (user\_id)
Foreign Keys: user_id -> users.id (Implicit from index name)
api_keys
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
api_key_hash: varchar(255), NULL - Secure hash of API key
name: varchar(255), NULL - User-defined name/label for the key
associated_permissions: text, NULL - JSON array or comma-separated list of permissions granted (e.g., ["read:client_data", "create:client_note"])
created_at: timestamp, NULL - current_timestamp()
last_used_at: timestamp, NULL
revoked_at: datetime, NULL - Timestamp when the key was revoked
associated_user_id: int(11), NULL
associated_site_id: int(11), NULL
Indexes:
PRIMARY (id)
idx_key_hash_unique (api_key_hash) - Unique
api_key_hash (api_key_hash) - Unique (Duplicate index name?)
fk_api_key_user (associated\_user\_id)
fk_api_key_site (associated\_site\_id)
idx_api_keys_revoked_at (revoked\_at)
Foreign Keys:
associated_user_id -> users.id (Implicit from index name)
associated_site_id -> sites.id (Implicit from index name)
budgets
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
name: varchar(255), NOT NULL
user_id: int(11), NULL
grant_id: int(11), NOT NULL
department_id: int(11), NOT NULL - Department responsible for this budget (e.g., Arizona@Work)
fiscal_year_start: date, NOT NULL
fiscal_year_end: date, NOT NULL
budget_type: enum('Staff', 'Admin'), NOT NULL - Staff
notes: text, NULL
created_at: timestamp, NULL - current_timestamp()
updated_at: timestamp, NULL - current_timestamp()
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
fk_budget_user_idx (user\_id)
fk_budget_grant_idx (grant\_id)
fk_budget_department_idx (department\_id)
idx_budgets_fiscal_year_start (fiscal\_year\_start)
idx_budgets_deleted_at (deleted\_at)
Foreign Keys:
user_id -> users.id (Implicit from index name)
grant_id -> grants.id (Implicit from index name)
department_id -> departments.id (Implicit from index name)
budget_allocations
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
budget_id: int(11), NOT NULL - FK to budgets table
transaction_date: date, NOT NULL
vendor_id: int(11), NULL
client_name: varchar(255), NULL
voucher_number: varchar(100), NULL
enrollment_date: date, NULL
class_start_date: date, NULL
purchase_date: date, NULL
payment_status: enum('P', 'U'), NOT NULL - U
program_explanation: text, NULL
funding_dw: decimal(10,2), NULL - 0.00
funding_dw_admin: decimal(10,2), NULL - 0.00
funding_dw_sus: decimal(10,2), NULL - 0.00
funding_adult: decimal(10,2), NULL - 0.00
funding_adult_admin: decimal(10,2), NULL - 0.00
funding_adult_sus: decimal(10,2), NULL - 0.00
funding_rr: decimal(10,2), NULL - 0.00
funding_h1b: decimal(10,2), NULL - 0.00
funding_youth_is: decimal(10,2), NULL - 0.00
funding_youth_os: decimal(10,2), NULL - 0.00
funding_youth_admin: decimal(10,2), NULL - 0.00
fin_voucher_received: varchar(10), NULL
fin_accrual_date: date, NULL
fin_obligated_date: date, NULL
fin_comments: text, NULL
fin_expense_code: varchar(50), NULL
fin_processed_by_user_id: int(11), NULL - FK to users.id (Finance user who processed)
created_by_user_id: int(11), NOT NULL - FK to users.id (Who created the row)
updated_by_user_id: int(11), NULL - FK to users.id (Who last updated the row)
created_at: timestamp, NULL - current_timestamp()
updated_at: timestamp, NULL - current_timestamp()
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
idx_alloc_budget_id (budget\_id)
idx_alloc_transaction_date (transaction\_date)
idx_alloc_deleted_at (deleted\_at)
fk_alloc_fin_processed_user_idx (fin\_processed\_by\_user\_id)
fk_alloc_created_user_idx (created\_by\_user\_id)
fk_alloc_updated_user_idx (updated\_by\_user\_id)
Foreign Keys:
budget_id -> budgets.id (Implicit)
vendor_id -> vendors.id (Implicit)
fin_processed_by_user_id -> users.id (Implicit from comment/index)
created_by_user_id -> users.id (Implicit from comment/index)
updated_by_user_id -> users.id (Implicit from comment/index)
checkin_answers
Comments: Stores answers to dynamic questions for individual check-ins (manual or client)
Columns:
id: int(11), NOT NULL (Primary)
check_in_id: int(11), NOT NULL - Foreign key to the check_ins table
question_id: int(11), NOT NULL - Foreign key to the global_questions table
answer: enum('Yes', 'No'), NOT NULL - Answer provided by the user
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
idx_checkin_question_unique (check\_in\_id, question\_id) - Unique - Ensure only one answer per question per check-in
fk_checkin_answers_checkin_idx (check\_in\_id)
fk_checkin_answers_question_idx (question\_id)
Foreign Keys:
check_in_id -> check_ins.id (Implicit from comment/index)
question_id -> global_questions.id (Implicit from comment/index)
checkin_notes
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
check_in_id: int(11), NOT NULL - FK to check_ins.id
note_text: text, NOT NULL
created_by_user_id: int(11), NULL - FK to users.id (if created via Web UI)
created_by_api_key_id: int(11), NULL - FK to api_keys.id (if created via API)
created_at: timestamp, NULL - current_timestamp()
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
idx_checkin_notes_checkin_id (check\_in\_id)
idx_checkin_notes_deleted_at (deleted\_at)
fk_checkin_note_user_creator_idx (created\_by\_user\_id)
fk_checkin_note_api_creator_idx (created\_by\_api\_key\_id)
Foreign Keys:
check_in_id -> check_ins.id (Implicit from comment/index)
created_by_user_id -> users.id (Implicit from comment/index)
created_by_api_key_id -> api_keys.id (Implicit from comment/index)
check_ins
Comments: Record of each client check-in
Columns:
id: int(11), NOT NULL (Primary) - Unique Check-in ID
site_id: int(11), NOT NULL - FK to the site where check-in occurred
first_name: varchar(100), NOT NULL
last_name: varchar(100), NOT NULL
check_in_time: timestamp, NOT NULL - current_timestamp() - When check-in submitted
additional_data: longtext, NULL - Stores dynamic answers and optional collected email
notified_staff_id: int(11), NULL - FK to staff_notifications if staff selected
client_email: varchar(255), NULL
q_unemployment_assistance: enum('YES', 'NO'), NULL
q_age: enum('YES', 'NO'), NULL
q_veteran: enum('YES', 'NO'), NULL
q_school: enum('YES', 'NO'), NULL
q_employment_layoff: enum('YES', 'NO'), NULL
q_unemployment_claim: enum('YES', 'NO'), NULL
q_employment_services: enum('YES', 'NO'), NULL
q_equus: enum('YES', 'NO'), NULL
q_seasonal_farmworker: enum('YES', 'NO'), NULL
client_id: int(11), NULL - Foreign Key to the clients table, Null for manual/anonymous check-ins
Indexes:
PRIMARY (id)
fk_checkins_staff (notified\_staff\_id)
idx_checkins_site_time (site\_id, check\_in\_time)
fk_checkins_client_idx (client\_id)
Foreign Keys:
site_id -> sites.id (Implicit)
notified_staff_id -> staff_notifications.id (Implicit from index name/comment)
client_id -> clients.id (Implicit from comment/index)
clients
Comments: Stores client account information for portal login and QR check-in.
Columns:
id: int(11), NOT NULL (Primary)
username: varchar(255), NOT NULL - Unique username for client login
email: varchar(255), NOT NULL - Unique email for client account and AI enrollment
password_hash: varchar(255), NOT NULL - Hashed password for client login
first_name: varchar(255), NOT NULL
last_name: varchar(255), NOT NULL
site_id: int(11), NULL
client_qr_identifier: varchar(255), NOT NULL - Persistent UUID/ID for static QR code
email_preference_jobs: tinyint(1), NOT NULL - 0 - 0=OptOut, 1=OptIn for job/event emails
created_at: timestamp, NOT NULL - current_timestamp()
updated_at: timestamp, NULL
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
username (username) - Unique
email (email) - Unique
client_qr_identifier (client\_qr\_identifier) - Unique
idx_clients_deleted_at (deleted\_at)
idx_clients_site_id (site\_id)
Foreign Keys: site_id -> sites.id (Implicit from index name)
client_answers
Comments: Stores client answers to site-specific dynamic questions.
Columns:
id: int(11), NOT NULL (Primary)
client_id: int(11), NOT NULL
question_id: int(11), NOT NULL
answer: enum('Yes', 'No'), NOT NULL
created_at: timestamp, NULL - current_timestamp()
updated_at: timestamp, NULL
Indexes:
PRIMARY (id)
uq_client_question (client\_id, question\_id) - Unique
idx_client_answers_client_id (client\_id)
idx_client_answers_question_id (question\_id)
Foreign Keys:
client_id -> clients.id (Implicit from index name/comment)
question_id -> site_questions.id or global_questions.id? (Comment says global_questions, Index says question_id, Living Plan v1.51 Section 7 says site_questions. Needs clarification, but FK relationship is present).
client_profile_audit_log
Comments: Logs changes made to client profile fields.
Columns:
id: int(11), NOT NULL (Primary)
client_id: int(11), NOT NULL
changed_by_user_id: int(11), NOT NULL
timestamp: timestamp, NOT NULL - current_timestamp()
field_name: varchar(255), NOT NULL - e.g., 'first_name', 'last_name', 'site_id', 'email_preference_jobs', 'question_id_X'
old_value: text, NULL - Previous value of the field
new_value: text, NULL - New value of the field
Indexes:
PRIMARY (id)
idx_audit_client (client\_id)
idx_audit_user (changed\_by\_user\_id)
idx_audit_timestamp (timestamp)
Foreign Keys:
client_id -> clients.id (Implicit from index name/comment)
changed_by_user_id -> users.id (Implicit from index name/comment)
departments
Comments: Stores global department names and stable slugs
Columns:
id: int(11), NOT NULL (Primary)
name: varchar(150), NOT NULL
slug: varchar(160), NULL
created_at: timestamp, NULL - current_timestamp()
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
name_UNIQUE (name) - Unique
slug_UNIQUE (slug) - Unique
Foreign Keys: None explicitly listed/implied here, but users.department_id points here.
finance_department_access
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
finance_user_id: int(11), NOT NULL - FK to users.id where department is Finance
accessible_department_id: int(11), NOT NULL - FK to departments.id that the Finance user can access
created_at: timestamp, NULL - current_timestamp()
Indexes:
PRIMARY (id)
idx_user_dept_unique (finance\_user\_id, accessible\_department\_id) - Unique - Prevent duplicate access entries
fk_fin_access_user_idx (finance\_user\_id)
fk_fin_access_dept_idx (accessible\_department\_id)
Foreign Keys:
finance_user_id -> users.id (Implicit from comment/index)
accessible_department_id -> departments.id (Implicit from comment/index)
forum_categories
Comments: Forum categories/sections
Columns:
id: int(10), NOT NULL (Primary)
name: varchar(100), NOT NULL - Name of the category
description: text, NULL - Optional description
view_role: enum('azwk_staff', 'outside_staff', 'director', 'administrator'), NOT NULL - azwk_staff - Minimum role required to view
post_role: enum('azwk_staff', 'outside_staff', 'director', 'administrator'), NOT NULL - azwk_staff - Minimum role required to create topics
reply_role: enum('azwk_staff', 'outside_staff', 'director', 'administrator'), NOT NULL - azwk_staff - Minimum role required to reply
display_order: int(11), NOT NULL - 0 - Display order
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
Foreign Keys: None explicitly listed/implied here.
forum_posts
Comments: Individual forum posts/messages
Columns:
id: int(10), NOT NULL (Primary)
topic_id: int(10), NOT NULL - FK pointing to forum_topics
user_id: int(11), NULL - FK pointing to user who wrote post
created_by_api_key_id: int(11), NULL - FK to api_keys.id (if created via API)
content: text, NOT NULL - Post content
created_at: timestamp, NOT NULL - current_timestamp()
updated_at: timestamp, NULL - Timestamp of last edit
updated_by_user_id: int(11), NULL - FK pointing to user who last edited
Indexes:
PRIMARY (id)
fk_forum_posts_topic_idx (topic\_id)
fk_forum_posts_user_idx (user\_id)
fk_forum_posts_editor_idx (updated\_by\_user\_id)
idx_posts_topic_created (topic\_id, created\_at)
fk_forum_posts_api_creator_idx (created\_by\_api\_key\_id)
Foreign Keys:
topic_id -> forum_topics.id (Implicit from comment/index)
user_id -> users.id (Implicit from comment/index)
updated_by_user_id -> users.id (Implicit from comment/index)
created_by_api_key_id -> api_keys.id (Implicit from comment/index)
forum_topics
Comments: Individual forum discussion threads
Columns:
id: int(10), NOT NULL (Primary)
category_id: int(10), NOT NULL - FK pointing to forum_categories
user_id: int(11), NULL - FK pointing to user who started topic
title: varchar(255), NOT NULL - Topic Title
is_sticky: tinyint(1), NOT NULL - 0 - 0 = Normal, 1 = Pinned
is_locked: tinyint(1), NOT NULL - 0 - 0 = Open, 1 = Closed
created_at: timestamp, NOT NULL - current_timestamp()
last_post_at: timestamp, NULL - Timestamp of latest post
last_post_user_id: int(11), NULL - FK pointing to last poster
Indexes:
PRIMARY (id)
fk_forum_topics_category_idx (category\_id)
fk_forum_topics_user_idx (user\_id)
fk_forum_topics_last_poster_idx (last\_post\_user\_id)
idx_topics_lastpost (last\_post\_at)
Foreign Keys:
category_id -> forum_categories.id (Implicit from comment/index)
user_id -> users.id (Implicit from comment/index)
last_post_user_id -> users.id (Implicit from comment/index)
global_ads
Comments: None provided in dump
Columns:
id: int(11), NOT NULL (Primary)
ad_type: enum('text', 'image'), NOT NULL
ad_title: varchar(150), NULL
ad_text: text, NULL
image_path: varchar(255), NULL
is_active: tinyint(1), NOT NULL - 1
created_at: timestamp, NULL - current_timestamp()
updated_at: timestamp, NULL - current_timestamp()
Indexes:
PRIMARY (id)
Foreign Keys: None explicitly listed/implied here.
global_questions
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
question_text: text, NOT NULL
question_title: varchar(20), NOT NULL
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
question_title (question\_title) - Unique
Foreign Keys: None explicitly listed/implied here, but site_questions.global_question_id and checkin_answers.question_id point here.
grants
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
name: varchar(255), NOT NULL
grant_code: varchar(100), NULL
description: text, NULL
start_date: date, NULL
end_date: date, NULL
created_at: timestamp, NULL - current_timestamp()
updated_at: timestamp, NULL - current_timestamp()
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
name_UNIQUE (name) - Unique
grant_code_UNIQUE (grant\_code) - Unique
idx_grants_deleted_at (deleted\_at)
Foreign Keys: None explicitly listed/implied here, but budgets.grant_id points here.
questions
Comments: Site-specific check-in questions (YES/NO) (Note: Living Plan v1.51 Section 7 uses site_questions for this purpose, possibly a naming inconsistency)
Columns:
id: int(11), NOT NULL (Primary) - Unique Question ID
site_id: int(11), NOT NULL - FK to the site this question belongs to
question_text: text, NOT NULL - The actual text of the question
question_title: varchar(20), NOT NULL
display_order: int(11), NULL - 0 - Order on form (0 first, then 1, etc.)
is_active: tinyint(1), NULL - 1 - Is this question shown on the form?
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
site_id (site\_id)
Foreign Keys: site_id -> sites.id (Implicit from comment/index)
sites
Comments: List of physical Arizona@Work sites
Columns:
id: int(11), NOT NULL (Primary) - Unique Site ID
name: varchar(100), NOT NULL - Name of the site (e.g., Sierra Vista)
email_collection_desc: varchar(255), NULL
is_active: tinyint(1), NULL - 1 - Is this site currently active?
Indexes:
PRIMARY (id)
name (name) - Unique
Foreign Keys: None explicitly listed/implied here, but users.site_id, clients.site_id, check_ins.site_id, questions.site_id, site_ads.site_id, site_configurations.site_id, site_questions.site_id, staff_notifications.site_id point here.
site_ads
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
site_id: int(11), NOT NULL
global_ad_id: int(11), NOT NULL
display_order: int(11), NOT NULL - 0
is_active: tinyint(1), NOT NULL - 1
created_at: timestamp, NULL - current_timestamp()
updated_at: timestamp, NULL - current_timestamp()
Indexes:
PRIMARY (id)
site_global_ad_unique (site\_id, global\_ad\_id) - Unique
global_ad_id (global\_ad\_id)
Foreign Keys:
site_id -> sites.id (Implicit from index name/comment)
global_ad_id -> global_ads.id (Implicit from index name/comment)
site_configurations
Comments: Stores site-specific settings
Columns:
id: int(11), NOT NULL (Primary)
site_id: int(11), NOT NULL - FK to sites table
config_key: varchar(50), NOT NULL - Configuration setting name (e.g., allow_email_collection)
config_value: text, NULL - Configuration value (can be boolean, string, etc.)
created_at: timestamp, NOT NULL - current_timestamp()
updated_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
site_key_unique (site\_id, config\_key) - Unique
Foreign Keys: site_id -> sites.id (Implicit from comment/index)
site_questions
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 uses this for site-specific dynamic questions)
Columns:
id: int(11), NOT NULL (Primary)
site_id: int(11), NOT NULL
global_question_id: int(11), NOT NULL
display_order: int(11), NULL - 0
is_active: tinyint(1), NULL - 1
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
uq_site_question (site\_id, global\_question\_id) - Unique
global_question_id (global\_question\_id)
Foreign Keys:
site_id -> sites.id (Implicit from index name/comment)
global_question_id -> global_questions.id (Implicit from index name/comment)
staff_notifications
Comments: Staff available in check-in email notifier dropdown per site
Columns:
id: int(11), NOT NULL (Primary) - Unique Staff Notifier ID
site_id: int(11), NOT NULL - FK to the site this staff can be notified for
staff_name: varchar(100), NOT NULL - Display name in dropdown
staff_email: varchar(255), NOT NULL - Email address for notification
is_active: tinyint(1), NULL - 1 - Show this staff in the dropdown?
Indexes:
PRIMARY (id)
site_id (site\_id)
Foreign Keys:
site_id -> sites.id (Implicit from comment/index)
check_ins.notified_staff_id points here (Implicit from comment/index)
system_context
Comments: None provided in dump
Columns:
context_key: varchar(100), NOT NULL (Primary)
context_value: text, NULL
last_updated: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (context\_key)
Foreign Keys: None explicitly listed/implied here.
users
Comments: User accounts for system access
Columns:
id: int(11), NOT NULL (Primary) - Unique User ID
username: varchar(50), NOT NULL - Login username
full_name: varchar(100), NULL - User full display name
email: varchar(255), NULL
job_title: varchar(100), NULL - User's job title
department_id: int(11), NULL
password_hash: varchar(255), NOT NULL - Hashed password (use password_hash)
role: enum('kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'), NOT NULL - kiosk
is_site_admin: tinyint(1), NOT NULL - 0 - Flag indicating if user has site-level admin privileges (0=No, 1=Yes)
site_id: int(11), NULL - FK to sites. NULL for Director/Admin (all sites), required for Kiosk/Supervisor
last_login: datetime, NULL - Timestamp of the last successful login
deleted_at: datetime, NULL
is_active: tinyint(1), NULL - 1 - Is the account active?
created_at: timestamp, NOT NULL - current_timestamp()
Indexes:
PRIMARY (id)
username (username) - Unique
site_id (site\_id)
fk_users_department_idx (department\_id)
Foreign Keys:
department_id -> departments.id (Implicit from index name/comment)
site_id -> sites.id (Implicit from index name/comment)
Various FKs point to users.id from other tables (e.g., budgets, budget_allocations, checkin_notes, client_profile_audit_log, forum_posts, forum_topics, finance_department_access).
vendors
Comments: None provided in dump (Note: Living Plan v1.51 Section 7 has comments)
Columns:
id: int(11), NOT NULL (Primary)
name: varchar(255), NOT NULL
client_name_required: tinyint(1), NOT NULL - 0 - 0 = No, 1 = Yes. Client name required in budget_allocations if this vendor is selected.
is_active: tinyint(1), NOT NULL - 1
created_at: timestamp, NULL - current_timestamp()
deleted_at: datetime, NULL
Indexes:
PRIMARY (id)
name_UNIQUE (name) - Unique
idx_vendors_active (is\_active)
idx_vendors_deleted_at (deleted\_at)
Foreign Keys: budget_allocations.vendor_id points here (Implicit).
This format should be easier to read and reference than the raw dump. It aligns with the tables listed in the Living Plan.
Noteworthy Points from the dump compared to the Living Plan v1.51 Section 7:
api_keys: The schema provided in the dump matches the corrected schema in Living Plan v1.51 Section 7, confirming the SQL script you ran was successful. It does show a potential duplicate index name (api_key_hash), but that's a minor detail.
check_ins: The q_* columns in the dump (q_unemployment_assistance, q_age, q_veteran, etc.) are different and more numerous than the q_veteran, q_age, q_interviewing specified in Living Plan v1.51 Section 7. The comment in the dump mentions additional_data storing dynamic answers, while the comment in the Living Plan says q_* are populated from client_answers during QR scans. This suggests a potential discrepancy in how dynamic question answers are stored/handled between the Living Plan documentation and the actual database schema/code. Living Plan v1.51 says checkin_answers stores manual answers and check_ins.q_* stores QR answers copied from client_answers. The schema dump's check_ins table has many q_* columns and also additional_data, while checkin_answers exists. This needs clarification for consistency between documentation and implementation.
checkin_answers: The FK comment says global_questions, but the index and Living Plan say site_questions. Consistency is needed.
client_answers: Similar to checkin_answers, the index points to question_id (likely site_questions.id) but the comment in the dump says global_questions. Living Plan v1.51 says site_questions. Consistency needed.
questions table: This table exists in the dump with site-specific questions, FK to sites, etc., but it's not explicitly mentioned or described in Living Plan v1.51 Section 7. The plan describes site_questions and global_questions being used for dynamic questions. This questions table might be an old or unused table, or it's the one actually being used instead of/in conjunction with site_questions. This needs clarification and potential update to the Living Plan schema section.
global_questions: Exists as expected.
site_questions: Exists as expected and used for FKs in client_answers and checkin_answers per Living Plan v1.51 (though the dump comments are inconsistent).
users: Includes is_active column, which is not in Living Plan v1.51 Section 7. The plan uses deleted_at for soft deletes. This is a discrepancy.