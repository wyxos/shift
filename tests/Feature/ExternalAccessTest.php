<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;

;

test('external user can be associated with task', function () {
    // Create a user, project, external user, and task
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id]);

    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'submitter_id' => $user->id,
        'submitter_type' => User::class
    ]);

    // Associate the external user with the task
    $externalUser->accessibleTasks()->attach($task->id);

    // Verify the relationship from the external user side
    expect($externalUser->accessibleTasks)->toHaveCount(1);
    expect($externalUser->accessibleTasks->first()->id)->toEqual($task->id);

    // Verify the relationship from the task side
    expect($task->externalUsers)->toHaveCount(1);
    expect($task->externalUsers->first()->id)->toEqual($externalUser->id);
});

test('multiple external users can be associated with task', function () {
    // Create a user, project, and task
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'submitter_id' => $user->id,
        'submitter_type' => User::class
    ]);

    // Create multiple external users
    $externalUsers = ExternalUser::factory()->count(3)->create([
        'project_id' => $project->id
    ]);

    // Associate all external users with the task
    foreach ($externalUsers as $externalUser) {
        $externalUser->accessibleTasks()->attach($task->id);
    }

    // Verify the relationship from the task side
    expect($task->externalUsers)->toHaveCount(3);

    // Verify each external user is associated with the task
    foreach ($externalUsers as $externalUser) {
        expect($externalUser->accessibleTasks)->toHaveCount(1);
        expect($externalUser->accessibleTasks->first()->id)->toEqual($task->id);
    }
});

test('task can be associated with multiple external users', function () {
    // Create a user, project, and external user
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id]);

    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id
    ]);

    // Create multiple tasks
    $tasks = Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'submitter_id' => $user->id,
        'submitter_type' => User::class
    ]);

    // Associate all tasks with the external user
    foreach ($tasks as $task) {
        $task->externalUsers()->attach($externalUser->id);
    }

    // Verify the relationship from the external user side
    expect($externalUser->accessibleTasks)->toHaveCount(3);

    // Verify each task is associated with the external user
    foreach ($tasks as $task) {
        expect($task->externalUsers)->toHaveCount(1);
        expect($task->externalUsers->first()->id)->toEqual($externalUser->id);
    }
});
