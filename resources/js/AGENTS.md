# SHIFT Frontend (`resources/js/`)

Applies inside `resources/js/**` in addition to the repo root file.

## Frontend Rules
- This subtree is the portal Inertia/Vue UI. The embedded SDK dashboard lives in `../shift-sdk-package/packages/shift-php/ui/`.
- Treat shared task surfaces as hard-parity areas with the SDK UI: task index and task sheet flows, `ShiftEditor`, `ButtonGroup` interactions, attachment/comment flows, and status/priority visuals.
- Prefer shared modules under `resources/js/shared/**` and the `@shared/**` alias before creating portal-only duplicates.
- New shared modules that may be imported by the SDK must not assume a global Ziggy `route()` helper at runtime. If the SDK needs URLs, pass explicit `/shift/api/**` endpoints from the SDK-side consumer.
- Standardize new toast and notification UX on `vue-sonner`.
- Do not introduce new Oruga components for new frontend work. Legacy Oruga usage can remain where it already exists.
- Do not edit generated build output in `public/build/**`.

## High-Value Touch Points
- App bootstrap: `resources/js/app.ts`
- Shared task behavior: `resources/js/shared/**`
- Portal task screens: `resources/js/pages/Tasks/**`
- Portal task components: `resources/js/components/**`
- Tests: `resources/js/__tests__/**`
