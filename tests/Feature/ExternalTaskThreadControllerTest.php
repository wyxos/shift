<?php

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->project = Project::factory()->withAuthor($this->user->id)->create();

    $this->externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'ext-123',
        'environment' => 'testing',
        'url' => 'https://example.com',
        'name' => 'External Test User',
        'email' => 'external@example.com',
    ]);

    $this->task = Task::factory()->for($this->project)->create();
    $this->task->submitter()->associate($this->externalIdentity)->save();
    $this->projectToken = $this->task->project->generateApiToken();
});

test('external thread creator can update their message via api', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>Before</p>',
        'sender_name' => $this->externalIdentity->name,
    ]);
    $thread->sender()->associate($this->externalIdentity);
    $thread->save();

    $response = $this->putJson(route('api.task-threads.update', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'content' => '<p>After</p>',
        'project' => $this->projectToken,
        'user' => [
            'id' => $this->externalIdentity->external_id,
            'environment' => $this->externalIdentity->environment,
            'url' => $this->externalIdentity->url,
            'name' => $this->externalIdentity->name,
            'email' => $this->externalIdentity->email,
        ],
        'metadata' => [
            'url' => $this->externalIdentity->url,
            'environment' => $this->externalIdentity->environment,
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
        'sender_name' => $this->externalIdentity->name,
    ]);
    $thread->sender()->associate($this->externalIdentity);
    $thread->save();

    $otherExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'different-user',
        'environment' => $this->externalIdentity->environment,
        'url' => $this->externalIdentity->url,
        'name' => 'Different',
        'email' => 'different@example.com',
    ]);

    $this->task->externalIdentities()->attach($otherExternalIdentity);

    $response = $this->putJson(route('api.task-threads.update', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'content' => '<p>Hack</p>',
        'project' => $this->projectToken,
        'user' => [
            'id' => $otherExternalIdentity->external_id,
            'environment' => $otherExternalIdentity->environment,
            'url' => $otherExternalIdentity->url,
            'name' => $otherExternalIdentity->name,
            'email' => $otherExternalIdentity->email,
        ],
        'metadata' => [
            'url' => $this->externalIdentity->url,
            'environment' => $this->externalIdentity->environment,
        ],
    ]);

    $response->assertStatus(403);
});

test('external thread creator can delete their message via api', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => '<p>To delete</p>',
        'sender_name' => $this->externalIdentity->name,
    ]);
    $thread->sender()->associate($this->externalIdentity);
    $thread->save();

    $response = $this->deleteJson(route('api.task-threads.destroy', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'project' => $this->projectToken,
        'user' => [
            'id' => $this->externalIdentity->external_id,
            'environment' => $this->externalIdentity->environment,
            'url' => $this->externalIdentity->url,
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
        'sender_name' => $this->externalIdentity->name,
    ]);
    $thread->sender()->associate($this->externalIdentity);
    $thread->save();

    $otherExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'different-user',
        'environment' => $this->externalIdentity->environment,
        'url' => $this->externalIdentity->url,
        'name' => 'Different',
        'email' => 'different@example.com',
    ]);

    $this->task->externalIdentities()->attach($otherExternalIdentity);

    $response = $this->deleteJson(route('api.task-threads.destroy', ['task' => $this->task->id, 'threadId' => $thread->id]), [
        'project' => $this->projectToken,
        'user' => [
            'id' => $otherExternalIdentity->external_id,
            'environment' => $otherExternalIdentity->environment,
            'url' => $otherExternalIdentity->url,
        ],
    ]);

    $response->assertStatus(403);
    expect(TaskThread::query()->whereKey($thread->id)->exists())->toBeTrue();
});
