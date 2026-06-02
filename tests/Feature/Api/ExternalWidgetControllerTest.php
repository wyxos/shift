<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;

    $this->project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'widget-project-token',
    ]);

    $this->project->environments()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
    ]);
});

test('config returns widget flags without exposing secrets', function () {
    $this->project->update([
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/widget/config?project='.$this->project->token);

    $response
        ->assertOk()
        ->assertJsonPath('project.id', $this->project->id)
        ->assertJsonPath('project.name', $this->project->name)
        ->assertJsonPath('widget.enabled', true)
        ->assertJsonPath('widget.guest_submissions_enabled', false)
        ->assertJsonPath('widget_enabled', true)
        ->assertJsonPath('guest_submissions_enabled', false)
        ->assertJsonMissingPath('project.token');
});

test('config returns not found for unknown projects', function () {
    $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/widget/config?project=unknown-project-token')
        ->assertNotFound();
});

test('task submission is rejected when widget is disabled', function () {
    $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/widget/tasks', [
            'project' => $this->project->token,
            'kind' => 'issue',
            'title' => 'Broken checkout',
            'description' => 'Checkout fails on card entry.',
            'anonymous' => true,
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'The embedded widget is disabled for this project.');

    expect(Task::query()->count())->toBe(0);
});

test('guest submissions are rejected when guest intake is disabled', function () {
    $this->project->update([
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);

    $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/widget/tasks', [
            'project' => $this->project->token,
            'kind' => 'feature',
            'title' => 'Add exports',
            'description' => 'CSV export would help.',
            'anonymous' => false,
            'user' => [
                'name' => 'Guest Reporter',
                'email' => 'guest@example.com',
            ],
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Guest widget submissions are disabled for this project.');
});

test('anonymous submissions create normal tasks without a submitter', function () {
    $this->project->update([
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => true,
    ]);

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/widget/tasks', [
            'project' => $this->project->token,
            'kind' => 'issue',
            'title' => 'Broken checkout',
            'description' => 'Checkout fails on card entry.',
            'anonymous' => true,
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/checkout',
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('title', 'Broken checkout')
        ->assertJsonPath('kind', 'issue')
        ->assertJsonPath('submitter', null);

    $this->assertDatabaseHas('tasks', [
        'project_id' => $this->project->id,
        'title' => 'Broken checkout',
        'submitter_id' => null,
        'submitter_type' => null,
    ]);

    $this->assertDatabaseHas('task_metadata', [
        'environment' => 'testing',
        'url' => 'https://example.com/checkout',
        'source' => 'embedded_widget',
        'intake_type' => 'issue',
    ]);
});

test('authenticated consuming app users can submit when guest submissions are disabled', function () {
    $this->project->update([
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/widget/tasks', [
            'project' => $this->project->token,
            'kind' => 'task',
            'title' => 'Review dashboard',
            'description' => 'The authenticated user found a layout problem.',
            'anonymous' => false,
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/dashboard',
            ],
            'user' => [
                'id' => 123,
                'name' => 'Session User',
                'email' => 'session@example.com',
                'environment' => 'testing',
                'url' => 'https://example.com',
                'authenticated' => true,
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('submitter.name', 'Session User');

    $externalUser = ExternalUser::query()->where('email', 'session@example.com')->first();
    expect($externalUser)->not->toBeNull();

    $task = Task::query()->firstOrFail();
    expect($task->submitter_id)->toBe($externalUser->id);
    expect($task->submitter_type)->toBe(ExternalUser::class);
});

test('manual guest submissions create or update external users', function () {
    $this->project->update([
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => true,
    ]);

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/widget/tasks', [
            'project' => $this->project->token,
            'kind' => 'feature',
            'title' => 'Add CSV exports',
            'description' => 'A manual guest reporter requested exports.',
            'anonymous' => false,
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/reports',
            ],
            'user' => [
                'name' => 'Manual Guest',
                'email' => 'manual@example.com',
                'authenticated' => false,
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('submitter.name', 'Manual Guest')
        ->assertJsonPath('submitter.email', 'manual@example.com');

    $externalUser = ExternalUser::query()->where('email', 'manual@example.com')->firstOrFail();
    expect($externalUser->external_id)->toStartWith('guest:');
    expect($externalUser->environment)->toBe('testing');
    expect($externalUser->url)->toBe('https://example.com');

    $task = Task::query()->firstOrFail();
    expect($task->submitter_id)->toBe($externalUser->id);
    expect($task->submitter_type)->toBe(ExternalUser::class);
});
