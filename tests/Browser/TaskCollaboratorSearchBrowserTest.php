<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('searches collaborators without moving the task details and adds a matching team member', function () {
    config()->set('collaborator_search.enabled', false);
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();
    $members = collect(range(1, 12))->map(function (int $number) use ($project): User {
        $user = User::factory()->create(['name' => 'Alexandra '.$number]);
        ProjectUser::factory()->create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'user_name' => $user->name, 'user_email' => $user->email, 'registration_status' => 'registered',
        ]);

        return $user;
    });
    $task = Task::factory()->for($project)->create(['title' => 'Review collaborator access', 'description' => 'Check collaborator search.']);
    $task->submitter()->associate($owner)->save();
    $this->actingAs($owner);

    $page = visit('/tasks?task='.$task->id)->resize(1440, 900)
        ->assertNoSmoke()
        ->assertVisible('[data-testid="task-collaborators-search"]')
        ->assertNotPresent('[data-testid="task-collaborators-dropdown"]')
        ->assertScript("(window.collaboratorFieldHeight = document.querySelector('[data-testid=\"task-collaborators\"]').getBoundingClientRect().height) > 0");
    $page->fill('[data-testid="task-collaborators-search"]', 'Alex')
        ->waitForText('Alexandra 1')
        ->assertSee('SHIFT team')
        ->assertScript("document.querySelectorAll('[data-testid*=\"-collaborator-option-\"]').length === 10")
        ->assertScript("document.querySelector('[data-testid=\"task-collaborators\"]').getBoundingClientRect().height === window.collaboratorFieldHeight")
        ->click('[data-testid="internal-collaborator-option-'.$members->first()->id.'"]')
        ->waitForText('Task changes saved')
        ->assertPresent('[aria-label="Remove Alexandra 1"]')
        ->assertNotPresent('[data-testid="task-collaborators-dropdown"]')
        ->assertNoSmoke();
    $this->assertDatabaseHas('task_collaborators', ['task_id' => $task->id, 'user_id' => $members->first()->id]);

    $page->resize(390, 844)
        ->fill('[data-testid="task-collaborators-search"]', 'Alex')
        ->waitForText('Alexandra 10')
        ->assertScript("document.querySelector('[data-testid=\"task-collaborators-dropdown\"]').getBoundingClientRect().right <= window.innerWidth")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
});
