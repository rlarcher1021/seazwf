-- --------------------------------------------------------
-- Table: agent_api_keys
-- Comments: Stores dedicated API keys for AI agents to access the Unified API Gateway.
-- Foreign Keys: associated_user_id -> users(id) (ON DELETE SET NULL), associated_site_id -> sites(id) (ON DELETE SET NULL)
-- --------------------------------------------------------
agent_api_keys
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		-- Auto Increment assumed
agent_name	varchar(255)	No		Descriptive name for the AI agent/client using the key
key_hash	varchar(255)	No		Hashed version of the API key (do not store raw key) -- Unique
associated_user_id	int(11)	Yes	NULL	Optional: User ID in the main system this agent acts on behalf of
associated_site_id	int(11)	Yes	NULL	Optional: Site ID this agent is primarily associated with
permissions	text	Yes	NULL	JSON encoded permissions structure for future granular control (Phase 2)
created_at	timestamp	No	current_timestamp()
last_used_at	timestamp	Yes	NULL	Timestamp of the last successful use of this key
revoked_at	datetime	Yes	NULL	Timestamp if the key has been revoked
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	1	A	No
key_hash	BTREE	Yes	No	key_hash	1	A	No
fk_agent_keys_user	BTREE	No	No	associated_user_id	1	A	Yes	-- Implicit FK to users.id
fk_agent_keys_site	BTREE	No	No	associated_site_id	1	A	Yes	-- Implicit FK to sites.id

-- --------------------------------------------------------
-- Table: ai_resume
-- Comments: Stores records related to AI Resume generation requests.
-- Foreign Keys: user_id -> users.id (Implicit)
-- --------------------------------------------------------
ai_resume
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No
client_name	varchar(255)	Yes	NULL
email	varchar(255)	Yes	NULL
user_id	int(11)	Yes	NULL	-- Likely FK to users.id
job_applied	varchar(255)	Yes	NULL
threadsID	varchar(255)	Yes	NULL	Identifier from the AI interaction/thread
request	text	Yes	NULL	Details of the request made
status	varchar(255)	No		e.g., Success, Fail reason
request_status	enum('queued', 'running', 'done', 'error')	Yes	queued
created_at	datetime	No	current_timestamp()	Date and time the record was created
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
idx_threadsID	BTREE	No	No	threadsID	0	A	Yes
idx_email	BTREE	No	No	email	0	A	Yes
idx_created_at	BTREE	No	No	created_at	0	A	No
idx_airesume_user	BTREE	No	No	user_id	0	A	Yes	-- Index supporting potential FK

-- --------------------------------------------------------
-- Table: ai_resume_logs
-- Comments: Stores logs for specific AI Resume generation events.
-- Foreign Keys: resume_id -> ai_resume.id (Implicit from index name)
-- --------------------------------------------------------
ai_resume_logs
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No
resume_id	bigint(20)	No		-- FK to ai_resume.id
event	varchar(50)	No
details	text	Yes	NULL
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
fk_ai_logs_resume	BTREE	No	No	resume_id	0	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: ai_resume_val
-- Comments: Stores validation or signup information related to AI resume features.
-- Foreign Keys: user_id -> users.id (Implicit from index name)
-- --------------------------------------------------------
ai_resume_val
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No
name	varchar(255)	Yes	NULL
email	varchar(255)	No		-- Unique
user_id	int(11)	Yes	NULL	-- Likely FK to users.id
site	varchar(100)	Yes	NULL	Identifier for the site/location of signup
signup_time	datetime	No	current_timestamp()	Timestamp when the user was added/validated
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	1	A	No
idx_unique_email	BTREE	Yes	No	email	1	A	No
idx_site	BTREE	No	No	site	1	A	Yes
fk_ai_resumeval_user	BTREE	No	No	user_id	1	A	Yes	-- Index supporting potential FK

