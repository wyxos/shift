<?php

use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\TaskThread;
use App\Models\TaskThreadMention;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->withAuthor($this->owner->id)->create([
        'token' => 'audience-project-token',
    ]);
    $this->task = Task::factory()->for($this->project)->create();
    $this->task->submitter()->associate($this->owner)->save();
});

function registerAudienceProjectUser(Project $project, User $user): void
{
    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
        'registration_status' => 'registered',
    ]);
}

function audienceMentionHtml(string $kind, int|string $id, string $label): string
{
    return '<p>Hello <span class="shift-mention" data-shift-mention="true" data-mention-kind="'.$kind
        .'" data-mention-id="'.$id.'">@'.$label.'</span></p>';
}

test('portal returns a chronological authorized union while preserving compatibility buckets', function () {
    $all = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>All first</p>',
        'sender_name' => $this->owner->name,
        'sender_type' => User::class,
        'sender_id' => $this->owner->id,
        'created_at' => now()->subMinute(),
    ]);
    $team = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'internal',
        'content' => '<p>Team second</p>',
        'sender_name' => $this->owner->name,
        'sender_type' => User::class,
        'sender_id' => $this->owner->id,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson(route('task-threads.index', $this->task))
        ->assertOk()
        ->assertJsonCount(1, 'external')
        ->assertJsonCount(1, 'internal')
        ->assertJsonCount(2, 'threads');

    expect($response->json('threads.0.id'))->toBe($all->id)
        ->and($response->json('threads.0.audience'))->toBe('all')
        ->and($response->json('threads.0.mentions'))->toBe([])
        ->and($response->json('threads.1.id'))->toBe($team->id)
        ->and($response->json('threads.1.audience'))->toBe('team')
        ->and($response->json('threads.1.mentions'))->toBe([]);
});

test('owner can atomically add and mention an eligible internal collaborator with only the reply notification', function () {
    Notification::fake();
    Queue::fake();
    $candidate = User::factory()->create();
    registerAudienceProjectUser($this->project, $candidate);

    $response = $this->actingAs($this->owner)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => audienceMentionHtml('internal', $candidate->id, 'Spoofed label'),
            'type' => 'external',
            'mentions' => [['kind' => 'internal', 'id' => $candidate->id]],
            'add_collaborators' => [['kind' => 'internal', 'id' => $candidate->id]],
        ])
        ->assertCreated();

    $threadId = $response->json('thread.id');

    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $this->task->id,
        'kind' => 'internal',
        'user_id' => $candidate->id,
    ]);
    $this->assertDatabaseHas('task_thread_mentions', [
        'task_thread_id' => $threadId,
        'kind' => 'internal',
        'user_id' => $candidate->id,
    ]);
    expect($response->json('thread.content'))->toContain('@'.$candidate->name)
        ->not->toContain('Spoofed label')
        ->and(TaskCollaboratorNotification::query()->count())->toBe(0);

    Notification::assertSentToTimes($candidate, TaskThreadUpdated::class, 1);
});

test('users without collaborator-management permission cannot add a mention recipient', function () {
    $actor = User::factory()->create();
    $candidate = User::factory()->create();
    registerAudienceProjectUser($this->project, $candidate);
    $this->task->internalCollaborators()->attach($actor->id);

    $this->actingAs($actor)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => audienceMentionHtml('internal', $candidate->id, $candidate->name),
            'type' => 'external',
            'mentions' => [['kind' => 'internal', 'id' => $candidate->id]],
            'add_collaborators' => [['kind' => 'internal', 'id' => $candidate->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('add_collaborators');

    expect(TaskThread::query()->count())->toBe(0);
    $this->assertDatabaseMissing('task_collaborators', [
        'task_id' => $this->task->id,
        'user_id' => $candidate->id,
    ]);
});

test('Team rejects external mentions and no partial message is persisted', function () {
    $external = ExternalUser::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'external-team-mention',
        'environment' => 'testing',
        'url' => 'https://consumer.example.test',
    ]);
    $this->task->externalCollaborators()->attach($external->id);

    $this->actingAs($this->owner)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => audienceMentionHtml('external', $external->external_id, $external->name),
            'type' => 'internal',
            'mentions' => [['kind' => 'external', 'id' => $external->external_id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mentions');

    expect(TaskThread::query()->count())->toBe(0)
        ->and(TaskThreadMention::query()->count())->toBe(0);
});

test('rendered mention markup must match authorized structured identities', function () {
    $collaborator = User::factory()->create();
    $this->task->internalCollaborators()->attach($collaborator->id);

    $this->actingAs($this->owner)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => audienceMentionHtml('internal', $collaborator->id, $collaborator->name),
            'type' => 'external',
            'mentions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mentions');

    expect(TaskThread::query()->count())->toBe(0);
});

test('editing cannot change audience and preserves the original message', function () {
    $thread = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'internal',
        'content' => '<p>Team original</p>',
        'sender_name' => $this->owner->name,
        'sender_type' => User::class,
        'sender_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->putJson(route('task-threads.update', [$this->task, $thread]), [
            'content' => '<p>Changed</p>',
            'type' => 'external',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    expect($thread->fresh()->type)->toBe('internal')
        ->and($thread->fresh()->content)->toBe('<p>Team original</p>');
});

test('All messages cannot quote Team messages or embed Team attachments', function () {
    Storage::fake('local');
    $team = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'internal',
        'content' => '<p>Team context</p>',
        'sender_name' => $this->owner->name,
        'sender_type' => User::class,
        'sender_id' => $this->owner->id,
    ]);
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class,
        'attachable_id' => $team->id,
        'original_filename' => 'team.txt',
        'path' => 'attachments/team.txt',
    ]);

    $this->actingAs($this->owner)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => '<blockquote class="shift-reply" data-reply-to="'.$team->id.'"><p>Team context</p></blockquote>',
            'type' => 'external',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');

    $this->actingAs($this->owner)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => '<p><a href="/attachments/'.$attachment->id.'/download">Team file</a></p>',
            'type' => 'external',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');

    expect(TaskThread::query()->count())->toBe(1);
});

