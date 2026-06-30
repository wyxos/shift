<?php

use App\Enums\ExternalUserRole;
use App\Enums\OrganisationRole;
use App\Models\ExternalContact;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('user can only see external users for owned projects', function () {
    $ownedOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create a project owned by the authenticated user
    $ownedProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $ownedOrganisation->id,
    ]);

    // Create an external user for the owned project
    $ownedExternalUser = ExternalUser::factory()->create([
        'project_id' => $ownedProject->id,
        'name' => 'Owned External User',
        'email' => 'owned@example.com',
    ]);

    // Create another project not owned by the authenticated user
    $otherUser = User::factory()->create();
    $otherOrganisation = Organisation::factory()->create([
        'author_id' => $otherUser->id,
    ]);
    $otherProject = Project::factory()->create([
        'author_id' => $otherUser->id,
        'organisation_id' => $otherOrganisation->id,
    ]);

    // Create an external user for the other project
    $otherExternalUser = ExternalUser::factory()->create([
        'project_id' => $otherProject->id,
        'name' => 'Other External User',
        'email' => 'other@example.com',
    ]);

    // Access the external users index page as the authenticated user
    $response = $this->actingAs($this->user)
        ->get(route('organisation.external-users', $ownedOrganisation));

    $response->assertStatus(200);

    // Assert that the owned external user is visible
    $response->assertSee('Owned External User');
    $response->assertSee('owned@example.com');

    // Assert that the other external user is not visible
    $response->assertDontSee('Other External User');
    $response->assertDontSee('other@example.com');
});

test('external users index can filter by project and includes role labels', function () {
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $billingProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $organisation->id,
        'name' => 'Billing Console',
    ]);
    $retailProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $organisation->id,
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
        ->get(route('organisation.external-users', [
            'organisation' => $organisation,
            'project_id' => $billingProject->id,
        ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('ExternalUsers/Index')
        ->has('externalUsers.data', 1)
        ->where('externalUsers.data.0.id', $billingExternalUser->id)
        ->where('externalUsers.data.0.project.id', $billingProject->id)
        ->where('externalUsers.data.0.role', ExternalUserRole::Owner->value)
        ->where('externalUsers.data.0.role_label', 'Client Owner')
        ->where('filters.project_id', $billingProject->id)
        ->has('projects', 2)
    );
});

test('external users index includes linked and linkable account management data', function () {
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $organisation->id,
        'name' => 'Portal',
    ]);
    $contact = ExternalContact::create([
        'project_id' => $project->id,
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_contact_id' => $contact->id,
        'name' => 'Alpha Sandbox Client',
        'email' => 'sandbox@example.com',
        'role' => ExternalUserRole::Owner,
    ]);
    $linkedUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_contact_id' => $contact->id,
        'name' => 'Bravo Staging Client',
        'email' => 'staging@example.com',
        'environment' => 'staging',
    ]);
    $linkableUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'name' => 'Charlie Production Client',
        'email' => 'production@example.com',
        'environment' => 'production',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.external-users', [
            'organisation' => $organisation,
            'project_id' => $project->id,
            'sort_by' => 'name',
        ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('ExternalUsers/Index')
        ->where('canManageExternalRoles', true)
        ->where('canManageLinkedAccounts', true)
        ->where('externalUsers.data.0.id', $externalUser->id)
        ->has('externalUsers.data.0.linked_accounts', 1)
        ->where('externalUsers.data.0.linked_accounts.0.id', $linkedUser->id)
        ->where('externalUsers.data.0.linkable_accounts.0.id', $linkableUser->id)
        ->where('externalUsers.data.0.can_manage_role', true)
        ->where('externalUsers.data.0.links.link_accounts', route('external-users.linked-accounts.store', $externalUser))
        ->where('externalUsers.data.0.linked_accounts.0.unlink_url', route('external-users.linked-accounts.destroy', [$externalUser, $linkedUser]))
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

test('external user form update redirects to the organisation external users page', function () {
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $organisation->id,
        'name' => 'Portal',
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'name' => 'Client QA',
        'email' => 'qa@example.com',
        'role' => ExternalUserRole::ClientDeveloper,
    ]);

    $this->actingAs($this->user)
        ->put(route('external-users.update', $externalUser), [
            'name' => 'Client QA Lead',
            'email' => 'lead@example.com',
        ])
        ->assertRedirect(route('organisation.external-users', [
            'organisation' => $organisation,
            'project_id' => $project->id,
        ]));

    $this->assertDatabaseHas('external_users', [
        'id' => $externalUser->id,
        'name' => 'Client QA Lead',
        'email' => 'lead@example.com',
    ]);
});

test('trusted internal roles can update external account roles from the portal backend', function () {
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $organisation->id,
        'client_id' => null,
        'name' => 'Portal',
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'name' => 'Client Contact',
        'email' => 'client@example.com',
        'role' => ExternalUserRole::User,
    ]);

    $this->actingAs($this->user)
        ->putJson(route('external-users.update', $externalUser), [
            'name' => 'Client Contact',
            'email' => 'client@example.com',
            'role' => ExternalUserRole::Owner->value,
        ])
        ->assertOk()
        ->assertJsonPath('external_user.role', ExternalUserRole::Owner->value)
        ->assertJsonPath('external_user.role_label', 'Client Owner')
        ->assertJsonPath('external_user.project.id', $project->id);

    $this->assertDatabaseHas('external_users', [
        'id' => $externalUser->id,
        'project_id' => $project->id,
        'role' => ExternalUserRole::Owner->value,
    ]);
});

