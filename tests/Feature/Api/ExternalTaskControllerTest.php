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
            'status' => 'in-progress',
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
            'status' => 'in-progress',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'status' => 'in-progress',
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

        // Create attachments for the task
        $attachment1 = \App\Models\Attachment::create([
            'attachable_id' => $task->id,
            'attachable_type' => Task::class,
            'original_filename' => 'test-document1.pdf',
            'path' => "attachments/{$task->id}/test-document1.pdf",
        ]);

        $attachment2 = \App\Models\Attachment::create([
            'attachable_id' => $task->id,
            'attachable_type' => Task::class,
            'original_filename' => 'test-document2.pdf',
            'path' => "attachments/{$task->id}/test-document2.pdf",
        ]);

        // Create fake files in storage
        \Illuminate\Support\Facades\Storage::put($attachment1->path, 'test content 1');
        \Illuminate\Support\Facades\Storage::put($attachment2->path, 'test content 2');

        // Verify files exist before deletion
        \Illuminate\Support\Facades\Storage::assertExists($attachment1->path);
        \Illuminate\Support\Facades\Storage::assertExists($attachment2->path);

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

        // Verify task is deleted
        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id
        ]);

        // Verify attachments are deleted from database
        $this->assertDatabaseMissing('attachments', [
            'id' => $attachment1->id
        ]);
        $this->assertDatabaseMissing('attachments', [
            'id' => $attachment2->id
        ]);

        // Verify files are deleted from storage
        \Illuminate\Support\Facades\Storage::assertMissing($attachment1->path);
        \Illuminate\Support\Facades\Storage::assertMissing($attachment2->path);
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

    // These tests have been removed as they are obsolete.
    // They have been replaced by test_toggle_status_succeeds_with_granted_access
    // and test_toggle_priority_succeeds_with_granted_access which include the required 'user' parameter.

    public function test_external_task_creation_sends_notifications_to_all_relevant_users()
    {
        \Illuminate\Support\Facades\Notification::fake();

        // Create additional users with access to the project
        $projectUser1 = User::factory()->create();
        $projectUser2 = User::factory()->create();

        // Give these users access to the project
        \App\Models\ProjectUser::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $projectUser1->id,
            'user_email' => $projectUser1->email,
            'user_name' => $projectUser1->name,
            'registration_status' => 'registered'
        ]);

        \App\Models\ProjectUser::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $projectUser2->id,
            'user_email' => $projectUser2->email,
            'user_name' => $projectUser2->name,
            'registration_status' => 'registered'
        ]);

        $taskData = [
            'title' => 'External Notification Test Task',
            'description' => 'This task should trigger notifications to all users',
            'project' => $this->project->token,
            'priority' => 'high',
            'status' => 'pending',
            'user' => $this->externalUserData,
            'metadata' => [
                'url' => 'https://example.com/task/notification-test',
                'environment' => 'testing'
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/tasks', $taskData);

        $response->assertStatus(201);

        // Assert that notifications were sent to the project owner and all users with access
        \Illuminate\Support\Facades\Notification::assertSentTo(
            [$this->user, $projectUser1, $projectUser2],
            \App\Notifications\TaskCreationNotification::class
        );
    }
    public function test_index_returns_tasks_with_granted_access()
    {
        // Create another external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com',
            'project_id' => $this->project->id
        ]);

        // Create a task submitted by the other external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'Task by other user',
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        // Grant access to our external user
        $task->externalUsers()->attach($this->externalUser->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/tasks?' . http_build_query([
                'project' => $this->project->token,
                'user' => $this->externalUserData
            ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.title', 'Task by other user');
    }

    public function test_show_returns_task_with_granted_access()
    {
        // Create another external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com',
            'project_id' => $this->project->id
        ]);

        // Create a task submitted by the other external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'Task by other user',
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        // Grant access to our external user
        $task->externalUsers()->attach($this->externalUser->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/tasks/{$task->id}?" . http_build_query([
                'project' => $this->project->token,
                'user' => $this->externalUserData
            ]));

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $task->id,
            'title' => 'Task by other user',
        ]);
    }

    public function test_update_succeeds_with_granted_access()
    {
        // Create another external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com',
            'project_id' => $this->project->id
        ]);

        // Create a task submitted by the other external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'Original Title',
            'status' => 'pending',
            'priority' => 'low',
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        // Grant access to our external user
        $task->externalUsers()->attach($this->externalUser->id);

        $updateData = [
            'title' => 'Updated By Access',
            'description' => 'Updated by user with granted access',
            'status' => 'in-progress',
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
            'title' => 'Updated By Access',
            'description' => 'Updated by user with granted access',
        ]);
    }

    public function test_toggle_status_succeeds_with_granted_access()
    {
        // Create another external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com',
            'project_id' => $this->project->id
        ]);

        // Create a task submitted by the other external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'status' => 'pending',
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        // Grant access to our external user
        $task->externalUsers()->attach($this->externalUser->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/tasks/{$task->id}/toggle-status", [
                'status' => 'completed',
                'project' => $this->project->token,
                'user' => [
                    'id' => $this->externalUser->external_id,
                    'environment' => $this->externalUser->environment,
                    'url' => $this->externalUser->url,
                ]
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'message' => 'Task status updated successfully'
        ]);
    }

    public function test_toggle_priority_succeeds_with_granted_access()
    {
        // Create another external user
        $otherExternalUser = ExternalUser::create([
            'external_id' => 'other-123',
            'name' => 'Other User',
            'email' => 'other@example.com',
            'environment' => 'testing',
            'url' => 'https://other.com',
            'project_id' => $this->project->id
        ]);

        // Create a task submitted by the other external user
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'priority' => 'low',
        ]);
        $task->submitter()->associate($otherExternalUser)->save();

        // Grant access to our external user
        $task->externalUsers()->attach($this->externalUser->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/tasks/{$task->id}/toggle-priority", [
                'priority' => 'high',
                'project' => $this->project->token,
                'user' => [
                    'id' => $this->externalUser->external_id,
                    'environment' => $this->externalUser->environment,
                    'url' => $this->externalUser->url,
                ]
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'priority' => 'high',
            'message' => 'Task priority updated successfully'
        ]);
    }
}
