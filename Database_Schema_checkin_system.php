Database Schema (MySQL):
(Structure based on user-provided schema details dated approx 2025-04-29 and applied API additions. AUTO_INCREMENT details omitted. Standard indexes like PRIMARY assumed unless noted. Foreign key relationships described.)
ai_resume:
Columns: id (Primary), client_name, email, user_id (FK -> users), job_applied, threadsID, request, status, request_status (ENUM), created_at.
Indexes: PRIMARY, idx_threadsID, idx_email, idx_created_at, idx_airesume_user (user_id).
Foreign Keys: user_id -> users(id).
ai_resume_logs:
Columns: id (Primary), resume_id (FK -> ai_resume), event, details, created_at.
Indexes: PRIMARY, fk_ai_logs_resume (resume_id).
Foreign Keys: resume_id -> ai_resume(id).
ai_resume_val:
Columns: id (Primary), name, email (Unique), user_id (FK -> users), site, signup_time, created_at.
Indexes: PRIMARY, idx_unique_email, idx_site, fk_ai_resumeval_user (user_id).
Foreign Keys: user_id -> users(id).
api_keys: (Modified - Schema Updated)
Comments: Stores API keys for external system access. associated_user_id / associated_site_id support scoped permissions.
Columns: id (Primary), key_hash (Unique, Comment: Secure hash of API key), description, associated_permissions (TEXT, Comment: JSON/list of permissions), created_at, last_used_at (Timestamp, Null), is_active (TINYINT, Default: 1), associated_user_id (INT, Null, FK -> users), associated_site_id (INT, Null, FK -> sites).
Indexes: PRIMARY, idx_key_hash_unique, idx_api_keys_active (is_active), fk_api_key_user (associated_user_id), fk_api_key_site (associated_site_id).
Foreign Keys: associated_user_id -> users(id) (ON DELETE SET NULL ON UPDATE CASCADE), associated_site_id -> sites(id) (ON DELETE SET NULL ON UPDATE CASCADE).
budgets:
Columns: id (Primary), name, user_id (FK -> users, Null), grant_id (FK -> grants), department_id (FK -> departments), fiscal_year_start, fiscal_year_end, budget_type (ENUM 'Staff', 'Admin'), notes, created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, fk_budget_user_idx, fk_budget_grant_idx, fk_budget_department_idx, idx_budgets_fiscal_year_start, idx_budgets_deleted_at.
Foreign Keys: user_id -> users(id), grant_id -> grants(id), department_id -> departments(id).
budget_allocations:
Columns: id (Primary), budget_id (FK -> budgets), transaction_date, vendor_id (FK -> vendors, Null), client_name (VARCHAR, Null), voucher_number, enrollment_date, class_start_date, purchase_date, payment_status (ENUM 'P', 'U', 'Void'), program_explanation, funding_* (DECIMAL fields), fin_* (VARCHAR/DATE/TEXT fields), fin_processed_by_user_id (FK -> users, Null), fin_processed_at (DATETIME, Null), created_by_user_id (FK -> users), updated_by_user_id (FK -> users, Null), created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, idx_alloc_budget_id, idx_alloc_transaction_date, idx_alloc_deleted_at, fk_alloc_fin_processed_user_idx, fk_alloc_created_user_idx, fk_alloc_updated_user_idx, fk_alloc_vendor (vendor_id).
Foreign Keys: budget_id -> budgets(id), vendor_id -> vendors(id), fin_processed_by_user_id -> users(id), created_by_user_id -> users(id), updated_by_user_id -> users(id).
check_ins: (Modified - Schema Updated)
Columns: id (Primary), site_id (FK -> sites), first_name, last_name, check_in_time, notified_staff_id (FK -> users, Null), client_email, q_veteran ENUM('Yes', 'No', 'Decline to Answer') NULL DEFAULT NULL, q_age ENUM('16-18', '19-24', '25-44', '45-54', '55-64', '65+', 'Decline to Answer') NULL DEFAULT NULL, q_interviewing ENUM('Yes', 'No', 'Decline to Answer') NULL DEFAULT NULL, [other demographic q_* fields...], client_id (INT, Null, FK -> clients).
Indexes: PRIMARY, check_ins_site_id_fk, check_ins_notified_staff_id_fk, idx_checkins_site_time (site_id, check_in_time), fk_checkins_client_idx (client_id).
Foreign Keys: site_id -> sites(id), notified_staff_id -> users(id), client_id -> clients(id) (ON DELETE SET NULL ON UPDATE CASCADE).
Comment: Stores data from manual entries (client_id=NULL) and QR scans (client_id populated).
checkin_answers: (NEW - Added for dynamic check-in questions)
Comments: Stores answers to dynamic questions for individual check-ins (manual or client).
Columns:
  id (INT, Primary, Auto Increment)
  check_in_id (INT, NOT NULL, FK -> check_ins, Comment: Foreign key to the check_ins table)
  question_id (INT, NOT NULL, FK -> global_questions, Comment: Foreign key to the global_questions table)
  answer (ENUM('Yes', 'No'), NOT NULL, Comment: Answer provided by the user)
  created_at (TIMESTAMP, Default: CURRENT_TIMESTAMP)
