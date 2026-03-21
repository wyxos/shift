<?php

use App\Models\Client;
use App\Models\Organisation;
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
