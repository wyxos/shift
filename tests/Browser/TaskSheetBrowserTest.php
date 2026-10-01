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
