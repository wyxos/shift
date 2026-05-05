<?php

use App\Enums\TaskCollaboratorKind;
use App\Mcp\Servers\ShiftServer;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTaskThreadsTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;

test('mcp tools fail closed without an authenticated user', function () {
    Task::factory()->create([
        'title' => 'Hidden task without MCP principal',
    ]);

    ShiftServer::tool(SearchTasksTool::class)
        ->assertHasErrors(['authenticated user']);
});

test('web mcp route requires sanctum authentication', function () {
    $this->getJson('/mcp/shift')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');

    $this->postJson('/mcp/shift', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => [
                'name' => 'pest',
                'version' => '0.0.1',
            ],
        ],
    ])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});

test('web mcp route uses the authenticated sanctum user to scope tools', function () {
    $user = User::factory()->create();
    $visibleProject = Project::factory()->withAuthor($user->id)->create();
    Task::factory()->create([
        'project_id' => $visibleProject->id,
        'title' => 'Visible web MCP task',
    ]);
    Task::factory()->create([
        'title' => 'Hidden web MCP task',
    ]);

    $token = $user->createToken('mcp-web-test', ['mcp:use'])->plainTextToken;

    $initializeResponse = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/mcp/shift', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'pest',
                    'version' => '0.0.1',
                ],
            ],
        ])
        ->assertOk()
        ->assertHeader('MCP-Session-Id');

    $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'MCP-Session-Id' => $initializeResponse->headers->get('MCP-Session-Id'),
        ])
        ->postJson('/mcp/shift', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_tasks',
                'arguments' => [
                    'query' => 'web MCP task',
                ],
            ],
        ])
        ->assertOk()
        ->assertSee('Visible web MCP task')
        ->assertDontSee('Hidden web MCP task');
});

test('web mcp route rejects sanctum tokens without the mcp ability', function () {
    $user = User::factory()->create();
    $token = $user->createToken('regular-api-token')->plainTextToken;

    $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/mcp/shift', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'pest',
                    'version' => '0.0.1',
                ],
            ],
        ])
        ->assertForbidden();
});

test('shift mcp server is registered as a local server', function () {
    expect(app(Registrar::class)->getLocalServer('shift'))->not->toBeNull();
});

test('list projects returns project context without api tokens', function () {
    $owner = User::factory()->create([
        'name' => 'SHIFT Owner',
        'email' => 'owner@example.com',
    ]);

    $project = Project::factory()
        ->withAuthor($owner->id)
        ->create([
            'name' => 'VoidCare Portal',
            'token' => 'secret-project-token',
        ]);

    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://voidcare.com',
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);

    ShiftServer::actingAs($owner)->tool(ListProjectsTool::class, [
        'search' => 'VoidCare',
    ])
        ->assertOk()
        ->assertSee(['VoidCare Portal', 'production', 'voidcare.com', 'SHIFT Owner'])
        ->assertDontSee('secret-project-token');
});

test('search tasks returns filtered task summaries without full descriptions', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create(['name' => 'VoidCare Portal']);

    $matchingTask = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Broken billing export',
        'description' => 'Sensitive implementation detail that should not be in search summaries.',
        'status' => 'pending',
        'priority' => 'high',
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Completed import tidy up',
        'status' => 'completed',
        'priority' => 'low',
    ]);

    Task::factory()->create([
        'title' => 'Broken billing export from hidden project',
        'description' => 'This should not appear because the user cannot see the project.',
        'status' => 'pending',
        'priority' => 'high',
    ]);

    ShiftServer::actingAs($owner)->tool(SearchTasksTool::class, [
        'query' => 'billing',
        'status' => 'pending',
    ])
        ->assertOk()
        ->assertSee([(string) $matchingTask->id, 'Broken billing export', 'VoidCare Portal', 'high'])
        ->assertDontSee('Sensitive implementation detail');
});

test('get task returns task details and collaborators', function () {
    $internalUser = User::factory()->create([
        'name' => 'Internal Collaborator',
        'email' => 'internal@example.com',
    ]);
    $externalUser = ExternalUser::factory()->create([
        'name' => 'External Collaborator',
        'email' => 'external@example.com',
    ]);
    $task = Task::factory()->create([
        'title' => 'Investigate task mail',
        'description' => 'Exact task details for MCP inspection.',
    ]);

    TaskCollaborator::query()->create([
        'task_id' => $task->id,
        'kind' => TaskCollaboratorKind::Internal,
        'user_id' => $internalUser->id,
    ]);
    TaskCollaborator::query()->create([
        'task_id' => $task->id,
        'kind' => TaskCollaboratorKind::External,
        'external_user_id' => $externalUser->id,
    ]);

    ShiftServer::actingAs($internalUser)->tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ])
        ->assertOk()
        ->assertSee([
            'Investigate task mail',
            'Exact task details for MCP inspection.',
            'internal@example.com',
            'external@example.com',
        ]);
});

