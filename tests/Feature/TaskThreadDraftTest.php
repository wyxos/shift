<?php

use App\Jobs\SendTaskThreadNotification;
use App\Jobs\SendTaskThreadNotifications;
use App\Mcp\Servers\ShiftServer;
use App\Mcp\Tools\DraftTaskThreadCommentTool;
use App\Mcp\Tools\EditTaskThreadCommentTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTaskThreadsTool;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use App\Services\TaskThreadNotificationService;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\AccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    Queue::fake();
    $this->author = User::factory()->create();
    $this->other = User::factory()->create();
    $this->project = Project::factory()->withAuthor($this->author->id)->create([
        'mcp_enabled' => true,
        'token' => 'draft-project-token',
    ]);
    $this->task = Task::factory()->for($this->project)->create();
    $this->task->submitter()->associate($this->author)->save();
    $this->task->internalCollaborators()->attach($this->other);
    $this->draft = TaskThread::query()->create([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>Private prepared reply</p>',
        'sender_name' => $this->author->name,
        'sender_type' => User::class,
        'sender_id' => $this->author->id,
        'is_draft' => true,
    ]);
});

function draftMcpAs(User $user, array $scopes = ['mcp:read', 'mcp:write']): \Laravel\Mcp\Server\Testing\PendingTestResponse
{
    $user->withAccessToken(new AccessToken([
        'oauth_user_id' => $user->id,
        'oauth_scopes' => $scopes,
    ]));

    return ShiftServer::actingAs($user);
}

