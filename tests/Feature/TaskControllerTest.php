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
        'status' => 'pending',
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
        ->has('projects', 1)
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

    $response = $this->actingAs($this->user)->get(route('tasks.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('filters.status', ['pending', 'in-progress', 'awaiting-feedback'])
    );
});

test('tasks v2 show includes created and updated timestamps', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Created at task',
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->getJson(route('tasks.v2.show', $task));

    $response->assertOk();
    $response->assertJsonPath('id', $task->id);
    $response->assertJsonStructure(['created_at', 'updated_at']);
    expect($response->json('created_at'))->toBeString()->not->toBeEmpty();
    expect($response->json('updated_at'))->toBeString()->not->toBeEmpty();
});

test('tasks v2 can filter tasks by environment', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $stagingTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $productionTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);

    $stagingTask->submitter()->associate($this->user)->save();
    $productionTask->submitter()->associate($this->user)->save();

    $stagingTask->metadata()->create([
        'environment' => 'staging',
        'url' => 'https://example.com/staging',
    ]);
    $productionTask->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/production',
    ]);

    $response = $this->actingAs($this->user)->get(route('tasks.index', [
        'environment' => 'staging',
    ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $stagingTask->id)
        ->where('tasks.data.0.environment', 'staging')
        ->where('filters.environment', 'staging')
    );
});

test('tasks v2 defaults to sorting by updated_at descending', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $olderTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'updated_at' => now()->subHour(),
    ]);
    $newerTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'updated_at' => now(),
    ]);

    $olderTask->submitter()->associate($this->user)->save();
    $newerTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->get(route('tasks.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->where('tasks.data.0.id', $newerTask->id)
        ->where('tasks.data.1.id', $olderTask->id)
        ->where('filters.sort_by', 'updated_at')
    );
});

test('tasks v2 can sort tasks by priority', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $lowTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'low',
    ]);
    $mediumTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $highTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);

    $lowTask->submitter()->associate($this->user)->save();
    $mediumTask->submitter()->associate($this->user)->save();
    $highTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->get(route('tasks.index', [
        'sort_by' => 'priority',
    ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->where('tasks.data.0.id', $highTask->id)
        ->where('tasks.data.1.id', $mediumTask->id)
        ->where('tasks.data.2.id', $lowTask->id)
        ->where('filters.sort_by', 'priority')
    );
});

test('index filters tasks by status query', function () {
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

    $response = $this->actingAs($this->user)
        ->get(route('tasks.index', ['status' => ['pending']]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $pendingTask->id)
    );
});

test('create route redirects to tasks index', function () {
    $response = $this->actingAs($this->user)
        ->get(route('tasks.create'));

    $response->assertRedirect(route('tasks.index'));
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

test('store v2 creates new task and returns json', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $taskData = [
        'title' => 'JSON Task',
        'description' => '<p>Rich description</p>',
        'project_id' => $project->id,
        'priority' => 'medium',
    ];

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), $taskData);

    $response->assertCreated();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('data.title', 'JSON Task');
    $response->assertJsonPath('data.description', '<p>Rich description</p>');
    $response->assertJsonPath('data.priority', 'medium');

    $this->assertDatabaseHas('tasks', [
        'title' => 'JSON Task',
        'description' => '<p>Rich description</p>',
        'project_id' => $project->id,
        'priority' => 'medium',
    ]);
});

test('store v2 syncs grouped collaborators and returns them in the response', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://client-app.test/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://client-app.test',
            'environment' => 'production',
            'users' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ], 200),
    ]);

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token',
    ]);
    $project->environments()->create([
        'environment' => 'production',
        'url' => 'https://client-app.test',
    ]);

    $internalCollaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $internalCollaborator->id,
        'user_email' => $internalCollaborator->email,
        'user_name' => $internalCollaborator->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Task with collaborators',
            'project_id' => $project->id,
            'environment' => 'production',
            'internal_collaborator_ids' => [$internalCollaborator->id],
            'external_collaborators' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ]);

    $response->assertCreated();

    $task = Task::where('title', 'Task with collaborators')->firstOrFail();
    expect($task->metadata?->environment)->toBe('production');
    $externalUser = ExternalUser::where('project_id', $project->id)
        ->where('external_id', 'client-7')
        ->first();

    expect($externalUser)->not->toBeNull();
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'user_id' => $internalCollaborator->id,
        'kind' => 'internal',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'external_user_id' => $externalUser->id,
        'kind' => 'external',
    ]);

    $response->assertJsonPath('data.internal_collaborators.0.id', $internalCollaborator->id);
    $response->assertJsonPath('data.external_collaborators.0.id', 'client-7');
});

