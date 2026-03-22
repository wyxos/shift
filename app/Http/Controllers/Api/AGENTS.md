# SHIFT External API (`app/Http/Controllers/Api/`)

Applies inside `app/Http/Controllers/Api/**` in addition to the repo root and `app/AGENTS.md`.

## Contract Rules
- This folder is the SDK-facing API contract used by external applications and `wyxos/shift-php`.
- Not every route in `routes/api.php` is Sanctum-protected: `sdk/install/**` is intentionally public and throttled, while the task, thread, attachment, collaborator, AI, and project-environment routes live behind `auth:sanctum`.
- Keep existing route names and paths stable unless the SDK is updated in the same task.
- Preserve the SDK install flow endpoints under `sdk/install/**`, including `/sessions`, `/sessions/poll`, `/sessions/projects`, `/sessions/projects/create`, and `/sessions/finalize`.
- Preserve the external task request shape: `project` token plus `user.*` and `metadata.*` fields.
- Do not rename or silently repurpose `user.id`, `user.environment`, `user.url`, or `metadata.*` keys without coordinated SDK changes.
- Attachment responses for external consumers must continue to work with the client-app proxy flow (`/shift/api/attachments/{attachment}/download` on the consumer side), not only with raw portal URLs.
- Auth or visibility changes here must preserve the current hidden-task `404` behavior for unauthorized access.

## High-Value Touch Points
- Routes: `routes/api.php`
- Tasks: `ExternalTaskController.php`
- Threads: `ExternalTaskThreadController.php`
- Attachments: `ExternalAttachmentController.php`
- SDK install flow: `SdkInstallController.php`
- Project environments: `ProjectEnvironmentController.php`
