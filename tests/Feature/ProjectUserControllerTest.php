<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Support\Facades\Notification;

;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('project invitation notification is sent to new user', function () {
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
});

test('project invitation notification is not sent to existing user', function () {
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
});
