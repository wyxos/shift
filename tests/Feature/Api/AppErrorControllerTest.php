<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

it('files a backend error as a task with a raw occurrence record', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/errors', appErrorPayload());

    $response->assertCreated()
        ->assertJsonPath('task.occurrences_count', 1)
        ->assertJsonPath('task.source', 'backend')
        ->assertJsonPath('task.environment', 'local')
        ->assertJsonPath('task.culprit.line', 88)
        ->assertJsonPath('occurrence.number', 1);

    expect(Schema::hasTable('app_errors'))->toBeFalse();
    expect(Schema::hasTable('task_error_occurrences'))->toBeTrue();
    expect(Task::query()->count())->toBe(1);
    expect(TaskThread::query()->count())->toBe(0);
    expect(DB::table('task_error_occurrences')->count())->toBe(1);

    $task = Task::query()->with('metadata')->firstOrFail();
    expect($task->error_signature)->toHaveLength(64)
        ->and($task->title)->toContain('RuntimeException')
        ->and($task->title)->toContain('InvoiceSync.php:88')
        ->and($task->status)->toBe(TaskStatus::Pending->value)
        ->and($task->priority)->toBe('high')
        ->and($task->error_culprit_file)->toBe('app/Services/Billing/InvoiceSync.php')
        ->and($task->error_culprit_line)->toBe(88)
        ->and($task->metadata?->source)->toBe('app_error')
        ->and($task->metadata?->intake_type)->toBe('error')
        ->and($task->metadata?->url)->toBe('https://consumer.test/invoices/1')
        ->and($task->metadata?->environment)->toBe('local')
        ->and($task->description)->toBe('');

    $occurrence = DB::table('task_error_occurrences')->first();
    expect($occurrence)->not->toBeNull()
        ->and($occurrence->task_id)->toBe($task->id)
        ->and($occurrence->number)->toBe(1)
        ->and($occurrence->source)->toBe('backend')
        ->and($occurrence->environment)->toBe('local')
        ->and($occurrence->release)->toBe('v1.2.3')
        ->and($occurrence->git_sha)->toBe('abc1234')
        ->and($occurrence->exception_class)->toBe(RuntimeException::class)
        ->and($occurrence->message)->toBe('Database password=[Filtered] failed')
        ->and($occurrence->culprit_file)->toBe('app/Services/Billing/InvoiceSync.php')
        ->and($occurrence->culprit_line)->toBe(88)
        ->and($occurrence->request_method)->toBe('POST')
        ->and($occurrence->request_url)->toBe('https://consumer.test/invoices/1')
        ->and($occurrence->request_path)->toBe('/invoices/1')
        ->and($occurrence->request_referrer)->toBe('https://consumer.test/dashboard');

    $payload = json_decode($occurrence->payload, true, 512, JSON_THROW_ON_ERROR);
    $stacktrace = json_decode($occurrence->stacktrace, true, 512, JSON_THROW_ON_ERROR);
    $context = json_decode($occurrence->context, true, 512, JSON_THROW_ON_ERROR);
    $occurrenceUser = json_decode($occurrence->user, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)
        ->toHaveKey('project', '[Filtered]')
        ->and($payload['exception']['message'])->toBe('Database password=[Filtered] failed')
        ->and($payload['context']['request']['authorization'])->toBe('[Filtered]')
        ->and($payload['context']['request']['body']['password'])->toBe('[Filtered]')
        ->and($payload['context']['request']['cookies']['shift_session'])->toBe('[Filtered]')
        ->and(json_encode($payload))->not->toContain('project-token')
        ->and(json_encode($payload))->not->toContain('cookie-secret')
        ->and(json_encode($payload))->not->toContain('Bearer secret')
        ->and(json_encode($payload))->not->toContain('```');

    expect($stacktrace['frames'][1])
        ->toMatchArray([
            'file' => app_path('Services/Billing/InvoiceSync.php'),
            'line' => 88,
            'function' => 'sync',
            'in_app' => true,
        ]);
    expect($context['request']['url'])->toBe('https://consumer.test/invoices/1');
    expect($occurrenceUser['id'])->toBe('consumer-user-1');

    $this->actingAs($user)
        ->getJson(route('task-error-occurrences.index', $task))
        ->assertOk()
        ->assertJsonPath('occurrences.0.number', 1)
        ->assertJsonPath('occurrences.0.message', 'Database password=[Filtered] failed')
        ->assertJsonPath('occurrences.0.request.url', 'https://consumer.test/invoices/1')
        ->assertJsonPath('occurrences.0.request.query.filter', 'open')
        ->assertJsonPath('occurrences.0.request.body.invoice_id', 1)
        ->assertJsonPath('occurrences.0.request.body.password', '[Filtered]')
        ->assertJsonPath('occurrences.0.stacktrace.frames.1.file', app_path('Services/Billing/InvoiceSync.php'))
        ->assertJsonPath('occurrences.0.payload.project', '[Filtered]');
});

