<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_project_invitation_notification_is_sent_to_new_user()
    {
        Notification::fake();

        // Create a project owned by the authenticated user
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        // New user email that doesn't exist in the system
        $newUserEmail = 'newprojectuser@example.com';
        $newUserName = 'New Project User';

        // Invite a new user to the project
        $response = $this->actingAs($this->user)
            ->post(route('project-users.store', $project), [
                'email' => $newUserEmail,
                'name' => $newUserName,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User granted access to project successfully.');

        // For route-based notifications, we need to use a different approach
        Notification::assertSentOnDemand(
            ProjectInvitationNotification::class
        );
    }

    public function test_project_invitation_notification_is_not_sent_to_existing_user()
    {
        Notification::fake();

        // Create a project owned by the authenticated user
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        // Create another user who will be added to the project
        $existingUser = User::factory()->create();

        // Add the existing user to the project
        $response = $this->actingAs($this->user)
            ->post(route('project-users.store', $project), [
                'email' => $existingUser->email,
                'name' => $existingUser->name,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User granted access to project successfully.');

        // Assert that no ProjectInvitationNotification was sent to the existing user
        Notification::assertNothingSent();
    }
}
