# SHIFT Developer Guidelines

## Project Overview

SHIFT is a task management system built with Laravel and Vue.js. It consists of two main components:

1. **Main Application** - A Laravel application with Vue.js frontend for task management
2. **SDK Package** - A Laravel package (`wyxos/shift-php`) for external application integration located at
   ../shift-sdk/packages/shift-php

## Tech Stack

### Backend

- PHP 8.2+
- Laravel 12.x
- Laravel Sanctum for API authentication
- Laravel Inertia for backend-frontend integration

### Frontend

- Vue.js 3.5+
- TypeScript
- Tailwind CSS 4.x
- Vite as build tool
- Inertia.js for SPA functionality
- UI components: Oruga UI, TanStack Table, Toast UI Editor

## Project Structure

### Main Application

```
shift/
├── app/                  # Application code
│   ├── Console/          # Artisan commands
│   ├── Http/             # Controllers, middleware, requests
│   ├── Jobs/             # Queue jobs
│   ├── Models/           # Eloquent models
│   ├── Notifications/    # Notification classes
│   ├── Providers/        # Service providers
│   └── Services/         # Business logic services
├── config/               # Configuration files
├── database/             # Migrations, factories, seeders
├── resources/            # Frontend assets
│   ├── js/               # Vue components and JavaScript
│   ├── css/              # CSS files
│   └── views/            # Blade templates
├── routes/               # Route definitions
└── tests/                # Test files
```

### SDK Package

```
shift-sdk-package/packages/shift-php/
├── config/               # Configuration files
├── routes/               # Route definitions
├── src/                  # PHP source code
└── ui/                   # Frontend components
```

## Development Commands

### Environment Setup

- Install PHP dependencies: `composer install`
- Install JavaScript dependencies: `npm install`
- Start development server: `composer dev`
- Start SSR mode: `composer dev:ssr`

### Testing

- Backend tests: `composer test`
- Frontend tests: `npm test`
- Test with coverage: `npm run test:coverage`

### Common Laravel Commands

- Create controller: `php artisan make:controller NameController`
- Create model with migration: `php artisan make:model Name -m`
- Create test: `php artisan make:test NameTest`
- Run migrations: `php artisan migrate`
- Roll back migrations: `php artisan migrate:rollback`
- Clear cache: `php artisan cache:clear`

## Best Practices

- Follow Laravel and PSR-12 style guidelines
- Use type hints and strict types
- Use dependency injection where possible
- Write tests for new features and bug fixes
- Run migrations and tests before each commit
- Generate Laravel components using artisan commands
- Remove unused code and perform basic refactoring
- Refer to official documentation for latest syntax and best practices
- Write or update unit, feature, and e2e tests as appropriate
- Make migrations, factories, and seeders for new or changed tables
- Update policies, validation rules, and documentation as needed
- Update related front-end components if needed
- Always generate new Laravel components using artisan commands

## AI Assistant Guidelines

- After completing each task, update this guidelines.md file if you've made changes that affect the project structure,
  workflow, or best practices
- When implementing new features, document them in this file if they introduce new patterns or technologies
- Keep this file concise and focused on information that will help with future development tasks
