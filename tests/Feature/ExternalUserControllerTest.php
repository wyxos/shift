<?php

use App\Enums\ExternalUserRole;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('user can only see external users for owned projects', function () {
    // Create a project owned by the authenticated user
    $ownedProject = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create an external user for the owned project
    $ownedExternalUser = ExternalUser::factory()->create([
        'project_id' => $ownedProject->id,
        'name' => 'Owned External User',
        'email' => 'owned@example.com',
    ]);

    // Create another project not owned by the authenticated user
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->create([
        'author_id' => $otherUser->id,
    ]);

    // Create an external user for the other project
    $otherExternalUser = ExternalUser::factory()->create([
        'project_id' => $otherProject->id,
        'name' => 'Other External User',
        'email' => 'other@example.com',
    ]);

    // Access the external users index page as the authenticated user
    $response = $this->actingAs($this->user)
        ->get(route('external-users.index'));

    $response->assertStatus(200);

    // Assert that the owned external user is visible
    $response->assertSee('Owned External User');
    $response->assertSee('owned@example.com');

    // Assert that the other external user is not visible
    $response->assertDontSee('Other External User');
    $response->assertDontSee('other@example.com');
});

test('external users index can filter by project and includes role labels', function () {
    $billingProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Billing Console',
    ]);
    $retailProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Retail Dashboard',
    ]);

    $billingExternalUser = ExternalUser::factory()->create([
        'project_id' => $billingProject->id,
        'name' => 'Billing Owner',
        'email' => 'billing@example.com',
        'role' => ExternalUserRole::Owner,
    ]);
    ExternalUser::factory()->create([
        'project_id' => $retailProject->id,
        'name' => 'Retail Guest',
        'email' => 'retail@example.com',
        'role' => ExternalUserRole::Guest,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('external-users.index', ['project_id' => $billingProject->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('ExternalUsers/Index')
        ->has('externalUsers.data', 1)
        ->where('externalUsers.data.0.id', $billingExternalUser->id)
        ->where('externalUsers.data.0.project.id', $billingProject->id)
        ->where('externalUsers.data.0.role', ExternalUserRole::Owner->value)
        ->where('externalUsers.data.0.role_label', 'Owner')
        ->where('filters.project_id', $billingProject->id)
        ->has('projects', 2)
    );
});

test('organisation external users route scopes projects and external users', function () {
    $billingOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $retailOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $billingProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $billingOrganisation->id,
        'name' => 'Billing Console',
    ]);
    $retailProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $retailOrganisation->id,
        'name' => 'Retail Dashboard',
    ]);

    $billingExternalUser = ExternalUser::factory()->create([
        'project_id' => $billingProject->id,
        'name' => 'Billing Owner',
        'email' => 'billing@example.com',
    ]);
    ExternalUser::factory()->create([
        'project_id' => $retailProject->id,
        'name' => 'Retail Guest',
        'email' => 'retail@example.com',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.external-users', [
            'organisation' => $billingOrganisation,
            'project_id' => $billingProject->id,
        ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('ExternalUsers/Index')
        ->has('externalUsers.data', 1)
        ->where('externalUsers.data.0.id', $billingExternalUser->id)
        ->where('externalUsers.data.0.project.id', $billingProject->id)
        ->where('filters.project_id', $billingProject->id)
        ->where('filters.organisation_id', $billingOrganisation->id)
        ->has('projects', 1)
        ->where('projects.0.id', $billingProject->id)
    );
});

test('external user update can return json for sheet edits', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Portal',
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'name' => 'Client QA',
        'email' => 'qa@example.com',
        'role' => ExternalUserRole::ClientDeveloper,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('external-users.update', $externalUser), [
            'name' => 'Client QA Lead',
            'email' => 'lead@example.com',
            'project_id' => $project->id,
        ]);

    $response->assertOk();
    $response->assertJsonPath('external_user.id', $externalUser->id);
    $response->assertJsonPath('external_user.name', 'Client QA Lead');
    $response->assertJsonPath('external_user.email', 'lead@example.com');
    $response->assertJsonPath('external_user.role', ExternalUserRole::ClientDeveloper->value);
    $response->assertJsonPath('external_user.project.id', $project->id);

    $this->assertDatabaseHas('external_users', [
        'id' => $externalUser->id,
        'name' => 'Client QA Lead',
        'email' => 'lead@example.com',
    ]);
});

test('user can only see external users for projects with access', function () {
    // Create a project not owned by the authenticated user
    $otherUser = User::factory()->create();
    $accessibleProject = Project::factory()->create([
        'author_id' => $otherUser->id,
    ]);

    // Give the authenticated user access to the project
    ProjectUser::factory()->create([
        'project_id' => $accessibleProject->id,
        'user_id' => $this->user->id,
        'user_email' => $this->user->email,
        'user_name' => $this->user->name,
        'registration_status' => 'registered',
    ]);

    // Create an external user for the accessible project
    $accessibleExternalUser = ExternalUser::factory()->create([
        'project_id' => $accessibleProject->id,
        'name' => 'Accessible External User',
        'email' => 'accessible@example.com',
    ]);

    // Create another project not owned by the authenticated user and without access
    $inaccessibleProject = Project::factory()->create([
        'author_id' => $otherUser->id,
    ]);

    // Create an external user for the inaccessible project
    $inaccessibleExternalUser = ExternalUser::factory()->create([
        'project_id' => $inaccessibleProject->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);

    // Access the external users index page as the authenticated user
    $response = $this->actingAs($this->user)
        ->get(route('external-users.index'));

    $response->assertStatus(200);

    // Assert that the accessible external user is visible
    $response->assertSee('Accessible External User');
    $response->assertSee('accessible@example.com');

    // Assert that the inaccessible external user is not visible
    $response->assertDontSee('Inaccessible External User');
    $response->assertDontSee('inaccessible@example.com');
});

test('user cannot edit external user for inaccessible project', function () {
    // Create a project not owned by the authenticated user
    $otherUser = User::factory()->create();
    $inaccessibleProject = Project::factory()->create([
        'author_id' => $otherUser->id,
    ]);

    // Create an external user for the inaccessible project
    $inaccessibleExternalUser = ExternalUser::factory()->create([
        'project_id' => $inaccessibleProject->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);

    // Try to access the edit page for the inaccessible external user
    $response = $this->actingAs($this->user)
        ->get(route('external-users.edit', $inaccessibleExternalUser->id));

    // Should get a 404 since the user doesn't have access to this external user
    $response->assertStatus(404);
});

test('user cannot update external user for inaccessible project', function () {
    // Create a project not owned by the authenticated user
    $otherUser = User::factory()->create();
    $inaccessibleProject = Project::factory()->create([
        'author_id' => $otherUser->id,
    ]);

    // Create an external user for the inaccessible project
    $inaccessibleExternalUser = ExternalUser::factory()->create([
        'project_id' => $inaccessibleProject->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);

    // Try to update the inaccessible external user
    $response = $this->actingAs($this->user)
        ->put(route('external-users.update', $inaccessibleExternalUser->id), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'project_id' => $inaccessibleProject->id,
        ]);

    // Should get a 404 or a redirect with validation error
    $response->assertStatus(302);
    // Redirects back with validation error
    $response->assertSessionHasErrors('project_id');

    // Validation error for project_id
    // Verify the external user was not updated
    $this->assertDatabaseHas('external_users', [
        'id' => $inaccessibleExternalUser->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);
});
