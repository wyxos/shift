<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('persists the description entered when creating a task', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $user->id,
    ]);

    $title = 'Browser Task '.uniqid();
    $description = "Client reported this bug.\nDetails should persist after creation.";

    $this->actingAs($user);

    visit('/tasks/create')
        ->assertPathIs('/tasks/create')
        ->assertSee('Write your task description here...')
        ->fill('title', $title)
        ->select('project_id', (string) $project->id)
        ->fill('#description .toastui-editor-md-container .ProseMirror', $description)
        ->press('Create Task')
        ->waitForEvent('networkidle')
        ->assertPathIs('/tasks');

    $task = Task::query()->where('title', $title)->first();

    expect($task)->not->toBeNull();
    expect($task?->description)->toBe($description);
    expect($task?->project_id)->toBe($project->id);
});
