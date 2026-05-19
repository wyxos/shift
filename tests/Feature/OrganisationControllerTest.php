<?php

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\User;

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

    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
    ]);

    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => null,
        'user_email' => 'invitee@example.com',
        'user_name' => 'Invited User',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('organisations.index', ['team' => $organisation->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Organisations/Index')
        ->where('panel.team', $organisation->id)
        ->where('panelOrganisation.id', $organisation->id)
        ->where('panelOrganisation.name', 'Acme Labs')
        ->where('panelOrganisation.teamUsers.0.name', 'Owner User')
        ->where('panelOrganisation.teamUsers.0.email', 'owner@example.com')
        ->where('panelOrganisation.teamUsers.0.status', 'owner')
        ->where('panelOrganisation.teamUsers.1.name', 'Jane Admin')
        ->where('panelOrganisation.teamUsers.1.email', 'jane@example.com')
        ->where('panelOrganisation.teamUsers.1.status', 'registered')
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
        ->get(route('organisations.index', ['team' => $organisation->id]));

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
