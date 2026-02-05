<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

;

test('project owner is not notified when registration is disabled', function () {
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

    // Attempt registration via the invitation (disabled)
    $response = $this->post('/register', [
        'name' => $invitedUserName,
        'email' => $invitedUserEmail,
        'password' => 'password',
        'password_confirmation' => 'password',
        'project_id' => $project->id,
    ]);

    $response->assertStatus(404);
    $this->assertGuest();

    // Assert the project user record was not updated
    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_email' => $invitedUserEmail,
        'registration_status' => 'pending',
    ]);

    Notification::assertNothingSent();
});
