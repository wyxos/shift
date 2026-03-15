<?php

use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->create([
        'author_id' => $this->owner->id,
        'token' => 'project-token',
    ]);
});

test('project managers can register a project environment', function () {
    Sanctum::actingAs($this->owner);

    $response = $this->postJson(route('api.project-environments.register'), [
        'project' => $this->project->token,
        'environment' => 'staging',
        'url' => 'https://client-staging.test',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.project_id', $this->project->id)
        ->assertJsonPath('data.key', 'staging')
        ->assertJsonPath('data.label', 'Staging')
        ->assertJsonPath('data.url', 'https://client-staging.test');

    $this->assertDatabaseHas('project_environments', [
        'project_id' => $this->project->id,
        'environment' => 'staging',
        'url' => 'https://client-staging.test',
    ]);
});

test('ordinary project members cannot register project environments', function () {
    $member = User::factory()->create();
    ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $member->id,
        'user_email' => $member->email,
        'user_name' => $member->name,
        'registration_status' => 'registered',
    ]);

    Sanctum::actingAs($member);

    $response = $this->postJson(route('api.project-environments.register'), [
        'project' => $this->project->token,
        'environment' => 'staging',
        'url' => 'https://client-staging.test',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('project_environments', [
        'project_id' => $this->project->id,
        'environment' => 'staging',
    ]);
});

test('registering an existing project environment updates its url', function () {
    Sanctum::actingAs($this->owner);

    $this->project->environments()->create([
        'environment' => 'production',
        'url' => 'https://old-client.test',
    ]);

    $response = $this->postJson(route('api.project-environments.register'), [
        'project' => $this->project->token,
        'environment' => 'production',
        'url' => 'https://new-client.test',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.key', 'production')
        ->assertJsonPath('data.url', 'https://new-client.test');

    expect($this->project->environments()->where('environment', 'production')->count())->toBe(1);
    $this->assertDatabaseHas('project_environments', [
        'project_id' => $this->project->id,
        'environment' => 'production',
        'url' => 'https://new-client.test',
    ]);
});

test('organisation owners can register project environments for nested projects', function () {
    $organisationOwner = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $organisationOwner->id,
    ]);
    $client = Client::factory()->create([
        'organisation_id' => $organisation->id,
    ]);
    $project = Project::factory()->create([
        'author_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'token' => 'org-project-token',
    ]);

    Sanctum::actingAs($organisationOwner);

    $response = $this->postJson(route('api.project-environments.register'), [
        'project' => $project->token,
        'environment' => 'production',
        'url' => 'https://client-production.test',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('project_environments', [
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client-production.test',
    ]);
});
