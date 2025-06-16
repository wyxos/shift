# SHIFT Developer Guidelines

## Project Overview

SHIFT is a task management system built with Laravel and Vue.js. It consists of two main components:

1. **Main Application** - A Laravel application with Vue.js frontend for task management
2. **SDK Package** - A Laravel package (`wyxos/shift-php`) that allows external applications to integrate with SHIFT

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
- Various UI components (Oruga UI, TanStack Table, Toast UI Editor)

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
│   ├── api.php           # API routes
│   └── web.php           # Web routes
└── tests/                # Test files
    ├── Feature/          # Feature tests
    └── Unit/             # Unit tests
```

### SDK Package

```
shift-sdk-package/packages/shift-php/
├── config/               # Configuration files
├── routes/               # Route definitions
├── src/                  # PHP source code
└── ui/                   # Frontend components
```

## Development Workflow

### Setting Up the Environment

1. Clone the repository
2. Install PHP dependencies: `composer install`
3. Install JavaScript dependencies: `npm install`
4. Copy `.env.example` to `.env` and configure your environment
5. Generate application key: `php artisan key:generate`
6. Run migrations: `php artisan migrate`
7. Seed the database: `php artisan db:seed`

### Running the Application

- Start the development server: `composer dev`
    - This runs Laravel server, queue worker, and Vite in parallel
- For SSR mode: `composer dev:ssr`

### Testing

#### Backend Testing
- Run all tests: `composer test`
- Run specific test suite: `php artisan test --testsuite=Unit`
- Create test database: `touch database/database.sqlite`

#### Frontend Testing
- Run all frontend tests: `npm test`
- Run tests in watch mode: `npm run test:watch`
- Run tests with coverage: `npm run test:coverage`

Vitest is used for testing Vue components. Test files are located in the `resources/js/__tests__` directory, mirroring the structure of the components being tested.

Example of a component test:
```typescript
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MyComponent from '@/components/MyComponent.vue'

describe('MyComponent.vue', () => {
  it('renders correctly', () => {
    const wrapper = mount(MyComponent, {
      props: { /* props here */ }
    })

    // Assert component behavior
    expect(wrapper.text()).toContain('Expected text')
  })
})
```

## SDK Integration

The SHIFT SDK allows external applications to submit tasks to the SHIFT dashboard:

1. Install the package: `composer require wyxos/shift-php`
2. Run the installation command: `php artisan install:shift`
3. Configure your `.env` with SHIFT credentials:
   ```
   SHIFT_TOKEN=your-api-token
   SHIFT_PROJECT=your-project-token
   SHIFT_URL=https://shift.wyxos.com
   ```

## Best Practices

- Follow Laravel and PSR-12 style guidelines
- Use type hints and strict types
- Use dependency injection where possible
- Write tests for new features and bug fixes
- Run migrations and tests before each commit
- Generate Laravel components using artisan commands
- Remove unused code and perform basic refactoring

## Common Commands

- Create a controller: `php artisan make:controller NameController`
- Create a model with migration: `php artisan make:model Name -m`
- Create a test: `php artisan make:test NameTest`
- Run migrations: `php artisan migrate`
- Roll back migrations: `php artisan migrate:rollback`
- Clear cache: `php artisan cache:clear`
- Generate IDE helper files: `php artisan ide-helper:generate`

- Follow Laravel and PSR-12 style guidelines.
- Use type hints and strict types.
- Use dependency injection where possible.
- Refer to official documentation or online resources to ensure usage of the latest syntax and best practices for all
  dependencies, packages, and plugins in use.
- Write or update unit, feature, and end-to-end (e2e) tests as appropriate for each task.
- Ensure the full test suite (unit, feature, e2e) passes after each change.
- Make migrations, factories, and seeders for new or changed tables.
- Update policies, validation rules, and documentation as needed.
- Run migrations and tests before each commit.
- Remove unused code and perform basic refactoring.
- Update related front-end components if needed.
- Always generate new Laravel components (models, migrations, controllers, policies, form requests, etc.) using the
  relevant php artisan make:* commands, not by manual file creation.