-- --------------------------------------------------------
-- Table: api_keys
-- Comments: Stores general API keys for system access (potentially different from agent_api_keys). (Note: Living Plan v1.51 Section 7 might have more comments)
-- Foreign Keys: associated_user_id -> users.id (Implicit), associated_site_id -> sites.id (Implicit)
-- --------------------------------------------------------
api_keys
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
api_key_hash	varchar(255)	Yes	NULL	Secure hash of API key -- Unique (Note: Duplicate index names `idx_key_hash_unique` and `api_key_hash` observed in Schema 1 dump, both unique)
name	varchar(255)	Yes	NULL	User-defined name/label for the key
associated_permissions	text	Yes	NULL	JSON array or comma-separated list of permissions granted (e.g., ["read:client_data", "create:client_note"])
created_at	timestamp	Yes	current_timestamp()
last_used_at	timestamp	Yes	NULL
revoked_at	datetime	Yes	NULL	Timestamp when the key was revoked
associated_user_id	int(11)	Yes	NULL	-- FK to users.id
associated_site_id	int(11)	Yes	NULL	-- FK to sites.id
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	4	A	No
idx_key_hash_unique	BTREE	Yes	No	api_key_hash	4	A	Yes
api_key_hash	BTREE	Yes	No	api_key_hash	4	A	Yes	-- Potential duplicate unique index
fk_api_key_user	BTREE	No	No	associated_user_id	4	A	Yes	-- Index supporting FK
fk_api_key_site	BTREE	No	No	associated_site_id	2	A	Yes	-- Index supporting FK
idx_api_keys_revoked_at	BTREE	No	No	revoked_at	4	A	Yes

-- --------------------------------------------------------
-- Table: budgets
-- Comments: Stores budget information, linked to grants and departments. (Note: Living Plan v1.51 Section 7 might have more comments)
-- Foreign Keys: user_id -> users.id (Implicit), grant_id -> grants.id (Implicit), department_id -> departments.id (Implicit)
-- --------------------------------------------------------
budgets
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
name	varchar(255)	No
user_id	int(11)	Yes	NULL	-- FK to users.id
grant_id	int(11)	No		-- FK to grants.id
department_id	int(11)	No		Department responsible for this budget (e.g., Arizona@Work) -- FK to departments.id
fiscal_year_start	date	No
fiscal_year_end	date	No
budget_type	enum('Staff', 'Admin')	No	Staff
notes	text	Yes	NULL
created_at	timestamp	Yes	current_timestamp()
updated_at	timestamp	Yes	current_timestamp()
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
fk_budget_user_idx	BTREE	No	No	user_id	0	A	Yes	-- Index supporting FK
fk_budget_grant_idx	BTREE	No	No	grant_id	0	A	No	-- Index supporting FK
fk_budget_department_idx	BTREE	No	No	department_id	0	A	No	-- Index supporting FK
idx_budgets_fiscal_year_start	BTREE	No	No	fiscal_year_start	0	A	No
idx_budgets_deleted_at	BTREE	No	No	deleted_at	0	A	Yes

-- --------------------------------------------------------
-- Table: budget_allocations
-- Comments: Stores individual financial transactions against budgets. (Note: Living Plan v1.51 Section 7 might have more comments)
-- Foreign Keys: budget_id -> budgets.id (Implicit), vendor_id -> vendors.id (Implicit), fin_processed_by_user_id -> users.id (Implicit), created_by_user_id -> users.id (Implicit), updated_by_user_id -> users.id (Implicit)
-- --------------------------------------------------------
budget_allocations
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
budget_id	int(11)	No		FK to budgets table
transaction_date	date	No
vendor_id	int(11)	Yes	NULL	-- FK to vendors.id
client_name	varchar(255)	Yes	NULL
voucher_number	varchar(100)	Yes	NULL
enrollment_date	date	Yes	NULL
class_start_date	date	Yes	NULL
purchase_date	date	Yes	NULL
payment_status	enum('P', 'U')	No	U
program_explanation	text	Yes	NULL
funding_dw	decimal(10,2)	Yes	0.00
funding_dw_admin	decimal(10,2)	Yes	0.00
funding_dw_sus	decimal(10,2)	Yes	0.00
funding_adult	decimal(10,2)	Yes	0.00
funding_adult_admin	decimal(10,2)	Yes	0.00
funding_adult_sus	decimal(10,2)	Yes	0.00
funding_rr	decimal(10,2)	Yes	0.00
funding_h1b	decimal(10,2)	Yes	0.00
funding_youth_is	decimal(10,2)	Yes	0.00
funding_youth_os	decimal(10,2)	Yes	0.00
funding_youth_admin	decimal(10,2)	Yes	0.00
fin_voucher_received	varchar(10)	Yes	NULL
fin_accrual_date	date	Yes	NULL
fin_obligated_date	date	Yes	NULL
fin_comments	text	Yes	NULL
fin_expense_code	varchar(50)	Yes	NULL
fin_processed_by_user_id	int(11)	Yes	NULL	FK to users.id (Finance user who processed)
fin_processed_at	datetime	Yes	NULL	-- Timestamp when finance processing occurred (Added in Schema 2)
created_by_user_id	int(11)	No		FK to users.id (Who created the row)
updated_by_user_id	int(11)	Yes	NULL	FK to users.id (Who last updated the row)
created_at	timestamp	Yes	current_timestamp()
updated_at	timestamp	Yes	current_timestamp()
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
idx_alloc_budget_id	BTREE	No	No	budget_id	0	A	No	-- Index supporting FK
idx_alloc_transaction_date	BTREE	No	No	transaction_date	0	A	No
idx_alloc_deleted_at	BTREE	No	No	deleted_at	0	A	Yes
fk_alloc_fin_processed_user_idx	BTREE	No	No	fin_processed_by_user_id	0	A	Yes	-- Index supporting FK
fk_alloc_created_user_idx	BTREE	No	No	created_by_user_id	0	A	No	-- Index supporting FK
fk_alloc_updated_user_idx	BTREE	No	No	updated_by_user_id	0	A	Yes	-- Index supporting FK

