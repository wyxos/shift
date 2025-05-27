<?php

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectUserRegisteredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectUserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_owner_receives_notification_when_user_registers_following_invite()
    {
        Notification::fake();

        // Create a project owner
        $projectOwner = User::factory()->create();

        // Create a project with the owner as the author
        $project = Project::factory()->create([
            'author_id' => $projectOwner->id,
        ]);

        // Create a project user record for an invited user (with email only, no user_id yet)
        $invitedUserEmail = 'invited@example.com';
        $invitedUserName = 'Invited User';

        ProjectUser::factory()->create([
            'project_id' => $project->id,
            'user_id' => null,
            'user_email' => $invitedUserEmail,
            'user_name' => $invitedUserName,
            'registration_status' => 'pending',
        ]);

        // Simulate the user registering via the invitation
        $response = $this->post('/register', [
            'name' => $invitedUserName,
            'email' => $invitedUserEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
            'project_id' => $project->id,
        ]);

        // Assert the user was registered and redirected to the projects page
        $this->assertAuthenticated();
        $response->assertRedirect(route('projects.index', ['highlight' => $project->id], false));

        // Assert the project user record was updated
        $this->assertDatabaseHas('project_users', [
            'project_id' => $project->id,
            'user_email' => $invitedUserEmail,
            'registration_status' => 'registered',
        ]);

        // Assert that the project owner received the notification
        Notification::assertSentTo(
            $projectOwner,
            ProjectUserRegisteredNotification::class,
            function ($notification, $channels) use ($project, $invitedUserEmail, $projectOwner) {
                $mailData = $notification->toMail($projectOwner);
                $arrayData = $notification->toArray($projectOwner);

                // Check that the notification contains the correct information
                return $arrayData['project_id'] === $project->id &&
                    $arrayData['user_email'] === $invitedUserEmail &&
                    str_contains($mailData->subject, 'New User Registration');
            }
        );
    }
}
