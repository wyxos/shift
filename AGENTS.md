# SHIFT (Portal)

Read `.codex/agents/laravel-boost/index.md` for the Laravel Boost baseline before changing anything here.

## Scope
- This file applies repo-wide unless a deeper `AGENTS.md` overrides it.
- SHIFT is the source-of-truth portal and external API for the `wyxos/shift-sdk` development host and `wyxos/shift-php` integration package.

## Repo Rules
- Keep source, fixtures, assets, documentation and Git metadata free of personal identities, machine paths, client data and private deployment details. Use synthetic examples; review staged content and prospective commit metadata before committing or publishing.
- Keep project instructions portable. Deployment configuration and personal operating guidance belong outside tracked project files.
- Prefer framework conventions and small, cohesive modules; preserve existing contracts and verify affected behavior.
- Treat SDK-facing route, payload, auth, attachment, install, or notification changes as cross-repo contract changes. Update the SDK in the same task.
- For editor-backed task create and edit flows, use the canonical task-list routes and route names (`tasks.*`), with create/edit state owned by the `Tasks/Index` sheet flow. The former `tasks.v2.*`/`tasks-v2` surface was removed; `tasks.create` and `tasks.edit` are redirect compatibility routes, not page owners.
- For task and thread authorization tests, establish a real access path first. Hidden tasks intentionally return `404`.
- Shared task UI or helpers that may be consumed by the SDK must not rely on a global Ziggy `route()` helper at SDK runtime. Pass explicit `/shift/api/**` URLs from the consuming SDK layer when needed.
- After SDK UI changes in the integration package, rebuild in its development host with `npm run build:shift`, then publish here with `php artisan shift:publish --group=public`.
- Do not edit generated build output in `public/build/**`.

## Docs Screenshot Automation
- Keep public docs focused on user relevance, installability, practical comparison, and demo proof. Do not put local fixture routes, Herd assumptions, regeneration commands, or other agent/contributor notes in reader-facing docs.
- The local screenshot route is `/docs/laravel-issue-reporting-demo/{screen}` and is available only in `local` and `testing`.
- Regenerate the committed docs screenshots from this repo with `npm run docs:screenshots`; the script captures `docs/assets/laravel-issue-reporting/*.png` at 1920x1080.
- Override the route with `SHIFT_LARAVEL_ISSUE_REPORTING_DEMO_URL` or `--base-url=` when the local app is not available at `https://shift.test`.

## Scope Map
- Backend domain and policies: `app/` -> `app/AGENTS.md`
- SDK-facing API: `app/Http/Controllers/Api/` -> `app/Http/Controllers/Api/AGENTS.md`
- Outbound integrations: `app/Services/` -> `app/Services/AGENTS.md`
- Portal UI: `resources/js/` -> `resources/js/AGENTS.md`
- Route contracts: `routes/api.php`, `routes/web.php`

# Laravel Boost Guidelines

Laravel Boost guidelines are split to avoid loading every generated rule into the root context. Read the relevant files in `.codex/agents/laravel-boost/` before Laravel, PHP, frontend, or testing work.

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
