<?php

use App\Models\Client;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
});

test('dashboard returns task metrics and chart datasets', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $user->id,
    ]);

    $pending = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);
    $inProgress = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'in-progress',
        'priority' => 'medium',
    ]);
    $completed = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'completed',
        'priority' => 'low',
    ]);

    $pending->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/production',
    ]);
    $inProgress->metadata()->create([
        'environment' => 'staging',
        'url' => 'https://example.com/staging',
    ]);
    $completed->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/production-completed',
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('metrics.total', 3)
        ->where('metrics.pending', 1)
        ->where('metrics.in_progress', 1)
        ->where('metrics.completed', 1)
        ->where('metrics.open', 2)
        ->where('metrics.high_priority_open', 1)
        ->has('charts.status', 6)
        ->has('charts.priority', 3)
        ->has('charts.throughput', 8)
        ->has('charts.environments')
        ->has('charts.projects')
    );
});

test('dashboard task metrics exclude requirement phase items', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $user->id,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);
    $requirement->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('metrics.total', 1)
        ->where('metrics.pending', 1)
        ->where('metrics.open', 1)
        ->where('metrics.high_priority_open', 1)
    );
});

test('dashboard metrics can be scoped to a shared organisation', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    $sharedOrganisation = Organisation::factory()->create([
        'author_id' => $owner->id,
    ]);
    OrganisationUser::create([
        'organisation_id' => $sharedOrganisation->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
    ]);
    $sharedClient = Client::factory()->create([
        'organisation_id' => $sharedOrganisation->id,
    ]);
    $sharedProject = Project::factory()->create([
        'name' => 'Atlas Console',
        'client_id' => $sharedClient->id,
        'author_id' => $owner->id,
    ]);
    ProjectUser::create([
        'project_id' => $sharedProject->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
        'registration_status' => 'registered',
    ]);
    Task::factory()->create([
        'project_id' => $sharedProject->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);

    $otherOrganisation = Organisation::factory()->create([
        'author_id' => $user->id,
    ]);
    $otherClient = Client::factory()->create([
        'organisation_id' => $otherOrganisation->id,
    ]);
    $otherProject = Project::factory()->create([
        'client_id' => $otherClient->id,
        'author_id' => $user->id,
    ]);
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'status' => 'completed',
        'priority' => 'low',
    ]);

    $response = $this->actingAs($user)->get(route('organisation.dashboard', $sharedOrganisation));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('organisation_id', $sharedOrganisation->id)
        ->where('metrics.total', 1)
        ->where('metrics.pending', 1)
        ->where('metrics.completed', 0)
        ->where('metrics.open', 1)
        ->where('metrics.high_priority_open', 1)
        ->where('charts.projects.0.project', 'Atlas Console')
        ->where('charts.projects.0.count', 1)
    );
});

test('sidebar organisation shared data only marks the list as having more when it is truncated', function () {
    $user = User::factory()->create();

    collect(['Atlas Commerce', 'Cedar Labs', 'Northwind Organisation', 'Northwind Studio', 'QA Org'])
        ->each(fn (string $name) => Organisation::factory()->create([
            'author_id' => $user->id,
            'name' => $name,
        ]));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sidebarOrganisations', 5)
            ->where('sidebarOrganisationsHasMore', false)
        );

    Organisation::factory()->create([
        'author_id' => $user->id,
        'name' => 'Zephyr Console',
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sidebarOrganisations', 5)
            ->where('sidebarOrganisationsHasMore', true)
        );
});

test('sidebar organisation shared data includes the active scoped organisation outside the first page', function () {
    $user = User::factory()->create();

    collect(['Atlas Commerce', 'Cedar Labs', 'Northwind Organisation', 'Northwind Studio', 'QA Org'])
        ->each(fn (string $name) => Organisation::factory()->create([
            'author_id' => $user->id,
            'name' => $name,
        ]));

    $activeOrganisation = Organisation::factory()->create([
        'author_id' => $user->id,
        'name' => 'Zephyr Console',
    ]);

    $this->actingAs($user)
        ->get(route('organisation.dashboard', $activeOrganisation))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sidebarOrganisations', 6)
            ->where('sidebarOrganisations.5.id', $activeOrganisation->id)
            ->where('sidebarOrganisations.5.name', 'Zephyr Console')
            ->where('sidebarOrganisationsHasMore', true)
        );
});
