# Feature Documentation

## External User Assignment in Task Editing

### Feature Purpose
This feature allows internal users to assign external users to tasks while respecting environment-based access controls. The system filters which external users are shown based on who created the task.

### Flow Overview
1. When editing a task, the system checks if the task was submitted by an internal or external user
2. If submitted by an internal user: Shows all external users associated with the project, displaying their environment in brackets
3. If submitted by an external user: Only shows external users from the same environment as the submitter

### Implementation Details

#### Backend Changes
**File**: `app/Http/Controllers/TaskController.php`
- Modified the `edit()` method to filter external users based on task submitter
- Added logic to check if task is externally submitted using `$task->isExternallySubmitted()`
- For internal tasks: Returns all project external users
- For external tasks: Filters external users by environment matching the submitter's environment

#### Frontend Changes
**File**: `resources/js/pages/Tasks/Edit.vue`
- Added `submitter_type` and `submitter` to the Props interface
- Added computed property `isTaskExternallySubmitted` to determine task origin
- Updated external user display to show environment information in brackets for internal tasks
- Environment is only shown when `!isTaskExternallySubmitted` (internal tasks)

### Setup/Configuration
No additional setup required. The feature uses existing:
- Task-ExternalUser many-to-many relationship
- ExternalUser environment field
- Task polymorphic submitter relationship

### Business Logic
- **Internal Task Creation**: All project external users are available for assignment, with environment clearly visible
- **External Task Creation**: Only external users from the same environment can be assigned
- This ensures external users from different environments (production, staging, etc.) don't accidentally get access to tasks from other environments

### Testing
Added comprehensive tests in `tests/Feature/TaskControllerTest.php`:
- `test_edit_shows_all_external_users_for_internal_task()`: Verifies all external users are shown for internal tasks
- `test_edit_shows_only_same_environment_external_users_for_external_task()`: Verifies environment filtering for external tasks

### Notes for Future Agents
- External users are linked to projects via the `project_id` field
- The `environment` field on external users is crucial for this filtering
- The polymorphic `submitter` relationship on tasks determines if filtering should occur
- Environment badges in the UI help users understand which environment external users belong to
