# SHIFT (Portal)

Read `.codex/agents/laravel-boost/index.md` for the Laravel Boost baseline and `PROFILE.md` for the repo's quality bar before changing anything here.

## Scope
- This file applies repo-wide unless a deeper `AGENTS.md` overrides it.
- SHIFT is the source-of-truth portal and external API for `../shift-sdk-package/` and `../shift-sdk-package/packages/shift-php`.

## Repo Rules
- Treat SDK-facing route, payload, auth, attachment, install, or notification changes as cross-repo contract changes. Update the SDK in the same task.
- For editor-backed task create and edit flows, use the V2 task endpoints and route names (`tasks.v2.*`), not the legacy redirect-based task pages.
- For task and thread authorization tests, establish a real access path first. Hidden tasks intentionally return `404`.
- For MCP route, auth, tool, or project-visibility work, read `shared:projects/notes/shift-mcp.md` through Knowledge MCP before changing behavior.
- Shared task UI or helpers that may be consumed by the SDK must not rely on a global Ziggy `route()` helper at SDK runtime. Pass explicit `/shift/api/**` URLs from the consuming SDK layer when needed.
- After SDK UI changes in the sibling repo, rebuild there with `npm run build:shift`, then publish here with `php artisan shift:publish --group=public`.
- For public SHIFT changes that need production, commit and push this repo first, then bring that commit into `../shift-hosted` by merge/cherry-pick/fetch-based application. Do not manually recreate the same edit in `shift-hosted` unless the user explicitly approves that exceptional fallback.
- Production deploys for `shift.wyxos.com` must run from `../shift-hosted` with `npm run release` and the `SHIFT production` preset. Do not deploy production from this public clone.
- Do not edit generated build output in `public/build/**`.

## Scope Map
- Backend domain and policies: `app/` -> `app/AGENTS.md`
- SDK-facing API: `app/Http/Controllers/Api/` -> `app/Http/Controllers/Api/AGENTS.md`
- Outbound integrations: `app/Services/` -> `app/Services/AGENTS.md`
- Portal UI: `resources/js/` -> `resources/js/AGENTS.md`
- Route contracts: `routes/api.php`, `routes/web.php`

# Laravel Boost Guidelines

Laravel Boost guidelines are split to avoid loading every generated rule into the root context. Read the relevant files in `.codex/agents/laravel-boost/` before Laravel, PHP, frontend, or testing work.

- Knowledge MCP pointer: `shared:projects/notes/shift.md`
- Split index: `.codex/agents/laravel-boost/index.md`
- `.codex/agents/laravel-boost/foundation.md`
- `.codex/agents/laravel-boost/boost.md`
- `.codex/agents/laravel-boost/php.md`
- `.codex/agents/laravel-boost/herd.md`
- `.codex/agents/laravel-boost/tests.md`
- `.codex/agents/laravel-boost/inertia-laravel-core.md`
- `.codex/agents/laravel-boost/inertia-laravel-v2.md`
- `.codex/agents/laravel-boost/laravel-core.md`
- `.codex/agents/laravel-boost/laravel-v12.md`
- `.codex/agents/laravel-boost/mcp-core.md`
- `.codex/agents/laravel-boost/pint-core.md`
- `.codex/agents/laravel-boost/pest-core.md`
- `.codex/agents/laravel-boost/pest-v4.md`
- `.codex/agents/laravel-boost/inertia-vue-core.md`
- `.codex/agents/laravel-boost/inertia-vue-v2-forms.md`
- `.codex/agents/laravel-boost/tailwindcss-core.md`
- `.codex/agents/laravel-boost/tailwindcss-v4.md`
