<?php

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\OrganisationAccessNotification;
use App\Notifications\OrganisationInvitationNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('organisation access notification is sent to existing user', function () {
    Notification::fake();

    // Create an organisation owned by the authenticated user
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create another user who will be added to the organisation
    $existingUser = User::factory()->create();

    // Add the existing user to the organisation
    $response = $this->actingAs($this->user)
        ->post(route('organisation-users.store', $organisation), [
            'email' => $existingUser->email,
            'name' => $existingUser->name,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'User invited to organisation successfully.');

    // Assert that the OrganisationAccessNotification was sent to the existing user
    Notification::assertSentTo(
        $existingUser,
        OrganisationAccessNotification::class
    );
});

test('organisation invitation notification is sent to new user', function () {
    Notification::fake();

    // Create an organisation owned by the authenticated user
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // New user email that doesn't exist in the system
    $newUserEmail = 'newuser@example.com';
    $newUserName = 'New User';

    // Invite a new user to the organisation
    $response = $this->actingAs($this->user)
        ->post(route('organisation-users.store', $organisation), [
            'email' => $newUserEmail,
            'name' => $newUserName,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'User invited to organisation successfully.');

    // For route-based notifications, we need to use a different approach
    Notification::assertSentOnDemand(
        OrganisationInvitationNotification::class
    );
});

test('organisation owner can sync a member project access', function () {
    $member = User::factory()->create([
        'name' => 'Jane Admin',
        'email' => 'jane@example.com',
    ]);

    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $organisationUser = OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
    ]);

    $enabledProject = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
    ]);

    $disabledProject = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
    ]);

    ProjectUser::create([
        'project_id' => $disabledProject->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson(route('organisation-users.projects.sync', [$organisation, $organisationUser]), [
            'project_ids' => [$enabledProject->id],
        ]);

    $response
        ->assertOk()
        ->assertJson([
            'project_ids' => [$enabledProject->id],
        ]);

    $this->assertDatabaseHas('project_users', [
        'project_id' => $enabledProject->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'registration_status' => 'registered',
    ]);
    $this->assertDatabaseMissing('project_users', [
        'project_id' => $disabledProject->id,
        'user_id' => $member->id,
    ]);
});

test('organisation project access sync rejects projects outside the organisation', function () {
    $member = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $outsideOrganisation = Organisation::factory()->create();
    $outsideProject = Project::factory()->create([
        'organisation_id' => $outsideOrganisation->id,
        'client_id' => null,
    ]);
    $organisationUser = OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
    ]);

    $this->actingAs($this->user)
        ->patchJson(route('organisation-users.projects.sync', [$organisation, $organisationUser]), [
            'project_ids' => [$outsideProject->id],
        ])
        ->assertUnprocessable();

    $this->assertDatabaseMissing('project_users', [
        'project_id' => $outsideProject->id,
        'user_id' => $member->id,
    ]);
});

test('removing organisation access also removes project access in that organisation', function () {
    $member = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
    ]);
    $organisationUser = OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
    ]);
    $projectUser = ProjectUser::create([
        'project_id' => $project->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
        'registration_status' => 'registered',
    ]);

    $this->actingAs($this->user)
        ->delete(route('organisation-users.destroy', [$organisation, $organisationUser]))
        ->assertRedirect();

    $this->assertDatabaseMissing('organisation_users', [
        'id' => $organisationUser->id,
    ]);
    $this->assertDatabaseMissing('project_users', [
        'id' => $projectUser->id,
    ]);
});