it('groups repeated errors with the same project environment and frame as task occurrences', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $first = $this->withToken($token)->postJson('/api/errors', appErrorPayload());
    $second = $this->withToken($token)->postJson('/api/errors', appErrorPayload([
        'exception' => [
            'class' => RuntimeException::class,
            'message' => 'Database password=another-secret failed',
        ],
    ]));

    $first->assertCreated();
    $second->assertCreated()
        ->assertJsonPath('task.occurrences_count', 2)
        ->assertJsonPath('task.signature', $first->json('task.signature'))
        ->assertJsonPath('occurrence.number', 2);

    expect(Task::query()->count())->toBe(1);
    expect(TaskThread::query()->count())->toBe(0);
    expect(DB::table('task_error_occurrences')->count())->toBe(2);

    $task = Task::query()->firstOrFail();
    expect($task->error_occurrences_count)->toBe(2);
});

it('accepts numeric host user ids from sdk error reports', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/errors', appErrorPayload([
        'user' => [
            'id' => 123,
            'name' => 'Consumer User',
            'email' => 'consumer@example.com',
            'environment' => 'local',
            'url' => 'https://consumer.test',
        ],
    ]));

    $response->assertCreated();

    $occurrence = DB::table('task_error_occurrences')->first();
    $occurrenceUser = json_decode($occurrence->user, true, 512, JSON_THROW_ON_ERROR);

    expect($occurrenceUser['id'])->toBe(123);
});

it('recovers when a duplicate grouped task is inserted during first occurrence creation', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;
    $seededDuplicate = false;

    Task::creating(function (Task $task) use (&$seededDuplicate, $user) {
        if ($seededDuplicate || blank($task->error_signature)) {
            return true;
        }

        $seededDuplicate = true;

        Task::withoutEvents(function () use ($task, $user) {
            $duplicate = Task::query()->create([
                'project_id' => $task->project_id,
                'title' => $task->title,
                'description' => '',
                'status' => TaskStatus::Pending->value,
                'priority' => 'high',
                'error_signature' => $task->error_signature,
                'error_source' => $task->error_source,
                'error_environment' => $task->error_environment,
                'error_release' => $task->error_release,
                'error_git_sha' => $task->error_git_sha,
                'error_exception_class' => $task->error_exception_class,
                'error_name' => $task->error_name,
                'error_culprit_file' => $task->error_culprit_file,
                'error_culprit_line' => $task->error_culprit_line,
                'error_culprit_function' => $task->error_culprit_function,
                'error_occurrences_count' => 0,
                'error_first_seen_at' => now(),
                'error_last_seen_at' => null,
            ]);
            $duplicate->submitter()->associate($user);
            $duplicate->save();
        });

        return true;
    });

    try {
        $response = $this->withToken($token)->postJson('/api/errors', appErrorPayload());
    } finally {
        Event::forget('eloquent.creating: '.Task::class);
    }

    $response->assertCreated()
        ->assertJsonPath('task.occurrences_count', 1)
        ->assertJsonPath('occurrence.number', 1);

    expect($seededDuplicate)->toBeTrue();
    expect(Task::query()->count())->toBe(1);
    expect(DB::table('task_error_occurrences')->count())->toBe(1);

    $task = Task::query()->firstOrFail();
    expect($task->error_occurrences_count)->toBe(1);
});

it('returns the latest fifteen error occurrences', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $this->withToken($token)->postJson('/api/errors', appErrorPayload())->assertCreated();

    $task = Task::query()->firstOrFail();

    foreach (range(2, 20) as $number) {
        $task->errorOccurrences()->create([
            'number' => $number,
            'source' => 'backend',
            'environment' => 'local',
            'release' => 'v1.2.3',
            'git_sha' => 'abc1234',
            'exception_class' => RuntimeException::class,
            'error_name' => null,
            'message' => "Occurrence {$number}",
            'culprit_file' => 'app/Services/Billing/InvoiceSync.php',
            'culprit_line' => 88,
            'culprit_function' => 'sync',
            'request_method' => 'POST',
            'request_url' => 'https://consumer.test/invoices/1',
            'request_path' => '/invoices/1',
            'request_referrer' => 'https://consumer.test/dashboard',
            'occurred_at' => now()->addSeconds($number),
            'received_at' => now()->addSeconds($number),
            'payload' => ['number' => $number],
            'stacktrace' => ['frames' => []],
            'context' => [],
            'user' => [],
            'metadata' => [],
        ]);
    }

    $task->update([
        'error_occurrences_count' => 20,
        'error_last_seen_at' => now()->addSeconds(20),
    ]);

    $this->actingAs($user)
        ->getJson(route('task-error-occurrences.index', $task))
        ->assertOk()
        ->assertJsonCount(15, 'occurrences')
        ->assertJsonPath('occurrences.0.number', 20)
        ->assertJsonPath('occurrences.14.number', 6)
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.per_page', 15)
        ->assertJsonPath('pagination.total', 20);
});

