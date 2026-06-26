<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

it('normalizes existing revision split app error tasks', function () {
    $project = Project::factory()->create();
    $firstSeenAt = now()->subHour();
    $lastSeenAt = now();
    $sharedAttributes = [
        'project_id' => $project->id,
        'title' => 'Backend error: UnexpectedResponseException at vendor/symfony/mailer/Transport/Smtp/SmtpTransport.php:331',
        'priority' => 'high',
        'description' => '',
        'error_source' => 'backend',
        'error_environment' => 'production',
        'error_exception_class' => UnexpectedResponseException::class,
        'error_name' => null,
        'error_culprit_file' => 'vendor/symfony/mailer/Transport/Smtp/SmtpTransport.php',
        'error_culprit_line' => 331,
        'error_culprit_function' => 'executeCommand',
    ];

    $olderTask = Task::factory()->create($sharedAttributes + [
        'status' => TaskStatus::Completed->value,
        'error_signature' => str_repeat('a', 64),
        'error_release' => null,
        'error_git_sha' => '8d04686a1ff34db0307625b7ad6fb4ccb306587a',
        'error_occurrences_count' => 1,
        'error_first_seen_at' => $firstSeenAt,
        'error_last_seen_at' => $firstSeenAt,
    ]);
    $newerTask = Task::factory()->create($sharedAttributes + [
        'status' => TaskStatus::Pending->value,
        'error_signature' => str_repeat('b', 64),
        'error_release' => null,
        'error_git_sha' => 'a82dec293d8646eaef9b230db9f852f03118ec6a',
        'error_occurrences_count' => 1,
        'error_first_seen_at' => $lastSeenAt,
        'error_last_seen_at' => $lastSeenAt,
    ]);

    createLegacyErrorOccurrence($olderTask, 1, '8d04686a1ff34db0307625b7ad6fb4ccb306587a', $firstSeenAt);
    createLegacyErrorOccurrence($newerTask, 1, 'a82dec293d8646eaef9b230db9f852f03118ec6a', $lastSeenAt);

    (require database_path('migrations/2026_06_26_110000_normalize_app_error_signatures_without_revisions.php'))->up();

    expect(Task::query()->count())->toBe(1);

    $task = Task::query()->firstOrFail();
    $signature = hash('sha256', json_encode([
        'project_id' => $project->id,
        'environment' => 'production',
        'source' => 'backend',
        'name' => UnexpectedResponseException::class,
        'file' => 'vendor/symfony/mailer/Transport/Smtp/SmtpTransport.php',
        'line' => 331,
        'function' => 'executeCommand',
    ], JSON_THROW_ON_ERROR));

    expect($task->id)->toBe($olderTask->id)
        ->and($task->error_signature)->toBe($signature)
        ->and($task->status)->toBe(TaskStatus::Pending->value)
        ->and($task->error_occurrences_count)->toBe(2)
        ->and($task->error_git_sha)->toBe('a82dec293d8646eaef9b230db9f852f03118ec6a');

    expect(DB::table('task_error_occurrences')->orderBy('number')->pluck('task_id')->all())
        ->toBe([$olderTask->id, $olderTask->id]);
    expect(DB::table('task_error_occurrences')->orderBy('number')->pluck('number')->all())
        ->toBe([1, 2]);
    expect(DB::table('task_error_occurrences')->orderBy('number')->pluck('git_sha')->all())
        ->toBe([
            '8d04686a1ff34db0307625b7ad6fb4ccb306587a',
            'a82dec293d8646eaef9b230db9f852f03118ec6a',
        ]);
});

function createLegacyErrorOccurrence(Task $task, int $number, string $gitSha, CarbonInterface $receivedAt): void
{
    DB::table('task_error_occurrences')->insert([
        'task_id' => $task->id,
        'number' => $number,
        'source' => 'backend',
        'environment' => 'production',
        'release' => null,
        'git_sha' => $gitSha,
        'exception_class' => UnexpectedResponseException::class,
        'error_name' => null,
        'message' => 'Expected response code "250" but got code "550"',
        'culprit_file' => 'vendor/symfony/mailer/Transport/Smtp/SmtpTransport.php',
        'culprit_line' => 331,
        'culprit_function' => 'executeCommand',
        'request_method' => 'POST',
        'request_url' => 'https://consumer.test/mail',
        'request_path' => '/mail',
        'request_referrer' => null,
        'occurred_at' => $receivedAt,
        'received_at' => $receivedAt,
        'payload' => json_encode([]),
        'stacktrace' => json_encode([]),
        'context' => json_encode([]),
        'user' => json_encode([]),
        'metadata' => json_encode([]),
        'created_at' => $receivedAt,
        'updated_at' => $receivedAt,
    ]);
}
