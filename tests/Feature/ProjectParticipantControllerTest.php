<?php

use App\Enums\ExternalIdentityRole;
use App\Enums\OrganisationRole;
use App\Models\ExternalIdentity;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectParticipant;
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
    $ownedExternalIdentity = ExternalIdentity::factory()->create([
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
    $otherExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $otherProject->id,
        'name' => 'Other External User',
        'email' => 'other@example.com',
    ]);

    // Access the external users index page as the authenticated user
    $response = $this->actingAs($this->user)
        ->get(route('organisation.project-participants', $ownedOrganisation));

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

    $billingExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $billingProject->id,
        'name' => 'Billing Owner',
        'email' => 'billing@example.com',
        'role' => ExternalIdentityRole::Owner,
    ]);
    ExternalIdentity::factory()->create([
        'project_id' => $retailProject->id,
        'name' => 'Retail Guest',
        'email' => 'retail@example.com',
        'role' => ExternalIdentityRole::Guest,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.project-participants', [
            'organisation' => $organisation,
            'project_id' => $billingProject->id,
        ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('ProjectParticipants/Index')
        ->has('externalIdentities.data', 1)
        ->where('externalIdentities.data.0.id', $billingExternalIdentity->id)
        ->where('externalIdentities.data.0.project.id', $billingProject->id)
        ->where('externalIdentities.data.0.role', ExternalIdentityRole::Owner->value)
        ->where('externalIdentities.data.0.role_label', 'Client Owner')
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
    $contact = ProjectParticipant::create([
        'project_id' => $project->id,
    ]);
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'project_participant_id' => $contact->id,
        'name' => 'Alpha Sandbox Client',
        'email' => 'sandbox@example.com',
        'role' => ExternalIdentityRole::Owner,
    ]);
    $linkedUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'project_participant_id' => $contact->id,
        'name' => 'Bravo Staging Client',
        'email' => 'staging@example.com',
        'environment' => 'staging',
        'url' => 'https://portal-staging.example.com',
    ]);
    $linkableUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'name' => 'Charlie Production Client',
        'email' => 'production@example.com',
        'environment' => 'production',
        'url' => 'https://portal.example.com',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.project-participants', [
            'organisation' => $organisation,
            'project_id' => $project->id,
            'sort_by' => 'name',
        ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('ProjectParticipants/Index')
        ->where('canManageExternalRoles', true)
        ->where('canManageLinkedAccounts', true)
        ->where('externalIdentities.data.0.id', $externalIdentity->id)
        ->has('externalIdentities.data.0.linked_accounts', 1)
        ->where('externalIdentities.data.0.linked_accounts.0.id', $linkedUser->id)
        ->where('externalIdentities.data.0.linked_accounts.0.url', 'https://portal-staging.example.com')
        ->where('externalIdentities.data.0.linkable_accounts.0.id', $linkableUser->id)
        ->where('externalIdentities.data.0.linkable_accounts.0.url', 'https://portal.example.com')
        ->where('externalIdentities.data.0.can_manage_role', true)
        ->where('externalIdentities.data.0.links.link_accounts', route('project-participants.linked-accounts.store', $externalIdentity))
        ->where('externalIdentities.data.0.linked_accounts.0.unlink_url', route('project-participants.linked-accounts.destroy', [$externalIdentity, $linkedUser]))
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

    $billingExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $billingProject->id,
        'name' => 'Billing Owner',
        'email' => 'billing@example.com',
    ]);
    ExternalIdentity::factory()->create([
        'project_id' => $retailProject->id,
        'name' => 'Retail Guest',
        'email' => 'retail@example.com',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.project-participants', [
            'organisation' => $billingOrganisation,
            'project_id' => $billingProject->id,
        ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('ProjectParticipants/Index')
        ->has('externalIdentities.data', 1)
        ->where('externalIdentities.data.0.id', $billingExternalIdentity->id)
        ->where('externalIdentities.data.0.project.id', $billingProject->id)
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
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'name' => 'Client QA',
        'email' => 'qa@example.com',
        'role' => ExternalIdentityRole::ClientDeveloper,
    ]);

    $response = $this->actingAs($this->user)
        ->putJson(route('project-participants.update', $externalIdentity), [
            'name' => 'Client QA Lead',
            'email' => 'lead@example.com',
            'project_id' => $project->id,
        ]);

    $response->assertOk();
    $response->assertJsonPath('external_identity.id', $externalIdentity->id);
    $response->assertJsonPath('external_identity.name', 'Client QA Lead');
    $response->assertJsonPath('external_identity.email', 'lead@example.com');
    $response->assertJsonPath('external_identity.role', ExternalIdentityRole::ClientDeveloper->value);
    $response->assertJsonPath('external_identity.project.id', $project->id);

    $this->assertDatabaseHas('external_identities', [
        'id' => $externalIdentity->id,
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
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'name' => 'Client QA',
        'email' => 'qa@example.com',
        'role' => ExternalIdentityRole::ClientDeveloper,
    ]);

    $this->actingAs($this->user)
        ->put(route('project-participants.update', $externalIdentity), [
            'name' => 'Client QA Lead',
            'email' => 'lead@example.com',
        ])
        ->assertRedirect(route('organisation.project-participants', [
            'organisation' => $organisation,
            'project_id' => $project->id,
        ]));

    $this->assertDatabaseHas('external_identities', [
        'id' => $externalIdentity->id,
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
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'name' => 'Client Contact',
        'email' => 'client@example.com',
        'role' => ExternalIdentityRole::User,
    ]);

    $this->actingAs($this->user)
        ->putJson(route('project-participants.update', $externalIdentity), [
            'name' => 'Client Contact',
            'email' => 'client@example.com',
            'role' => ExternalIdentityRole::Owner->value,
        ])
        ->assertOk()
        ->assertJsonPath('external_identity.role', ExternalIdentityRole::Owner->value)
        ->assertJsonPath('external_identity.role_label', 'Client Owner')
        ->assertJsonPath('external_identity.project.id', $project->id);

    $this->assertDatabaseHas('external_identities', [
        'id' => $externalIdentity->id,
        'project_id' => $project->id,
        'role' => ExternalIdentityRole::Owner->value,
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
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'role' => ExternalIdentityRole::User,
    ]);

    $this->actingAs($developer)
        ->putJson(route('project-participants.update', $externalIdentity), [
            'name' => 'Escalated User',
            'email' => 'escalated@example.com',
            'role' => ExternalIdentityRole::Owner->value,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('external_identities', [
        'id' => $externalIdentity->id,
        'role' => ExternalIdentityRole::User->value,
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
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $sourceProject->id,
        'name' => 'Client QA',
        'email' => 'qa@example.com',
        'role' => ExternalIdentityRole::User,
    ]);

    $this->actingAs($this->user)
        ->putJson(route('project-participants.update', $externalIdentity), [
            'name' => 'Client QA',
            'email' => 'qa@example.com',
            'project_id' => $targetProject->id,
            'role' => ExternalIdentityRole::ClientDeveloper->value,
        ])
        ->assertOk()
        ->assertJsonPath('external_identity.project.id', $sourceProject->id)
        ->assertJsonPath('external_identity.role', ExternalIdentityRole::ClientDeveloper->value);

    $this->assertDatabaseHas('external_identities', [
        'id' => $externalIdentity->id,
        'project_id' => $sourceProject->id,
        'role' => ExternalIdentityRole::ClientDeveloper->value,
    ]);
});

