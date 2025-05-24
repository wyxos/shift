# Junie Guidelines – SHIFT (Core App)

## 📌 Project Role
This is the **core** task management system built in Laravel and Vue.js. It manages:
- Organizations
- Clients
- Projects
- Tasks

This project includes:
- A web-based admin dashboard (Vue + Inertia.js)
- REST API endpoints for external integrations
- The backend logic for managing users, permissions, and data

## 🧱 Architecture

### Domain Structure
- Organizations → Clients → Projects → Tasks
- Tasks have:
    - Status (complete/incomplete)
    - Priority (low/high)
    - Optional file attachments (planned)
    - Comments (planned)

### Tech Stack
- Laravel (Backend)
- Vue.js + Inertia.js (Frontend)
- Sanctum for API authentication
- Tailwind CSS for UI

## 🧪 Testing Guidelines
- Use PestPHP for unit and feature tests
- All tests live in `tests/` directory
- Minimum 90% code coverage for controllers and services
- Use factories and seeders for test data

## 📁 Directory Conventions
- `app/Models` – Eloquent models
- `app/Http/Controllers` – API and Web controllers
- `resources/js/Pages` – Vue components for pages
- `resources/js/Components` – Shared Vue components
- `routes/web.php` – Web routes
- `routes/api.php` – API routes

## 🔐 Auth Notes
- Use `auth:sanctum` for API routes
- Vue.js frontend uses Inertia with middleware for auth

## 🔄 SDK Integration Notes
- The `shift-sdk` package sends requests to the `/api/sdk/tasks` endpoint
- SDK-authenticated users are linked to an existing organization
- Requests from the SDK are authenticated via token
- All external tasks created via the SDK are stored as part of existing projects

## ✅ Conventions
- Use dependency injection over facades where possible
- Use `FormRequest` classes for validation
- Controllers should stay slim – move logic to Services
- Vue components must use composition API

## ⚠️ Safeguards
- Do not modify SDK-related routes without reviewing SDK contract (found at ../shift-sdk)
- Do not delete migrations or seeders unless explicitly deprecated

## 🎯 Good Junie Tasks
- Generate new CRUD modules (controller + model + migration)
- Scaffold Vue.js pages with Tailwind styling
- Write integration tests for REST API endpoints
- Generate factory + seeder for a given model
- Add controller/service logic for planned features (e.g. file attachments)
