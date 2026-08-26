<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;

test('migration backfills external submitters as collaborators without duplicating explicit rows', function () {
    $project = Project::factory()->create();
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
    ]);

    $unassignedTask = Task::factory()->for($project)->create();
    $unassignedTask->submitter()->associate($externalUser)->save();

    $assignedTask = Task::factory()->for($project)->create();
    $assignedTask->submitter()->associate($externalUser)->save();
    $assignedTask->externalCollaborators()->attach($externalUser->id);

    $migration = require database_path('migrations/2026_08_26_040000_backfill_external_task_submitter_collaborators.php');
    $migration->up();
    $migration->up();

    $this->assertDatabaseCount('task_collaborators', 2);

    foreach ([$unassignedTask, $assignedTask] as $task) {
        expect($task->externalCollaborators()->whereKey($externalUser->id)->count())->toBe(1);
    }
});
