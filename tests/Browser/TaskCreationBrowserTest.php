<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
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

it('lets a task manager remove an external submitter from follow up collaborators', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $externalSubmitter = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'name' => 'External Submitter',
        'email' => 'external-submitter@example.com',
    ]);
    $project->environments()->create([
        'environment' => $externalSubmitter->environment,
        'url' => $externalSubmitter->url,
    ]);
    $task = Task::factory()->for($project)->create([
        'title' => 'Submitter follow up preference',
    ]);
    $task->submitter()->associate($externalSubmitter)->save();
    $task->externalCollaborators()->attach($externalSubmitter->id);

    $this->actingAs($user);

    visit("/tasks?task={$task->id}")
        ->assertNoSmoke()
        ->assertSee('External Submitter')
        ->assertPresent('[aria-label="Remove External Submitter"]')
        ->click('[aria-label="Remove External Submitter"]')
        ->waitForText('Task changes saved')
        ->assertNotPresent('[aria-label="Remove External Submitter"]')
        ->assertNoSmoke();

    expect($task->refresh()->submitter_id)->toBe($externalSubmitter->id);
    $this->assertDatabaseMissing('task_collaborators', [
        'task_id' => $task->id,
        'external_user_id' => $externalSubmitter->id,
    ]);
});

it('renders email import independently from editor rewriting', function () {
    config()->set('ai_features.email_import.enabled', true);
    config()->set('ai_features.rewrite.enabled', false);

    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create();

    $this->actingAs($user);

    visit('/tasks')
        ->assertNoSmoke()
        ->click('[data-testid="open-create-task"]')
        ->assertVisible('[data-testid="task-email-import-dropzone"]')
        ->assertSee('Drop .eml or browse')
        ->assertDontSee('Improve with AI');
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

    visit("/error-reports?task={$task->id}")
        ->assertPathIs('/error-reports')
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

it('groups nearby messages and keeps the timeline controls visible across task sheet widths', function () {
    $user = User::factory()->create();
    $teammate = User::factory()->create(['name' => 'Alexandria Catherine Montgomery-Smythe']);
    $otherTeammate = User::factory()->create(['name' => 'Bob Mercer']);
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Audience browser proof',
        'description' => '',
    ]);
    $task->submitter()->associate($user)->save();
    $conversationStartedAt = now()->startOfMinute()->subMinutes(15);

    $sharedMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>Shared update</p>',
        'sender_name' => $teammate->name,
        'sender_type' => User::class,
        'sender_id' => $teammate->id,
        'created_at' => $conversationStartedAt,
        'updated_at' => $conversationStartedAt,
    ]);
    $groupedMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>One more detail</p>',
        'sender_name' => $teammate->name,
        'sender_type' => User::class,
        'sender_id' => $teammate->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(2),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(2),
    ]);
    $otherSenderMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>I’ll take a look</p>',
        'sender_name' => $otherTeammate->name,
        'sender_type' => User::class,
        'sender_id' => $otherTeammate->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(3),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(3),
    ]);
    $returnedSenderMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>Thanks, that helps</p>',
        'sender_name' => $teammate->name,
        'sender_type' => User::class,
        'sender_id' => $teammate->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(4),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(4),
    ]);
    $thresholdMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>Follow-up after pause</p>',
        'sender_name' => $teammate->name,
        'sender_type' => User::class,
        'sender_id' => $teammate->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(10),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(10),
    ]);
    $sentMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>My follow-up</p>',
        'sender_name' => $user->name,
        'sender_type' => User::class,
        'sender_id' => $user->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(11),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(11),
    ]);
    $groupedSentMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>One last thing</p>',
        'sender_name' => $user->name,
        'sender_type' => User::class,
        'sender_id' => $user->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(12),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(12),
    ]);
    $teamMessage = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'internal',
        'content' => '<p>Team planning note</p>',
        'sender_name' => $teammate->name,
        'sender_type' => User::class,
        'sender_id' => $teammate->id,
        'created_at' => $conversationStartedAt->copy()->addMinutes(13),
        'updated_at' => $conversationStartedAt->copy()->addMinutes(13),
    ]);

    $this->actingAs($user);

    $page = visit("/tasks?task={$task->id}")
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertSee('Shared update')
        ->assertSee('One more detail')
        ->assertSee('I’ll take a look')
        ->assertSee('Thanks, that helps')
        ->assertSee('Follow-up after pause')
        ->assertSee('Team planning note')
        ->assertSee('My follow-up')
        ->assertSee('One last thing')
        ->assertSee('Alexandria Catherine Montgomery-Smythe')
        ->assertVisible('[data-testid="task-comments-pane"]')
        ->assertVisible("[data-testid=\"comment-meta-{$sharedMessage->id}\"]")
        ->assertNotPresent("[data-testid=\"comment-meta-{$groupedMessage->id}\"]")
        ->assertVisible("[data-testid=\"comment-meta-{$otherSenderMessage->id}\"]")
        ->assertVisible("[data-testid=\"comment-meta-{$returnedSenderMessage->id}\"]")
        ->assertVisible("[data-testid=\"comment-meta-{$thresholdMessage->id}\"]")
        ->assertVisible("[data-testid=\"comment-meta-{$teamMessage->id}\"]")
        ->assertVisible("[data-testid=\"comment-meta-{$sentMessage->id}\"]")
        ->assertNotPresent("[data-testid=\"comment-meta-{$groupedSentMessage->id}\"]")
        ->assertVisible('[data-testid="thread-audience-all"]')
        ->assertVisible('[data-testid="thread-audience-team"]')
        ->assertVisible('[data-testid="toolbar-send"]')
        ->assertScript(
            "document.querySelector('[data-testid=\"comment-meta-{$sharedMessage->id}\"]').getBoundingClientRect().width > document.querySelector('[data-testid=\"comment-bubble-{$sharedMessage->id}\"]').getBoundingClientRect().width",
        );

    $page->resize(768, 1024)
        ->click('[data-testid="edit-mobile-pane-comments"]')
        ->assertVisible('[data-testid="task-comments-pane"]')
        ->assertVisible('[data-testid="thread-audience-all"]')
        ->assertVisible('[data-testid="thread-audience-team"]')
        ->assertVisible('[data-testid="toolbar-send"]');

    $page->resize(390, 844)
        ->assertVisible('[data-testid="task-comments-pane"]')
        ->assertVisible('[data-testid="thread-audience-all"]')
        ->assertVisible('[data-testid="thread-audience-team"]')
        ->assertVisible('[data-testid="toolbar-send"]');
});
