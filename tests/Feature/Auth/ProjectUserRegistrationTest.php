<?php

use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectUserRegisteredNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('invited project users can register and must verify their email', function () {
    Notification::fake();
    config(['shift.registration_mode' => 'invite_only']);

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

    $registrationUrl = URL::signedRoute('register', [
        'email' => $invitedUserEmail,
        'name' => $invitedUserName,
        'project_id' => $project->id,
        'organisation_id' => $organisation->id,
    ]);

    $this->get($registrationUrl)->assertOk();

    // Register via the invitation
    $response = $this->post('/register', [
        'name' => $invitedUserName,
        'email' => $invitedUserEmail,
        'password' => 'password',
        'password_confirmation' => 'password',
        'project_id' => $project->id,
        'organisation_id' => $organisation->id,
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

test('registration cannot use an invitation context without opening its signed invitation link', function () {
    $projectOwner = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $projectOwner->id]);
    $project = Project::factory()->create([
        'author_id' => $projectOwner->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);
    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => null,
        'user_email' => 'pending@example.com',
        'user_name' => 'Pending User',
        'registration_status' => 'pending',
    ]);

    $this->post('/register', [
        'name' => 'Pending User',
        'email' => 'pending@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'project_id' => $project->id,
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'pending@example.com']);
});

test('an invitation session cannot be reused for a different pending invitation', function () {
    config(['shift.registration_mode' => 'invite_only']);

    $projectOwner = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $projectOwner->id]);
    $projects = Project::factory()
        ->count(2)
        ->create([
            'author_id' => $projectOwner->id,
            'client_id' => null,
            'organisation_id' => $organisation->id,
        ]);

    foreach ($projects as $project) {
        ProjectUser::factory()->create([
            'project_id' => $project->id,
            'user_id' => null,
            'user_email' => 'shared-invitee@example.com',
            'user_name' => 'Shared Invitee',
            'registration_status' => 'pending',
        ]);
    }

    $registrationUrl = URL::signedRoute('register', [
        'email' => 'shared-invitee@example.com',
        'name' => 'Shared Invitee',
        'project_id' => $projects[0]->id,
        'organisation_id' => $organisation->id,
    ]);

    $this->get($registrationUrl)->assertOk();
    $this->post('/register', [
        'name' => 'Shared Invitee',
        'email' => 'shared-invitee@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'project_id' => $projects[1]->id,
        'organisation_id' => $organisation->id,
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'shared-invitee@example.com']);
});
