Database Schema: checkin_system
Generated Date: 2025-04-22 (approx)

-------------------------------------
-- Table: ai_resume
-------------------------------------
Columns:
  id (Primary): bigint(20), No Null
  client_name: varchar(255), Yes Null
  email: varchar(255), Yes Null
  user_id: int(11), Yes Null
  job_applied: varchar(255), Yes Null
  threadsID: varchar(255), Yes Null, Comment: Identifier from the AI interaction/thread
  request: text, Yes Null, Comment: Details of the request made
  status: varchar(10), No Null, Comment: e.g., Success, Fail, Pending
  request_status: enum('queued', 'running', 'done', 'error'), No Null, Default: 'queued'
  created_at: datetime, No Null, Default: current_timestamp(), Comment: Date and time the record was created

Indexes:
  PRIMARY: id (Unique)
  idx_threadsID: threadsID
  idx_email: email
  idx_created_at: created_at
  idx_airesume_user: user_id

-------------------------------------
-- Table: ai_resume_logs
-------------------------------------
Columns:
  id (Primary): bigint(20), No Null
  resume_id: bigint(20), No Null
  event: varchar(50), No Null
  details: text, Yes Null
  created_at: timestamp, No Null, Default: current_timestamp()

Indexes:
  PRIMARY: id (Unique)
  fk_ai_logs_resume: resume_id

-------------------------------------
-- Table: ai_resume_val
-------------------------------------
Columns:
  id (Primary): bigint(20), No Null
  name: varchar(255), Yes Null
  email: varchar(255), No Null
  user_id: int(11), Yes Null
  site: varchar(100), Yes Null, Comment: Identifier for the site/location of signup
  signup_time: datetime, No Null, Default: current_timestamp(), Comment: Timestamp when the user was added/validated
  created_at: timestamp, No Null, Default: current_timestamp()

Indexes:
  PRIMARY: id (Unique)
  idx_unique_email: email (Unique)
  idx_site: site
  fk_ai_resumeval_user: user_id

-------------------------------------
-- Table: budgets
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  name: varchar(255), No Null
  user_id: int(11), No Null, Comment: Assigned staff member (for Staff type) or potentially Director/Admin user (for Admin type)
  grant_id: int(11), No Null
  department_id: int(11), No Null, Comment: Department responsible for this budget (e.g., Arizona@Work)
  fiscal_year_start: date, No Null
  fiscal_year_end: date, No Null
  budget_type: enum('Staff', 'Admin'), No Null, Default: 'Staff'
  notes: text, Yes Null
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp() (Note: Should likely be ON UPDATE CURRENT_TIMESTAMP)
  deleted_at: datetime, Yes Null

Indexes:
  PRIMARY: id (Unique)
  fk_budget_user_idx: user_id
  fk_budget_grant_idx: grant_id
  fk_budget_department_idx: department_id
  idx_budgets_fiscal_year_start: fiscal_year_start
  idx_budgets_deleted_at: deleted_at

-------------------------------------
-- Table: budget_allocations
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  budget_id: int(11), No Null, Comment: FK to budgets table
  transaction_date: date, No Null
  payee_vendor: varchar(255), No Null
  voucher_number: varchar(100), Yes Null
  enrollment_date: date, Yes Null
  class_start_date: date, Yes Null
  purchase_date: date, Yes Null
  payment_status: enum('P', 'U'), No Null, Default: 'U'
  program_explanation: text, Yes Null
  funding_dw: decimal(10,2), Yes Null, Default: 0.00
  funding_dw_admin: decimal(10,2), Yes Null, Default: 0.00
  funding_dw_sus: decimal(10,2), Yes Null, Default: 0.00
  funding_adult: decimal(10,2), Yes Null, Default: 0.00
  funding_adult_admin: decimal(10,2), Yes Null, Default: 0.00
  funding_adult_sus: decimal(10,2), Yes Null, Default: 0.00
  funding_rr: decimal(10,2), Yes Null, Default: 0.00
  funding_h1b: decimal(10,2), Yes Null, Default: 0.00
  funding_youth_is: decimal(10,2), Yes Null, Default: 0.00
  funding_youth_os: decimal(10,2), Yes Null, Default: 0.00
  funding_youth_admin: decimal(10,2), Yes Null, Default: 0.00
  fin_voucher_received: varchar(10), Yes Null
  fin_accrual_date: date, Yes Null
  fin_obligated_date: date, Yes Null
  fin_comments: text, Yes Null
  fin_expense_code: varchar(50), Yes Null
  fin_processed_by_user_id: int(11), Yes Null, Comment: FK to users.id (Finance user who processed)
  fin_processed_at: datetime, Yes Null
  created_by_user_id: int(11), No Null, Comment: FK to users.id (Who created the row)
  updated_by_user_id: int(11), Yes Null, Comment: FK to users.id (Who last updated the row)
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp() (Note: Should likely be ON UPDATE CURRENT_TIMESTAMP)
  deleted_at: datetime, Yes Null

