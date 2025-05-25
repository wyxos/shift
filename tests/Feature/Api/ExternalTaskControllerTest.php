<?php

namespace Tests\Feature\Api;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskMetadata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;
    protected ExternalUser $externalUser;
    protected array $externalUserData;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and generate a token for API access
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Create a project with a token for API access
        $this->project = Project::factory()->create([
            'token' => 'test-project-token',
            'author_id' => $this->user->id
        ]);

        // External user data
        $this->externalUserData = [
            'id' => 'ext-123',
            'name' => 'External User',
            'email' => 'external@example.com',
            'environment' => 'testing',
            'url' => 'https://example.com'
        ];

        // Create an external user
        $this->externalUser = ExternalUser::create([
            'external_id' => $this->externalUserData['id'],
            'name' => $this->externalUserData['name'],
            'email' => $this->externalUserData['email'],
            'environment' => $this->externalUserData['environment'],
            'url' => $this->externalUserData['url']
        ]);
    }

    public function test_index_returns_tasks_for_external_user()
    {
        // Create tasks submitted by the external user
        $task1 = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $task1->submitter()->associate($this->externalUser)->save();

        $task2 = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $task2->submitter()->associate($this->externalUser)->save();

        // Create a task by another user (should not be returned)
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com'
        ]);

        $task3 = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $task3->submitter()->associate($otherExternalUser)->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/tasks?' . http_build_query([
                'project' => $this->project->token,
                'user' => $this->externalUserData
            ]));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_show_returns_task_details()
    {
        // Create a task submitted by the external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'Test Task',
        ]);
        $task->submitter()->associate($this->externalUser)->save();

        $task->metadata()->create([
            'url' => 'https://example.com/task/123',
            'environment' => 'testing'
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/tasks/{$task->id}?" . http_build_query([
                'project' => $this->project->token,
                'user' => $this->externalUserData
            ]));

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $task->id,
            'title' => 'Test Task',
            'project_id' => $this->project->id,
        ]);
    }

    public function test_show_returns_404_for_task_in_different_project()
    {
        // Create another project
        $otherProject = Project::factory()->create([
            'token' => 'other-project-token'
        ]);

        // Create a task in the other project
        $task = Task::factory()->create([
            'project_id' => $otherProject->id,
        ]);
        $task->submitter()->associate($this->externalUser)->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/tasks/{$task->id}?" . http_build_query([
                'project' => $this->project->token, // Using the original project token
                'user' => $this->externalUserData
            ]));

        $response->assertStatus(404);
    }

    public function test_store_creates_new_task()
    {
        $taskData = [
            'title' => 'New External Task',
            'description' => 'Task created via API',
            'project' => $this->project->token,
            'priority' => 'high',
            'status' => 'pending',
            'user' => $this->externalUserData,
            'metadata' => [
                'url' => 'https://example.com/task/new',
                'environment' => 'testing'
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/tasks', $taskData);

        $response->assertStatus(201);
        $response->assertJson([
            'title' => 'New External Task',
            'description' => 'Task created via API',
            'priority' => 'high',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('tasks', [
            'title' => 'New External Task',
            'description' => 'Task created via API',
            'project_id' => $this->project->id,
            'priority' => 'high',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('task_metadata', [
            'url' => 'https://example.com/task/new',
            'environment' => 'testing',
        ]);
    }

    public function test_update_updates_task()
    {
        // Create a task submitted by the external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'Original Title',
            'status' => 'pending',
            'priority' => 'low',
        ]);
        $task->submitter()->associate($this->externalUser)->save();

        $updateData = [
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'status' => 'in_progress',
            'priority' => 'high',
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id,
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/tasks/{$task->id}", $updateData);

        $response->assertStatus(200);
        $response->assertJson([
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'status' => 'in_progress',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'status' => 'in_progress',
            'priority' => 'high',
        ]);
    }

    public function test_update_returns_403_for_unauthorized_user()
    {
        // Create a task submitted by a different external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com'
        ]);

        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        $updateData = [
            'title' => 'Updated Title',
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id, // Different user than the submitter
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->putJson("/api/tasks/{$task->id}", $updateData);

        $response->assertStatus(403);
    }

    public function test_destroy_deletes_task()
    {
        // Create a task submitted by the external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $task->submitter()->associate($this->externalUser)->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/tasks/{$task->id}?" . http_build_query([
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id,
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ]
        ]));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Task deleted successfully'
        ]);

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id
        ]);
    }

    public function test_destroy_returns_403_for_unauthorized_user()
    {
        // Create a task submitted by a different external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com'
        ]);

        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/tasks/{$task->id}?" . http_build_query([
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id, // Different user than the submitter
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ]
        ]));

        $response->assertStatus(403);
    }

    public function test_toggle_status_updates_task_status()
    {
        // Create a task submitted by the external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'status' => 'pending',
        ]);
        $task->submitter()->associate($this->externalUser)->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/tasks/{$task->id}/toggle-status", [
            'status' => 'completed',
            'project' => $this->project->token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'message' => 'Task status updated successfully'
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed'
        ]);
    }

    public function test_toggle_priority_updates_task_priority()
    {
        // Create a task submitted by the external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'priority' => 'low',
        ]);
        $task->submitter()->associate($this->externalUser)->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/tasks/{$task->id}/toggle-priority", [
            'priority' => 'high',
            'project' => $this->project->token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'priority' => 'high',
            'message' => 'Task priority updated successfully'
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'priority' => 'high'
        ]);
    }
}
