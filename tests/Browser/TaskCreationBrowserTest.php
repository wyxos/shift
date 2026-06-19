<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('redirects legacy create route to the tasks list', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/tasks/create')
        ->assertPathIs('/tasks')
        ->assertSee('Tasks');
});

it('opens an error intake task with tabbed comments and occurrences', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'UI error: Widget crashed at https://consumer.test/widget.js:88',
        'status' => 'pending',
        'priority' => 'high',
        'description' => '',
        'error_signature' => str_repeat('a', 64),
        'error_source' => 'ui',
        'error_environment' => 'local',
        'error_name' => 'WidgetCrash',
        'error_culprit_file' => 'https://consumer.test/widget.js',
        'error_culprit_line' => 88,
        'error_occurrences_count' => 1,
        'error_first_seen_at' => now()->subMinute(),
        'error_last_seen_at' => now(),
    ]);
    $task->submitter()->associate($user)->save();

    DB::table('task_error_occurrences')->insert([
        'task_id' => $task->id,
        'number' => 1,
        'source' => 'ui',
        'environment' => 'local',
        'error_name' => 'WidgetCrash',
        'message' => 'Widget crashed token=[Filtered]',
        'culprit_file' => 'https://consumer.test/widget.js',
        'culprit_line' => 88,
        'culprit_function' => 'renderWidget',
        'request_url' => 'https://consumer.test/demo',
        'request_referrer' => 'https://consumer.test/dashboard',
        'payload' => json_encode(['project' => '[Filtered]', 'message' => 'Widget crashed token=[Filtered]'], JSON_THROW_ON_ERROR),
        'stacktrace' => json_encode([
            'frames' => [
                [
                    'file' => 'https://consumer.test/widget.js',
                    'line' => 88,
                    'function' => 'renderWidget',
                    'in_app' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'context' => json_encode(['request' => ['url' => 'https://consumer.test/demo']], JSON_THROW_ON_ERROR),
        'user' => json_encode(['environment' => 'local', 'url' => 'https://consumer.test'], JSON_THROW_ON_ERROR),
        'received_at' => now(),
        'occurred_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user);

    visit("/tasks?task={$task->id}")
        ->assertSee('UI error: Widget crashed')
        ->assertSee('Occurrences')
        ->assertSee('Comments')
        ->assertDontSee('Occurrence #1')
        ->click('@error-occurrences-tab')
        ->assertSee('Occurrence #1')
        ->assertSee('Widget crashed token=[Filtered]')
        ->assertSee('https://consumer.test/dashboard')
        ->assertSee('https://consumer.test/widget.js:88');
});
