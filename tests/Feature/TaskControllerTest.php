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

        $task = \App\Models\Task::where('title', 'Sample Task')->first();

        // Check that the task was created with the correct attributes
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Sample Task',
            'description' => 'Optional description',
            'project_id' => $project->id,
            'author_id' => $user->id,
        ]);

        // Check that the task is associated with a project user
        $this->assertNotNull($task->project_user_id);
        $this->assertEquals($user->id, $task->projectUser->user_id);

        // Check that the task is not flagged as an external submission
        $this->assertFalse($task->isExternallySubmitted());
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

    public function test_it_shows_task_via_api()
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $task->id,
            'title' => $task->title,
            'project_id' => $project->id,
        ]);
    }

    public function test_it_updates_task_via_api()
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->put(route('tasks.update', $task), [
            'title' => 'Updated Task Title',
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Task Title',
        ]);
    }

    public function test_it_deletes_task()
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->delete(route('tasks.destroy', $task));

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id,
        ]);
    }

    public function test_it_lists_tasks_for_project_via_api()
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        // Create a ProjectUser record
        $projectUser = \App\Models\ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
        ]);

        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
            'project_user_id' => $projectUser->id,
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->getJson("/api/projects/{$project->id}/tasks");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
            'total',
        ]);
    }

    public function test_it_toggles_task_status()
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum');

        // Update to in_progress
        $response = $this->patchJson("/api/tasks/{$task->id}/toggle-status", [
            'status' => 'in_progress'
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'in_progress',
            'message' => 'Task status updated successfully'
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
        ]);

        // Update to completed
        $response = $this->patchJson("/api/tasks/{$task->id}/toggle-status", [
            'status' => 'completed'
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'message' => 'Task status updated successfully'
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed',
        ]);

        // Update back to pending
        $response = $this->patchJson("/api/tasks/{$task->id}/toggle-status", [
            'status' => 'pending'
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'pending',
            'message' => 'Task status updated successfully'
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_toggles_task_priority()
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create([
            'project_id' => $project->id,
            'author_id' => $user->id,
            'priority' => 'medium',
        ]);

        $this->actingAs($user, 'sanctum');

        // Update to high priority
        $response = $this->patchJson("/api/tasks/{$task->id}/toggle-priority", [
            'priority' => 'high'
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'priority' => 'high',
            'message' => 'Task priority updated successfully'
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'priority' => 'high',
        ]);

        // Update to low priority
        $response = $this->patchJson("/api/tasks/{$task->id}/toggle-priority", [
            'priority' => 'low'
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'priority' => 'low',
            'message' => 'Task priority updated successfully'
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'priority' => 'low',
        ]);
    }

    public function test_it_creates_task_with_external_submitter_info()
    {
        $user = User::factory()->create(); // API token owner
        $project = Project::factory()->create();

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson(route('tasks.store'), [
            'title' => 'External Task',
            'description' => 'Task from external submitter',
            'project_id' => $project->id,
            'submitter_name' => 'Tom',
            'source_url' => 'https://project-a.example.com',
            'environment' => 'production',
        ]);

        $response->assertStatus(201);

        $task = \App\Models\Task::where('title', 'External Task')->first();

        // Check that the task was created
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'External Task',
            'description' => 'Task from external submitter',
            'project_id' => $project->id,
            'author_id' => $user->id,
        ]);

        // Check that the external task source was created
        $this->assertDatabaseHas('external_task_sources', [
            'task_id' => $task->id,
            'submitter_name' => 'Tom',
            'source_url' => 'https://project-a.example.com',
            'environment' => 'production',
        ]);

        // Check that the task doesn't have a project_user_id
        $this->assertNull($task->project_user_id);
    }

    public function test_it_updates_task_with_external_submitter_info()
    {
        $user = User::factory()->create(); // API token owner
        $project = Project::factory()->create();

        // First create a task with external submitter info
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson(route('tasks.store'), [
            'title' => 'External Task',
            'description' => 'Task from external submitter',
            'project_id' => $project->id,
            'submitter_name' => 'Tom',
            'source_url' => 'https://project-a.example.com',
            'environment' => 'production',
        ]);

        $task = \App\Models\Task::where('title', 'External Task')->first();

        // Now update the task with new external submitter info
        $response = $this->putJson("/api/tasks/{$task->id}", [
            'title' => 'Updated External Task',
            'description' => 'Updated task from external submitter',
            'submitter_name' => 'Tom Smith',
            'source_url' => 'https://project-b.example.com',
            'environment' => 'staging',
        ]);

        $response->assertStatus(200);

        // Check that the task was updated
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated External Task',
            'description' => 'Updated task from external submitter',
        ]);

        // Check that the external task source was updated
        $this->assertDatabaseHas('external_task_sources', [
            'task_id' => $task->id,
            'submitter_name' => 'Tom Smith',
            'source_url' => 'https://project-b.example.com',
            'environment' => 'staging',
        ]);

        // Check that the task still doesn't have a project_user_id
        $this->assertNull($task->fresh()->project_user_id);
    }
}