it('keeps task comments separate from error occurrences', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $this->withToken($token)->postJson('/api/errors', appErrorPayload())->assertCreated();

    $task = Task::query()->firstOrFail();
    TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>Real task comment</p>',
        'sender_id' => $user->id,
        'sender_type' => User::class,
        'sender_name' => $user->name,
    ]);

    $this->actingAs($user)
        ->getJson(route('task-threads.index', $task))
        ->assertOk()
        ->assertJsonCount(0, 'internal')
        ->assertJsonPath('external.0.content', '<p>Real task comment</p>');
});

it('reopens a completed error task when the same signature occurs again', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $this->withToken($token)->postJson('/api/errors', appErrorPayload())->assertCreated();
    $task = Task::query()->firstOrFail();
    $task->update(['status' => TaskStatus::Completed->value]);

    $this->withToken($token)->postJson('/api/errors', appErrorPayload())->assertCreated();

    expect($task->fresh()->status)->toBe(TaskStatus::Pending->value);
});

it('groups repeated errors across deployment revisions while retaining occurrence revisions', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'project-token']);
    $token = $user->createToken('sdk')->plainTextToken;

    $first = $this->withToken($token)->postJson('/api/errors', appErrorPayload(['git_sha' => 'abc1234']));
    $second = $this->withToken($token)->postJson('/api/errors', appErrorPayload(['git_sha' => 'def5678']));
    $third = $this->withToken($token)->postJson('/api/errors', appErrorPayload([
        'release' => 'v2.0.0',
        'git_sha' => null,
    ]));

    $first->assertCreated();
    $second->assertCreated()
        ->assertJsonPath('task.signature', $first->json('task.signature'))
        ->assertJsonPath('task.occurrences_count', 2);
    $third->assertCreated()
        ->assertJsonPath('task.signature', $first->json('task.signature'))
        ->assertJsonPath('task.occurrences_count', 3);

    expect(Task::query()->count())->toBe(1);
    expect(TaskThread::query()->count())->toBe(0);
    expect(DB::table('task_error_occurrences')->count())->toBe(3);
    expect(DB::table('task_error_occurrences')->orderBy('number')->pluck('git_sha')->all())
        ->toBe(['abc1234', 'def5678', null]);
    expect(DB::table('task_error_occurrences')->orderBy('number')->pluck('release')->all())
        ->toBe(['v1.2.3', 'v1.2.3', 'v2.0.0']);
});

it('returns not found when the authenticated user cannot access the project token', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    Project::factory()->withAuthor($owner->id)->create(['token' => 'project-token']);

    $response = $this->withToken($otherUser->createToken('sdk')->plainTextToken)
        ->postJson('/api/errors', appErrorPayload());

    $response->assertNotFound()
        ->assertJsonPath('error', 'Project not found');

    expect(Task::query()->count())->toBe(0);
});

it('requires an authenticated token', function () {
    $response = $this->postJson('/api/errors', appErrorPayload());

    $response->assertUnauthorized();
});

it('validates source and project input before storing an occurrence', function () {
    $user = User::factory()->create();

    $response = $this->withToken($user->createToken('sdk')->plainTextToken)
        ->postJson('/api/errors', [
            'project' => '',
            'source' => 'cron',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['project', 'source']);

    expect(Task::query()->count())->toBe(0);
});

function appErrorPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'project' => 'project-token',
        'source' => 'backend',
        'environment' => 'local',
        'release' => 'v1.2.3',
        'git_sha' => 'abc1234',
        'exception' => [
            'class' => RuntimeException::class,
            'message' => 'Database password=secret failed',
        ],
        'stacktrace' => [
            'frames' => [
                [
                    'file' => base_path('vendor/laravel/framework/src/Illuminate/Foundation/Application.php'),
                    'line' => 12,
                    'function' => 'handle',
                    'in_app' => false,
                ],
                [
                    'file' => app_path('Services/Billing/InvoiceSync.php'),
                    'line' => 88,
                    'function' => 'sync',
                    'in_app' => true,
                ],
            ],
        ],
        'context' => [
            'request' => [
                'method' => 'POST',
                'url' => 'https://consumer.test/invoices/1',
                'path' => '/invoices/1',
                'referrer' => 'https://consumer.test/dashboard',
                'authorization' => 'Bearer secret',
                'query' => [
                    'filter' => 'open',
                    'token' => 'query-secret',
                ],
                'body' => [
                    'invoice_id' => 1,
                    'password' => 'request-secret',
                ],
                'cookies' => [
                    'shift_session' => 'cookie-secret',
                ],
            ],
        ],
        'user' => [
            'id' => 'consumer-user-1',
            'name' => 'Consumer User',
            'email' => 'consumer@example.com',
            'environment' => 'local',
            'url' => 'https://consumer.test',
        ],
        'occurred_at' => now()->subMinute()->toIso8601String(),
    ], $overrides);
}
