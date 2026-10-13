<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a task and sends a comment through the browser', function () {
    Queue::fake();
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $this->actingAs($user);

    $page = visit('/tasks')->resize(1440, 1000)
        ->click('[data-testid="open-create-task"]')
        ->fill('[data-testid="create-task-title"]', 'Dependency browser task proof')
        ->fill('[data-testid="create-description-editor"] .ProseMirror', 'Task after dependency update.')
        ->click('[data-testid="submit-create-task"]')
        ->waitForText('Task created')
        ->assertNoSmoke();

    $task = Task::query()->where('project_id', $project->id)->where('title', 'Dependency browser task proof')->sole();

    $page->navigate('/tasks?task='.$task->id)
        ->assertSee('Task after dependency update.')
        ->fill('[data-testid="comments-editor"] .ProseMirror', 'Comment after dependency update')
        ->click('[data-testid="toolbar-send"]')
        ->waitForText('Comment after dependency update')
        ->assertScript('document.querySelector("[data-testid=comments-editor] .ProseMirror").textContent.trim() === ""')
        ->assertNoSmoke();

    expect($task->threads()->where('content', 'like', '%Comment after dependency update%')->count())->toBe(1);
});
