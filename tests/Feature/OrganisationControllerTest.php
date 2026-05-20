<?php

use App\Models\Client;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;

test('organisations index includes ownership context for owned and shared organisations', function () {
    $user = User::factory()->create();
    $sharedOwner = User::factory()->create();

    $ownedOrganisation = Organisation::factory()->create([
        'author_id' => $user->id,
        'name' => 'Acme Labs',
    ]);

    $sharedOrganisation = Organisation::factory()->create([
        'author_id' => $sharedOwner->id,
        'name' => 'Beta Systems',
    ]);

    OrganisationUser::create([
        'organisation_id' => $sharedOrganisation->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
    ]);

    $response = $this->actingAs($user)
        ->get(route('organisations.index', ['sort_by' => 'name']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Organisations/Index')
        ->has('organisations.data', 2)
        ->where('organisations.data.0.id', $ownedOrganisation->id)
        ->where('organisations.data.0.isOwner', true)
        ->where('organisations.data.1.id', $sharedOrganisation->id)
        ->where('organisations.data.1.isOwner', false)
    );
});

test('organisations index exposes team users for the selected owner organisation', function () {
    $owner = User::factory()->create([
        'name' => 'Owner User',
        'email' => 'owner@example.com',
    ]);

    $member = User::factory()->create([
        'name' => 'Jane Admin',
        'email' => 'jane@example.com',
    ]);

    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
        'name' => 'Acme Labs',
    ]);

    $client = Client::factory()->create([
        'organisation_id' => $organisation->id,
        'name' => 'Acme Client',
    ]);

    $clientProject = Project::factory()->create([
        'client_id' => $client->id,
        'organisation_id' => null,
        'name' => 'Billing Console',
    ]);

    Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
        'name' => 'Atlas Portal',
    ]);

    $organisationUser = OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
    ]);

    ProjectUser::create([
        'project_id' => $clientProject->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
        'registration_status' => 'registered',
    ]);

    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => null,
        'user_email' => 'invitee@example.com',
        'user_name' => 'Invited User',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('organisation.team', $organisation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Organisations/Index')
        ->where('panel.team', $organisation->id)
        ->where('panelOrganisation.id', $organisation->id)
        ->where('panelOrganisation.name', 'Acme Labs')
        ->where('panelOrganisation.projects.0.name', 'Atlas Portal')
        ->where('panelOrganisation.projects.1.name', 'Billing Console')
        ->where('panelOrganisation.teamUsers.0.name', 'Owner User')
        ->where('panelOrganisation.teamUsers.0.email', 'owner@example.com')
        ->where('panelOrganisation.teamUsers.0.status', 'owner')
        ->where('panelOrganisation.teamUsers.1.name', 'Jane Admin')
        ->where('panelOrganisation.teamUsers.1.email', 'jane@example.com')
        ->where('panelOrganisation.teamUsers.1.organisationUserId', $organisationUser->id)
        ->where('panelOrganisation.teamUsers.1.status', 'registered')
        ->where('panelOrganisation.teamUsers.1.projectIds', [$clientProject->id])
        ->where('panelOrganisation.teamUsers.2.name', 'Invited User')
        ->where('panelOrganisation.teamUsers.2.email', 'invitee@example.com')
        ->where('panelOrganisation.teamUsers.2.status', 'pending')
    );
});

test('shared organisation users cannot load owner panel data', function () {
    $owner = User::factory()->create();
    $sharedUser = User::factory()->create();

    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
    ]);

    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $sharedUser->id,
        'user_email' => $sharedUser->email,
        'user_name' => $sharedUser->name,
    ]);

    $response = $this->actingAs($sharedUser)
        ->get(route('organisation.team', $organisation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Organisations/Index')
        ->where('panel.team', $organisation->id)
        ->where('panelOrganisation', null)
    );
});

test('only organisation owners can update or delete organisations', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
        'name' => 'Protected Organisation',
    ]);

    $this->actingAs($otherUser)
        ->put(route('organisations.update', $organisation), ['name' => 'Changed'])
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->delete(route('organisations.destroy', $organisation))
        ->assertForbidden();

    expect($organisation->fresh()->name)->toBe('Protected Organisation');
});
