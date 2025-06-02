<?php

namespace Tests\Feature;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalUserProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_user_gets_project_id_when_task_is_created()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user without a project
        $externalUser = ExternalUser::factory()->create([
            'project_id' => null,
            'external_id' => 123,
            'environment' => 'testing',
            'url' => 'https://example.com',
        ]);

        // Create a task with the external user as submitter
        $task = Task::factory()->create([
            'project_id' => $project->id,
        ]);

        // Associate the external user with the task
        $task->submitter()->associate($externalUser)->save();

        // Simulate the API call that would update the external user's project_id
        $this->post(route('api.external-tasks.store'), [
            'title' => 'Test Task',
            'description' => 'Test Description',
            'project' => $project->token,
            'user' => [
                'id' => $externalUser->external_id,
                'name' => $externalUser->name,
                'email' => $externalUser->email,
                'environment' => $externalUser->environment,
                'url' => $externalUser->url,
            ],
            'metadata' => [
                'url' => 'https://example.com/test',
                'environment' => 'testing',
            ],
        ]);

        // Refresh the external user from the database
        $externalUser->refresh();

        // Assert that the external user now has the project_id set
        $this->assertEquals($project->id, $externalUser->project_id);
    }

    public function test_external_user_can_be_assigned_to_project_through_ui()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user without a project
        $externalUser = ExternalUser::factory()->create([
            'project_id' => null,
        ]);

        // Simulate updating the external user through the UI
        $response = $this->put(route('external-users.update', $externalUser->id), [
            'name' => $externalUser->name,
            'email' => $externalUser->email,
            'project_id' => $project->id,
        ]);

        // Refresh the external user from the database
        $externalUser->refresh();

        // Assert that the external user now has the project_id set
        $this->assertEquals($project->id, $externalUser->project_id);
    }
}
