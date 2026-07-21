<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Models\Task;
use App\Models\TaskThread;
use App\Services\ShiftPermissionService;
use App\Services\TaskThreadNotificationService;
use App\Support\RichContentSanitizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld(false)]
class AddTaskThreadCommentTool extends Tool
{
    use FormatsShiftRecords;

    protected string $name = 'add_task_thread_comment';

    protected string $description = 'Add an internal or explicitly external thread comment to a visible SHIFT task. Requires comment permission and the mcp:write token ability.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'content' => ['required', 'string'],
            'type' => ['nullable', 'string', Rule::in(['internal', 'external'])],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        if (! $access->canWrite($principal)) {
            return Response::error('This SHIFT MCP tool requires a token with the mcp:write ability.');
        }

        $task = $access->tasksFor($principal)
            ->with(['project', 'submitter', 'externalCollaborators'])
            ->find($validated['task_id']);

        if (! $task instanceof Task) {
            return Response::error("Task [{$validated['task_id']}] was not found or is not visible to the authenticated user.");
        }

        if (! app(ShiftPermissionService::class)->canCommentOnTask($task, $principal->user->id)) {
            return Response::error("You do not have permission to comment on task [{$task->id}].");
        }

        $thread = TaskThread::query()->create([
            'task_id' => $task->id,
            'type' => $validated['type'] ?? 'internal',
            'content' => app(RichContentSanitizer::class)->sanitize($validated['content']),
            'sender_name' => $principal->user->name,
            'sender_type' => $principal->user::class,
            'sender_id' => $principal->user->id,
        ]);

        $thread->load(['sender', 'attachments']);

        app(TaskThreadNotificationService::class)->send($task, $thread);

        return Response::structured([
            'thread' => $this->thread($thread),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()
                ->description('The SHIFT task ID.')
                ->required(),
            'content' => $schema->string()
                ->description('Rich-text HTML comment content. Dangerous HTML is sanitized.')
                ->required(),
            'type' => $schema->string()
                ->description('Thread type. Defaults to internal. External comments may notify external task audience.')
                ->enum(['internal', 'external'])
                ->default('internal'),
        ];
    }
}