Indexes: PRIMARY, fk_checkin_answers_checkin_idx (check_in_id), fk_checkin_answers_question_idx (question_id), idx_checkin_question_unique (check_in_id, question_id, Unique, Comment: Ensure only one answer per question per check-in).
Foreign Keys: check_in_id -> check_ins(id) (ON DELETE CASCADE), question_id -> global_questions(id) (ON DELETE CASCADE).

checkin_notes: (Structure Confirmed)
Comments: Stores notes associated with specific check-in records. created_by_api_key_id tracks API creation.
Columns: id (Primary), check_in_id (FK -> check_ins), note_text (TEXT), created_by_user_id (FK -> users, Null), created_by_api_key_id (FK -> api_keys, Null), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, idx_checkin_notes_checkin_id, idx_checkin_notes_deleted_at, fk_checkin_note_user_creator_idx (created_by_user_id), fk_checkin_note_api_creator_idx (created_by_api_key_id).
Foreign Keys: check_in_id -> check_ins(id), created_by_user_id -> users(id), created_by_api_key_id -> api_keys(id).
client_answers: (NEW - Added for dynamic questions)
Comments: Stores client answers to dynamic questions from global_questions.
Columns:
id (Primary)
client_id (INT, FK -> clients)
question_id (INT, FK -> global_questions)
answer (TEXT, Null)
created_at (TIMESTAMP, Default: CURRENT_TIMESTAMP)
updated_at (TIMESTAMP, Null, ON UPDATE CURRENT_TIMESTAMP)
Indexes: PRIMARY, fk_client_answers_client_idx (client_id), fk_client_answers_question_idx (question_id), idx_client_question_unique (client_id, question_id).
Foreign Keys: client_id -> clients(id) (ON DELETE CASCADE), question_id -> global_questions(id) (ON DELETE CASCADE).
clients: (Modified - Added site_id)
Comments: Stores client account information for portal login and QR check-in.
Columns:
id (Primary)
username (VARCHAR, Unique)
email (VARCHAR, Unique)
password_hash (VARCHAR)
first_name (VARCHAR)
last_name (VARCHAR)
site_id (INT, Null, FK -> sites)
client_qr_identifier (VARCHAR, Unique, Comment: Persistent UUID/ID for static QR code)
email_preference_jobs (TINYINT(1), NOT NULL, Default: 0, Comment: 0=OptOut, 1=OptIn for job/event emails)
q_veteran ENUM('Yes', 'No', 'Decline to Answer') NULL DEFAULT NULL
q_age ENUM('16-18', '19-24', '25-44', '45-54', '55-64', '65+', 'Decline to Answer') NULL DEFAULT NULL
q_interviewing ENUM('Yes', 'No', 'Decline to Answer') NULL DEFAULT NULL
[other relevant q_* demographic fields...]
created_at (TIMESTAMP, Default: CURRENT_TIMESTAMP)
updated_at (TIMESTAMP, Null, ON UPDATE CURRENT_TIMESTAMP)
deleted_at (DATETIME, Null)
Indexes: PRIMARY, username_UNIQUE, email_UNIQUE, qr_identifier_UNIQUE, idx_clients_deleted_at, fk_clients_site_idx (site_id).
Foreign Keys: site_id -> sites(id) (ON DELETE SET NULL ON UPDATE CASCADE).
client_profile_audit_log: (NEW - Added for Site Admin client profile changes)
Comments: Logs changes made to client profile fields by authorized staff.
Columns:
  id (INT, Primary, Auto Increment)
  client_id (INT, NOT NULL, FK -> clients, Comment: The client whose profile was changed)
  changed_by_user_id (INT, NOT NULL, FK -> users, Comment: The staff user who made the change)
  timestamp (TIMESTAMP, NOT NULL, Default: CURRENT_TIMESTAMP, Comment: When the change occurred)
  field_name (VARCHAR(255), NOT NULL, Comment: e.g., 'first_name', 'last_name', 'site_id', 'email_preference_jobs', 'question_id_X')
  old_value (TEXT, NULL, Comment: Previous value of the field)
  new_value (TEXT, NULL, Comment: New value of the field)
