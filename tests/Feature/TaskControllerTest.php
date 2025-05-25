<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_index_displays_tasks()
    {
        // Create a project owned by the user
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        // Create tasks for the project
        $tasks = Task::factory()->count(3)->create([
            'project_id' => $project->id,
        ]);

        // Set the submitter for each task
        foreach ($tasks as $task) {
            $task->submitter()->associate($this->user)->save();
        }

        $response = $this->actingAs($this->user)
            ->get(route('tasks.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->has('tasks.data', 3)
        );
    }

    public function test_index_filters_tasks_by_project()
    {
        // Create two projects owned by the user
        $project1 = Project::factory()->create([
            'author_id' => $this->user->id
        ]);
        $project2 = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        // Create tasks for each project
        $tasksProject1 = Task::factory()->count(2)->create([
            'project_id' => $project1->id,
        ]);
        $tasksProject2 = Task::factory()->count(3)->create([
            'project_id' => $project2->id,
        ]);

        // Set the submitter for each task
        foreach ($tasksProject1 as $task) {
            $task->submitter()->associate($this->user)->save();
        }
        foreach ($tasksProject2 as $task) {
            $task->submitter()->associate($this->user)->save();
        }

        $response = $this->actingAs($this->user)
            ->get(route('tasks.index', ['project_id' => $project1->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->has('tasks.data', 2)
        );
    }

    public function test_create_displays_form()
    {
        $response = $this->actingAs($this->user)
            ->get(route('tasks.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Tasks/Create')
            ->has('projects')
        );
    }

    public function test_store_creates_new_task()
    {
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        $taskData = [
            'title' => 'Test Task',
            'description' => 'Test Description',
            'project_id' => $project->id,
            'priority' => 'high',
            'status' => 'pending'
        ];

        $response = $this->actingAs($this->user)
            ->post(route('tasks.store'), $taskData);

        $response->assertRedirect(route('tasks.index'));
        $response->assertSessionHas('success', 'Task created successfully.');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Test Task',
            'description' => 'Test Description',
            'project_id' => $project->id,
            'priority' => 'high',
            'status' => 'pending'
        ]);

        // Check that the submitter is set correctly
        $task = Task::where('title', 'Test Task')->first();
        $this->assertEquals($this->user->id, $task->submitter->id);
        $this->assertEquals(User::class, $task->submitter_type);
    }

    public function test_edit_displays_form()
    {
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
        ]);
        $task->submitter()->associate($this->user)->save();

        $response = $this->actingAs($this->user)
            ->get(route('tasks.edit', $task));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Tasks/Edit')
            ->has('task')
            ->has('project')
        );
    }

    public function test_update_updates_task()
    {
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Original Title',
            'status' => 'pending',
            'priority' => 'low'
        ]);
        $task->submitter()->associate($this->user)->save();

        $updateData = [
            'title' => 'Updated Title',
            'status' => 'in_progress',
            'priority' => 'high'
        ];

        $response = $this->actingAs($this->user)
            ->put(route('tasks.update', $task), $updateData);

        $response->assertRedirect(route('tasks.index'));
        $response->assertSessionHas('success', 'Task updated successfully.');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Title',
            'status' => 'in_progress',
            'priority' => 'high'
        ]);
    }

    public function test_destroy_deletes_task()
    {
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
        ]);
        $task->submitter()->associate($this->user)->save();

        $response = $this->actingAs($this->user)
            ->delete(route('tasks.destroy', $task));

        $response->assertRedirect(route('tasks.index'));
        $response->assertSessionHas('success', 'Task deleted successfully.');

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id
        ]);
    }

    public function test_toggle_status_updates_task_status()
    {
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'pending'
        ]);
        $task->submitter()->associate($this->user)->save();

        $response = $this->actingAs($this->user)
            ->patch(route('tasks.toggle-status', $task), [
                'status' => 'completed'
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'completed');
        $response->assertSessionHas('message', 'Task status updated successfully');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed'
        ]);
    }

    public function test_toggle_priority_updates_task_priority()
    {
        $project = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'priority' => 'low'
        ]);
        $task->submitter()->associate($this->user)->save();

        $response = $this->actingAs($this->user)
            ->patch(route('tasks.toggle-priority', $task), [
                'priority' => 'high'
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('priority', 'high');
        $response->assertSessionHas('message', 'Task priority updated successfully');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'priority' => 'high'
        ]);
    }
}
