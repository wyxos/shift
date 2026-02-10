<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('index displays tasks', function () {
    // Create a project owned by the user
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
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
});

test('tasks v2 defaults to excluding completed tasks', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $pendingTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $completedTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'completed',
    ]);

    $pendingTask->submitter()->associate($this->user)->save();
    $completedTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->get(route('tasks.v2'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/IndexV2')
        ->has('tasks', 1)
        ->where('filters.status', ['pending', 'in-progress', 'awaiting-feedback'])
    );
});

test('index filters tasks by project', function () {
    // Create two projects owned by the user
    $project1 = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $project2 = Project::factory()->create([
        'author_id' => $this->user->id,
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
});

test('create displays form', function () {
    $response = $this->actingAs($this->user)
        ->get(route('tasks.create'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Create')
        ->has('projects')
    );
});

test('store creates new task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $taskData = [
        'title' => 'Test Task',
        'description' => 'Test Description',
        'project_id' => $project->id,
        'priority' => 'high',
        'status' => 'pending',
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
        'status' => 'pending',
    ]);

    // Check that the submitter is set correctly
    $task = Task::where('title', 'Test Task')->first();
    expect($task->submitter->id)->toEqual($this->user->id);
    expect($task->submitter_type)->toEqual(User::class);
});

test('edit displays form', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
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
});

test('update updates task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Original Title',
        'status' => 'pending',
        'priority' => 'low',
    ]);
    $task->submitter()->associate($this->user)->save();

    $updateData = [
        'title' => 'Updated Title',
        'status' => 'in-progress',
        'priority' => 'high',
    ];

    $response = $this->actingAs($this->user)
        ->put(route('tasks.update', $task), $updateData);

    $response->assertRedirect(route('tasks.index'));
    $response->assertSessionHas('success', 'Task updated successfully.');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'Updated Title',
        'status' => 'in-progress',
        'priority' => 'high',
    ]);
});

test('destroy deletes task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    // Create attachments for the task
    $attachment1 = \App\Models\Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'test-document1.pdf',
        'path' => "attachments/{$task->id}/test-document1.pdf",
    ]);

    $attachment2 = \App\Models\Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'test-document2.pdf',
        'path' => "attachments/{$task->id}/test-document2.pdf",
    ]);

    // Create fake files in storage
    \Illuminate\Support\Facades\Storage::put($attachment1->path, 'test content 1');
    \Illuminate\Support\Facades\Storage::put($attachment2->path, 'test content 2');

    // Verify files exist before deletion
    \Illuminate\Support\Facades\Storage::assertExists($attachment1->path);
    \Illuminate\Support\Facades\Storage::assertExists($attachment2->path);

    $response = $this->actingAs($this->user)
        ->delete(route('tasks.destroy', $task));

    $response->assertRedirect(route('tasks.index'));
    $response->assertSessionHas('success', 'Task deleted successfully.');

    // Verify task is deleted
    $this->assertDatabaseMissing('tasks', [
        'id' => $task->id,
    ]);

    // Verify attachments are deleted from database
    $this->assertDatabaseMissing('attachments', [
        'id' => $attachment1->id,
    ]);
    $this->assertDatabaseMissing('attachments', [
        'id' => $attachment2->id,
    ]);

    // Verify files are deleted from storage
    \Illuminate\Support\Facades\Storage::assertMissing($attachment1->path);
    \Illuminate\Support\Facades\Storage::assertMissing($attachment2->path);
});

test('toggle status updates task status', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->patch(route('tasks.toggle-status', $task), [
            'status' => 'completed',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'completed');
    $response->assertSessionHas('message', 'Task status updated successfully');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'completed',
    ]);
});

test('toggle status updates task status to awaiting feedback', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->patch(route('tasks.toggle-status', $task), [
            'status' => 'awaiting-feedback',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'awaiting-feedback');
    $response->assertSessionHas('message', 'Task status updated successfully');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'awaiting-feedback',
    ]);
});

test('toggle priority updates task priority', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'priority' => 'low',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->patch(route('tasks.toggle-priority', $task), [
            'priority' => 'high',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('priority', 'high');
    $response->assertSessionHas('message', 'Task priority updated successfully');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'priority' => 'high',
    ]);
});

test('task creation sends notifications', function () {
    \Illuminate\Support\Facades\Notification::fake();

    // Create a project owned by the user
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create additional users with access to the project
    $projectUser1 = User::factory()->create();
    $projectUser2 = User::factory()->create();

    // Give these users access to the project
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $projectUser1->id,
        'user_email' => $projectUser1->email,
        'user_name' => $projectUser1->name,
        'registration_status' => 'registered',
    ]);

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $projectUser2->id,
        'user_email' => $projectUser2->email,
        'user_name' => $projectUser2->name,
        'registration_status' => 'registered',
    ]);

    // Create a task for the project
    $taskData = [
        'title' => 'Notification Test Task',
        'description' => 'This task should trigger notifications',
        'project_id' => $project->id,
        'priority' => 'high',
        'status' => 'pending',
    ];

    $response = $this->actingAs($this->user)
        ->post(route('tasks.store'), $taskData);

    $response->assertRedirect(route('tasks.index'));
    $response->assertSessionHas('success', 'Task created successfully.');

    // Get the created task
    $task = \App\Models\Task::where('title', 'Notification Test Task')->first();

    // Assert that notifications were NOT sent to the task creator (who is also the project owner)
    \Illuminate\Support\Facades\Notification::assertNotSentTo(
        [$this->user],
        \App\Notifications\TaskCreationNotification::class
    );

    // Assert that notifications were sent to users with access to the project
    \Illuminate\Support\Facades\Notification::assertSentTo(
        [$projectUser1, $projectUser2],
        \App\Notifications\TaskCreationNotification::class
    );
});

test('edit shows all external users for internal task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create external users with different environments
    $externalUser1 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'name' => 'Production User',
    ]);
    $externalUser2 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'staging',
        'name' => 'Staging User',
    ]);

    // Create a task submitted by an internal user
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.edit', $task));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Edit')
        ->has('projectExternalUsers', 2)
        ->where('projectExternalUsers.0.name', 'Production User')
        ->where('projectExternalUsers.1.name', 'Staging User')
    );
});

test('edit shows only same environment external users for external task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create external users with different environments
    $externalUser1 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'name' => 'Production User',
    ]);
    $externalUser2 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'staging',
        'name' => 'Staging User',
    ]);
    $externalUser3 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'name' => 'Another Production User',
    ]);

    // Create a task submitted by an external user from production
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($externalUser1)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.edit', $task));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Edit')
        ->has('projectExternalUsers', 2) // Only production users should be shown
        ->where('projectExternalUsers.0.name', 'Production User')
        ->where('projectExternalUsers.1.name', 'Another Production User')
    );
});