-- --------------------------------------------------------
-- Table: checkin_answers
-- Comments: Stores answers to dynamic questions for individual check-ins (manual or client).
-- Foreign Keys: check_in_id -> check_ins.id (Implicit), question_id -> global_questions.id (Implicit)
-- --------------------------------------------------------
checkin_answers
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
check_in_id	int(11)	No		Foreign key to the check_ins table
question_id	int(11)	No		Foreign key to the global_questions table
answer	enum('Yes', 'No')	No		Answer provided by the user
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
idx_checkin_question_unique	BTREE	Yes	No	check_in_id	0	A	No	Ensure only one answer per question per check-in
question_id	0	A	No
fk_checkin_answers_checkin_idx	BTREE	No	No	check_in_id	0	A	No	-- Index supporting FK
fk_checkin_answers_question_idx	BTREE	No	No	question_id	0	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: checkin_notes
-- Comments: Stores notes associated with specific check-ins. (Note: Living Plan v1.51 Section 7 might have more comments)
-- Foreign Keys: check_in_id -> check_ins.id (Implicit), created_by_user_id -> users.id (Implicit), created_by_api_key_id -> api_keys.id (Implicit)
-- --------------------------------------------------------
checkin_notes
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
check_in_id	int(11)	No		FK to check_ins.id
note_text	text	No
created_by_user_id	int(11)	Yes	NULL	FK to users.id (if created via Web UI)
created_by_api_key_id	int(11)	Yes	NULL	FK to api_keys.id (if created via API)
created_at	timestamp	Yes	current_timestamp()
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	3	A	No
idx_checkin_notes_checkin_id	BTREE	No	No	check_in_id	3	A	No	-- Index supporting FK
idx_checkin_notes_deleted_at	BTREE	No	No	deleted_at	3	A	Yes
fk_checkin_note_user_creator_idx	BTREE	No	No	created_by_user_id	3	A	Yes	-- Index supporting FK
fk_checkin_note_api_creator_idx	BTREE	No	No	created_by_api_key_id	3	A	Yes	-- Index supporting FK

-- --------------------------------------------------------
-- Table: check_ins
-- Comments: Record of each client check-in.
-- Foreign Keys: site_id -> sites.id (Implicit), notified_staff_id -> staff_notifications.id (Implicit), client_id -> clients.id (Implicit)
-- --------------------------------------------------------
check_ins
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		Unique Check-in ID
site_id	int(11)	No		FK to the site where check-in occurred
first_name	varchar(100)	No
last_name	varchar(100)	No
check_in_time	timestamp	No	current_timestamp()	When check-in submitted
additional_data	longtext	Yes	NULL	Stores dynamic answers and optional collected email
notified_staff_id	int(11)	Yes	NULL	FK to staff_notifications if staff selected
client_email	varchar(255)	Yes	NULL
q_unemployment_assistance	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_age	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_veteran	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_school	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_employment_layoff	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_unemployment_claim	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_employment_services	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_equus	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
q_seasonal_farmworker	enum('YES', 'NO')	Yes	NULL	-- Pre-defined question answer
client_id	int(11)	Yes	NULL	Foreign Key to the clients table, Null for manual/anonymous check-ins
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	21	A	No
fk_checkins_staff	BTREE	No	No	notified_staff_id	2	A	Yes	-- Index supporting FK
idx_checkins_site_time	BTREE	No	No	site_id	10	A	No
check_in_time	21	A	No
fk_checkins_client_idx	BTREE	No	No	client_id	2	A	Yes	-- Index supporting FK