test('get task hides tasks outside the authenticated users visibility', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'title' => 'Hidden task detail',
    ]);

    ShiftServer::actingAs($user)->tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ])
        ->assertHasErrors(['not found or is not visible'])
        ->assertDontSee('Hidden task detail');
});

test('list task threads returns thread content for a task', function () {
    $sender = User::factory()->create(['name' => 'Thread Sender']);
    $project = Project::factory()->withAuthor($sender->id)->create();
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);

    TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'internal',
        'content' => 'The reproduction is attached to this task.',
        'sender_name' => $sender->name,
        'sender_type' => User::class,
        'sender_id' => $sender->id,
    ]);

    ShiftServer::actingAs($sender)->tool(ListTaskThreadsTool::class, [
        'task_id' => $task->id,
    ])
        ->assertOk()
        ->assertSee(['internal', 'The reproduction is attached to this task.', 'Thread Sender']);
});

test('list notifications filters database notifications by user and task', function () {
    $user = User::factory()->create(['email' => 'recipient@example.com']);
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $task = Task::factory()->create(['title' => 'Notification target task']);
    $notificationId = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $notificationId,
        'type' => TaskCreationNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode([
            'task_id' => $task->id,
            'task_title' => $task->title,
            'url' => route('tasks.index', ['task' => $task->id]),
        ], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => TaskCreationNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $otherUser->id,
        'data' => json_encode([
            'task_id' => $task->id,
            'task_title' => 'Other user task notification',
            'url' => route('tasks.index', ['task' => $task->id]),
        ], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ShiftServer::actingAs($user)->tool(ListNotificationsTool::class, [
        'notifiable_email' => 'recipient@example.com',
        'task_id' => $task->id,
        'unread_only' => true,
    ])
        ->assertOk()
        ->assertSee([$notificationId, 'TaskCreationNotification', 'recipient@example.com', 'shift'])
        ->assertDontSee(['other@example.com', 'Notification target task', 'Other user task notification', 'task_title']);
});

test('list notifications omits raw notification content and classifies links', function () {
    $user = User::factory()->create(['email' => 'recipient@example.com']);
    $task = Task::factory()->create(['title' => 'Sensitive task title']);
    $notificationId = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $notificationId,
        'type' => TaskThreadUpdated::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode([
            'task_id' => $task->id,
            'task_title' => $task->title,
            'type' => 'external',
            'content' => '<p>Private HTML thread body</p>',
            'url' => 'https://voidcare.com/shift/tasks?task='.$task->id,
            'thread_id' => 123,
        ], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ShiftServer::actingAs($user)->tool(ListNotificationsTool::class, [
        'task_id' => $task->id,
    ])
        ->assertOk()
        ->assertSee([$notificationId, 'TaskThreadUpdated', 'consuming_project', 'voidcare.com', '/shift/tasks', 'external'])
        ->assertDontSee(['Sensitive task title', 'Private HTML thread body', '<p>', 'task_title', 'content']);
});

test('configured sanctum and project tokens restrict local mcp reads to the token project', function () {
    $user = User::factory()->create();
    $visibleProject = Project::factory()->create([
        'name' => 'VoidCare Token Project',
        'token' => 'voidcare-project-token',
    ]);
    $otherProject = Project::factory()->create([
        'name' => 'Other Token Project',
        'token' => 'other-project-token',
    ]);

    foreach ([$visibleProject, $otherProject] as $project) {
        ProjectUser::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
        ]);
    }

    Task::factory()->create([
        'project_id' => $visibleProject->id,
        'title' => 'Visible token-scoped task',
    ]);
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'title' => 'Hidden token-scoped task',
    ]);

    config([
        'shift_mcp.auth_token' => $user->createToken('mcp-test', ['mcp:use'])->plainTextToken,
        'shift_mcp.project_token' => 'voidcare-project-token',
    ]);

    ShiftServer::tool(SearchTasksTool::class, [
        'query' => 'token-scoped',
    ])
        ->assertOk()
        ->assertSee('Visible token-scoped task')
        ->assertDontSee('Hidden token-scoped task');
});

test('configured local mcp auth token must include the mcp ability', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Task behind regular token',
    ]);

    config([
        'shift_mcp.auth_token' => $user->createToken('regular-api-token')->plainTextToken,
    ]);

    ShiftServer::tool(SearchTasksTool::class, [
        'query' => 'regular token',
    ])
        ->assertHasErrors(['authenticated user'])
        ->assertDontSee('Task behind regular token');
});
