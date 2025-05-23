## SHIFT Project Guideline

* **Frameworks:** Laravel 12 (backend), Vue 3 + Inertia.js (frontend)
* **Testing:** PHPUnit for backend code
* **Frontend:** Use Tailwind CSS
* **Coding Style:**

    * Follow default Laravel/Vue conventions
    * Use PSR-12 for PHP
    * Use Prettier for JS/TS/Vue formatting
* **Folder Structure:**

    * Keep backend logic in appropriate Laravel folders (Models, Controllers, etc.)
    * Place Vue components under `resources/js/Components`
* **Commits:**

    * Use short, descriptive commit messages
    * Prefer branches for new features/bugfixes
* **Environment:**

    * Copy `.env.example` to `.env` and set up local variables
* **Testing:**

    * Add or update PHPUnit tests when adding features or fixing bugs

## SHIFT SDK

SHIFT consists of two main components:
1. **SHIFT** - A web application for managing clients, projects, and tasks
2. **SHIFT SDK** - A package that allows integration with other Laravel applications.
3. **SHIFT SDK PACKAGE** - A Laravel app with the SDK installed to develop and test the SDK.
