<?php

use App\Jobs\SendTaskThreadNotifications;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    Storage::fake('local');
    $this->author = User::factory()->create();
    $this->other = User::factory()->create();
    $this->project = Project::factory()->withAuthor($this->author->id)->create(['token' => 'idempotency-project-token']);
    $this->task = Task::factory()->for($this->project)->create();
    $this->task->submitter()->associate($this->author)->save();
    $this->task->internalCollaborators()->attach($this->other);
    $this->key = (string) Str::uuid();
    $this->payload = ['content' => '<p>Send once</p>', 'type' => 'external', 'client_request_id' => $this->key];
    $this->external = ExternalUser::factory()->create([
        'project_id' => $this->project->id, 'external_id' => 'external-idempotency-author',
        'environment' => 'testing', 'url' => 'https://consumer.example.test',
    ]);
    $this->task->externalCollaborators()->attach($this->external);
    $this->context = [
        'project' => $this->project->token,
        'user' => [
            'id' => $this->external->external_id, 'environment' => $this->external->environment,
            'url' => $this->external->url, 'name' => $this->external->name, 'email' => $this->external->email,
        ],
        'metadata' => ['environment' => $this->external->environment, 'url' => $this->external->url],
    ];
});

test('portal retries with a stable client request ID return the original message and queue once', function () {
    $url = route('task-threads.store', $this->task);
    $first = $this->actingAs($this->author)->postJson($url, $this->payload)->assertCreated();
    $second = $this->actingAs($this->author)->postJson($url, [
        ...$this->payload, 'content' => '<p>Must not change the already accepted message</p>', 'type' => 'internal',
    ])->assertCreated();

    expect($second->json('thread.id'))->toBe($first->json('thread.id'))
        ->and($second->json('thread.content'))->toBe('<p>Send once</p>')
        ->and($second->json('thread.audience'))->toBe('all')
        ->and($second->json('thread.client_request_id'))->toBe($this->key)
        ->and(TaskThread::query()->count())->toBe(1);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
    $this->getJson(route('task-threads.index', $this->task))->assertJsonPath('threads.0.client_request_id', $this->key);
});

test('request IDs are scoped to the sender and task rather than globally', function () {
    $first = $this->actingAs($this->author)->postJson(route('task-threads.store', $this->task), $this->payload)->assertCreated();
    $otherSender = $this->actingAs($this->other)->postJson(route('task-threads.store', $this->task), $this->payload)->assertCreated();
    $otherTask = Task::factory()->for($this->project)->create();
    $otherTaskResponse = $this->actingAs($this->author)->postJson(route('task-threads.store', $otherTask), $this->payload)->assertCreated();

    expect($otherSender->json('thread.id'))->not->toBe($first->json('thread.id'))
        ->and($otherTaskResponse->json('thread.id'))->not->toBe($first->json('thread.id'))
        ->and(TaskThread::query()->count())->toBe(3);
});

test('portal retries preserve moved inline attachments and do not reprocess consumed temporary files', function () {
    $identifier = 'thread-retry-'.Str::uuid();
    $upload = $this->actingAs($this->author)->post(route('attachments.upload'), [
        'file' => UploadedFile::fake()->image('proof.png'), 'temp_identifier' => $identifier,
    ])->assertOk();
    $payload = [...$this->payload, 'content' => '<p><img src="'.$upload->json('url').'"></p>', 'temp_identifier' => $identifier];
    $url = route('task-threads.store', $this->task);
    $first = $this->postJson($url, $payload)->assertCreated();
    $second = $this->postJson($url, $payload)->assertCreated();

    expect($second->json('thread.id'))->toBe($first->json('thread.id'))
        ->and($second->json('thread.content'))->toBe($first->json('thread.content'))
        ->and(Attachment::query()->count())->toBe(1)
        ->and($second->json('thread.content'))->not->toContain('/attachments/temp/');
    $attachment = Attachment::query()->firstOrFail();
    Storage::assertExists($attachment->path);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
});

test('retry recovers from queue dispatch failure after message persistence without duplicating the message', function () {
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $url = route('task-threads.store', $this->task);
    $this->actingAs($this->author)->postJson($url, $this->payload)->assertServerError();
    $saved = TaskThread::query()->firstOrFail();
    expect($saved->client_request_id)->toBe($this->key)->and($saved->notifications_queued_at)->toBeNull();

    Bus::swap($dispatcher);
    $this->postJson($url, $this->payload)->assertCreated()->assertJsonPath('thread.id', $saved->id);
    expect(TaskThread::query()->count())->toBe(1);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
});

