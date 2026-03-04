<?php

use App\Models\Project;
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
        ->has('charts.status', 5)
        ->has('charts.priority', 3)
        ->has('charts.throughput', 8)
        ->has('charts.environments')
        ->has('charts.projects')
    );
});