-- --------------------------------------------------------
-- Table: clients
-- Comments: Stores client account information for portal login and QR check-in.
-- Foreign Keys: site_id -> sites.id (Implicit)
-- --------------------------------------------------------
clients
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
username	varchar(255)	No		Unique username for client login
email	varchar(255)	No		Unique email for client account and AI enrollment
password_hash	varchar(255)	No		Hashed password for client login
first_name	varchar(255)	No
last_name	varchar(255)	No
site_id	int(11)	Yes	NULL	-- FK to sites.id
client_qr_identifier	varchar(255)	No		Persistent UUID/ID for static QR code -- Unique
email_preference_jobs	tinyint(1)	No	0	0=OptOut, 1=OptIn for job/event emails
created_at	timestamp	No	current_timestamp()
updated_at	timestamp	Yes	NULL
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
username	BTREE	Yes	No	username	0	A	No
email	BTREE	Yes	No	email	0	A	No
client_qr_identifier	BTREE	Yes	No	client_qr_identifier	0	A	No
idx_clients_deleted_at	BTREE	No	No	deleted_at	0	A	Yes
idx_clients_site_id	BTREE	No	No	site_id	0	A	Yes	-- Index supporting FK

-- --------------------------------------------------------
-- Table: client_answers
-- Comments: Stores client answers to site-specific dynamic questions.
-- Foreign Keys: client_id -> clients.id (Implicit), question_id -> site_questions.id OR global_questions.id (Needs clarification, see S1 notes)
-- --------------------------------------------------------
client_answers
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
client_id	int(11)	No		-- FK to clients.id
question_id	int(11)	No		-- FK to site_questions.id or global_questions.id (Check application logic)
answer	enum('Yes', 'No')	No
created_at	timestamp	Yes	current_timestamp()
updated_at	timestamp	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	18	A	No
uq_client_question	BTREE	Yes	No	client_id	6	A	No	-- Ensures unique answer per client/question pair
question_id	18	A	No
idx_client_answers_client_id	BTREE	No	No	client_id	6	A	No	-- Index supporting FK
idx_client_answers_question_id	BTREE	No	No	question_id	18	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: client_profile_audit_log
-- Comments: Logs changes made to client profile fields.
-- Foreign Keys: client_id -> clients.id (Implicit), changed_by_user_id -> users.id (Implicit)
-- --------------------------------------------------------
client_profile_audit_log
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
client_id	int(11)	No		-- FK to clients.id
changed_by_user_id	int(11)	No		-- FK to users.id
timestamp	timestamp	No	current_timestamp()
field_name	varchar(255)	No		e.g., 'first_name', 'last_name', 'site_id', 'email_preference_jobs', 'question_id_X'
old_value	text	Yes	NULL	Previous value of the field
new_value	text	Yes	NULL	New value of the field
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
idx_audit_client	BTREE	No	No	client_id	0	A	No	-- Index supporting FK
idx_audit_user	BTREE	No	No	changed_by_user_id	0	A	No	-- Index supporting FK
idx_audit_timestamp	BTREE	No	No	timestamp	0	A	No

-- --------------------------------------------------------
-- Table: departments
-- Comments: Stores global department names and stable slugs. Referenced by users.department_id, budgets.department_id, etc.
-- --------------------------------------------------------
departments
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
name	varchar(150)	No		-- Unique
slug	varchar(160)	Yes	NULL	-- Unique
created_at	timestamp	Yes	current_timestamp()
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	2	A	No
name_UNIQUE	BTREE	Yes	No	name	2	A	No
slug_UNIQUE	BTREE	Yes	No	slug	2	A	Yes

