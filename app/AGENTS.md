# SHIFT Backend (`app/`)

Applies inside `app/**` in addition to the repo root file.

## Backend Rules
- Keep portal business rules, policies, jobs, notifications, and model behavior in `app/**`; do not hide contract changes in unrelated helpers.
- Changes to tasks, task threads, attachments, collaborators, or external-user access must preserve the current hidden-task `404` behavior and collaborator-based access model.
- If a backend change affects SDK-facing payloads, permissions, install flow, notification delivery, or attachment handling, update the sibling SDK repo in the same task.
- Put outbound HTTP or client-app integration behavior in `app/Services/**`, not in controllers.
- Keep route-specific contract awareness close to `routes/api.php` and `routes/web.php`; do not assume every backend change is portal-only.

## High-Value Touch Points
- Task domain: `app/Models/Task.php`, `app/Models/TaskThread.php`, `app/Models/Attachment.php`
- Policies and access checks: `app/Policies/**`
- Portal controllers: `app/Http/Controllers/**`
- Async work and notifications: `app/Jobs/**`, `app/Notifications/**`