Indexes:
  PRIMARY: id (Unique)
  idx_alloc_budget_id: budget_id
  idx_alloc_transaction_date: transaction_date
  idx_alloc_deleted_at: deleted_at
  fk_alloc_fin_processed_user_idx: fin_processed_by_user_id
  fk_alloc_created_user_idx: created_by_user_id
  fk_alloc_updated_user_idx: updated_by_user_id

-------------------------------------
-- Table: check_ins
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  site_id: int(11), No Null
  first_name: varchar(100), No Null
  last_name: varchar(100), No Null
  check_in_time: timestamp, Yes Null, Default: current_timestamp()
  notified_staff_id: int(11), Yes Null
  client_email: varchar(100), Yes Null
  q_veteran: enum('YES', 'NO'), Yes Null
  q_age: enum('YES', 'NO'), Yes Null
  q_interviewing: enum('YES', 'NO'), Yes Null

Indexes:
  PRIMARY: id (Unique)
  check_ins_site_id_fk: site_id
  check_ins_notified_staff_id_fk: notified_staff_id
  idx_checkins_site_time: (site_id, check_in_time)

-------------------------------------
-- Table: departments
-------------------------------------
Comments: Stores global department names
Columns:
  id (Primary): int(11), No Null
  name: varchar(150), No Null
  slug: varchar(160), Yes Null
  created_at: timestamp, Yes Null, Default: current_timestamp()
  deleted_at: datetime, Yes Null

Indexes:
  PRIMARY: id (Unique)
  name_UNIQUE: name (Unique)
  slug_UNIQUE: slug (Unique)

-------------------------------------
-- Table: finance_department_access
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  finance_user_id: int(11), No Null, Comment: FK to users.id where department is Finance
  accessible_department_id: int(11), No Null, Comment: FK to departments.id that the Finance user can access
  created_at: timestamp, Yes Null, Default: current_timestamp()

Indexes:
  PRIMARY: id (Unique)
  idx_user_dept_unique: (finance_user_id, accessible_department_id) (Unique), Comment: Prevent duplicate access entries
  fk_fin_access_user_idx: finance_user_id
  fk_fin_access_dept_idx: accessible_department_id

-------------------------------------
-- Table: forum_categories
-------------------------------------
Comments: Forum categories/sections
Columns:
  id (Primary): int(10), No Null
  name: varchar(100), No Null, Comment: Name of the category
  description: text, Yes Null, Comment: Optional description
  view_role: enum('azwk_staff', 'outside_staff', 'director', 'administrator'), No Null, Default: 'azwk_staff', Comment: Minimum role required to view
  post_role: enum('azwk_staff', 'outside_staff', 'director', 'administrator'), No Null, Default: 'azwk_staff', Comment: Minimum role required to create topics
  reply_role: enum('azwk_staff', 'outside_staff', 'director', 'administrator'), No Null, Default: 'azwk_staff', Comment: Minimum role required to reply
  display_order: int(11), No Null, Default: 0, Comment: Display order
  created_at: timestamp, No Null, Default: current_timestamp()

Indexes:
  PRIMARY: id (Unique)

-------------------------------------
-- Table: forum_posts
-------------------------------------
Comments: Individual forum posts/messages
Columns:
  id (Primary): int(10), No Null
  topic_id: int(10), No Null, Comment: FK pointing to forum_topics
  user_id: int(11), Yes Null, Comment: FK pointing to user who wrote post
  content: text, No Null, Comment: Post content
  created_at: timestamp, No Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Comment: Timestamp of last edit
  updated_by_user_id: int(11), Yes Null, Comment: FK pointing to user who last edited

Indexes:
  PRIMARY: id (Unique)
  fk_forum_posts_topic_idx: topic_id
  fk_forum_posts_user_idx: user_id
  fk_forum_posts_editor_idx: updated_by_user_id
  idx_posts_topic_created: (topic_id, created_at)