test('developer project members cannot update external account roles', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'organisation_id' => $organisation->id,
        'client_id' => null,
    ]);
    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $developer->id,
        'user_email' => $developer->email,
        'user_name' => $developer->name,
        'role' => OrganisationRole::Developer->value,
    ]);
    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $developer->id,
        'user_email' => $developer->email,
        'user_name' => $developer->name,
        'registration_status' => 'registered',
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'role' => ExternalUserRole::User,
    ]);

    $this->actingAs($developer)
        ->putJson(route('external-users.update', $externalUser), [
            'name' => 'Escalated User',
            'email' => 'escalated@example.com',
            'role' => ExternalUserRole::Owner->value,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('external_users', [
        'id' => $externalUser->id,
        'role' => ExternalUserRole::User->value,
    ]);
});

test('external user updates keep project assignment read only', function () {
    $sourceProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Source Portal',
    ]);
    $targetProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Target Portal',
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $sourceProject->id,
        'name' => 'Client QA',
        'email' => 'qa@example.com',
        'role' => ExternalUserRole::User,
    ]);

    $this->actingAs($this->user)
        ->putJson(route('external-users.update', $externalUser), [
            'name' => 'Client QA',
            'email' => 'qa@example.com',
            'project_id' => $targetProject->id,
            'role' => ExternalUserRole::ClientDeveloper->value,
        ])
        ->assertOk()
        ->assertJsonPath('external_user.project.id', $sourceProject->id)
        ->assertJsonPath('external_user.role', ExternalUserRole::ClientDeveloper->value);

    $this->assertDatabaseHas('external_users', [
        'id' => $externalUser->id,
        'project_id' => $sourceProject->id,
        'role' => ExternalUserRole::ClientDeveloper->value,
    ]);
});

test('trusted internal users can link and unlink same project external accounts', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $sandboxUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'sandbox',
    ]);
    $productionUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('external-users.linked-accounts.store', $sandboxUser), [
            'linked_external_user_id' => $productionUser->id,
        ])
        ->assertOk()
        ->assertJsonPath('external_user.id', $sandboxUser->id)
        ->assertJsonPath('external_user.linked_accounts.0.id', $productionUser->id);

    $sandboxUser->refresh();
    $productionUser->refresh();
    expect($sandboxUser->external_contact_id)->not()->toBeNull()
        ->and($productionUser->external_contact_id)->toBe($sandboxUser->external_contact_id);

    $this->actingAs($this->user)
        ->deleteJson(route('external-users.linked-accounts.destroy', [$sandboxUser, $productionUser]))
        ->assertOk()
        ->assertJsonPath('external_user.id', $sandboxUser->id)
        ->assertJsonCount(0, 'external_user.linked_accounts');

    $sandboxUser->refresh();
    $productionUser->refresh();
    expect($productionUser->external_contact_id)->not()->toBe($sandboxUser->external_contact_id);
});

test('external account links stay within a project', function () {
    $sourceProject = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $targetProject = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $sourceUser = ExternalUser::factory()->create([
        'project_id' => $sourceProject->id,
    ]);
    $targetUser = ExternalUser::factory()->create([
        'project_id' => $targetProject->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('external-users.linked-accounts.store', $sourceUser), [
            'linked_external_user_id' => $targetUser->id,
        ])
        ->assertNotFound();

    expect($sourceUser->refresh()->external_contact_id)->toBeNull()
        ->and($targetUser->refresh()->external_contact_id)->toBeNull();
});

test('developer project members cannot manage external account links', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'organisation_id' => $organisation->id,
        'client_id' => null,
    ]);
    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $developer->id,
        'user_email' => $developer->email,
        'user_name' => $developer->name,
        'role' => OrganisationRole::Developer->value,
    ]);
    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $developer->id,
        'user_email' => $developer->email,
        'user_name' => $developer->name,
        'registration_status' => 'registered',
    ]);
    $sourceUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
    ]);
    $targetUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->actingAs($developer)
        ->postJson(route('external-users.linked-accounts.store', $sourceUser), [
            'linked_external_user_id' => $targetUser->id,
        ])
        ->assertForbidden();

    expect($sourceUser->refresh()->external_contact_id)->toBeNull()
        ->and($targetUser->refresh()->external_contact_id)->toBeNull();
});

test('user can only see external users for projects with access', function () {
    // Create a project not owned by the authenticated user
    $otherUser = User::factory()->create();
    $accessibleOrganisation = Organisation::factory()->create([
        'author_id' => $otherUser->id,
    ]);
    $accessibleProject = Project::factory()->create([
        'author_id' => $otherUser->id,
        'organisation_id' => $accessibleOrganisation->id,
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
        'organisation_id' => $accessibleOrganisation->id,
    ]);

    // Create an external user for the inaccessible project
    $inaccessibleExternalUser = ExternalUser::factory()->create([
        'project_id' => $inaccessibleProject->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);

    // Access the external users index page as the authenticated user
    $response = $this->actingAs($this->user)
        ->get(route('organisation.external-users', $accessibleOrganisation));

    $response->assertStatus(200);

    // Assert that the accessible external user is visible
    $response->assertSee('Accessible External User');
    $response->assertSee('accessible@example.com');

    // Assert that the inaccessible external user is not visible
    $response->assertDontSee('Inaccessible External User');
    $response->assertDontSee('inaccessible@example.com');
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
        ->putJson(route('external-users.update', $inaccessibleExternalUser->id), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'project_id' => $inaccessibleProject->id,
        ]);

    $response->assertNotFound();

    $this->assertDatabaseHas('external_users', [
        'id' => $inaccessibleExternalUser->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);
});
