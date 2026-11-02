<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Servers\ShiftServer;
use App\Mcp\Tools\EditTaskThreadCommentTool;
use App\Mcp\Tools\GetTaskWriteContextTool;
use App\Mcp\Tools\ListTaskThreadsTool;
use App\Mcp\Tools\PublishTaskThreadCommentTool;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Laravel\Passport\AccessToken;

function threadCommentMcpAs(User $user, array $scopes = ['mcp:read']): \Laravel\Mcp\Server\Testing\PendingTestResponse
{
    $token = new AccessToken([
        'oauth_user_id' => $user->getAuthIdentifier(),
        'oauth_scopes' => $scopes,
    ]);
    $user->withAccessToken($token);

    return ShiftServer::actingAs($user);
}

test('mcp write tools can publish and edit own thread comments', function () {
    $user = User::factory()->create([
        'name' => 'Codex Operator',
    ]);
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);

    threadCommentMcpAs($user, ['mcp:read', 'mcp:write'])->tool(PublishTaskThreadCommentTool::class, [
        'task_id' => $task->id,
        'content' => '<p>Initial Codex reply.</p><script>alert("x")</script>',
    ])
        ->assertOk()
        ->assertSee(['Initial Codex reply.', 'Codex Operator'])
        ->assertDontSee('script');

    $thread = TaskThread::query()->where('task_id', $task->id)->firstOrFail();

    expect($thread->is_draft)->toBeFalse();
    expect($thread->sender_id)->toBe($user->id);
    expect($thread->sender_type)->toBe(User::class);

    threadCommentMcpAs($user, ['mcp:read', 'mcp:write'])->tool(EditTaskThreadCommentTool::class, [
        'thread_id' => $thread->id,
        'content' => '<p>Edited Codex reply.</p>',
    ])
        ->assertOk()
        ->assertSee('Edited Codex reply.');

    $this->assertDatabaseHas('task_threads', [
        'id' => $thread->id,
        'content' => '<p>Edited Codex reply.</p>',
    ]);
});

test('mcp comment tools map All and Team without changing legacy storage values', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);

    threadCommentMcpAs($user, ['mcp:read', 'mcp:write'])->tool(PublishTaskThreadCommentTool::class, [
        'task_id' => $task->id,
        'content' => '<p>Shared comment.</p>',
        'audience' => 'all',
    ])
        ->assertOk()
        ->assertSee(['all', 'external', 'Shared comment.']);

    threadCommentMcpAs($user, ['mcp:read', 'mcp:write'])->tool(PublishTaskThreadCommentTool::class, [
        'task_id' => $task->id,
        'content' => '<p>Team comment.</p>',
        'audience' => 'team',
    ])
        ->assertOk()
        ->assertSee(['team', 'internal', 'Team comment.']);

    expect(TaskThread::query()->where('type', 'external')->count())->toBe(1)
        ->and(TaskThread::query()->where('type', 'internal')->count())->toBe(1);

    threadCommentMcpAs($user)->tool(ListTaskThreadsTool::class, [
        'task_id' => $task->id,
        'audience' => 'all',
    ])
        ->assertOk()
        ->assertSee('Shared comment.')
        ->assertDontSee('Team comment.');
});

test('mcp cannot edit an All message to quote Team content', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $team = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'internal',
        'content' => '<p>Team context.</p>',
        'sender_name' => $user->name,
        'sender_type' => User::class,
        'sender_id' => $user->id,
    ]);
    $all = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>All original.</p>',
        'sender_name' => $user->name,
        'sender_type' => User::class,
        'sender_id' => $user->id,
    ]);

    threadCommentMcpAs($user, ['mcp:read', 'mcp:write'])->tool(EditTaskThreadCommentTool::class, [
        'thread_id' => $all->id,
        'content' => '<blockquote class="shift-reply" data-reply-to="'.$team->id.'"><p>Team context.</p></blockquote>',
    ])->assertHasErrors(['Remove Team reply references']);

    expect($all->fresh()->content)->toBe('<p>All original.</p>');
});

test('mcp write tools cannot edit another users thread comment', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create([
        'mcp_enabled' => true,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $thread = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'internal',
        'content' => '<p>Original owner comment.</p>',
        'sender_name' => $owner->name,
        'sender_type' => User::class,
        'sender_id' => $owner->id,
    ]);
    $task->internalCollaborators()->attach($other->id);

    threadCommentMcpAs($other, ['mcp:read', 'mcp:write'])->tool(EditTaskThreadCommentTool::class, [
        'thread_id' => $thread->id,
        'content' => '<p>Unauthorized edit.</p>',
    ])
        ->assertHasErrors(['own messages']);

    $this->assertDatabaseHas('task_threads', [
        'id' => $thread->id,
        'content' => '<p>Original owner comment.</p>',
    ]);
});

test('get task write context returns fields enums and capabilities for inline drafting', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'development',
        'url' => 'https://portal.example.test',
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Pending->value,
        'priority' => TaskPriority::Medium->value,
    ]);

    threadCommentMcpAs($user)->tool(GetTaskWriteContextTool::class, [
        'task_id' => $task->id,
    ])
        ->assertOk()
        ->assertSee([
            'editable_task_fields',
            'thread_comment_fields',
            'pending',
            'completed',
            'high',
            'can_edit_task',
            'can_comment',
            'environment',
            'development',
            'portal.example.test',
        ]);
});
