# SHIFT Database Schema

This document describes the database schema for the SHIFT project, including all tables, columns, data types, primary keys, foreign keys, relationships, and indexes.

## Table of Contents
- [Users](#users)
- [Organisations](#organisations)
- [Organisation Users](#organisation-users)
- [Clients](#clients)
- [Projects](#projects)
- [Project Users](#project-users)
- [Tasks](#tasks)
- [Task Metadata](#task-metadata)
- [External Users](#external-users)
- [Attachments](#attachments)
- [System Tables](#system-tables)

## Users
**Table Name:** `users`

**Description:** Stores user account information.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| name | varchar | No | | User's name |
| email | varchar | No | | User's email address |
| email_verified_at | datetime | Yes | | When the email was verified |
| password | varchar | No | | Hashed password |
| remember_token | varchar | Yes | | Token for "remember me" functionality |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Indexes:**
- `users_email_unique` (UNIQUE) on `email`

**Relationships:**
- Has many `organisations` (as author)
- Has many `projects` (as author)
- Has many `organisation_users`
- Has many `project_users`
- Has many `tasks` (as submitter)

## Organisations
**Table Name:** `organisations`

**Description:** Stores organization information.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| name | varchar | No | | Organization name |
| author_id | integer | No | | User ID who created the organization |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Indexes:**
- `organisations_name_unique` (UNIQUE) on `name`

**Foreign Keys:**
- `author_id` references `users.id` (ON DELETE CASCADE)

**Relationships:**
- Belongs to `users` (as author)
- Has many `clients`
- Has many `projects`
- Has many `organisation_users`

## Organisation Users
**Table Name:** `organisation_users`

**Description:** Junction table for users belonging to organizations.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| organisation_id | integer | No | | Organization ID |
| user_id | integer | Yes | | User ID (if registered) |
| user_email | varchar | No | | User's email |
| user_name | varchar | No | | User's name |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Foreign Keys:**
- `organisation_id` references `organisations.id` (ON DELETE CASCADE)

**Relationships:**
- Belongs to `organisations`
- Belongs to `users` (if user_id is not null)

## Clients
**Table Name:** `clients`

**Description:** Stores client information.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| name | varchar | No | | Client name |
| organisation_id | integer | Yes | | Organization ID |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Foreign Keys:**
- `organisation_id` references `organisations.id` (ON DELETE CASCADE)

**Relationships:**
- Belongs to `organisations`
- Has many `projects`

## Projects
**Table Name:** `projects`

**Description:** Stores project information.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| name | varchar | No | | Project name |
| client_id | integer | Yes | | Client ID |
| organisation_id | integer | Yes | | Organization ID |
| author_id | integer | Yes | | User ID who created the project |
| token | varchar | Yes | | API token for the project |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Foreign Keys:**
- `client_id` references `clients.id` (ON DELETE CASCADE)
- `organisation_id` references `organisations.id` (ON DELETE CASCADE)
- `author_id` references `users.id` (ON DELETE SET NULL)

**Relationships:**
- Belongs to `clients`
- Belongs to `organisations`
- Belongs to `users` (as author)
- Has many `tasks`
- Has many `project_users`

## Project Users
**Table Name:** `project_users`

**Description:** Junction table for users belonging to projects.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| project_id | integer | No | | Project ID |
| user_id | integer | Yes | | User ID (if registered) |
| user_email | varchar | No | | User's email |
| user_name | varchar | No | | User's name |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Foreign Keys:**
- `project_id` references `projects.id` (ON DELETE CASCADE)

**Relationships:**
- Belongs to `projects`
- Belongs to `users` (if user_id is not null)
- Has many `tasks` (as submitter, before polymorphic relationship)

## Tasks
**Table Name:** `tasks`

**Description:** Stores task information.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| title | varchar | No | | Task title |
| project_id | integer | No | | Project ID |
| status | varchar | No | 'pending' | Task status |
| priority | varchar | No | 'medium' | Task priority |
| description | text | Yes | | Task description |
| due_date | datetime | Yes | | Due date |
| submitter_id | integer | Yes | | ID of the entity that submitted the task |
| submitter_type | varchar | Yes | | Type of the entity that submitted the task |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Foreign Keys:**
- `project_id` references `projects.id` (ON DELETE CASCADE)

**Relationships:**
- Belongs to `projects`
- Belongs to polymorphic `submitter` (can be a user, project_user, or external_user)
- Has many `task_metadata`
- Has many `attachments` (polymorphic)

## Task Metadata
**Table Name:** `task_metadata`

**Description:** Stores additional metadata for tasks.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| task_id | integer | No | | Task ID |
| environment | varchar | No | 'production' | Environment (production, staging, etc.) |
| url | varchar | No | | URL related to the task (renamed from source_url) |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Foreign Keys:**
- `task_id` references `tasks.id` (ON DELETE CASCADE)

**Relationships:**
- Belongs to `tasks`

## External Users
**Table Name:** `external_users`

**Description:** Stores information about external users who can interact with tasks.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| name | varchar | No | | External user's name |
| email | varchar | Yes | | External user's email |
| environment | varchar | No | | Environment (production, staging, etc.) |
| url | varchar | No | | URL related to the external user |
| external_id | integer | No | | ID from the external system |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Relationships:**
- Has many `tasks` (as submitter)

## Attachments
**Table Name:** `attachments`

**Description:** Stores file attachments for various entities.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| original_filename | varchar | No | | Original filename |
| path | varchar | No | | File path |
| attachable_id | unsignedBigInteger | Yes | | ID of the entity the attachment belongs to |
| attachable_type | varchar | Yes | | Type of the entity the attachment belongs to |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Relationships:**
- Belongs to polymorphic `attachable` (can be a task, project, etc.)

## System Tables
These are Laravel's internal tables used for system functionality.

### Migrations
**Table Name:** `migrations`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| migration | varchar | No | | Migration name |
| batch | integer | No | | Batch number |

### Password Reset Tokens
**Table Name:** `password_reset_tokens`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| email | varchar | No | | User's email |
| token | varchar | No | | Reset token |
| created_at | datetime | Yes | | Creation timestamp |

**Primary Key:** `email`

### Sessions
**Table Name:** `sessions`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | varchar | No | | Session ID |
| user_id | integer | Yes | | User ID |
| ip_address | varchar | Yes | | IP address |
| user_agent | text | Yes | | User agent |
| payload | text | No | | Session data |
| last_activity | integer | No | | Last activity timestamp |

**Primary Key:** `id`

**Indexes:**
- `sessions_user_id_index` on `user_id`
- `sessions_last_activity_index` on `last_activity`

### Cache
**Table Name:** `cache`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| key | varchar | No | | Cache key |
| value | text | No | | Cache value |
| expiration | integer | No | | Expiration timestamp |

**Primary Key:** `key`

### Cache Locks
**Table Name:** `cache_locks`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| key | varchar | No | | Lock key |
| owner | varchar | No | | Lock owner |
| expiration | integer | No | | Expiration timestamp |

**Primary Key:** `key`

### Jobs
**Table Name:** `jobs`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| queue | varchar | No | | Queue name |
| payload | text | No | | Job payload |
| attempts | integer | No | | Number of attempts |
| reserved_at | integer | Yes | | When the job was reserved |
| available_at | integer | No | | When the job is available |
| created_at | integer | No | | Creation timestamp |

**Indexes:**
- `jobs_queue_index` on `queue`

### Job Batches
**Table Name:** `job_batches`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | varchar | No | | Batch ID |
| name | varchar | No | | Batch name |
| total_jobs | integer | No | | Total number of jobs |
| pending_jobs | integer | No | | Number of pending jobs |
| failed_jobs | integer | No | | Number of failed jobs |
| failed_job_ids | text | No | | IDs of failed jobs |
| options | text | Yes | | Batch options |
| cancelled_at | integer | Yes | | When the batch was cancelled |
| created_at | integer | No | | Creation timestamp |
| finished_at | integer | Yes | | When the batch finished |

**Primary Key:** `id`

### Failed Jobs
**Table Name:** `failed_jobs`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| uuid | varchar | No | | Job UUID |
| connection | text | No | | Connection name |
| queue | text | No | | Queue name |
| payload | text | No | | Job payload |
| exception | text | No | | Exception information |
| failed_at | datetime | No | CURRENT_TIMESTAMP | When the job failed |

**Indexes:**
- `failed_jobs_uuid_unique` (UNIQUE) on `uuid`

### Personal Access Tokens
**Table Name:** `personal_access_tokens`

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer | No | auto-increment | Primary key |
| tokenable_type | varchar | No | | Type of the entity the token belongs to |
| tokenable_id | integer | No | | ID of the entity the token belongs to |
| name | varchar | No | | Token name |
| token | varchar | No | | Hashed token |
| abilities | text | Yes | | Token abilities |
| last_used_at | datetime | Yes | | When the token was last used |
| expires_at | datetime | Yes | | When the token expires |
| created_at | datetime | Yes | | Creation timestamp |
| updated_at | datetime | Yes | | Last update timestamp |

**Indexes:**
- `personal_access_tokens_tokenable_type_tokenable_id_index` on `tokenable_type, tokenable_id`
- `personal_access_tokens_token_unique` (UNIQUE) on `token`
