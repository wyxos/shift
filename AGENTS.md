# SHIFT (Portal)

## PROFILE.md (Required)
- Read and follow `PROFILE.md` in this repo before making changes.
- Its guidance on quality, naming, and refactoring is mandatory for all work.

## Project Snapshot
- Repo type: single Laravel app (Portal) with external API consumed by the PHP SDK.
- Stack: PHP 8.2+ (Laravel 12, Sanctum), Vue 3 + Inertia, Vite, Tailwind, PHPUnit, Laravel Pint, ESLint + Prettier, Vitest.
- This repo is the source-of-truth for the SHIFT API and UI; follow the nearest `AGENTS.md` (nearest-wins).
 - Product focus: SHIFT is a Laravel app for tracking tasks/issues within Laravel projects (orgs → clients → projects → tasks).

## Related Repos (cross-repo awareness)
- SDK workspace on this machine: `../shift-sdk-package/` (contains the SDK source + local dev harness).
- SDK purpose (`wyxos/shift-php`):
  - Provides `/shift/**` dashboard UI (served from built SPA assets).
  - Provides `/shift/api/**` proxy endpoints that call back to this portal’s external API.
  - Includes Artisan install/publish/test commands for integration.
- Cross-repo contract awareness:
  - External API contract here is consumed by the SDK package `wyxos/shift-php`.
  - If you change any external API routes/payloads (`routes/api.php`, `app/Http/Controllers/Api/**`), update the SDK accordingly.
  - If you change SDK routes/proxy expectations (`packages/shift-php/routes/shift.php`, `packages/shift-php/src/Http/Controllers/**`), verify/update SHIFT to match.
- Local SDK dev workflow (from portal repo):
  - Prefer: `php artisan shift:toggle --local --path=../shift-sdk-package/packages/shift-php`
  - Switch back: `php artisan shift:toggle --online`
  - After SDK UI changes: run `npm run build:shift` in `../shift-sdk-package/`, then `php artisan shift:publish --group=public` here.

## Root Setup Commands
- Install (PHP): `composer install`
- Install (JS): `npm install` (CI uses `npm ci`)
- Dev (app + Vite): `composer dev`
- Build: `npm run build`
- Test (PHP): `composer test` (or `./vendor/bin/phpunit`)
- Test (JS): `npm run test` (watch: `npm run test:watch`)
- Lint/format:
  - PHP: `vendor/bin/pint`
  - JS format: `npm run format` (check: `npm run format:check`)
  - JS lint: `npm run lint`

## Universal Conventions
- Prefer minimal, scoped changes; don’t refactor unrelated code.
- Don’t edit generated build output: `public/build/**`.
- Keep API changes backwards-compatible when possible (SDK consumers).
- PHP style: Pint (`vendor/bin/pint`).
- JS style: Prettier + ESLint (`npm run format`, `npm run lint`).
- Cross-repo alignment is mandatory:
  - Any change in SHIFT that affects SDK routes, payloads, assets, or UI parity must be reflected in `../shift-sdk-package/`.
  - Any SDK change that affects portal routes, payloads, or UI parity must be reflected here.
- Documentation upkeep:
  - If behavior or workflows change, update relevant `AGENTS.md` and `README.md`.

## Security & Secrets
- Never commit `.env` or real tokens/keys.
- Auth for external API uses Sanctum (`routes/api.php`); treat tokens as secrets.

## JIT Index (what to open, not what to paste)

### Package Structure
- Backend: `app/` → `app/AGENTS.md`
- Frontend: `resources/js/` → `resources/js/AGENTS.md`
- External API (SDK-facing): `routes/api.php`, `app/Http/Controllers/Api/` → `app/Http/Controllers/Api/AGENTS.md`
- Outbound notifications to client apps: `app/Services/ExternalNotificationService.php` → `app/Services/AGENTS.md`
- Routes: `routes/web.php`, `routes/auth.php`, `routes/settings.php`
- DB schema notes: `database-schema.md`, `database/schema/**`
- Tests: `tests/**` (PHP) and `resources/js/__tests__/**` (Vitest)

### Quick Find Commands
- Search backend (skip deps): `rg -n "ThingToFind" app routes config database tests --hidden --glob "!.git/**"`
- Search external API controllers: `rg -n "namespace App\\Http\\Controllers\\Api" app/Http/Controllers/Api`
- Find SDK-facing routes: `rg -n "auth:sanctum|/tasks|/attachments" routes/api.php`
- Search frontend (skip deps): `rg -n "ThingToFind" resources/js --hidden --glob "!node_modules/**"`

## Definition of Done
- PHP tests: `composer test` (uses `php artisan test` / PHPUnit)
- `vendor/bin/pint` is clean
- JS checks: `npm run format:check` and `npm run lint`
- JS tests (when JS changes): `npm run test`
- `npm run build` succeeds when frontend assets or UI changes
- If external API changed: SDK updated in `../shift-sdk-package/`