-------------------------------------
-- Table: forum_topics
-------------------------------------
Comments: Individual forum discussion threads
Columns:
  id (Primary): int(10), No Null
  category_id: int(10), No Null, Comment: FK pointing to forum_categories
  user_id: int(11), Yes Null, Comment: FK pointing to user who started topic
  title: varchar(255), No Null, Comment: Topic Title
  is_sticky: tinyint(1), No Null, Default: 0, Comment: 0 = Normal, 1 = Pinned
  is_locked: tinyint(1), No Null, Default: 0, Comment: 0 = Open, 1 = Closed
  created_at: timestamp, No Null, Default: current_timestamp()
  last_post_at: timestamp, Yes Null, Comment: Timestamp of latest post
  last_post_user_id: int(11), Yes Null, Comment: FK pointing to last poster

Indexes:
  PRIMARY: id (Unique)
  fk_forum_topics_category_idx: category_id
  fk_forum_topics_user_idx: user_id
  fk_forum_topics_last_poster_idx: last_post_user_id
  idx_topics_lastpost: last_post_at

-------------------------------------
-- Table: global_ads
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  ad_type: enum('text', 'image'), No Null
  ad_title: varchar(150), Yes Null
  ad_text: text, Yes Null
  image_path: varchar(255), Yes Null
  is_active: tinyint(1), No Null, Default: 1
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp() (Note: Should likely be ON UPDATE CURRENT_TIMESTAMP)

Indexes:
  PRIMARY: id (Unique)

-------------------------------------
-- Table: global_questions
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  question_text: text, No Null
  question_title: varchar(50), No Null, Comment: Stores base name, must be unique
  created_at: timestamp, Yes Null, Default: current_timestamp()

Indexes:
  PRIMARY: id (Unique)
  question_title: question_title (Unique)

-------------------------------------
-- Table: grants
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  name: varchar(255), No Null
  grant_code: varchar(100), Yes Null
  description: text, Yes Null
  start_date: date, Yes Null
  end_date: date, Yes Null
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp() (Note: Should likely be ON UPDATE CURRENT_TIMESTAMP)
  deleted_at: datetime, Yes Null

Indexes:
  PRIMARY: id (Unique)
  name_UNIQUE: name (Unique)
  grant_code_UNIQUE: grant_code (Unique)
  idx_grants_deleted_at: deleted_at

-------------------------------------
-- Table: sites
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  name: varchar(150), No Null
  email_collection_desc: text, Yes Null
  is_active: tinyint(1), No Null, Default: 1

Indexes:
  PRIMARY: id (Unique)

-------------------------------------
-- Table: site_ads
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  site_id: int(11), No Null
  global_ad_id: int(11), No Null
  display_order: int(11), No Null, Default: 0
  is_active: tinyint(1), No Null, Default: 1
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp() (Note: Should likely be ON UPDATE CURRENT_TIMESTAMP)

Indexes:
  PRIMARY: id (Unique)
  site_global_ad_unique: (site_id, global_ad_id) (Unique)
  site_ads_global_ad_id_fk: global_ad_id

-------------------------------------
-- Table: site_configurations
-------------------------------------
Columns:
  site_id (Primary): int(11), No Null
  config_key (Primary): varchar(50), No Null
  config_value: text, Yes Null, Comment: Configuration value (can be boolean, string, etc.)
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp() (Note: Should likely be ON UPDATE CURRENT_TIMESTAMP)

Indexes:
  PRIMARY: (site_id, config_key) (Unique)

-------------------------------------
-- Table: site_questions
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  site_id: int(11), No Null
  global_question_id: int(11), No Null
  display_order: int(11), No Null, Default: 0
  is_active: tinyint(1), No Null, Default: 1
  created_at: timestamp, Yes Null, Default: current_timestamp()

Indexes:
  PRIMARY: id (Unique)
  site_global_question_unique: (site_id, global_question_id) (Unique)
  site_questions_gq_id_fk: global_question_id

-------------------------------------
-- Table: staff_notifications
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  site_id: int(11), No Null
  staff_name: varchar(100), No Null
  staff_email: varchar(100), No Null
  is_active: tinyint(1), No Null, Default: 1

Indexes:
  PRIMARY: id (Unique)
  staff_notifications_site_id_fk: site_id

-------------------------------------
-- Table: users
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  username: varchar(50), No Null
  full_name: varchar(100), No Null
  email: varchar(100), No Null
  job_title: varchar(100), Yes Null, Comment: User's job title
  department_id: int(11), Yes Null
  password_hash: varchar(255), No Null
  role: enum('kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator'), No Null, Default: 'kiosk'
  site_id: int(11), Yes Null
  last_login: timestamp, Yes Null
  is_active: tinyint(1), No Null, Default: 1
  created_at: timestamp, Yes Null, Default: current_timestamp()
  deleted_at: datetime, Yes Null

