<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppErrorReportedNotification;

it('renders app error mail with the error summary first', function () {
    $project = Project::factory()->create(['name' => 'Example']);
    $task = Task::factory()
        ->for($project)
        ->create([
            'title' => 'RuntimeException at vendor/symfony/console/Application.php:112',
            'error_environment' => 'production',
            'error_occurrences_count' => 1,
        ]);

    $mail = (new AppErrorReportedNotification(
        task: $task,
        reason: 'created',
        url: 'https://shift.test/tasks?task=123',
    ))->toMail(User::factory()->create());
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Example - RuntimeException at vendor/symfony/console/Application.php:112')
        ->and($mail->greeting)->toBe('RuntimeException at vendor/symfony/console/Application.php:112')
        ->and($mail->introLines)->toBe([
            'Project: Example',
            'Event: Reported',
            'Environment: production',
            'Occurrences: 1',
        ]);

    expect($html)
        ->toContain('RuntimeException at vendor/symfony/console/Application.php:112')
        ->toContain('Project: Example')
        ->not->toContain('Hello!');
});