test('store v2 persists temp attachments and rewrites editor temp urls', function () {
    \Illuminate\Support\Facades\Storage::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $tempIdentifier = 'task-create-temp';
    $tempPath = "temp_attachments/{$tempIdentifier}/task-screenshot.png";

    \Illuminate\Support\Facades\Storage::put($tempPath, 'image-bytes');
    \Illuminate\Support\Facades\Storage::put(
        "{$tempPath}.meta",
        json_encode(['original_filename' => 'Task Screenshot.png'], JSON_THROW_ON_ERROR),
    );

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Task with inline upload',
        'description' => "<p><img src=\"/attachments/temp/{$tempIdentifier}/task-screenshot.png\"></p>",
        'project_id' => $project->id,
        'priority' => 'medium',
        'temp_identifier' => $tempIdentifier,
    ]);

    $response->assertCreated();

    $task = Task::where('title', 'Task with inline upload')->firstOrFail();
    $attachment = $task->attachments()->first();

    expect($attachment)->not->toBeNull();
    expect($attachment->original_filename)->toBe('Task Screenshot.png');

    $task->refresh();
    $downloadUrl = route('attachments.download', $attachment, false);

    expect($task->description)->toContain($downloadUrl);
    \Illuminate\Support\Facades\Storage::assertExists($attachment->path);
    \Illuminate\Support\Facades\Storage::assertMissing($tempPath);
    \Illuminate\Support\Facades\Storage::assertMissing("{$tempPath}.meta");
});

test('store v2 rejects inaccessible project ids', function () {
    $otherUsersProject = Project::factory()->create();

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Blocked task',
        'project_id' => $otherUsersProject->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('project_id');
});

test('store v2 requires an environment before syncing external collaborators', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token',
    ]);

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Needs environment',
        'project_id' => $project->id,
        'external_collaborators' => [
            [
                'id' => 'client-7',
                'name' => 'Client User',
                'email' => 'client@example.com',
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('environment');
});

test('internal collaborator can view task details without broader project visibility', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Collaborator visible task',
    ]);
    $task->submitter()->associate($owner)->save();
    $task->internalCollaborators()->attach($this->user->id);

    $response = $this->actingAs($this->user)->getJson(route('tasks.v2.show', $task));

    $response
        ->assertOk()
        ->assertJsonPath('id', $task->id)
        ->assertJsonPath('internal_collaborators.0.id', $this->user->id);
});

test('collaborator candidate endpoint requires an environment before external lookup', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $registeredUser = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $registeredUser->id,
        'user_email' => $registeredUser->email,
        'user_name' => $registeredUser->name,
        'registration_status' => 'registered',
    ]);

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => null,
        'user_email' => 'pending@example.com',
        'user_name' => 'Pending Invite',
        'registration_status' => 'pending',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('tasks.v2.collaborators', $project));

    $response
        ->assertOk()
        ->assertJsonPath('external_available', false)
        ->assertJsonPath('external_error', 'Select an environment before tagging external collaborators.')
        ->assertJsonCount(2, 'internal');
    $response->assertJsonFragment(['id' => $this->user->id]);
    $response->assertJsonFragment(['id' => $registeredUser->id]);
});

