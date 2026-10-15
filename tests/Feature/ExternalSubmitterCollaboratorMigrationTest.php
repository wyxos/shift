<?php

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

test('migration backfills external submitters as collaborators without duplicating explicit rows', function () {
    $project = Project::factory()->create();
    $externalUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
    ]);

    $unassignedTask = Task::factory()->for($project)->create();
    $unassignedTask->submitter()->associate($externalUser)->save();

    $assignedTask = Task::factory()->for($project)->create();
    $assignedTask->submitter()->associate($externalUser)->save();
    $assignedTask->externalCollaborators()->attach($externalUser->id);

    $rename = require database_path('migrations/2026_10_15_120000_rename_external_accounts_to_project_participants.php');
    $rename->down();

    $migration = require database_path('migrations/2026_08_26_040000_backfill_external_task_submitter_collaborators.php');
    $migration->up();
    $migration->up();

    $this->assertDatabaseCount('task_collaborators', 2);

    foreach ([$unassignedTask, $assignedTask] as $task) {
        expect(DB::table('task_collaborators')->where('task_id', $task->id)->where('external_user_id', $externalUser->id)->count())->toBe(1);
    }
});