-- --------------------------------------------------------
-- Table: finance_department_access
-- Comments: Maps finance users to the departments they can access budget data for. (Note: Living Plan v1.51 Section 7 might have more comments)
-- Foreign Keys: finance_user_id -> users.id (Implicit), accessible_department_id -> departments.id (Implicit)
-- --------------------------------------------------------
finance_department_access
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
finance_user_id	int(11)	No		FK to users.id where department is Finance
accessible_department_id	int(11)	No		FK to departments.id that the Finance user can access
created_at	timestamp	Yes	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
idx_user_dept_unique	BTREE	Yes	No	finance_user_id	0	A	No	Prevent duplicate access entries
accessible_department_id	0	A	No
fk_fin_access_user_idx	BTREE	No	No	finance_user_id	0	A	No	-- Index supporting FK
fk_fin_access_dept_idx	BTREE	No	No	accessible_department_id	0	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: forum_categories
-- Comments: Forum categories/sections. Defines view/post/reply permissions by role.
-- --------------------------------------------------------
forum_categories
Column	Type	Null	Default	Comments
id (Primary)	int(10)	No
name	varchar(100)	No		Name of the category
description	text	Yes	NULL	Optional description
view_role	enum('azwk_staff', 'outside_staff', 'director', 'administrator')	No	azwk_staff	Minimum role required to view
post_role	enum('azwk_staff', 'outside_staff', 'director', 'administrator')	No	azwk_staff	Minimum role required to create topics
reply_role	enum('azwk_staff', 'outside_staff', 'director', 'administrator')	No	azwk_staff	Minimum role required to reply
display_order	int(11)	No	0	Display order
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	3	A	No

-- --------------------------------------------------------
-- Table: forum_posts
-- Comments: Individual forum posts/messages within a topic.
-- Foreign Keys: topic_id -> forum_topics.id (Implicit), user_id -> users.id (Implicit), created_by_api_key_id -> api_keys.id (Implicit), updated_by_user_id -> users.id (Implicit)
-- --------------------------------------------------------
forum_posts
Column	Type	Null	Default	Comments
id (Primary)	int(10)	No
topic_id	int(10)	No		FK pointing to forum_topics
user_id	int(11)	Yes	NULL	FK pointing to user who wrote post
created_by_api_key_id	int(11)	Yes	NULL	FK to api_keys.id (if created via API)
content	text	No		Post content
created_at	timestamp	No	current_timestamp()
updated_at	timestamp	Yes	NULL	Timestamp of last edit
updated_by_user_id	int(11)	Yes	NULL	FK pointing to user who last edited
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	2	A	No
fk_forum_posts_topic_idx	BTREE	No	No	topic_id	2	A	No	-- Index supporting FK
fk_forum_posts_user_idx	BTREE	No	No	user_id	2	A	Yes	-- Index supporting FK
fk_forum_posts_editor_idx	BTREE	No	No	updated_by_user_id	2	A	Yes	-- Index supporting FK
idx_posts_topic_created	BTREE	No	No	topic_id	2	A	No
created_at	2	A	No
fk_forum_posts_api_creator_idx	BTREE	No	No	created_by_api_key_id	2	A	Yes	-- Index supporting FK

-- --------------------------------------------------------
-- Table: forum_topics
-- Comments: Individual forum discussion threads (topics).
-- Foreign Keys: category_id -> forum_categories.id (Implicit), user_id -> users.id (Implicit), last_post_user_id -> users.id (Implicit)
-- --------------------------------------------------------
forum_topics
Column	Type	Null	Default	Comments
id (Primary)	int(10)	No
category_id	int(10)	No		FK pointing to forum_categories
user_id	int(11)	Yes	NULL	FK pointing to user who started topic
title	varchar(255)	No		Topic Title
is_sticky	tinyint(1)	No	0	0 = Normal, 1 = Pinned
is_locked	tinyint(1)	No	0	0 = Open, 1 = Closed
created_at	timestamp	No	current_timestamp()
last_post_at	timestamp	Yes	NULL	Timestamp of latest post
last_post_user_id	int(11)	Yes	NULL	FK pointing to last poster
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	2	A	No
fk_forum_topics_category_idx	BTREE	No	No	category_id	2	A	No	-- Index supporting FK
fk_forum_topics_user_idx	BTREE	No	No	user_id	2	A	Yes	-- Index supporting FK
fk_forum_topics_last_poster_idx	BTREE	No	No	last_post_user_id	2	A	Yes	-- Index supporting FK
idx_topics_lastpost	BTREE	No	No	last_post_at	2	A	Yes