Indexes: PRIMARY, idx_audit_client (client_id), idx_audit_user (changed_by_user_id), idx_audit_timestamp (timestamp).
Foreign Keys: client_id -> clients(id) (ON DELETE CASCADE), changed_by_user_id -> users(id) (ON DELETE RESTRICT).
departments:
Comments: Stores global department names.
Columns: id (Primary), name (Unique), slug (Unique), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, name_UNIQUE, slug_UNIQUE.
finance_department_access:
Comments: Legacy? Maps specific users to departments they can access (potentially for other modules). Budget access defined by Role+Dept in users table.
Columns: id (Primary), finance_user_id (FK -> users), accessible_department_id (FK -> departments), created_at.
Indexes: PRIMARY, idx_user_dept_unique (finance_user_id, accessible_department_id), fk_fin_access_user_idx, fk_fin_access_dept_idx.
Foreign Keys: finance_user_id -> users(id), accessible_department_id -> departments(id).
forum_categories:
Comments: Forum categories/sections.
Columns: id (Primary), name, description, view_role (ENUM), post_role (ENUM), reply_role (ENUM), display_order, created_at.
Indexes: PRIMARY.
forum_posts: (Structure Confirmed - Modified for API)
Comments: Individual forum posts/messages. created_by_api_key_id tracks API creation.
Columns: id (Primary), topic_id (FK -> forum_topics), user_id (FK -> users, Null), created_by_api_key_id (FK -> api_keys, Null), content (TEXT), created_at, updated_at (Timestamp, Null), updated_by_user_id (FK -> users, Null).
Indexes: PRIMARY, fk_forum_posts_topic_idx, fk_forum_posts_user_idx, fk_forum_posts_editor_idx (updated_by_user_id), fk_forum_posts_api_creator_idx (created_by_api_key_id), idx_posts_topic_created (topic_id, created_at).
Foreign Keys: topic_id -> forum_topics(id), user_id -> users(id), updated_by_user_id -> users(id), created_by_api_key_id -> api_keys(id).
forum_topics:
Comments: Individual forum discussion threads.
Columns: id (Primary), category_id (FK -> forum_categories), user_id (FK -> users, Null), title, is_sticky (TINYINT), is_locked (TINYINT), created_at, last_post_at (Timestamp, Null), last_post_user_id (FK -> users, Null).
Indexes: PRIMARY, fk_forum_topics_category_idx, fk_forum_topics_user_idx, fk_forum_topics_last_poster_idx, idx_topics_lastpost.
Foreign Keys: category_id -> forum_categories(id), user_id -> users(id), last_post_user_id -> users(id).
global_ads:
Columns: id (Primary), ad_type (ENUM), ad_title, ad_text, image_path, is_active (TINYINT), created_at, updated_at.
Indexes: PRIMARY.
global_questions:
Columns: id (Primary), question_text (TEXT), question_title (Unique), created_at.
Indexes: PRIMARY, question_title.
grants:
Columns: id (Primary), name (Unique), grant_code (Unique), description, start_date, end_date, created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, name_UNIQUE, grant_code_UNIQUE, idx_grants_deleted_at.
sites:
Columns: id (Primary), name, email_collection_desc, is_active (TINYINT).
Indexes: PRIMARY.
site_ads:
Columns: id (Primary), site_id (FK -> sites), global_ad_id (FK -> global_ads), display_order, is_active (TINYINT), created_at, updated_at.
Indexes: PRIMARY, site_global_ad_unique (site_id, global_ad_id), site_ads_global_ad_id_fk.
Foreign Keys: site_id -> sites(id), global_ad_id -> global_ads(id).
site_configurations:
Columns: site_id (Primary, FK -> sites), config_key (Primary), config_value (TEXT), created_at, updated_at.
Indexes: PRIMARY (site_id, config_key).
Foreign Keys: site_id -> sites(id).
site_questions:
Columns: id (Primary), site_id (FK -> sites), global_question_id (FK -> global_questions), display_order, is_active (TINYINT), created_at.
Indexes: PRIMARY, site_global_question_unique (site_id, global_question_id), site_questions_gq_id_fk.
Foreign Keys: site_id -> sites(id), global_question_id -> global_questions(id).
staff_notifications:
Columns: id (Primary), site_id (FK -> sites), staff_name, staff_email, is_active (TINYINT).
Indexes: PRIMARY, staff_notifications_site_id_fk.
Foreign Keys: site_id -> sites(id).
users: (Modified - Added is_site_admin)
Comments: Stores Staff/Admin/Kiosk users. is_site_admin grants site-specific admin rights.
Columns: id (Primary), username (Unique), full_name, email (Unique), job_title, department_id (FK -> departments, Null), password_hash, role (ENUM 'kiosk','azwk_staff','outside_staff','director','administrator'), is_site_admin (TINYINT(1), NOT NULL, Default: 0, Comment: Flag indicating if user has site-level admin privileges (0=No, 1=Yes)), site_id (FK -> sites, Null), last_login (Timestamp, Null), is_active (TINYINT), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, username, email, users_site_id_fk, fk_users_department_idx.
Foreign Keys: department_id -> departments(id), site_id -> sites(id).
vendors:
Columns: id (Primary), name (Unique), client_name_required (TINYINT), is_active (TINYINT), created_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, name_UNIQUE, idx_vendors_active, idx_vendors_deleted_at.