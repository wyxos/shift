<?php

use App\Models\Client;
use App\Models\Organisation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('clients index only includes accessible clients and exposes organisation names', function () {
    $ownedOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Acme Org',
    ]);

    $otherOrganisation = Organisation::factory()->create([
        'name' => 'Other Org',
    ]);

    $firstClient = Client::factory()->create([
        'name' => 'Acme Client',
        'organisation_id' => $ownedOrganisation->id,
    ]);

    $secondClient = Client::factory()->create([
        'name' => 'Beta Client',
        'organisation_id' => $ownedOrganisation->id,
    ]);

    Client::factory()->create([
        'name' => 'Hidden Client',
        'organisation_id' => $otherOrganisation->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('clients.index', ['sort_by' => 'name']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients')
        ->has('clients.data', 2)
        ->where('clients.data.0.id', $firstClient->id)
        ->where('clients.data.0.organisation_name', 'Acme Org')
        ->where('clients.data.1.id', $secondClient->id)
        ->where('clients.data.1.organisation_name', 'Acme Org')
        ->where('filters.sort_by', 'name')
        ->has('organisations', 1)
    );
});

test('clients index filters by search and sorts oldest first', function () {
    $ownedOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $olderClient = Client::factory()->create([
        'name' => 'Alpha Client',
        'organisation_id' => $ownedOrganisation->id,
        'created_at' => now()->subDay(),
    ]);

    $newerClient = Client::factory()->create([
        'name' => 'Alpha Client New',
        'organisation_id' => $ownedOrganisation->id,
        'created_at' => now(),
    ]);

    Client::factory()->create([
        'name' => 'Zeta Client',
        'organisation_id' => $ownedOrganisation->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('clients.index', [
            'search' => 'Alpha',
            'sort_by' => 'oldest',
        ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients')
        ->has('clients.data', 2)
        ->where('clients.data.0.id', $olderClient->id)
        ->where('clients.data.1.id', $newerClient->id)
        ->where('filters.search', 'Alpha')
        ->where('filters.sort_by', 'oldest')
    );
});
