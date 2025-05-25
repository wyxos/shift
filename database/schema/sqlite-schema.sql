CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "organisations"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "author_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("author_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "organisations_name_unique" on "organisations"("name");
CREATE TABLE IF NOT EXISTS "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" varchar not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE TABLE IF NOT EXISTS "project_users"(
  "id" integer primary key autoincrement not null,
  "project_id" integer not null,
  "user_id" integer,
  "user_email" varchar not null,
  "user_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("project_id") references projects("id") on delete cascade on update no action
);
CREATE TABLE IF NOT EXISTS "organisation_users"(
  "id" integer primary key autoincrement not null,
  "organisation_id" integer not null,
  "user_id" integer,
  "user_email" varchar not null,
  "user_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("organisation_id") references organisations("id") on delete cascade on update no action
);
CREATE TABLE IF NOT EXISTS "task_metadata"(
  "id" integer primary key autoincrement not null,
  "task_id" integer not null,
  "environment" varchar not null default 'production',
  "source_url" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("task_id") references "tasks"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "external_users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "email" varchar,
  "environment" varchar not null,
  "url" varchar not null,
  "external_id" integer not null
);
CREATE TABLE IF NOT EXISTS "tasks"(
  "id" integer primary key autoincrement not null,
  "title" varchar not null,
  "project_id" integer not null,
  "status" varchar not null default('pending'),
  "priority" varchar not null default('medium'),
  "description" text,
  "due_date" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "submitter_id" integer,
  "submitter_type" varchar,
  foreign key("project_id") references projects("id") on delete cascade on update no action
);
CREATE TABLE IF NOT EXISTS "clients"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "organisation_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("organisation_id") references organisations("id") on delete cascade on update no action
);
CREATE TABLE IF NOT EXISTS "projects"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "client_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "token" varchar,
  "organisation_id" integer,
  "author_id" integer,
  foreign key("organisation_id") references organisations("id") on delete cascade on update no action,
  foreign key("client_id") references clients("id") on delete cascade on update no action,
  foreign key("author_id") references "users"("id") on delete set null
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2023_06_20_000000_add_missing_project_user_id_to_tasks_table',1);
INSERT INTO migrations VALUES(5,'2023_06_20_000001_rename_name_to_title_in_tasks_table',1);
INSERT INTO migrations VALUES(6,'2023_06_20_000002_add_project_user_id_and_title_to_tasks_table',1);
INSERT INTO migrations VALUES(7,'2025_04_25_055900_create_organisations_table',1);
INSERT INTO migrations VALUES(8,'2025_04_26_102521_create_clients_table',1);
INSERT INTO migrations VALUES(9,'2025_04_27_051008_create_projects_table',1);
INSERT INTO migrations VALUES(10,'2025_04_27_051021_create_tasks_table',1);
INSERT INTO migrations VALUES(11,'2025_04_28_011536_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(12,'2025_05_04_155514_rename_column_to_title',1);
INSERT INTO migrations VALUES(13,'2025_05_05_030356_create_project_users_table',1);
INSERT INTO migrations VALUES(14,'2025_05_05_100033_add_project_user_id_to_tasks_table',1);
INSERT INTO migrations VALUES(15,'2025_05_19_000000_create_organisation_users_table',1);
INSERT INTO migrations VALUES(16,'2025_05_20_000000_modify_project_users_table',1);
INSERT INTO migrations VALUES(17,'2025_05_20_000001_create_external_task_sources_table',1);
INSERT INTO migrations VALUES(18,'2025_05_20_000001_modify_organisation_users_table',1);
INSERT INTO migrations VALUES(19,'2025_05_21_000000_add_project_api_token_to_projects_table',1);
INSERT INTO migrations VALUES(20,'2025_05_23_004217_rename_project_api_token_to_token_in_projects_table',1);
INSERT INTO migrations VALUES(21,'2025_05_23_020559_rename_external_task_sources_to_external_users',1);
INSERT INTO migrations VALUES(22,'2025_05_23_020635_create_task_metadata_table',1);
INSERT INTO migrations VALUES(23,'2025_05_24_040825_add_author_id_back_to_tasks_table',1);
INSERT INTO migrations VALUES(24,'2025_05_25_000001_create_task_external_user_pivot_table',1);
INSERT INTO migrations VALUES(25,'2025_05_26_000001_add_external_user_id_to_tasks_table',1);
INSERT INTO migrations VALUES(26,'2025_06_01_000001_update_tasks_table_for_polymorphic_submitter',1);
INSERT INTO migrations VALUES(27,'2025_06_02_000001_update_tasks_submitter_to_use_user_instead_of_project_user',1);
INSERT INTO migrations VALUES(28,'2025_05_24_084745_remove_author_id_from_tasks_table',2);
INSERT INTO migrations VALUES(29,'2025_05_24_091245_update_clients_and_projects_tables_for_optional_relationships',3);
INSERT INTO migrations VALUES(30,'2023_06_21_000000_add_author_id_to_projects_table',4);
INSERT INTO migrations VALUES(31,'2025_05_24_235301_add_environment_and_url_to_external_users_table',5);
INSERT INTO migrations VALUES(33,'2025_05_25_014806_add_external_id_to_external_users_table',6);
