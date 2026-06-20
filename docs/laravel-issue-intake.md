# Laravel App Issue Intake

SHIFT captures issue reports from the Laravel page where the problem happened.

Install `wyxos/shift-php` in a Laravel app, let signed-in users report an issue from the current page, and keep the task, request context, backend error details, and follow-up thread in one place.

The demo shows that report moving from the host app into a task thread with enough context for triage.

## Workflow Fit

- Email is flexible, but the app route, signed-in user, environment, and current page state usually need to be reconstructed later.
- Ticket systems are good intake queues, but they often sit outside the product surface and ask the reporter to translate app behavior into support language.
- GitHub issues are useful once work is developer-shaped, but many app users should not need repository access or issue-writing habits.
- Sentry is built for exception telemetry. Backend error occurrences can be attached to the same task conversation as user-submitted context and follow-up.

The report starts from the Laravel app page before it becomes a detached support thread.

## Install Path

For a Laravel app:

```bash
composer require wyxos/shift-php
php artisan install:shift
```

The installer uses browser verification by default. It detects the local app URL and environment, asks a portal user to approve the install, writes the project credentials, registers the app environment, scaffolds a collaborator resolver when needed, and publishes the embedded dashboard assets.

Use the hosted portal URL:

```env
SHIFT_URL=https://shift.wyxos.com
```

Use a local or self-hosted portal URL for development or a private install:

```env
SHIFT_URL=https://shift.test
```

Local and private URLs are supported by the installer and package client. The active portal still needs to reach the app URL for collaborator lookup.

## Demo Screenshots

The screenshots below are generated from local fixture screens in this repository. They use fake names, `example.test` addresses, and `.test` URLs; no hosted data, client records, user records, or tokens are included.

![Embedded issue form](assets/laravel-issue-intake/01-embedded-issue-form.png)

![Created task with app context](assets/laravel-issue-intake/02-created-task-context.png)

![Backend error intake](assets/laravel-issue-intake/03-backend-error-intake.png)

![Task thread and follow-up](assets/laravel-issue-intake/04-task-thread-follow-up.png)

## Screenshot Scope

- Embedded issue/task form in a Laravel app.
- Created task with the originating app context attached.
- Backend error occurrence intake with scrubbed request and stack context.
- Task thread follow-up where the app user and developer discuss the same report.

## Regenerate Screenshots

Prerequisites:

- Run from `/Users/joeyj/Developer/wyxos/php/shift`.
- Composer and npm dependencies installed.
- The local app available through Herd at `https://shift.test`.
- `APP_ENV=local` or the test environment. The fixture route returns `404` elsewhere.

Command:

```bash
npm run docs:screenshots
```

Optional arguments:

```bash
npm run docs:screenshots -- --base-url=https://shift.test/docs/laravel-issue-intake-demo
npm run docs:screenshots -- --output-dir=docs/assets/laravel-issue-intake
npm run docs:screenshots -- --headed
```

The script captures the four fixture URLs at a 1920x1080 viewport and checks each PNG's dimensions before reporting success.

## Local Fixture Boundary

The screenshot route is:

```text
/docs/laravel-issue-intake-demo/{screen}
```

It is available only in `local` and `testing`. It keeps the screenshot set repeatable without hosted services or customer data.

The fixture data is deliberately human-readable but fake: example names, `example.test` email addresses, local `.test` URLs, and invented task IDs.
