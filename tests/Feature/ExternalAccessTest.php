<?php

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

test('external user can be associated with task', function () {
    // Create a user, project, external user, and task
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id]);

    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'submitter_id' => $user->id,
        'submitter_type' => User::class,
    ]);

    // Associate the external user with the task
    $externalIdentity->accessibleTasks()->attach($task->id);

    // Verify the relationship from the external user side
    expect($externalIdentity->accessibleTasks)->toHaveCount(1);
    expect($externalIdentity->accessibleTasks->first()->id)->toEqual($task->id);

    // Verify the relationship from the task side
    expect($task->externalIdentities)->toHaveCount(1);
    expect($task->externalIdentities->first()->id)->toEqual($externalIdentity->id);
});

test('multiple external users can be associated with task', function () {
    // Create a user, project, and task
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'submitter_id' => $user->id,
        'submitter_type' => User::class,
    ]);

    // Create multiple external users
    $externalIdentities = ExternalIdentity::factory()->count(3)->create([
        'project_id' => $project->id,
    ]);

    // Associate all external users with the task
    foreach ($externalIdentities as $externalIdentity) {
        $externalIdentity->accessibleTasks()->attach($task->id);
    }

    // Verify the relationship from the task side
    expect($task->externalIdentities)->toHaveCount(3);

    // Verify each external user is associated with the task
    foreach ($externalIdentities as $externalIdentity) {
        expect($externalIdentity->accessibleTasks)->toHaveCount(1);
        expect($externalIdentity->accessibleTasks->first()->id)->toEqual($task->id);
    }
});

test('task can be associated with multiple external users', function () {
    // Create a user, project, and external user
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id]);

    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
    ]);

    // Create multiple tasks
    $tasks = Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'submitter_id' => $user->id,
        'submitter_type' => User::class,
    ]);

    // Associate all tasks with the external user
    foreach ($tasks as $task) {
        $task->externalIdentities()->attach($externalIdentity->id);
    }

    // Verify the relationship from the external user side
    expect($externalIdentity->accessibleTasks)->toHaveCount(3);

    // Verify each task is associated with the external user
    foreach ($tasks as $task) {
        expect($task->externalIdentities)->toHaveCount(1);
        expect($task->externalIdentities->first()->id)->toEqual($externalIdentity->id);
    }
});
