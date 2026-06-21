<?php

it('renders local Laravel issue reporting demo screens with deterministic dummy data', function (
    string $screen,
    string $heading,
    string $demoUrl,
) {
    $this->get("/docs/laravel-issue-reporting-demo/{$screen}")
        ->assertOk()
        ->assertSee('Laravel issue reporting demo', false)
        ->assertSee($heading, false)
        ->assertSee($demoUrl, false)
        ->assertSee('Maya Thompson', false)
        ->assertSee('data-screenshot-ready="'.$screen.'"', false);
})->with([
    'report form' => [
        'report-form',
        'Issue report form',
        'https://shift-sdk-package.test/billing/invoices/INV-1047',
    ],
    'created task' => [
        'created-task',
        'Created task with app details',
        'https://shift.test/tasks?project=northstar-billing-local',
    ],
    'error report' => [
        'error-report',
        'Laravel error report',
        'https://shift-sdk-package.test/admin/reports/month-end',
    ],
    'task thread' => [
        'task-thread',
        'Task thread',
        'https://shift.test/tasks/SH-4187',
    ],
]);

it('returns not found for unknown Laravel issue reporting demo screens', function () {
    $this->get('/docs/laravel-issue-reporting-demo/not-real')
        ->assertNotFound();
});

it('hides Laravel issue reporting demo screens outside local and testing environments', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/docs/laravel-issue-reporting-demo/report-form')
        ->assertNotFound();
});