-- --------------------------------------------------------
-- Table: global_ads
-- Comments: Stores global advertisements (text or image) that can be displayed across sites.
-- --------------------------------------------------------
global_ads
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
ad_type	enum('text', 'image')	No
ad_title	varchar(150)	Yes	NULL
ad_text	text	Yes	NULL
image_path	varchar(255)	Yes	NULL
is_active	tinyint(1)	No	1
created_at	timestamp	Yes	current_timestamp()
updated_at	timestamp	Yes	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	4	A	No

-- --------------------------------------------------------
-- Table: global_questions
-- Comments: Stores globally defined questions that can be used across sites or in check-ins. (Note: Living Plan v1.51 Section 7 might have more comments) Referenced by site_questions.global_question_id, checkin_answers.question_id.
-- --------------------------------------------------------
global_questions
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
question_text	text	No
question_title	varchar(20)	No		-- Unique short identifier/title
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	7	A	No
question_title	BTREE	Yes	No	question_title	7	A	No

-- --------------------------------------------------------
-- Table: grants
-- Comments: Stores information about funding grants. (Note: Living Plan v1.51 Section 7 might have more comments) Referenced by budgets.grant_id.
-- --------------------------------------------------------
grants
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
name	varchar(255)	No		-- Unique
grant_code	varchar(100)	Yes	NULL	-- Unique
description	text	Yes	NULL
start_date	date	Yes	NULL
end_date	date	Yes	NULL
created_at	timestamp	Yes	current_timestamp()
updated_at	timestamp	Yes	current_timestamp()
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
name_UNIQUE	BTREE	Yes	No	name	0	A	No
grant_code_UNIQUE	BTREE	Yes	No	grant_code	0	A	Yes
idx_grants_deleted_at	BTREE	No	No	deleted_at	0	A	Yes

-- --------------------------------------------------------
-- Table: questions
-- Comments: Site-specific check-in questions (YES/NO). (Note: Potential naming confusion with `site_questions`. Schema 1 suggests this might be legacy or for a specific type of question vs `site_questions` linking global ones. Verify usage.)
-- Foreign Keys: site_id -> sites.id (Implicit)
-- --------------------------------------------------------
questions
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		Unique Question ID
site_id	int(11)	No		FK to the site this question belongs to
question_text	text	No		The actual text of the question
question_title	varchar(20)	No
display_order	int(11)	Yes	0	Order on form (0 first, then 1, etc.)
is_active	tinyint(1)	Yes	1	Is this question shown on the form?
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
site_id	BTREE	No	No	site_id	0	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: sites
-- Comments: List of physical Arizona@Work sites. Referenced by users, clients, check_ins, questions, site_ads, site_configurations, site_questions, staff_notifications.
-- --------------------------------------------------------
sites
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		Unique Site ID
name	varchar(100)	No		Name of the site (e.g., Sierra Vista) -- Unique
email_collection_desc	varchar(255)	Yes	NULL	Description shown if email collection is enabled for check-in
is_active	tinyint(1)	Yes	1	Is this site currently active?
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	4	A	No
name	BTREE	Yes	No	name	4	A	No

-- --------------------------------------------------------
-- Table: site_ads
-- Comments: Maps global ads to specific sites, controlling display order and activation. (Note: Living Plan v1.51 Section 7 might have more comments)
-- Foreign Keys: site_id -> sites.id (Implicit), global_ad_id -> global_ads.id (Implicit)
-- --------------------------------------------------------
site_ads
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
site_id	int(11)	No		-- FK to sites.id
global_ad_id	int(11)	No		-- FK to global_ads.id
display_order	int(11)	No	0
is_active	tinyint(1)	No	1
created_at	timestamp	Yes	current_timestamp()
updated_at	timestamp	Yes	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	4	A	No
site_global_ad_unique	BTREE	Yes	No	site_id	2	A	No	-- Ensures unique mapping per site/ad
global_ad_id	4	A	No
global_ad_id	BTREE	No	No	global_ad_id	4	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: site_configurations
-- Comments: Stores site-specific settings (key-value pairs).
-- Foreign Keys: site_id -> sites.id (Implicit)
-- --------------------------------------------------------
site_configurations
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
site_id	int(11)	No		FK to sites table
config_key	varchar(50)	No		Configuration setting name (e.g., allow_email_collection) -- Unique per site_id
config_value	text	Yes	NULL	Configuration value (can be boolean, string, etc.)
created_at	timestamp	No	current_timestamp()
updated_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	11	A	No
site_key_unique	BTREE	Yes	No	site_id	11	A	No	-- Ensures unique key per site
config_key	11	A	No

