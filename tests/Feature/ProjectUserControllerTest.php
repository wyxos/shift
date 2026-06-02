<?php

use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('project invitation notification is sent to new user', function () {
    Notification::fake();

    // Create a project owned by the authenticated user
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
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

    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $organisation->id,
        'user_id' => null,
        'user_email' => $newUserEmail,
        'user_name' => $newUserName,
    ]);
    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_id' => null,
        'user_email' => $newUserEmail,
        'user_name' => $newUserName,
        'registration_status' => 'pending',
    ]);

    // For route-based notifications, we need to use a different approach
    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class
    );
});

test('project invitation notification is not sent to existing user', function () {
    Notification::fake();

    // Create a project owned by the authenticated user
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
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

    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $organisation->id,
        'user_id' => $existingUser->id,
        'user_email' => $existingUser->email,
        'user_name' => $existingUser->name,
    ]);
    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_id' => $existingUser->id,
        'user_email' => $existingUser->email,
        'user_name' => $existingUser->name,
        'registration_status' => 'registered',
    ]);

    // Assert that no ProjectInvitationNotification was sent to the existing user
    Notification::assertNothingSent();
});

test('project invite matches existing users case insensitively', function () {
    Notification::fake();

    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);
    $existingUser = User::factory()->create([
        'email' => 'mixed-case-project-member@example.com',
    ]);

    $this->actingAs($this->user)
        ->post(route('project-users.store', $project), [
            'email' => 'MIXED-CASE-PROJECT-MEMBER@example.com',
            'name' => $existingUser->name,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $organisation->id,
        'user_id' => $existingUser->id,
    ]);
    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_id' => $existingUser->id,
        'registration_status' => 'registered',
    ]);

    Notification::assertNothingSent();
});

test('project invite reuses existing organisation membership', function () {
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);
    $existingUser = User::factory()->create();

    $organisationUser = $organisation->organisationUsers()->create([
        'user_id' => $existingUser->id,
        'user_email' => $existingUser->email,
        'user_name' => $existingUser->name,
    ]);

    $this->actingAs($this->user)
        ->post(route('project-users.store', $project), [
            'email' => $existingUser->email,
            'name' => $existingUser->name,
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('organisation_users', 1);
    $this->assertDatabaseHas('organisation_users', [
        'id' => $organisationUser->id,
        'organisation_id' => $organisation->id,
        'user_id' => $existingUser->id,
    ]);
    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_id' => $existingUser->id,
        'registration_status' => 'registered',
    ]);
});
