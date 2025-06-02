<?php

namespace Tests\Feature;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ExternalAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that an external user can be associated with a task through the pivot table.
     */
    public function test_external_user_can_be_associated_with_task(): void
    {
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
        $this->assertCount(1, $externalUser->accessibleTasks);
        $this->assertEquals($task->id, $externalUser->accessibleTasks->first()->id);

        // Verify the relationship from the task side
        $this->assertCount(1, $task->externalUsers);
        $this->assertEquals($externalUser->id, $task->externalUsers->first()->id);
    }

    /**
     * Test that multiple external users can be associated with a task.
     */
    public function test_multiple_external_users_can_be_associated_with_task(): void
    {
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
        $this->assertCount(3, $task->externalUsers);

        // Verify each external user is associated with the task
        foreach ($externalUsers as $externalUser) {
            $this->assertCount(1, $externalUser->accessibleTasks);
            $this->assertEquals($task->id, $externalUser->accessibleTasks->first()->id);
        }
    }

    /**
     * Test that a task can be associated with multiple external users.
     */
    public function test_task_can_be_associated_with_multiple_external_users(): void
    {
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
        $this->assertCount(3, $externalUser->accessibleTasks);

        // Verify each task is associated with the external user
        foreach ($tasks as $task) {
            $this->assertCount(1, $task->externalUsers);
            $this->assertEquals($externalUser->id, $task->externalUsers->first()->id);
        }
    }
}