test('collaborator candidate endpoint uses the selected environment registration for external lookup', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://staging-client.test/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://staging-client.test',
            'environment' => 'staging',
            'users' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ], 200),
    ]);

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token',
    ]);
    $project->environments()->create([
        'environment' => 'staging',
        'url' => 'https://staging-client.test',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('tasks.v2.collaborators', [
            'project' => $project,
            'environment' => 'staging',
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('external_available', true)
        ->assertJsonPath('external.0.id', 'client-7');
});

test('edit route redirects to v2 task view', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.edit', $task));

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));
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

test('task creation notifies the submitter and explicitly tagged collaborators only', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $taggedCollaborator = User::factory()->create();
    $untaggedProjectUser = User::factory()->create();

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $taggedCollaborator->id,
        'user_email' => $taggedCollaborator->email,
        'user_name' => $taggedCollaborator->name,
        'registration_status' => 'registered',
    ]);

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $untaggedProjectUser->id,
        'user_email' => $untaggedProjectUser->email,
        'user_name' => $untaggedProjectUser->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Notification Policy Task',
            'project_id' => $project->id,
            'internal_collaborator_ids' => [$taggedCollaborator->id],
        ]);

    $response->assertCreated();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $this->user,
        \App\Notifications\TaskCreationNotification::class,
    );

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $taggedCollaborator,
        \App\Notifications\TaskCreationNotification::class,
    );

    \Illuminate\Support\Facades\Notification::assertNotSentTo(
        $untaggedProjectUser,
        \App\Notifications\TaskCreationNotification::class,
    );
});

test('task creation notifies tagged internal collaborators', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $collaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $collaborator->id,
        'user_email' => $collaborator->email,
        'user_name' => $collaborator->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Tagged internal collaborator task',
            'project_id' => $project->id,
            'internal_collaborator_ids' => [$collaborator->id],
        ]);

    $response->assertCreated();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $collaborator,
        \App\Notifications\TaskCreationNotification::class,
    );
});

test('store v2 dispatches create notification jobs for tagged external collaborators', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://client-app.test/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://client-app.test',
            'environment' => 'local',
            'users' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ], 200),
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token-external-create',
    ]);
    $project->environments()->create([
        'environment' => 'local',
        'url' => 'https://client-app.test',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Tagged external collaborator task',
            'project_id' => $project->id,
            'environment' => 'local',
            'external_collaborators' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ]);

    $response->assertCreated();

    $task = Task::query()->where('title', 'Tagged external collaborator task')->firstOrFail();
    $externalUser = ExternalUser::query()->where('email', 'client@example.com')->firstOrFail();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\NotifyExternalUser::class, function ($job) use ($task, $externalUser) {
        return $job->taskId === $task->id
            && $job->externalUserId === $externalUser->id;
    });
});

test('edit route redirects for internal task', function () {
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

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));
});

test('edit route redirects for external submitted task', function () {
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

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));
});

test('attached internal collaborator can update collaborators through the v2 collaborator endpoint', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $manager = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $manager->id,
        'user_email' => $manager->email,
        'user_name' => $manager->name,
        'registration_status' => 'registered',
    ]);

    $newCollaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $newCollaborator->id,
        'user_email' => $newCollaborator->email,
        'user_name' => $newCollaborator->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Collaborator managed task',
    ]);
    $task->submitter()->associate($owner)->save();
    $task->internalCollaborators()->attach($manager->id);

    $response = $this->actingAs($manager)
        ->patchJson(route('tasks.v2.collaborators.update', $task), [
            'internal_collaborator_ids' => [$manager->id, $newCollaborator->id],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('task.can_manage_collaborators', true)
        ->assertJsonPath('task.internal_collaborators.1.id', $newCollaborator->id);

    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'user_id' => $newCollaborator->id,
    ]);
});

test('visible project member without task attachment cannot update collaborators', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $viewer = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $viewer->id,
        'user_email' => $viewer->email,
        'user_name' => $viewer->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($owner)->save();

    $response = $this->actingAs($viewer)
        ->patchJson(route('tasks.v2.collaborators.update', $task), [
            'internal_collaborator_ids' => [$viewer->id],
        ]);

    $response->assertForbidden();
});

test('adding an internal collaborator to an existing task sends collaborator added notification', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $collaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $collaborator->id,
        'user_email' => $collaborator->email,
        'user_name' => $collaborator->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Existing task',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->patchJson(route('tasks.v2.collaborators.update', $task), [
            'internal_collaborator_ids' => [$collaborator->id],
        ]);

    $response->assertOk();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $collaborator,
        \App\Notifications\TaskCollaboratorAddedNotification::class,
    );
});

