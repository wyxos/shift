# SHIFT

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Laravel development teams handle issues and feature requests submitted by users
from inside connected applications. Developers need enough context to investigate
the report and keep follow-up with the original request.

Application users submit requests from the page they are using and review or
discuss requests they can access.

## Product Purpose

SHIFT brings an application report, its page and request details, related errors,
attachments, and replies into one task. Success means a developer can start
investigating without first reconstructing the report across email, screenshots,
and logs, while the reporter can follow the discussion.

## Positioning

The public starting point is issue reporting inside Laravel applications.
`wyxos/shift-php` connects the reporting experience to the portal and supplies
the page URL, route, signed-in user, environment, and selected request details.
The portal provides the structure to manage that work.

## Operating Context

- Users submit an issue or feature request from a connected Laravel application.
- Developers review tasks, app details, error events, attachments, and threads
  in the portal, then reply beside the report.
- Organisations, clients, projects, environments, and collaborators organise
  the work and determine access.
- The PHP package supports browser-based installation and manual credentials.
  Connected applications can use a hosted or self-hosted portal.
- This repository owns the shared portal and external API. Hosted marketing,
  billing, and SaaS administration belong to the hosted edition.

## Capabilities and Constraints

- Support in-app issue reports, feature requests, Laravel error reports,
  attachments, task threads, and notifications.
- Preserve project and task access rules and each external collaborator's source
  identity. Similar names or emails do not establish shared access.
- Embedded request intake offers Feature request and Report an issue. Internal
  task categories remain separate from those client-facing choices.
- A task can have no applicable environment. Collaborator changes must preserve
  its environment.
- Keep task creation and editing in the canonical task-list sheet flow.
- Portal and PHP package changes must preserve the shared API and embedded UI
  contracts.
- The public portal's home route sends guests to login and signed-in users to
  their dashboard.

## Brand Commitments

Use SHIFT as the product name and preserve its approved logo and wordmarks in
`public/brand/`, with the favicon in `public/favicon.svg`.
Use plain, practical language grounded in what the user can do.

## Evidence on Hand

- `README.md` describes the product, current capabilities, installation, and
  open-source licence.
- `docs/laravel-issue-reporting.md` explains the reporting workflow and package
  installation.
- `docs/assets/laravel-issue-reporting/*.png` demonstrates the report form,
  created task, error report, and task thread using illustrative fixtures.
  Those fixtures are not customer evidence or testimonials.
- The existing portal implementation provides the current workflows and UI.

## Product Principles

1. Start reports where the issue happens and carry useful application context
   into the task.
2. Keep investigation and follow-up beside the original report.
3. Make reporting straightforward for application users and investigation
   practical for developers.
4. Preserve access boundaries and compatibility across the portal and connected
   applications.

## Open Decisions

Additional product and accessibility requirements have not been established.
Confirm any specific user needs or required accessibility standard when a
future task depends on them.
