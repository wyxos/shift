<?php

use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->externalUser = ExternalUser::factory()->create([
        'external_id' => 'ext-123',
        'environment' => 'testing',
        'url' => 'https://example.com',
        'name' => 'External Test User',
        'email' => 'external@example.com',
    ]);

    $this->task = Task::factory()->create();
    $this->projectToken = $this->task->project->generateApiToken();
});

test('external thread creator can update their message via api', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>Before</p>',
        'sender_name' => $this->externalUser->name,
    ]);
    $thread->sender()->associate($this->externalUser);
    $thread->save();

    $response = $this->putJson(route('api.task-threads.update', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'content' => '<p>After</p>',
        'project' => $this->projectToken,
        'user' => [
            'id' => $this->externalUser->external_id,
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
            'name' => $this->externalUser->name,
            'email' => $this->externalUser->email,
        ],
        'metadata' => [
            'url' => $this->externalUser->url,
            'environment' => $this->externalUser->environment,
        ],
    ]);

    $response->assertOk();
    expect($response->json('thread.id'))->toBe($thread->id);
    expect($response->json('thread.content'))->toBe('<p>After</p>');
});

test('external thread update is forbidden for non owner', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>Before</p>',
        'sender_name' => $this->externalUser->name,
    ]);
    $thread->sender()->associate($this->externalUser);
    $thread->save();

    $response = $this->putJson(route('api.task-threads.update', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'content' => '<p>Hack</p>',
        'project' => $this->projectToken,
        'user' => [
            'id' => 'different-user',
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
            'name' => 'Different',
            'email' => 'different@example.com',
        ],
        'metadata' => [
            'url' => $this->externalUser->url,
            'environment' => $this->externalUser->environment,
        ],
    ]);

    $response->assertStatus(403);
});

test('external thread creator can delete their message via api', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>To delete</p>',
        'sender_name' => $this->externalUser->name,
    ]);
    $thread->sender()->associate($this->externalUser);
    $thread->save();

    $response = $this->deleteJson(route('api.task-threads.destroy', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'project' => $this->projectToken,
        'user' => [
            'id' => $this->externalUser->external_id,
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
        ],
    ]);

    $response->assertOk();
    expect(TaskThread::query()->whereKey($thread->id)->exists())->toBeFalse();
});

test('external thread delete is forbidden for non owner', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>To delete</p>',
        'sender_name' => $this->externalUser->name,
    ]);
    $thread->sender()->associate($this->externalUser);
    $thread->save();

    $response = $this->deleteJson(route('api.task-threads.destroy', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'project' => $this->projectToken,
        'user' => [
            'id' => 'different-user',
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
        ],
    ]);

    $response->assertStatus(403);
    expect(TaskThread::query()->whereKey($thread->id)->exists())->toBeTrue();
});
