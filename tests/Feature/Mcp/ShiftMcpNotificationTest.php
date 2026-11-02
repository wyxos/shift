<?php

use App\Mcp\Servers\ShiftServer;
use App\Mcp\Tools\ListNotificationsTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;

function notificationMcpAs(User $user, array $scopes = ['mcp:read']): \Laravel\Mcp\Server\Testing\PendingTestResponse
{
    $token = new AccessToken([
        'oauth_user_id' => $user->getAuthIdentifier(),
        'oauth_scopes' => $scopes,
    ]);
    $user->withAccessToken($token);

    return ShiftServer::actingAs($user);
}

test('list notifications filters database notifications by user and task', function () {
    $user = User::factory()->create(['email' => 'recipient@example.com']);
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Notification target task',
    ]);
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
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Sensitive task title',
    ]);
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
            'url' => 'https://portal.example.test/shift/tasks?task='.$task->id,
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
        ->assertSee([$notificationId, 'TaskThreadUpdated', 'consuming_project', 'portal.example.test', '/shift/tasks', 'external'])
        ->assertDontSee(['Sensitive task title', 'Private HTML thread body', '<p>', 'task_title', 'content']);
});

test('list notifications omits task notifications for projects without mcp enabled', function () {
    $user = User::factory()->create(['email' => 'recipient@example.com']);
    $enabledProject = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    $disabledProject = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => false,
    ]);
    $enabledTask = Task::factory()->create([
        'project_id' => $enabledProject->id,
        'title' => 'Enabled notification task',
    ]);
    $disabledTask = Task::factory()->create([
        'project_id' => $disabledProject->id,
        'title' => 'Disabled notification task',
    ]);
    $enabledNotificationId = (string) Str::uuid();
    $disabledNotificationId = (string) Str::uuid();

    foreach ([[$enabledNotificationId, $enabledTask], [$disabledNotificationId, $disabledTask]] as [$id, $task]) {
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => TaskCreationNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'task_title' => $task->title,
                'url' => route('tasks.index', ['task' => $task->id]),
            ], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    ShiftServer::actingAs($user)->tool(ListNotificationsTool::class)
        ->assertOk()
        ->assertSee($enabledNotificationId)
        ->assertDontSee($disabledNotificationId)
        ->assertDontSee('Disabled notification task');
});
