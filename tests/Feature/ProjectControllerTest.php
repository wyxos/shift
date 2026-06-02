<?php

use App\Models\Client;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('projects index includes owned and shared projects with ownership context', function () {
    $ownedOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Acme Org',
    ]);

    $ownedClient = Client::factory()->create([
        'name' => 'Acme Client',
        'organisation_id' => $ownedOrganisation->id,
    ]);

    $ownedProject = Project::factory()->create([
        'name' => 'Owned Project',
        'client_id' => $ownedClient->id,
        'organisation_id' => null,
        'author_id' => null,
    ]);

    $sharedOwner = User::factory()->create();
    $sharedOrganisation = Organisation::factory()->create([
        'author_id' => $sharedOwner->id,
        'name' => 'Partner Org',
    ]);

    $sharedProject = Project::factory()->create([
        'name' => 'Shared Project',
        'client_id' => null,
        'organisation_id' => $sharedOrganisation->id,
        'author_id' => $sharedOwner->id,
    ]);

    ProjectUser::create([
        'project_id' => $sharedProject->id,
        'user_id' => $this->user->id,
        'user_email' => $this->user->email,
        'user_name' => $this->user->name,
        'registration_status' => 'registered',
    ]);

    $hiddenOwner = User::factory()->create();
    $hiddenOrganisation = Organisation::factory()->create([
        'author_id' => $hiddenOwner->id,
        'name' => 'Hidden Org',
    ]);

    Project::factory()->create([
        'name' => 'Hidden Project',
        'client_id' => null,
        'organisation_id' => $hiddenOrganisation->id,
        'author_id' => $hiddenOwner->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('projects.index', ['sort_by' => 'name']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Projects')
        ->has('projects.data', 2)
        ->where('projects.data.0.id', $ownedProject->id)
        ->where('projects.data.0.client_name', 'Acme Client')
        ->where('projects.data.0.organisation_name', 'Acme Org')
        ->where('projects.data.0.isOwner', true)
        ->where('projects.data.1.id', $sharedProject->id)
        ->where('projects.data.1.organisation_name', 'Partner Org')
        ->where('projects.data.1.isOwner', false)
        ->where('filters.sort_by', 'name')
        ->has('clients', 1)
        ->has('organisations', 1)
    );
});

test('projects index filters by search and sorts oldest first', function () {
    $ownedOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Acme Org',
    ]);

    $olderProject = Project::factory()->create([
        'name' => 'Portal Alpha',
        'client_id' => null,
        'organisation_id' => $ownedOrganisation->id,
        'author_id' => $this->user->id,
        'created_at' => now()->subDay(),
    ]);

    $newerProject = Project::factory()->create([
        'name' => 'Portal Beta',
        'client_id' => null,
        'organisation_id' => $ownedOrganisation->id,
        'author_id' => $this->user->id,
        'created_at' => now(),
    ]);

    Project::factory()->create([
        'name' => 'Backoffice',
        'client_id' => null,
        'organisation_id' => $ownedOrganisation->id,
        'author_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('projects.index', [
            'search' => 'Portal',
            'sort_by' => 'oldest',
        ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Projects')
        ->has('projects.data', 2)
        ->where('projects.data.0.id', $olderProject->id)
        ->where('projects.data.1.id', $newerProject->id)
        ->where('filters.search', 'Portal')
        ->where('filters.sort_by', 'oldest')
    );
});

test('projects index can be scoped by organisation route', function () {
    $firstOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $secondOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $scopedProject = Project::factory()->create([
        'name' => 'Scoped Project',
        'client_id' => null,
        'organisation_id' => $firstOrganisation->id,
        'author_id' => $this->user->id,
    ]);
    Project::factory()->create([
        'name' => 'Other Project',
        'client_id' => null,
        'organisation_id' => $secondOrganisation->id,
        'author_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.projects', $firstOrganisation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Projects')
        ->has('projects.data', 1)
        ->where('projects.data.0.id', $scopedProject->id)
        ->where('filters.organisation_id', $firstOrganisation->id)
    );
});

test('projects organisation route is hidden from users without organisation access', function () {
    $otherOrganisation = Organisation::factory()->create();

    $this->actingAs($this->user)
        ->get(route('organisation.projects', $otherOrganisation))
        ->assertNotFound();
});

test('organisation members only see projects with explicit project access', function () {
    $owner = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
    ]);

    OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $this->user->id,
        'user_email' => $this->user->email,
        'user_name' => $this->user->name,
    ]);

    $visibleProject = Project::factory()->create([
        'name' => 'Visible Shared Project',
        'client_id' => null,
        'organisation_id' => $organisation->id,
        'author_id' => $owner->id,
    ]);
    Project::factory()->create([
        'name' => 'Hidden Organisation Project',
        'client_id' => null,
        'organisation_id' => $organisation->id,
        'author_id' => $owner->id,
    ]);

    ProjectUser::create([
        'project_id' => $visibleProject->id,
        'user_id' => $this->user->id,
        'user_email' => $this->user->email,
        'user_name' => $this->user->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.projects', $organisation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Projects')
        ->has('projects.data', 1)
        ->where('projects.data.0.id', $visibleProject->id)
    );
});

test('project managers can generate api tokens', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => null,
        'token' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('projects.api-token', $project));

    $response->assertOk();
    $response->assertJsonPath('project_id', $project->id);

    expect($project->fresh()->token)->not->toBeNull();
});

test('shared project members cannot generate api tokens', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => null,
        'token' => null,
    ]);

    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $this->user->id,
        'user_email' => $this->user->email,
        'user_name' => $this->user->name,
        'registration_status' => 'registered',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('projects.api-token', $project))
        ->assertForbidden();

    expect($project->fresh()->token)->toBeNull();
});

test('projects cannot be created for inaccessible organisations', function () {
    $otherOrganisation = Organisation::factory()->create();

    $this->actingAs($this->user)
        ->postJson(route('projects.store'), [
            'name' => 'Blocked Project',
            'organisation_id' => $otherOrganisation->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('organisation_id');

    $this->assertDatabaseMissing('projects', [
        'name' => 'Blocked Project',
        'organisation_id' => $otherOrganisation->id,
    ]);
});

test('projects cannot be created for inaccessible clients', function () {
    $otherOrganisation = Organisation::factory()->create();
    $client = Client::factory()->create([
        'organisation_id' => $otherOrganisation->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('projects.store'), [
            'name' => 'Blocked Client Project',
            'client_id' => $client->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');

    $this->assertDatabaseMissing('projects', [
        'name' => 'Blocked Client Project',
        'client_id' => $client->id,
    ]);
});

test('projects the user cannot manage cannot be updated or deleted', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => null,
        'name' => 'Protected Project',
    ]);

    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $this->user->id,
        'user_email' => $this->user->email,
        'user_name' => $this->user->name,
        'registration_status' => 'registered',
    ]);

    $this->actingAs($this->user)
        ->putJson(route('projects.update', $project), [
            'name' => 'Changed Project',
        ])
        ->assertForbidden();

    $this->actingAs($this->user)
        ->deleteJson(route('projects.destroy', $project))
        ->assertForbidden();

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'name' => 'Protected Project',
    ]);
});
