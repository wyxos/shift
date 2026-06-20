<?php

it('renders local public discovery demo screens with deterministic dummy data', function (
    string $screen,
    string $heading,
    string $demoUrl,
) {
    $this->get("/docs/public-discovery-demo/{$screen}")
        ->assertOk()
        ->assertSee('SHIFT public discovery demo', false)
        ->assertSee($heading, false)
        ->assertSee($demoUrl, false)
        ->assertSee('Maya Thompson', false)
        ->assertSee('data-screenshot-ready="'.$screen.'"', false);
})->with([
    'embedded issue form' => [
        'embedded-issue-form',
        'Embedded issue form',
        'https://shift-sdk-package.test/billing/invoices/INV-1047',
    ],
    'created task context' => [
        'created-task-context',
        'Created task with app context',
        'https://shift.test/tasks?project=northstar-billing-local',
    ],
    'backend error intake' => [
        'backend-error-intake',
        'Backend error intake',
        'https://shift-sdk-package.test/admin/reports/month-end',
    ],
    'task thread follow-up' => [
        'task-thread-follow-up',
        'Task thread and follow-up',
        'https://shift.test/tasks/SH-4187',
    ],
]);

it('returns not found for unknown public discovery demo screens', function () {
    $this->get('/docs/public-discovery-demo/not-real')
        ->assertNotFound();
});

it('hides public discovery demo screens outside local and testing environments', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/docs/public-discovery-demo/embedded-issue-form')
        ->assertNotFound();
});
