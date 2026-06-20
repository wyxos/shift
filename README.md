# SHIFT

**Laravel app issue intake from inside the app**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?logo=vue.js)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php)](https://php.net)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript)](https://typescriptlang.org)

SHIFT is an open-source Laravel portal for issue intake from inside a Laravel application.

The current focus is practical: a user reports an issue from the app page where it happened, `wyxos/shift-php` carries app/user/request context into the portal, and the developer follows up in the task thread without reconstructing the report from email, screenshots, and logs.

The portal still has the normal structure needed to manage the work (organisations, clients, projects, tasks, attachments, and threads), but the public entry point is Laravel in-app issue intake rather than a broad project-management tool.

## Features

- **Laravel in-app intake**: Report issues from the app surface through `wyxos/shift-php`.
- **App context**: Carry environment, app URL, route, user context, and request metadata with the report.
- **Backend error intake**: Attach scrubbed Laravel exception occurrences to tasks.
- **Task threads**: Keep developer follow-up beside the original app report.
- **Portal structure**: Organize work by organisations, clients, projects, tasks, attachments, and collaborators.
- **Modern Laravel stack**: Laravel 12, Vue 3, TypeScript, Inertia, Tailwind CSS.

---

## Quick Start

### Prerequisites

- PHP 8.3+
- Node.js 18+
- Composer
- MySQL/PostgreSQL

### Installation

```bash
# Clone the repository
git clone https://github.com/wyxos/shift.git
cd shift

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure your database in .env then run:
php artisan migrate --seed

# Build assets
npm run build

# Start the development server
composer dev
```

Visit [http://localhost:8000](http://localhost:8000) and log in with the seeded admin account.

---

## Documentation

- [Laravel app issue intake](docs/laravel-issue-intake.md)

## SDK Install Flow

The portal exposes a cache-backed browser/device install flow for SDK consumers.

- `POST /api/sdk/install/sessions` creates an install session and returns the device/user codes plus verification URLs.
- `/sdk/install` lets a signed-in user enter or prefill a code, log in if needed, and approve the install session.
- `POST /api/sdk/install/sessions/poll`, `/projects`, `/projects/create`, and `/finalize` let the CLI wait for approval, list manageable projects, create a standalone project when needed, and finalize credentials.
- Finalization reuses an existing project token when possible, otherwise creates one, then issues a user API token once and registers the selected environment URL.

## Support

- **[Report Issues](https://github.com/wyxos/shift/issues)** - Bug reports and feature requests
- **[Website](https://wyxos.com)** - More information about Wyxos

---

## License

Licensed under the [MIT License](LICENSE).
