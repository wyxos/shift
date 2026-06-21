<?php

namespace App\Support;

final class LaravelIssueReportingDemo
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function screens(): array
    {
        return [
            'report-form' => [
                'slug' => 'report-form',
                'kind' => 'form',
                'accent' => 'teal',
                'title' => 'Issue report form',
                'subtitle' => 'A Laravel user reports an issue from the page where it happened.',
                'demo_url' => 'https://shift-sdk-package.test/billing/invoices/INV-1047',
                'location' => 'Laravel app',
                'task' => [
                    'id' => 'SH-4187',
                    'title' => 'Invoice export fails for finance reviewer',
                    'project' => 'Northstar Billing Portal',
                    'status' => 'Ready for review',
                    'priority' => 'High',
                ],
                'person' => [
                    'name' => 'Maya Thompson',
                    'email' => 'maya.thompson@example.test',
                    'role' => 'Finance reviewer',
                ],
                'form' => [
                    ['label' => 'Title', 'value' => 'Invoice export fails for finance reviewer'],
                    ['label' => 'What happened?', 'value' => 'The export button shows a success toast, but the CSV never downloads. I tried it twice on invoice INV-1047.'],
                    ['label' => 'Expected result', 'value' => 'Download a CSV with invoice lines, totals, and payment status.'],
                    ['label' => 'Page', 'value' => '/billing/invoices/INV-1047'],
                ],
                'details' => [
                    'Environment' => 'local',
                    'App URL' => 'https://shift-sdk-package.test',
                    'Route' => 'billing.invoices.show',
                    'User ID' => 'demo-user-24',
                    'Browser' => 'Chrome 126 on macOS',
                ],
            ],
            'created-task' => [
                'slug' => 'created-task',
                'kind' => 'task',
                'accent' => 'indigo',
                'title' => 'Created task with app details',
                'subtitle' => 'The task includes the page, route, environment, and reporter.',
                'demo_url' => 'https://shift.test/tasks?project=northstar-billing-local',
                'location' => 'Portal task',
                'task' => [
                    'id' => 'SH-4187',
                    'title' => 'Invoice export fails for finance reviewer',
                    'project' => 'Northstar Billing Portal',
                    'status' => 'Open',
                    'priority' => 'High',
                    'source' => 'Created from Laravel app',
                ],
                'person' => [
                    'name' => 'Maya Thompson',
                    'email' => 'maya.thompson@example.test',
                    'role' => 'Finance reviewer',
                ],
                'details' => [
                    'Submitted from' => 'https://shift-sdk-package.test/billing/invoices/INV-1047',
                    'Environment' => 'local',
                    'Route' => 'billing.invoices.show',
                    'App user' => 'Maya Thompson <maya.thompson@example.test>',
                    'Request ID' => 'demo-req-9f72a8',
                ],
                'timeline' => [
                    'Issue reported from the app',
                    'Task created in project',
                    'Page and user details attached',
                    'Thread opened for replies',
                ],
            ],
            'error-report' => [
                'slug' => 'error-report',
                'kind' => 'error',
                'accent' => 'rose',
                'title' => 'Laravel error report',
                'subtitle' => 'Cleaned Laravel exception details can create or update the matching task.',
                'demo_url' => 'https://shift-sdk-package.test/admin/reports/month-end',
                'location' => 'Laravel exception reporter',
                'task' => [
                    'id' => 'SH-4192',
                    'title' => 'Month-end report export throws storage disk exception',
                    'project' => 'Northstar Billing Portal',
                    'status' => 'Needs developer review',
                    'priority' => 'Urgent',
                ],
                'person' => [
                    'name' => 'Maya Thompson',
                    'email' => 'maya.thompson@example.test',
                    'role' => 'Finance reviewer',
                ],
                'error' => [
                    'class' => 'RuntimeException',
                    'message' => 'Configured export disk [reports-local] is not available.',
                    'seen' => 'Seen 3 times in 11 minutes',
                    'release' => 'local-demo-2026.06.20',
                ],
                'details' => [
                    'Method' => 'POST',
                    'Path' => '/admin/reports/month-end/export',
                    'Controller' => 'ReportExportController@store',
                    'Environment' => 'local',
                    'Removed fields' => 'password, token, authorization, cookie',
                ],
                'frames' => [
                    'app/Services/Reports/MonthEndExport.php:84',
                    'app/Http/Controllers/ReportExportController.php:31',
                    'vendor/laravel/framework/src/Illuminate/Routing/Controller.php:54',
                ],
            ],
            'task-thread' => [
                'slug' => 'task-thread',
                'kind' => 'thread',
                'accent' => 'amber',
                'title' => 'Task thread',
                'subtitle' => 'Developers and app users discuss the issue on the original task.',
                'demo_url' => 'https://shift.test/tasks/SH-4187',
                'location' => 'Task thread',
                'task' => [
                    'id' => 'SH-4187',
                    'title' => 'Invoice export fails for finance reviewer',
                    'project' => 'Northstar Billing Portal',
                    'status' => 'Waiting for confirmation',
                    'priority' => 'High',
                ],
                'person' => [
                    'name' => 'Maya Thompson',
                    'email' => 'maya.thompson@example.test',
                    'role' => 'Finance reviewer',
                ],
                'thread' => [
                    [
                        'author' => 'Daniel Reed',
                        'role' => 'Developer',
                        'body' => 'I can reproduce this locally from INV-1047. The export job queues correctly, but the browser never receives the signed download URL.',
                        'time' => '10:14',
                    ],
                    [
                        'author' => 'Maya Thompson',
                        'role' => 'Finance reviewer',
                        'body' => 'That matches what I saw. I need the CSV for month-end review, but the PDF export still works.',
                        'time' => '10:21',
                    ],
                    [
                        'author' => 'Daniel Reed',
                        'role' => 'Developer',
                        'body' => 'Fix is ready in the local branch. Can you retry the CSV export on the staging invoice once it is deployed?',
                        'time' => '10:43',
                    ],
                ],
                'details' => [
                    'Original page' => 'https://shift-sdk-package.test/billing/invoices/INV-1047',
                    'Environment' => 'local',
                    'Reporter' => 'Maya Thompson',
                    'Follow-up owner' => 'Daniel Reed',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function screen(string $screen): ?array
    {
        return self::screens()[$screen] ?? null;
    }
}
