<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps the back action inside the title field on desktop and mobile', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Review export options']);
    $task->submitter()->associate($user)->save();
    $this->actingAs($user);

    $page = visit("/tasks?task={$task->id}")
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertVisible('[data-testid="task-title-group"] [data-testid="task-edit-back"]')
        ->assertNotPresent('[data-testid="sheet-close"]');

    $page->resize(390, 844)
        ->assertVisible('[data-testid="task-title-group"] [data-testid="task-edit-back"]')
        ->click('[data-testid="task-edit-back"]')
        ->assertNotPresent('[data-testid="task-edit-title"]')
        ->assertNoSmoke();
});

it('changes status through a compact menu', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Review export options', 'status' => 'pending', 'priority' => 'medium']);
    $task->submitter()->associate($user)->save();
    $this->actingAs($user);

    $page = visit("/tasks?task={$task->id}")
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertNotPresent('[data-testid="task-status-in-progress"]')
        ->click('[data-testid="task-status-trigger"]')
        ->assertVisible('[data-testid="task-status-in-progress"]')
        ->click('[data-testid="task-status-in-progress"]')
        ->waitForText('Task changes saved');

    expect($task->refresh()->status)->toBe('in-progress');

});

it('changes priority through a compact menu on mobile', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Review export options', 'status' => 'pending', 'priority' => 'medium']);
    $task->submitter()->associate($user)->save();
    $this->actingAs($user);

    visit("/tasks?task={$task->id}")->resize(390, 844)
        ->click('[data-testid="task-priority-trigger"]')
        ->assertVisible('[data-testid="task-priority-high"]')
        ->click('[data-testid="task-priority-high"]')
        ->waitForText('Task changes saved')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();

    expect($task->refresh()->priority)->toBe('high');
});
