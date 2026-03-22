# SHIFT (Portal)

Read `CLAUDE.md` for the Laravel Boost baseline and `PROFILE.md` for the repo's quality bar before changing anything here.

## Scope
- This file applies repo-wide unless a deeper `AGENTS.md` overrides it.
- SHIFT is the source-of-truth portal and external API for `../shift-sdk-package/` and `../shift-sdk-package/packages/shift-php`.

## Repo Rules
- Treat SDK-facing route, payload, auth, attachment, install, or notification changes as cross-repo contract changes. Update the SDK in the same task.
- For editor-backed task create and edit flows, use the V2 task endpoints and route names (`tasks.v2.*`), not the legacy redirect-based task pages.
- For task and thread authorization tests, establish a real access path first. Hidden tasks intentionally return `404`.
- Shared task UI or helpers that may be consumed by the SDK must not rely on a global Ziggy `route()` helper at SDK runtime. Pass explicit `/shift/api/**` URLs from the consuming SDK layer when needed.
- After SDK UI changes in the sibling repo, rebuild there with `npm run build:shift`, then publish here with `php artisan shift:publish --group=public`.
- For unattended releases, prefer `node ~/Developer/wyxos/scripts/release-shift.mjs shift ...` instead of `npm run release`.
- Do not edit generated build output in `public/build/**`.

## Scope Map
- Backend domain and policies: `app/` -> `app/AGENTS.md`
- SDK-facing API: `app/Http/Controllers/Api/` -> `app/Http/Controllers/Api/AGENTS.md`
- Outbound integrations: `app/Services/` -> `app/Services/AGENTS.md`
- Portal UI: `resources/js/` -> `resources/js/AGENTS.md`
- Route contracts: `routes/api.php`, `routes/web.php`