Indexes:
  PRIMARY: id (Unique)
  username: username (Unique)
  email: email (Unique)
  users_site_id_fk: site_id
  fk_users_department_idx: department_id

-------------------------------------
-- Table: vendors (NEW)
-------------------------------------
Columns:
  id (Primary): int, No Null, Auto Increment
  name: varchar(255), No Null, Unique
  client_name_required: tinyint(1), No Null, Default: 0, Comment: 0 = No, 1 = Yes. Client name required in budget_allocations if this vendor is selected.
  is_active: tinyint(1), No Null, Default: 1
  created_at: timestamp, Null, Default: current_timestamp()
  deleted_at: datetime, Null

Indexes:
  PRIMARY: id (Unique)
  name_UNIQUE: name (Unique)
  idx_vendors_active: is_active
  idx_vendors_deleted_at: deleted_at

-------------------------------------
-- Table: budget_allocations (MODIFIED)
-------------------------------------
Columns:
  id (Primary): int(11), No Null
  budget_id: int(11), No Null, Comment: FK to budgets table
  transaction_date: date, No Null
  vendor_id: int, Null, Comment: FK to vendors table (Was payee_vendor VARCHAR) - Should be NOT NULL after migration/setup.
  client_name: varchar(255), Null, Comment: NEW - Conditionally required based on vendor.id
  voucher_number: varchar(100), Yes Null
  enrollment_date: date, Yes Null
  class_start_date: date, Yes Null
  purchase_date: date, Yes Null
  payment_status: enum('P', 'U', 'Void'), No Null, Default: 'U', Comment: ADDED 'Void'
  program_explanation: text, Yes Null
  funding_dw: decimal(10,2), Yes Null, Default: 0.00
  funding_dw_admin: decimal(10,2), Yes Null, Default: 0.00
  funding_dw_sus: decimal(10,2), Yes Null, Default: 0.00
  funding_adult: decimal(10,2), Yes Null, Default: 0.00
  funding_adult_admin: decimal(10,2), Yes Null, Default: 0.00
  funding_adult_sus: decimal(10,2), Yes Null, Default: 0.00
  funding_rr: decimal(10,2), Yes Null, Default: 0.00
  funding_h1b: decimal(10,2), Yes Null, Default: 0.00
  funding_youth_is: decimal(10,2), Yes Null, Default: 0.00
  funding_youth_os: decimal(10,2), Yes Null, Default: 0.00
  funding_youth_admin: decimal(10,2), Yes Null, Default: 0.00
  fin_voucher_received: varchar(10), Yes Null
  fin_accrual_date: date, Yes Null
  fin_obligated_date: date, Yes Null
  fin_comments: text, Yes Null
  fin_expense_code: varchar(50), Yes Null
  fin_processed_by_user_id: int(11), Yes Null, Comment: FK to users.id (Finance user who processed)
  fin_processed_at: datetime, Yes Null
  created_by_user_id: int(11), No Null, Comment: FK to users.id (Who created the row)
  updated_by_user_id: int(11), Yes Null, Comment: FK to users.id (Who last updated the row)
  created_at: timestamp, Yes Null, Default: current_timestamp()
  updated_at: timestamp, Yes Null, Default: current_timestamp()
  deleted_at: datetime, Yes Null

Indexes: (Include fk_alloc_vendor index now)
  PRIMARY: id (Unique)
  idx_alloc_budget_id: budget_id
  idx_alloc_transaction_date: transaction_date
  idx_alloc_deleted_at: deleted_at
  fk_alloc_fin_processed_user_idx: fin_processed_by_user_id
  fk_alloc_created_user_idx: created_by_user_id
  fk_alloc_updated_user_idx: updated_by_user_id
  -- fk_alloc_vendor: vendor_id (Add index if not automatically created by FK constraint)

Foreign Keys: (Include fk_alloc_vendor)
  fk_alloc_budget: budget_id -> budgets(id)
  fk_alloc_vendor: vendor_id -> vendors(id)
  fk_alloc_fin_processed_user: fin_processed_by_user_id -> users(id)
  fk_alloc_created_user: created_by_user_id -> users(id)
  fk_alloc_updated_user: updated_by_user_id -> users(id)

-------------------------------------