-- --------------------------------------------------------
-- Table: site_questions
-- Comments: Maps global questions to specific sites, controlling display order and activation. (Note: Living Plan v1.51 Section 7 uses this for site-specific dynamic questions, potentially overlapping/conflicting with `questions` table based on S1 notes).
-- Foreign Keys: site_id -> sites.id (Implicit), global_question_id -> global_questions.id (Implicit)
-- --------------------------------------------------------
site_questions
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
site_id	int(11)	No		-- FK to sites.id
global_question_id	int(11)	No		-- FK to global_questions.id
display_order	int(11)	Yes	0
is_active	tinyint(1)	Yes	1
created_at	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	22	A	No
uq_site_question	BTREE	Yes	No	site_id	11	A	No	-- Ensures unique mapping per site/question
global_question_id	22	A	No
global_question_id	BTREE	No	No	global_question_id	22	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: staff_notifications
-- Comments: Staff available in check-in email notifier dropdown per site.
-- Foreign Keys: site_id -> sites.id (Implicit). Referenced by check_ins.notified_staff_id.
-- --------------------------------------------------------
staff_notifications
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		Unique Staff Notifier ID
site_id	int(11)	No		FK to the site this staff can be notified for
staff_name	varchar(100)	No		Display name in dropdown
staff_email	varchar(255)	No		Email address for notification
is_active	tinyint(1)	Yes	1	Show this staff in the dropdown?
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	1	A	No
site_id	BTREE	No	No	site_id	1	A	No	-- Index supporting FK

-- --------------------------------------------------------
-- Table: system_context
-- Comments: Stores global system-wide key-value settings or context.
-- --------------------------------------------------------
system_context
Column	Type	Null	Default	Comments
context_key (Primary)	varchar(100)	No
context_value	text	Yes	NULL
last_updated	timestamp	No	current_timestamp()
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	context_key	0	A	No

-- --------------------------------------------------------
-- Table: users
-- Comments: User accounts for system access.
-- Foreign Keys: site_id -> sites.id (Implicit), department_id -> departments.id (Implicit)
-- --------------------------------------------------------
users
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		Unique User ID
username	varchar(50)	No		Login username -- Unique
full_name	varchar(100)	Yes	NULL	User full display name
email	varchar(255)	Yes	NULL	User's email address (Note: Schema 1 had this as NOT NULL, UNIQUE)
job_title	varchar(100)	Yes	NULL	User's job title
department_id	int(11)	Yes	NULL	FK to departments table
password_hash	varchar(255)	No		Hashed password
role	enum('kiosk', 'azwk_staff', 'outside_staff', 'director', 'administrator')	No	kiosk	User's role
is_site_admin	tinyint(1)	No	0	Flag indicating if user has site-level admin privileges (0=No, 1=Yes)
site_id	int(11)	Yes	NULL	FK to sites. NULL for Director/Admin (all sites), required for Kiosk/Supervisor/azwk_staff/outside_staff. Primary site association.
last_login	datetime	Yes	NULL	Timestamp of the last successful login
deleted_at	datetime	Yes	NULL	Timestamp if account is soft-deleted
is_active	tinyint(1)	Yes	1	Is the account active?
created_at	timestamp	No	current_timestamp()	When user account was created
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	6	A	No
username	BTREE	Yes	No	username	6	A	No
site_id	BTREE	No	No	site_id	6	A	Yes	-- Index supporting FK
fk_users_department_idx	BTREE	No	No	department_id	6	A	Yes	-- Index supporting FK

-- --------------------------------------------------------
-- Table: vendors
-- Comments: Stores vendor information for budget allocations. (Note: Living Plan v1.51 Section 7 might have more comments). Referenced by budget_allocations.vendor_id.
-- --------------------------------------------------------
vendors
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No
name	varchar(255)	No		-- Unique Vendor Name
client_name_required	tinyint(1)	No	0	0 = No, 1 = Yes. Client name required in budget_allocations if this vendor is selected.
is_active	tinyint(1)	No	1
created_at	timestamp	Yes	current_timestamp()
deleted_at	datetime	Yes	NULL
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No
name_UNIQUE	BTREE	Yes	No	name	0	A	No
idx_vendors_active	BTREE	No	No	is_active	0	A	No
idx_vendors_deleted_at	BTREE	No	No	deleted_at	0	A	Yes