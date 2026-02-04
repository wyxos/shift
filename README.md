# SHIFT

**Open Source Task Management System**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?logo=vue.js)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript)](https://typescriptlang.org)

SHIFT is a task management system built with Laravel 12 and Vue 3. It provides a clean interface for managing tasks across organizations, clients, and projects with role-based access control.

## Features

- **Multi-tenant Structure**: Organizations → Clients → Projects → Tasks
- **User Management**: Role-based access with project assignments
- **Task Tracking**: Create, assign, and monitor task progress
- **File Attachments**: Upload and manage task-related files
- **Task Threads**: Discussion threads for task collaboration
- **External Integration**: API endpoints for external user access
- **Modern UI**: Built with Vue 3, TypeScript, and Tailwind CSS

---

## Quick Start

### Prerequisites
- PHP 8.1+
- Node.js 16+
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
php artisan serve
```

Visit [http://localhost:8000](http://localhost:8000) and log in with the seeded admin account.

### Docker Setup

```bash
# Clone and start with Docker
git clone https://github.com/wyxos/shift.git
cd shift
docker-compose up -d
```

---

## Tech Stack

- **Backend**: Laravel 12 with PHP 8.2+
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

**Open Source Task Management System**

</div>
