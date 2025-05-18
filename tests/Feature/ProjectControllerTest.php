<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_projects_via_api()
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create(['author_id' => $user->id]);
        $client = Client::factory()->create(['organisation_id' => $organisation->id]);
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/projects');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
            'total',
        ]);
        $response->assertJsonCount(1, 'data');
    }

    public function test_it_creates_project_via_api()
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create(['author_id' => $user->id]);
        $client = Client::factory()->create(['organisation_id' => $organisation->id]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/projects', [
            'name' => 'New Project',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('projects', [
            'name' => 'New Project',
            'client_id' => $client->id,
        ]);
    }

    public function test_it_updates_project_via_api()
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create(['author_id' => $user->id]);
        $client = Client::factory()->create(['organisation_id' => $organisation->id]);
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->actingAs($user, 'sanctum');

        $response = $this->putJson("/api/projects/{$project->id}", [
            'name' => 'Updated Project Name',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated Project Name',
        ]);
    }

    public function test_it_deletes_project_via_api()
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create(['author_id' => $user->id]);
        $client = Client::factory()->create(['organisation_id' => $organisation->id]);
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->actingAs($user, 'sanctum');

        $response = $this->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('projects', [
            'id' => $project->id,
        ]);
    }
}
