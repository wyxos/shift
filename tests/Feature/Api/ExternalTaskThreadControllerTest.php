<?php

namespace Tests\Feature\Api;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ExternalTaskThreadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $token;
    protected $project;
    protected $user;
    protected $externalUser;
    protected $externalUserData;
    protected $task;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a project with an API token
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);
        $this->project->generateApiToken();
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Create an external user
        $this->externalUser = ExternalUser::factory()->create([
            'external_id' => 'ext-123',
            'environment' => 'testing',
            'url' => 'https://example.com',
            'name' => 'External Test User',
            'email' => 'external@example.com',
        ]);

        $this->externalUserData = [
            'id' => $this->externalUser->external_id,
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
            'name' => $this->externalUser->name,
            'email' => $this->externalUser->email,
        ];

        // Create a task
        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => 'Test Task',
        ]);
        $this->task->submitter()->associate($this->externalUser)->save();
    }

    public function test_external_thread_creation_sends_notifications_to_project_users()
    {
        Notification::fake();

        // Create additional users with access to the project
        $projectUser1 = User::factory()->create();
        $projectUser2 = User::factory()->create();

        // Give these users access to the project
        ProjectUser::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $projectUser1->id,
            'user_email' => $projectUser1->email,
            'user_name' => $projectUser1->name,
            'registration_status' => 'registered'
        ]);

        ProjectUser::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $projectUser2->id,
            'user_email' => $projectUser2->email,
            'user_name' => $projectUser2->name,
            'registration_status' => 'registered'
        ]);

        $threadData = [
            'content' => 'This is a test message from an external user',
            'type' => 'external',
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/tasks/{$this->task->id}/threads", $threadData);

        $response->assertStatus(201);

        // Assert that notifications were sent to all project users
        Notification::assertSentTo(
            [$this->user, $projectUser1, $projectUser2],
            TaskThreadUpdated::class
        );
    }
}
