# SHIFT

**Laravel app issue intake from inside the app**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?logo=vue.js)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php)](https://php.net)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript)](https://typescriptlang.org)

SHIFT is an open-source Laravel portal for issue intake from inside a Laravel application.

Its current focus is practical: a user reports an issue from the app page where it happened, `wyxos/shift-php` carries app/user/request context into SHIFT, and the developer follows up in the task thread without reconstructing the report from email, screenshots, and logs.

The portal still has the normal structure needed to manage the work (organisations, clients, projects, tasks, attachments, and threads), but the public entry point is Laravel in-app issue intake rather than a broad project-management tool.

## Features

- **Laravel in-app intake**: Report issues from the app surface through `wyxos/shift-php`.
- **App context**: Carry environment, app URL, route, user context, and request metadata with the report.
- **Backend error intake**: Attach scrubbed Laravel exception occurrences to SHIFT tasks.
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

### Registration

Registration is temporarily disabled. Create users via seeding or the database, or re-enable routes in `routes/auth.php` when you want public signups.

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

## Public Discovery Docs

The first public discovery package is here:

- [SHIFT public discovery](docs/public-discovery.md)
- [Repeatable local screenshots](docs/assets/public-discovery/)

The screenshots are generated from local-only dummy fixture screens. They do not use production data, hosted SHIFT screenshots, Voidcare data, real clients, real users, or production tokens.

Regenerate them from this repo with:

```bash
npm run docs:screenshots
```

The command expects the local Herd route `https://shift.test/docs/public-discovery-demo/{screen}` and verifies each generated PNG is 1920x1080.

## SDK Install Flow

SHIFT exposes a cache-backed browser/device install flow for SDK consumers.

- `POST /api/sdk/install/sessions` creates an install session and returns the device/user codes plus verification URLs.
- `/sdk/install` lets a SHIFT user enter or prefill a code, log in if needed, and approve the install session.
- `POST /api/sdk/install/sessions/poll`, `/projects`, `/projects/create`, and `/finalize` let the CLI wait for approval, list manageable projects, create a standalone project when needed, and finalize credentials.
- Finalization reuses an existing project token when possible, otherwise creates one, then issues a user API token once and registers the selected environment URL.

---

## Tech Stack

- **Backend**: Laravel 12 with PHP 8.3+
- **Frontend**: Vue 3 with TypeScript and Inertia.js
- **Styling**: Tailwind CSS
- **Database**: MySQL/PostgreSQL
- **Authentication**: Laravel Sanctum

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

- **[Report Issues](https://github.com/wyxos/shift/issues)** - Bug reports and feature requests
- **[Website](https://wyxos.com)** - More information about Wyxos

---

## License

SHIFT is open-source software licensed under the [MIT License](LICENSE). You're free to use, modify, and distribute this software according to the license terms.

---

## Acknowledgments

- Created by [Wyxos](https://wyxos.com)

---

<div align="center">

**[Back to Top](#shift)**

**Laravel app issue intake from inside the app**

</div>