test('MCP prepares a sanitized private draft without notifying any audience', function () {
    draftMcpAs($this->author)->tool(DraftTaskThreadCommentTool::class, [
        'task_id' => $this->task->id,
        'content' => '<p>New prepared reply</p><script>alert(1)</script>',
        'audience' => 'all',
    ])->assertOk()->assertSee('New prepared reply')->assertDontSee('<script');

    $draft = TaskThread::query()->withDraftsFor($this->author)->latest('id')->firstOrFail();
    expect($draft->is_draft)->toBeTrue()
        ->and($draft->type)->toBe('external')
        ->and($draft->sender_id)->toBe($this->author->id)
        ->and($draft->published_at)->toBeNull()
        ->and(TaskThread::query()->count())->toBe(0);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('MCP drafts require write scope and a visible MCP-enabled task', function () {
    draftMcpAs($this->author, ['mcp:read'])->tool(DraftTaskThreadCommentTool::class, [
        'task_id' => $this->task->id, 'content' => '<p>No write</p>',
    ])->assertHasErrors(['mcp:write']);
    $outsider = User::factory()->create();
    draftMcpAs($outsider)->tool(DraftTaskThreadCommentTool::class, [
        'task_id' => $this->task->id, 'content' => '<p>Hidden</p>',
    ])->assertHasErrors(['not found or is not visible']);
    $this->project->update(['mcp_enabled' => false]);
    draftMcpAs($this->author)->tool(DraftTaskThreadCommentTool::class, [
        'task_id' => $this->task->id, 'content' => '<p>Disabled</p>',
    ])->assertHasErrors(['not found or is not visible']);
    expect(TaskThread::withoutGlobalScope('published')->count())->toBe(1);
});

test('portal conversation includes only the current authors unpublished drafts', function () {
    $this->actingAs($this->author)->getJson(route('task-threads.index', $this->task))
        ->assertOk()->assertJsonCount(1, 'threads')
        ->assertJsonPath('threads.0.is_draft', true)
        ->assertJsonPath('threads.0.can_publish', true);
    $this->actingAs($this->other)->getJson(route('task-threads.index', $this->task))
        ->assertOk()->assertJsonCount(0, 'threads');
    $this->actingAs($this->other)->getJson(route('task-threads.show', [$this->task, $this->draft]))->assertNotFound();
    $this->actingAs($this->other)->putJson(route('task-threads.update', [$this->task, $this->draft]), [
        'content' => '<p>Changed</p>',
    ])->assertNotFound();
    $this->actingAs($this->other)->deleteJson(route('task-threads.destroy', [$this->task, $this->draft]))->assertNotFound();
});

test('MCP lists and edits own drafts but hides drafts from other task collaborators and task counts', function () {
    draftMcpAs($this->author)->tool(ListTaskThreadsTool::class, ['task_id' => $this->task->id])
        ->assertOk()->assertSee('Private prepared reply');
    draftMcpAs($this->other)->tool(ListTaskThreadsTool::class, ['task_id' => $this->task->id])
        ->assertOk()->assertDontSee('Private prepared reply');
    draftMcpAs($this->author)->tool(EditTaskThreadCommentTool::class, [
        'thread_id' => $this->draft->id, 'content' => '<p>Revised draft</p>',
    ])->assertOk()->assertSee('Revised draft');
    draftMcpAs($this->other)->tool(EditTaskThreadCommentTool::class, [
        'thread_id' => $this->draft->id, 'content' => '<p>Hijacked</p>',
    ])->assertHasErrors(['was not found']);
    draftMcpAs($this->author)->tool(GetTaskTool::class, ['task_id' => $this->task->id])
        ->assertOk()->assertDontSee('Revised draft');
    expect($this->task->loadCount('threads')->threads_count)->toBe(0);
    Notification::assertNothingSent();
});

test('author can edit and delete a draft without publishing it', function () {
    $this->actingAs($this->author)->putJson(route('task-threads.update', [$this->task, $this->draft]), [
        'content' => '<p>Portal revision</p>',
    ])->assertOk()->assertJsonPath('thread.is_draft', true)->assertJsonPath('thread.can_publish', true);
    $this->actingAs($this->author)->deleteJson(route('task-threads.destroy', [$this->task, $this->draft]))->assertOk();
    $this->assertDatabaseMissing('task_threads', ['id' => $this->draft->id]);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('publishing a draft once exposes it and notifies the audience exactly once', function () {
    $external = ExternalUser::factory()->create(['project_id' => $this->project->id]);
    $this->task->externalCollaborators()->attach($external);
    $this->travel(5)->minutes();
    $url = route('task-threads.publish', [$this->task, $this->draft]);
    $this->actingAs($this->author)->postJson($url)
        ->assertOk()->assertJsonPath('thread.is_draft', false)->assertJsonPath('thread.can_publish', false);
    $this->actingAs($this->author)->postJson($url)->assertOk();
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
    Queue::assertPushed(SendTaskThreadNotifications::class, function (SendTaskThreadNotifications $job): bool {
        $job->handle(app(TaskThreadNotificationService::class));

        return true;
    });
    $published = TaskThread::query()->findOrFail($this->draft->id);
    expect($published->published_at)->not->toBeNull()
        ->and($published->created_at->equalTo($published->published_at))->toBeTrue();
    $this->actingAs($this->other)->getJson(route('task-threads.index', $this->task))
        ->assertJsonCount(1, 'threads')->assertJsonPath('threads.0.id', $published->id);
    Notification::assertSentToTimes($this->other, TaskThreadUpdated::class, 1);
    Queue::assertPushed(SendTaskThreadNotification::class, 1);
});

test('only the author with continued task access can publish the draft', function () {
    $url = route('task-threads.publish', [$this->task, $this->draft]);
    $this->actingAs($this->other)->postJson($url)->assertNotFound();
    $otherTask = Task::factory()->for($this->project)->create();
    $this->actingAs($this->author)->postJson(route('task-threads.publish', [$otherTask, $this->draft]))->assertNotFound();
    $this->task->submitter()->associate($this->other)->save();
    $this->project->update(['author_id' => $this->other->id]);
    $this->actingAs($this->author)->postJson($url)->assertNotFound();
    expect(TaskThread::withoutGlobalScope('published')->findOrFail($this->draft->id)->is_draft)->toBeTrue();
    Notification::assertNothingSent();
});

test('notification service refuses to send an unpublished draft', function () {
    app(TaskThreadNotificationService::class)->send($this->task, $this->draft);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('publishing Team drafts preserves their audience and sends no external callback', function () {
    $external = ExternalUser::factory()->create(['project_id' => $this->project->id]);
    $this->task->externalCollaborators()->attach($external);
    $this->draft->update(['type' => 'internal']);

    $this->actingAs($this->author)->postJson(route('task-threads.publish', [$this->task, $this->draft]))
        ->assertOk()->assertJsonPath('thread.audience', 'team')->assertJsonPath('thread.is_draft', false);
    Queue::assertPushed(SendTaskThreadNotifications::class, function (SendTaskThreadNotifications $job): bool {
        $job->handle(app(TaskThreadNotificationService::class));

        return true;
    });

    Notification::assertSentToTimes($this->other, TaskThreadUpdated::class, 1);
    Queue::assertNotPushed(SendTaskThreadNotification::class);
});

test('publishing revalidates audience references and leaves invalid drafts unpublished', function () {
    $team = TaskThread::query()->create([
        'task_id' => $this->task->id, 'type' => 'internal', 'content' => '<p>Team secret</p>',
        'sender_name' => $this->author->name, 'sender_type' => User::class, 'sender_id' => $this->author->id,
    ]);
    $this->draft->update(['content' => '<blockquote data-reply-to="'.$team->id.'"><p>Team secret</p></blockquote>']);

    $this->actingAs($this->author)->postJson(route('task-threads.publish', [$this->task, $this->draft]))
        ->assertUnprocessable()->assertJsonValidationErrors('content');

    expect(TaskThread::withoutGlobalScope('published')->findOrFail($this->draft->id)->is_draft)->toBeTrue();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('draft attachments remain private and the owner can download them', function () {
    Storage::fake('local');
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class,
        'attachable_id' => $this->draft->id,
        'original_filename' => 'draft.txt',
        'path' => 'attachments/draft.txt',
    ]);
    Storage::put($attachment->path, 'Private draft attachment');
    $this->actingAs($this->author)->get(route('attachments.download', $attachment))->assertOk();
    $this->actingAs($this->other)->get(route('attachments.download', $attachment))->assertNotFound();
    $this->actingAs($this->other)->getJson(route('attachments.list', ['type' => 'task_thread', 'id' => $this->draft->id]))->assertNotFound();
});

test('external API cannot list read edit delete or download unpublished drafts', function () {
    Storage::fake('local');
    Sanctum::actingAs($this->author);
    $external = ExternalUser::factory()->create([
        'project_id' => $this->project->id,
        'environment' => 'testing',
        'url' => 'https://consumer.example.test',
    ]);
    $this->task->externalCollaborators()->attach($external);
    $context = [
        'project' => $this->project->token,
        'user' => ['id' => $external->external_id, 'environment' => $external->environment, 'url' => $external->url],
    ];
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class, 'attachable_id' => $this->draft->id,
        'original_filename' => 'draft.txt', 'path' => 'attachments/draft.txt',
    ]);
    Storage::put($attachment->path, 'Private');
    $this->getJson(route('api.task-threads.index', ['task' => $this->task, ...$context]))->assertOk()->assertJsonCount(0, 'external');
    $url = route('api.task-threads.show', ['task' => $this->task, 'threadId' => $this->draft->id, ...$context]);
    $this->getJson($url)->assertNotFound();
    $this->putJson($url, [...$context, 'content' => '<p>Changed</p>'])->assertNotFound();
    $this->deleteJson($url, $context)->assertNotFound();
    $this->getJson(route('api.attachments.download', ['attachment' => $attachment, ...$context]))->assertNotFound();
    $this->postJson(route('api.task-threads.store', ['task' => $this->task]), [
        ...$context, 'content' => '<p>Cannot draft here</p>', 'type' => 'external', 'is_draft' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('is_draft');
});

test('publishing All drafts permits their own inline attachments', function () {
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class, 'attachable_id' => $this->draft->id,
        'original_filename' => 'draft.png', 'path' => 'attachments/draft.png',
    ]);
    $this->draft->update(['content' => '<p><img src="/attachments/'.$attachment->id.'/download"></p>']);

    $this->actingAs($this->author)->postJson(route('task-threads.publish', [$this->task, $this->draft]))
        ->assertOk()->assertJsonPath('thread.is_draft', false);

    expect(TaskThread::query()->findOrFail($this->draft->id)->content)->toContain('/attachments/'.$attachment->id.'/download');
});

test('publishing All drafts cannot expose another unpublished drafts inline attachments', function () {
    $otherDraft = TaskThread::withoutGlobalScope('published')->create([
        'task_id' => $this->task->id, 'type' => 'external', 'content' => '<p>Another draft</p>',
        'sender_name' => $this->author->name, 'sender_type' => User::class, 'sender_id' => $this->author->id,
        'is_draft' => true,
    ]);
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class, 'attachable_id' => $otherDraft->id,
        'original_filename' => 'other-draft.png', 'path' => 'attachments/other-draft.png',
    ]);
    $this->draft->update(['content' => '<p><img src="/attachments/'.$attachment->id.'/download"></p>']);

    $this->actingAs($this->author)->postJson(route('task-threads.publish', [$this->task, $this->draft]))
        ->assertUnprocessable()->assertJsonValidationErrors('content');

    expect(TaskThread::withoutGlobalScope('published')->findOrFail($this->draft->id)->is_draft)->toBeTrue();
    Notification::assertNothingSent();
});

test('database queue insertion failure rolls back draft publication so a retry can recover', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', config('database.default'));
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $url = route('task-threads.publish', [$this->task, $this->draft]);

    $this->actingAs($this->author)->postJson($url)->assertServerError();

    $draft = TaskThread::withoutGlobalScope('published')->findOrFail($this->draft->id);
    expect($draft->is_draft)->toBeTrue()
        ->and($draft->published_at)->toBeNull()
        ->and($draft->notifications_queued_at)->toBeNull();

    Bus::swap($dispatcher);
    $this->actingAs($this->author)->postJson($url)->assertOk()->assertJsonPath('thread.is_draft', false);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
});

test('publication atomically persists a durable database notification job', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', config('database.default'));
    $queue = new QueueManager(app());
    $queue->addConnector('database', fn () => new \Illuminate\Queue\Connectors\DatabaseConnector(app('db')));
    Queue::swap($queue);
    $url = route('task-threads.publish', [$this->task, $this->draft]);

    $this->actingAs($this->author)->postJson($url)->assertOk();
    $this->actingAs($this->author)->postJson($url)->assertOk();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(json_decode(DB::table('jobs')->value('payload'), true)['displayName'])->toBe(SendTaskThreadNotifications::class)
        ->and(TaskThread::query()->findOrFail($this->draft->id)->notifications_queued_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('notification delivery failure is retried by the durable job after publication', function () {
    $this->actingAs($this->author)->postJson(route('task-threads.publish', [$this->task, $this->draft]))->assertOk();
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
    $job = Queue::pushed(SendTaskThreadNotifications::class)->first();
    $notifications = app(TaskThreadNotificationService::class);
    $failure = Mockery::mock(TaskThreadNotificationService::class);
    $failure->shouldReceive('send')->once()->andThrow(new RuntimeException('Dispatch failed'));

    expect(fn () => $job->handle($failure))->toThrow(RuntimeException::class, 'Dispatch failed');
    expect(TaskThread::query()->findOrFail($this->draft->id)->is_draft)->toBeFalse();

    $job->handle($notifications);
    Notification::assertSentToTimes($this->other, TaskThreadUpdated::class, 1);
});

test('owned All draft edits preserve their own permanent inline image without publishing', function (string $surface) {
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class, 'attachable_id' => $this->draft->id,
        'original_filename' => 'draft.png', 'path' => 'attachments/draft.png',
    ]);
    $content = '<p>Revised reply</p><p><img src="/attachments/'.$attachment->id.'/download"></p>';

    if ($surface === 'portal') {
        $this->actingAs($this->author)->putJson(route('task-threads.update', [$this->task, $this->draft]), [
            'content' => $content,
        ])->assertOk()->assertJsonPath('thread.is_draft', true)->assertJsonPath('thread.content', $content);
    } else {
        draftMcpAs($this->author)->tool(EditTaskThreadCommentTool::class, [
            'thread_id' => $this->draft->id, 'content' => $content,
        ])->assertOk()->assertSee('Revised reply');
    }

    $draft = TaskThread::withoutGlobalScope('published')->findOrFail($this->draft->id);
    expect($draft->is_draft)->toBeTrue()->and($draft->content)->toBe($content);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['portal', 'mcp']);

test('All draft edits cannot expose another unpublished drafts permanent inline image', function (string $surface) {
    $otherDraft = TaskThread::withoutGlobalScope('published')->create([
        'task_id' => $this->task->id, 'type' => 'external', 'content' => '<p>Another private draft</p>',
        'sender_name' => $this->author->name, 'sender_type' => User::class, 'sender_id' => $this->author->id,
        'is_draft' => true,
    ]);
    $attachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class, 'attachable_id' => $otherDraft->id,
        'original_filename' => 'other-draft.png', 'path' => 'attachments/other-draft.png',
    ]);
    $content = '<p><img src="/attachments/'.$attachment->id.'/download"></p>';

    if ($surface === 'portal') {
        $this->actingAs($this->author)->putJson(route('task-threads.update', [$this->task, $this->draft]), [
            'content' => $content,
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
    } else {
        draftMcpAs($this->author)->tool(EditTaskThreadCommentTool::class, [
            'thread_id' => $this->draft->id, 'content' => $content,
        ])->assertHasErrors(['Remove Team or unrelated attachments']);
    }

    $draft = TaskThread::withoutGlobalScope('published')->findOrFail($this->draft->id);
    expect($draft->is_draft)->toBeTrue()->and($draft->content)->toBe('<p>Private prepared reply</p>');
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['portal', 'mcp']);
