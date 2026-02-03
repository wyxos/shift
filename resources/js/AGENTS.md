# SHIFT Frontend (`resources/js/`)

## Package Identity
- Vue 3 + Inertia UI for the SHIFT portal.
- Unit tests live in `resources/js/__tests__/**` (Vitest).

## Setup & Run
- Install: `npm install` (CI uses `npm ci`)
- Dev server: `npm run dev` (or `composer dev` for full stack)
- Build: `npm run build`
- Test: `npm run test` (watch: `npm run test:watch`, coverage: `npm run test:coverage`)
- Format: `npm run format` (check: `npm run format:check`)
- Lint: `npm run lint`

## Patterns & Conventions
- Pages: `resources/js/pages/**`
  - ✅ DO: Follow CRUD page patterns like `resources/js/pages/Tasks/Index.vue` and `resources/js/pages/Tasks/Edit.vue`
- Components: `resources/js/components/**`
  - ✅ DO: Keep shared UI in components (example: `resources/js/components/TaskThreadMessage.vue`)
  - ❌ DON'T: Edit build output in `public/build/**`
- Composables: `resources/js/composables/**`
  - ✅ DO: Keep task feature hooks in composables (examples: `resources/js/composables/useTaskThreads.ts`, `resources/js/composables/useTaskAttachments.ts`)
- Tests:
  - ✅ DO: Add page/component tests under `resources/js/__tests__/**` (example: `resources/js/__tests__/pages/Dashboard.test.ts`)

## Touch Points / Key Files
- App entry: `resources/js/app.ts`
- Shared utilities: `resources/js/lib/utils.ts`
- Tasks pages: `resources/js/pages/Tasks/Index.vue`
- Test setup: `resources/js/__tests__/setupTests.ts`

## JIT Index Hints
- Find a page: `rg -n "<script setup" resources/js/pages`
- Find a composable: `rg -n "export function use" resources/js/composables`
- Run a single test file: `npm run test -- resources/js/__tests__/pages/Dashboard.test.ts`

## Pre-PR Checks
- `npm run format:check && npm run lint && npm run test && npm run build`