test('mention candidates prioritize current collaborators and Team omits external people', function () {
    $internal = User::factory()->create();
    $addable = User::factory()->create();
    registerAudienceProjectUser($this->project, $addable);
    $external = ExternalUser::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'external-candidate',
        'environment' => 'testing',
        'url' => 'https://consumer.example.test',
    ]);
    $this->task->internalCollaborators()->attach($internal->id);
    $this->task->externalCollaborators()->attach($external->id);

    $all = $this->actingAs($this->owner)
        ->getJson(route('task-thread-mentions.candidates', [$this->task, 'audience' => 'all']))
        ->assertOk();
    $team = $this->actingAs($this->owner)
        ->getJson(route('task-thread-mentions.candidates', [$this->task, 'audience' => 'team']))
        ->assertOk();

    expect(collect($all->json('existing'))->pluck('id')->all())->toBe([$internal->id, $external->external_id])
        ->and(collect($all->json('addable'))->pluck('id'))->toContain($addable->id)
        ->and(collect($team->json('existing'))->pluck('id')->all())->toBe([$internal->id])
        ->and(collect($team->json('addable'))->pluck('kind')->unique()->all())->toBe(['internal']);
});

test('external API cannot show update delete or download Team resources', function () {
    Storage::fake('local');
    Sanctum::actingAs($this->owner);
    $external = ExternalUser::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'external-reader',
        'environment' => 'testing',
        'url' => 'https://consumer.example.test',
    ]);
    $this->task->externalCollaborators()->attach($external->id);
    $team = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'internal',
        'content' => '<p>Team secret</p>',
        'sender_name' => $external->name,
        'sender_type' => ExternalUser::class,
        'sender_id' => $external->id,
    ]);
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class,
        'attachable_id' => $team->id,
        'original_filename' => 'team-secret.txt',
        'path' => 'attachments/team-secret.txt',
    ]);
    Storage::put($attachment->path, 'secret');
    $context = [
        'project' => $this->project->token,
        'user' => [
            'id' => $external->external_id,
            'environment' => $external->environment,
            'url' => $external->url,
            'name' => $external->name,
            'email' => $external->email,
        ],
        'metadata' => [
            'environment' => $external->environment,
            'url' => $external->url,
        ],
    ];

    $this->getJson(route('api.task-threads.show', [
        'task' => $this->task,
        'threadId' => $team->id,
        ...$context,
    ]))->assertNotFound();
    $this->putJson(route('api.task-threads.update', ['task' => $this->task, 'threadId' => $team->id]), [
        ...$context,
        'content' => '<p>Changed</p>',
    ])->assertNotFound();
    $this->deleteJson(route('api.task-threads.destroy', ['task' => $this->task, 'threadId' => $team->id]), $context)->assertNotFound();
    $this->getJson(route('api.attachments.download', ['attachment' => $attachment, ...$context]))->assertNotFound();

    expect($team->fresh()->content)->toBe('<p>Team secret</p>')
        ->and(TaskThread::query()->whereKey($team->id)->exists())->toBeTrue();
});

test('additive mention migration leaves historical audience rows untouched and requires no backfill', function () {
    $all = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => 'Historical All',
        'sender_name' => $this->owner->name,
        'sender_type' => User::class,
        'sender_id' => $this->owner->id,
    ]);
    $team = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'internal',
        'content' => 'Historical Team',
        'sender_name' => $this->owner->name,
        'sender_type' => User::class,
        'sender_id' => $this->owner->id,
    ]);

    Schema::dropIfExists('task_thread_mentions');
    $migration = require database_path('migrations/2026_07_30_120000_create_task_thread_mentions_table.php');
    $migration->up();

    expect(Schema::hasTable('task_thread_mentions'))->toBeTrue()
        ->and(TaskThread::query()->whereKey($all->id)->value('type'))->toBe('external')
        ->and(TaskThread::query()->whereKey($team->id)->value('type'))->toBe('internal')
        ->and(TaskThreadMention::query()->count())->toBe(0);
});
