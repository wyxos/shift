# SHIFT – Internal Task Management System

SHIFT is a private, modular task management system built with **Laravel** and **Vue.js**, designed to streamline task tracking and project collaboration across multiple teams and applications.

---

## 🔧 Components

SHIFT consists of two tightly integrated components:

1. **Dashboard** (Admin Panel)  
   Web UI to manage organizations, clients, projects, and tasks.

2. **SDK** (Laravel Package)  
   An installable package to submit and sync tasks from external Laravel apps to the core dashboard.

---

## 🧩 Features

### 🖥️ Dashboard
- **Organizations**: Group clients under an umbrella
- **Clients**: Define project scopes
- **Projects**: Assign teams, track tasks
- **Tasks**: Create, update, and track
- **Priorities & Statuses**: Low/Medium/High, Pending/In Progress/Done

### 📦 SDK Integration
- Submit tasks from external Laravel apps via API
- Auto-send context (user, app, env) with task submissions
- View/manage external tasks within the dashboard

---

## 🛠️ Tech Stack

| Layer       | Tech                  |
|-------------|------------------------|
| Backend     | Laravel (v10+)         |
| Frontend    | Vue.js 3, Inertia.js   |
| Auth        | Laravel Sanctum        |
| Styling     | Tailwind CSS           |
| API Format  | REST (JSON)            |

---

## 🧱 Data Structure

- **Organization** → has many **Clients**
- **Client** → has many **Projects**
- **Project** → has many **Tasks**
- **Project** ↔ can have many **Users**

---

## 🚧 Development Status

SHIFT is under **active development**. Core features are stable; SDK integration is evolving.

### ✅ Completed
- Dashboard UI for managing organizations, clients, projects, tasks
- Role-based access via Laravel policies
- Full REST API for Projects & Tasks
- SDK install & test commands
- Authenticated submission from external apps

### 🧪 In Progress / Planned
- Task file attachments
- Task comments & activity logs
- Sub-tasks / checklists
- Project metrics / analytics
- OAuth / external user accounts
- Slack/email/push notifications

---

## 🚀 Getting Started (Dashboard)

```bash
git clone git@github.com:wyxos/shift.git
cd shift
cp .env.example .env
composer install
npm install
php artisan migrate
npm run dev
php artisan serve
````

Frontend will be available at:
**[http://localhost:8000](http://localhost:8000)**

Admin credentials are available in the internal docs or seeded users.

---

## 🔌 SDK Setup (External App)

Install the SDK package in a Laravel app:

```bash
composer require wyxos/shift-sdk
php artisan install:shift
```

You’ll be prompted to:

* Enter your SHIFT API token
* Provide a project token
* Configure `.env`

---

## 📂 Repo Notes

This is a **private monorepo**, containing:

* `dashboard/` – Core Laravel app
* `packages/shift-sdk/` – Reusable SDK for integration

All code is internal-use only. Do not distribute without permission.

---

## 🧪 Local Testing

To test SDK integration locally:

```bash
php artisan shift:test
```

This submits a test task to verify SDK ↔ dashboard connectivity.

---

## 📝 License

This repository is private and proprietary. For internal use by Wyxos team members only. All rights reserved.
