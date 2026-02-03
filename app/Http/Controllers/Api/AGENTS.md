# SHIFT External API (`app/Http/Controllers/Api/`)

## Package Identity
- SDK-facing REST API used by external applications via the PHP SDK (`wyxos/shift-php`).
- Routes are defined in `routes/api.php` and are protected by `auth:sanctum`.

## Setup & Run
- Run portal locally: `composer dev`
- PHP tests: `./vendor/bin/phpunit`

## API Patterns (public contract)
- Routes: `routes/api.php`
  - ✅ DO: Keep route paths stable (`/tasks`, `/tasks/{task}`, `/tasks/{task}/threads`, `/attachments/*`).
- Controllers:
  - Tasks: `app/Http/Controllers/Api/ExternalTaskController.php`
  - Threads: `app/Http/Controllers/Api/ExternalTaskThreadController.php`
  - Attachments: `app/Http/Controllers/Api/ExternalAttachmentController.php`
- Request shape (SDK contract):
  - ✅ DO: Accept `project` token plus `user.*` and `metadata.*` fields (see usage in `app/Http/Controllers/Api/ExternalTaskController.php`).
  - ❌ DON'T: Rename `user.id`, `user.environment`, `user.url` without coordinated SDK changes.
- Attachment URLs:
  - ✅ DO: Return download URLs that point back to the client app’s SDK proxy route (`/shift/api/attachments/{id}/download`), as done in `app/Http/Controllers/Api/ExternalTaskController.php`.

## Touch Points / Key Files
- Routes: `routes/api.php`
- Primary controller: `app/Http/Controllers/Api/ExternalTaskController.php`

## JIT Index Hints
- Find request field usage: `rg -n "request\(\)->offsetGet\('user\." app/Http/Controllers/Api`
- Find project token checks: `rg -n "request\('project'\)" app/Http/Controllers/Api`

## Common Gotchas
- Any breaking change here requires a coordinated change in `../shift-sdk-package/packages/shift-php/src/Http/Controllers/**`.

## Pre-PR Checks
- `vendor/bin/pint && ./vendor/bin/phpunit`
