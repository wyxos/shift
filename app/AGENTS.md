# SHIFT Backend (`app/`)

## Package Identity
- Laravel 12 backend for SHIFT portal: multi-tenant organisations/clients/projects/tasks, plus an external API for SDK consumers.

## Setup & Run
- Install: `composer install`
- Dev (full stack): `composer dev`
- Tests: `composer test` (or `./vendor/bin/phpunit`)
- Format (PHP): `vendor/bin/pint`

## Patterns & Conventions
- Controllers:
  - UI controllers: `app/Http/Controllers/*.php` (Inertia pages + actions)
    - ✅ DO: Keep request validation and persistence patterns consistent with `app/Http/Controllers/TaskController.php`
  - External API controllers: `app/Http/Controllers/Api/**` (SDK-facing)
    - ✅ DO: Treat these as a public contract (see `app/Http/Controllers/Api/AGENTS.md`)
    - ❌ DON'T: Change payload shapes/route paths without updating `../shift-sdk-package/`
- Services:
  - ✅ DO: Put integration concerns in `app/Services/**` (example: `app/Services/ExternalNotificationService.php`)
- Jobs/Notifications:
  - ✅ DO: Use jobs for async work (example: `app/Jobs/SendTaskThreadNotification.php`)
  - ✅ DO: Keep notification content in `app/Notifications/**` (example: `app/Notifications/TaskCreationNotification.php`)
- Models:
  - ✅ DO: Keep tenancy relationships on models (examples: `app/Models/Organisation.php`, `app/Models/Project.php`, `app/Models/Task.php`)

## Touch Points / Key Files
- SDK-facing API routes: `routes/api.php`
- SDK-facing controllers: `app/Http/Controllers/Api/ExternalTaskController.php`
- Outbound notifications to client apps: `app/Services/ExternalNotificationService.php`
- Task domain: `app/Models/Task.php`, `app/Models/TaskThread.php`, `app/Models/Attachment.php`

## JIT Index Hints
- Find controllers: `rg -n "class .*Controller" app/Http/Controllers`
- Find Sanctum-protected endpoints: `rg -n "auth:sanctum" routes/api.php`
- Find notifications/jobs: `rg -n "extends (Notification|Job)" app/Notifications app/Jobs`

## Common Gotchas
- SDK expects `project` token and `user.*`/`metadata.*` fields in external API payloads (see `app/Http/Controllers/Api/ExternalTaskController.php`).

## Pre-PR Checks
- `vendor/bin/pint`
- `composer test` (uses `php artisan test` / PHPUnit)
- If API contract changes: update SDK + relevant `AGENTS.md`/`README.md`
