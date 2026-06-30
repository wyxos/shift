<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\User;

test('standalone clients page is retired', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/clients')
        ->assertNotFound();
});

test('standalone external users page is retired', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/external-users')
        ->assertNotFound();
});

test('external user edit page is retired', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $user->id,
    ]);
    $externalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->actingAs($user)
        ->get("/external-users/{$externalUser->id}/edit")
        ->assertNotFound();
});

test('public users page is retired', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/users')
        ->assertNotFound();
});
