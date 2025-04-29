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
-- Table: api_keys (NEW - Requires SQL CREATE)
Comments: Stores API keys for external system access.
Columns: id (Primary), key_hash (Unique, Comment: Secure hash of API key), description, associated_permissions (TEXT, Comment: JSON/list of permissions), created_at, last_used_at (Timestamp, Null), is_active (TINYINT, Default: 1).
Indexes: PRIMARY, idx_key_hash_unique, idx_api_keys_active (is_active).
-- Table: budgets
Columns: id (Primary), name, user_id (FK -> users, Allows Null), grant_id (FK -> grants), department_id (FK -> departments), fiscal_year_start, fiscal_year_end, budget_type (ENUM 'Staff', 'Admin'), notes, created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, fk_budget_user_idx, fk_budget_grant_idx, fk_budget_department_idx, idx_budgets_fiscal_year_start, idx_budgets_deleted_at.
Foreign Keys: user_id -> users(id), grant_id -> grants(id), department_id -> departments(id). (Note: user_id FK needs review due to NULL allowance).
-- Table: budget_allocations
Columns: id (Primary), budget_id (FK -> budgets), transaction_date, vendor_id (FK -> vendors, Null), client_name (VARCHAR, Null), voucher_number, enrollment_date, class_start_date, purchase_date, payment_status (ENUM 'P', 'U', 'Void'), program_explanation, funding_* (DECIMAL fields), fin_* (VARCHAR/DATE/TEXT fields), fin_processed_by_user_id (FK -> users, Null), fin_processed_at (DATETIME, Null), created_by_user_id (FK -> users), updated_by_user_id (FK -> users, Null), created_at, updated_at, deleted_at (DATETIME, Null).
Indexes: PRIMARY, idx_alloc_budget_id, idx_alloc_transaction_date, idx_alloc_deleted_at, fk_alloc_fin_processed_user_idx, fk_alloc_created_user_idx, fk_alloc_updated_user_idx, fk_alloc_vendor (vendor_id).
Foreign Keys: budget_id -> budgets(id), vendor_id -> vendors(id), fin_processed_by_user_id -> users(id), created_by_user_id -> users(id), updated_by_user_id -> users(id).
-- Table: check_ins
Columns: id (Primary), site_id (FK -> sites), first_name, last_name, check_in_time, notified_staff_id (FK -> users, Null), client_email, q_veteran (ENUM), q_age (ENUM), q_interviewing (ENUM).
Indexes: PRIMARY, check_ins_site_id_fk, check_ins_notified_staff_id_fk, idx_checkins_site_time (site_id, check_in_time).
Foreign Keys: site_id -> sites(id), notified_staff_id -> users(id).
-- Table: checkin_notes (NEW - Requires SQL CREATE)
Comments: Stores notes associated with specific check-in records.
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
-- Table: forum_posts
Comments: Individual forum posts/messages
Columns: id (Primary), topic_id (FK -> forum_topics), user_id (FK -> users, Null), content (TEXT), created_at, updated_at (Timestamp, Null), updated_by_user_id (FK -> users, Null).
Indexes: PRIMARY, fk_forum_posts_topic_idx, fk_forum_posts_user_idx, fk_forum_posts_editor_idx, idx_posts_topic_created (topic_id, created_at).
Foreign Keys: topic_id -> forum_topics(id), user_id -> users(id), updated_by_user_id -> users(id).
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