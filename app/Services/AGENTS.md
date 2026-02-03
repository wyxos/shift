# Backend Services (`app/Services/`)

## Package Identity
- Integration/service-layer code for SHIFT, kept out of controllers.

## Patterns & Conventions
- ✅ DO: Put outbound integrations here (example: `app/Services/ExternalNotificationService.php`).
- Outbound notification contract:
  - SHIFT posts to client apps at `/shift/api/notifications` (see `app/Services/ExternalNotificationService.php`).
  - The PHP SDK package implements that receiver route in `packages/shift-php/routes/shift.php` in `../shift-sdk-package/`.
  - ❌ DON'T: Change the outbound URL path (`/shift/api/notifications`) without coordinating with the SDK.

## Touch Points / Key Files
- External notifications: `app/Services/ExternalNotificationService.php`

## JIT Index Hints
- Find outbound notification usage: `rg -n "ExternalNotificationService" app`
- Find notification handlers: `rg -n "handler" app/Services app/Notifications`

## Pre-PR Checks
- `vendor/bin/pint && ./vendor/bin/phpunit`
