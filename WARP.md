# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

Project summary
- Stack: Laravel 12 (PHP 8.3+), Inertia v2, Vue 3 + TypeScript, Vite, Tailwind CSS v4, Ziggy, Sanctum, Pest v4
- Frontend SSR is supported (resources/js/ssr.ts, composer dev:ssr)
- Multi-tenant domain: Organisations → Clients → Projects → Tasks with attachments, threads, external users

Core commands
- Install and bootstrap
  - composer install
  - npm install
  - cp .env.example .env; php artisan key:generate
  - php artisan migrate --seed
- Development
  - Preferred (runs app server, queue listener, and Vite together):
    - composer run dev
  - With server-side rendering (also tails logs via pail and starts SSR server):
    - composer run dev:ssr
- Build assets
  - npm run build
  - npm run build:ssr
  - If routes change and the frontend references them, regenerate Ziggy routes:
    - php artisan ziggy:generate
- Lint/format
  - Backend formatting (Laravel Pint):
    - vendor/bin/pint --dirty   # format only changed files
    - vendor/bin/pint           # format entire codebase
  - Frontend formatting and lint:
    - npm run format            # Prettier write
    - npm run format:check      # Prettier check
    - npm run lint              # ESLint (fix)
- Tests (backend, Pest v4)
  - Run all: php artisan test
  - Single file: php artisan test tests/Feature/ExampleTest.php
  - Filter by name: php artisan test --filter="partialTestName"
  - CI parity: ./vendor/bin/phpunit
- Tests (frontend, Vitest)
  - All: npm test
  - Watch: npm run test:watch
  - Coverage: npm run test:coverage
  - Single file: npm test -- path/to/file.test.ts
  - Filter by name: npm test -- -t "test name"

High-level architecture
- HTTP and routing (Laravel 12 streamlined structure)
  - Route files: routes/web.php (Inertia pages), routes/api.php (API), routes/auth.php (auth), routes/settings.php (settings), routes/console.php
  - Laravel 12 registers middleware/exceptions/routes via bootstrap/app.php; service providers in bootstrap/providers.php; console commands auto-discovered (no Console\Kernel)
- Controllers
  - Web: app/Http/Controllers/* (e.g., DashboardController, TaskController, ProjectController, OrganisationController, AttachmentController, NotificationController)
  - API: app/Http/Controllers/Api/* for external integrations (ExternalTaskController, ExternalTaskThreadController, ExternalAttachmentController)
  - Settings: app/Http/Controllers/Settings/* for user profile, password, and API settings
- Domain models (Eloquent)
  - app/Models includes: Organisation, Client, Project, Task, TaskThread, TaskMetadata, Attachment, User, plus pivot models (OrganisationUser, ProjectUser) and ExternalUser
  - Tests run against in-memory sqlite by default (phpunit.xml)
- Frontend (Inertia + Vue 3 + Vite)
  - Entry points: resources/js/app.ts (client), resources/js/ssr.ts (SSR)
  - Vite config: laravel-vite-plugin, tailwindcss/vite, @ alias → resources/js, ziggy-js alias → vendor/tightenco/ziggy
  - Use Ziggy in the client to reference backend routes; Inertia pages live under resources/js/Pages
- Queues and logs
  - Local dev uses php artisan queue:listen alongside the web server (composer run dev)
  - Logs can be tailed with php artisan pail (included in dev:ssr)

CI reference (GitHub Actions)
- .github/workflows/tests.yml: sets up PHP 8.4 and Node 22, builds assets, runs phpunit; runs ziggy:generate before build
- .github/workflows/lint.yml: runs Pint, Prettier (format), and ESLint (lint)

Important project rules (from CLAUDE.md and .github/copilot-instructions.md)
- Prefer php artisan make:* for scaffolding; pass --no-interaction in automated contexts
- Use Pest for tests; place tests under tests/Feature and tests/Unit; run the minimum affected tests during iteration
- Run vendor/bin/pint to format PHP code (use --dirty for staged changes)
- Inertia components under resources/js/Pages; use Inertia::render on the backend
- Tailwind v4 is in use; import via @import "tailwindcss" and avoid deprecated utilities
- Use named routes and route() for URL generation; use Ziggy on the client

Notes specific to this repo
- composer run dev is the recommended way to develop locally because it starts the HTTP server, Vite dev server, and queue listener together
- If frontend route references break after adding/modifying routes, run php artisan ziggy:generate
- SSR is supported; use composer run dev:ssr for SSR-enabled local development

