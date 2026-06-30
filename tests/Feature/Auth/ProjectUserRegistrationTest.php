<?php

use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectUserRegisteredNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('invited project users can register and must verify their email', function () {
    Notification::fake();

    // Create a project owner
    $projectOwner = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $projectOwner->id,
    ]);

    // Create a project with the owner as the author
    $project = Project::factory()->create([
        'author_id' => $projectOwner->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);

    // Create a project user record for an invited user (with email only, no user_id yet)
    $invitedUserEmail = 'invited@EXAMPLE.com';
    $invitedUserName = 'Invited User';

    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => null,
        'user_email' => $invitedUserEmail,
        'user_name' => $invitedUserName,
        'registration_status' => 'pending',
    ]);

    // Register via the invitation
    $response = $this->post('/register', [
        'name' => $invitedUserName,
        'email' => $invitedUserEmail,
        'password' => 'password',
        'password_confirmation' => 'password',
        'project_id' => $project->id,
    ]);

    $registeredUser = User::where('email', 'invited@example.com')->firstOrFail();

    $response->assertRedirect(route('organisation.projects', [
        'organisation' => $organisation,
        'highlight' => $project->id,
    ], absolute: false));
    $this->assertAuthenticatedAs($registeredUser);
    expect($registeredUser->email_verified_at)->toBeNull();

    // Assert invitation records were linked, but app access is still gated by email verification.
    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_id' => $registeredUser->id,
        'registration_status' => 'registered',
    ]);
    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $organisation->id,
        'user_id' => $registeredUser->id,
    ]);

    Notification::assertSentTo($registeredUser, VerifyEmail::class);
    Notification::assertSentTo(
        $projectOwner,
        ProjectUserRegisteredNotification::class,
        fn (ProjectUserRegisteredNotification $notification) => $notification->toArray($projectOwner)['organisation_id'] === $organisation->id,
    );

    $this->get(route('organisation.projects', $organisation, absolute: false))
        ->assertRedirect(route('verification.notice', absolute: false));
});
