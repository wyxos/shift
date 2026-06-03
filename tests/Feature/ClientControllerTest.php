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

test('clients index can be scoped by organisation route', function () {
    $firstOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $secondOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $scopedClient = Client::factory()->create([
        'name' => 'Scoped Client',
        'organisation_id' => $firstOrganisation->id,
    ]);
    Client::factory()->create([
        'name' => 'Other Client',
        'organisation_id' => $secondOrganisation->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('organisation.clients', $firstOrganisation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients')
        ->has('clients.data', 1)
        ->where('clients.data.0.id', $scopedClient->id)
        ->where('filters.organisation_id', $firstOrganisation->id)
    );
});

test('clients organisation route is hidden from users without organisation access', function () {
    $otherOrganisation = Organisation::factory()->create();

    $this->actingAs($this->user)
        ->get(route('organisation.clients', $otherOrganisation))
        ->assertNotFound();
});

test('clients cannot be created for inaccessible organisations', function () {
    $otherOrganisation = Organisation::factory()->create();

    $this->actingAs($this->user)
        ->postJson(route('clients.store'), [
            'name' => 'Blocked Client',
            'organisation_id' => $otherOrganisation->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('organisation_id');

    $this->assertDatabaseMissing('clients', [
        'name' => 'Blocked Client',
        'organisation_id' => $otherOrganisation->id,
    ]);
});

test('clients in inaccessible organisations cannot be updated or deleted', function () {
    $otherOrganisation = Organisation::factory()->create();
    $client = Client::factory()->create([
        'organisation_id' => $otherOrganisation->id,
        'name' => 'Protected Client',
    ]);

    $this->actingAs($this->user)
        ->putJson(route('clients.update', $client), [
            'name' => 'Changed Client',
        ])
        ->assertForbidden();

    $this->actingAs($this->user)
        ->deleteJson(route('clients.destroy', $client))
        ->assertForbidden();

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'name' => 'Protected Client',
        'organisation_id' => $otherOrganisation->id,
    ]);
});