test('a request key never bypasses revoked task access', function () {
    $this->actingAs($this->other)->postJson(route('task-threads.store', $this->task), $this->payload)->assertCreated();
    $this->task->internalCollaborators()->detach($this->other);
    $this->postJson(route('task-threads.store', $this->task), $this->payload)->assertNotFound();
    expect(TaskThread::query()->count())->toBe(1);
});

test('legacy requests without client IDs remain separate messages and malformed IDs are rejected', function () {
    $url = route('task-threads.store', $this->task);
    $this->actingAs($this->author)->postJson($url, ['content' => '<p>Legacy</p>', 'type' => 'internal'])->assertCreated();
    $this->postJson($url, ['content' => '<p>Legacy</p>', 'type' => 'internal'])->assertCreated();
    $this->postJson($url, [...$this->payload, 'client_request_id' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
    expect(TaskThread::query()->count())->toBe(2);
});

test('external API retries return the original message and queue its notifications once', function () {
    Sanctum::actingAs($this->author);
    $url = route('api.task-threads.store', $this->task);
    $payload = [...$this->payload, ...$this->context];
    $first = $this->postJson($url, $payload)->assertCreated();
    $second = $this->postJson($url, $payload)->assertCreated();

    expect($second->json('thread.id'))->toBe($first->json('thread.id'))
        ->and($second->json('thread.client_request_id'))->toBe($this->key)
        ->and(TaskThread::query()->count())->toBe(1);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
    $this->getJson(route('api.task-threads.index', ['task' => $this->task, ...$this->context]))
        ->assertOk()->assertJsonPath('external.0.client_request_id', $this->key);
});

test('external retries recover from a post-save queue failure without duplicating a thread', function () {
    Sanctum::actingAs($this->author);
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $url = route('api.task-threads.store', $this->task);
    $payload = [...$this->payload, ...$this->context];
    $this->postJson($url, $payload)->assertServerError();
    $saved = TaskThread::query()->firstOrFail();
    Bus::swap($dispatcher);

    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('thread.id', $saved->id);
    expect(TaskThread::query()->count())->toBe(1);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
});

test('portal retry after queue failure retains its persisted inline image', function () {
    $identifier = 'thread-recovery-'.Str::uuid();
    $upload = $this->actingAs($this->author)->post(route('attachments.upload'), [
        'file' => UploadedFile::fake()->image('recovery.png'), 'temp_identifier' => $identifier,
    ])->assertOk();
    $payload = [...$this->payload, 'content' => '<p><img src="'.$upload->json('url').'"></p>', 'temp_identifier' => $identifier];
    $url = route('task-threads.store', $this->task);
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $this->postJson($url, $payload)->assertServerError();
    $saved = TaskThread::query()->firstOrFail();
    $attachment = $saved->attachments()->firstOrFail();
    Storage::assertExists($attachment->path);
    Bus::swap($dispatcher);

    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('thread.id', $saved->id)
        ->assertJsonPath('thread.content', $saved->content);
    expect(Attachment::query()->count())->toBe(1);
    Queue::assertPushed(SendTaskThreadNotifications::class, 1);
});

test('external retries retain moved inline images and their SDK proxy URLs', function () {
    $identifier = 'external-thread-retry-'.Str::uuid();
    $upload = $this->actingAs($this->author)->post(route('attachments.upload'), [
        'file' => UploadedFile::fake()->image('external-proof.png'), 'temp_identifier' => $identifier,
    ])->assertOk();
    Sanctum::actingAs($this->author);
    $payload = [...$this->payload, ...$this->context, 'content' => '<p><img src="'.$upload->json('url').'"></p>', 'temp_identifier' => $identifier];
    $url = route('api.task-threads.store', $this->task);
    $first = $this->postJson($url, $payload)->assertCreated();
    $second = $this->postJson($url, $payload)->assertCreated();
    $attachment = Attachment::query()->firstOrFail();

    expect($second->json('thread.id'))->toBe($first->json('thread.id'))
        ->and($second->json('thread.content'))->toBe($first->json('thread.content'))
        ->and($second->json('thread.content'))->toContain('/shift/api/attachments/'.$attachment->id.'/download')
        ->and(Attachment::query()->count())->toBe(1);
    Storage::assertExists($attachment->path);
});
