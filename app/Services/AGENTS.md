# SHIFT Services (`app/Services/`)

Applies inside `app/Services/**` in addition to the repo root and `app/AGENTS.md`.

## Service Rules
- Use this folder for outbound integrations and service-layer behavior that should stay out of controllers.
- The external notification contract currently posts to `<client app>/shift/api/notifications`. Do not change that path without updating `../shift-sdk-package/packages/shift-php`.
- Notification bodies currently include `handler`, `payload`, and `source`. Keep that shape aligned with the SDK receiver if it changes.
- Notification requests may include signed headers. When a signing secret exists, preserve `X-Shift-Timestamp` and `X-Shift-Signature`.
- Keep the signature format aligned with the SDK receiver before changing it.
- Local and private client-app URLs intentionally skip TLS verification for callback delivery. Do not remove or broaden that behavior casually.
- If notification payload shape, source fields, headers, or delivery semantics change here, update the SDK receiver and the relevant tests in the same task.

## High-Value Touch Points
- External callbacks: `ExternalNotificationService.php`
- Notification dispatchers and jobs: `app/Notifications/**`, `app/Jobs/**`