test('trusted internal users can link and unlink same project external accounts', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $sandboxUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'environment' => 'sandbox',
    ]);
    $productionUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('project-participants.linked-accounts.store', $sandboxUser), [
            'linked_external_identity_id' => $productionUser->id,
        ])
        ->assertOk()
        ->assertJsonPath('external_identity.id', $sandboxUser->id)
        ->assertJsonPath('external_identity.linked_accounts.0.id', $productionUser->id);

    $sandboxUser->refresh();
    $productionUser->refresh();
    expect($sandboxUser->project_participant_id)->not()->toBeNull()
        ->and($productionUser->project_participant_id)->toBe($sandboxUser->project_participant_id);

    $this->actingAs($this->user)
        ->deleteJson(route('project-participants.linked-accounts.destroy', [$sandboxUser, $productionUser]))
        ->assertOk()
        ->assertJsonPath('external_identity.id', $sandboxUser->id)
        ->assertJsonCount(0, 'external_identity.linked_accounts');

    $sandboxUser->refresh();
    $productionUser->refresh();
    expect($productionUser->project_participant_id)->not()->toBe($sandboxUser->project_participant_id);
});

test('an external account cannot be linked to itself', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $externalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('project-participants.linked-accounts.store', $externalIdentity), [
            'linked_external_identity_id' => $externalIdentity->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'An external account cannot be linked to itself.');

    expect($externalIdentity->refresh()->project_participant_id)->toBeNull();
});

test('external account links stay within a project', function () {
    $sourceProject = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $targetProject = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $sourceUser = ExternalIdentity::factory()->create([
        'project_id' => $sourceProject->id,
    ]);
    $targetUser = ExternalIdentity::factory()->create([
        'project_id' => $targetProject->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('project-participants.linked-accounts.store', $sourceUser), [
            'linked_external_identity_id' => $targetUser->id,
        ])
        ->assertNotFound();

    expect($sourceUser->refresh()->project_participant_id)->toBeNull()
        ->and($targetUser->refresh()->project_participant_id)->toBeNull();
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
    $sourceUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
    ]);
    $targetUser = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->actingAs($developer)
        ->postJson(route('project-participants.linked-accounts.store', $sourceUser), [
            'linked_external_identity_id' => $targetUser->id,
        ])
        ->assertForbidden();

    expect($sourceUser->refresh()->project_participant_id)->toBeNull()
        ->and($targetUser->refresh()->project_participant_id)->toBeNull();
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
    $accessibleExternalIdentity = ExternalIdentity::factory()->create([
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
    $inaccessibleExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $inaccessibleProject->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);

    // Access the external users index page as the authenticated user
    $response = $this->actingAs($this->user)
        ->get(route('organisation.project-participants', $accessibleOrganisation));

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
    $inaccessibleExternalIdentity = ExternalIdentity::factory()->create([
        'project_id' => $inaccessibleProject->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);

    // Try to update the inaccessible external user
    $response = $this->actingAs($this->user)
        ->putJson(route('project-participants.update', $inaccessibleExternalIdentity->id), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'project_id' => $inaccessibleProject->id,
        ]);

    $response->assertNotFound();

    $this->assertDatabaseHas('external_identities', [
        'id' => $inaccessibleExternalIdentity->id,
        'name' => 'Inaccessible External User',
        'email' => 'inaccessible@example.com',
    ]);
});
