<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_task_via_web_form(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('tasks.store'), [
            'title' => 'Sample Task',
            'description' => 'Optional description',
            'project_id' => $project->id,
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'title' => 'Sample Task',
            'description' => 'Optional description',
            'project_id' => $project->id,
            'author_id' => $user->id,
        ]);
    }

    public function test_it_creates_task_via_api_using_project_user_token()
    {
        $project = \App\Models\Project::factory()->create();
        $user = \App\Models\User::factory()->create();

        $this->withoutExceptionHandling();

        $this->actingAs($user, 'sanctum');

        $response = $this
            ->postJson(route('tasks.store'), [
                'title' => 'From external project',
                'description' => 'Sent via API',
                'project_id' => $project->id,
                'user_id' => 4,
                'user_name' => 'External Project User',
                'user_email' => 'john@example.com'
            ]);

        $response->assertStatus(201);

        $task = \App\Models\Task::where('title', 'From external project')->first();

        $this->assertDatabaseHas('tasks', [
            'title' => 'From external project',
            'project_id' => $project->id,
            'project_user_id' => $task->projectUser->id,
        ]);
    }

}
