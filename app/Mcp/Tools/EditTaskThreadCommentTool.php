<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Models\TaskThread;
use App\Models\User;
use App\Support\RichContentSanitizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld(false)]
class EditTaskThreadCommentTool extends Tool
{
    use FormatsShiftRecords;

    protected string $name = 'edit_task_thread_comment';

    protected string $description = 'Edit a SHIFT task thread comment created by the authenticated MCP user. Requires the mcp:write token ability.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'thread_id' => ['required', 'integer'],
            'content' => ['required', 'string'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        if (! $access->canWrite($principal)) {
            return Response::error('This SHIFT MCP tool requires a token with the mcp:write ability.');
        }

        $thread = TaskThread::query()
            ->with(['task.project', 'sender', 'attachments'])
            ->find($validated['thread_id']);

        if (! $thread instanceof TaskThread) {
            return Response::error("Thread comment [{$validated['thread_id']}] was not found.");
        }

        if (! $access->tasksFor($principal)->whereKey($thread->task_id)->exists()) {
            return Response::error("Task [{$thread->task_id}] was not found or is not visible to the authenticated user.");
        }

        if ($thread->sender_type !== User::class || $thread->sender_id !== $principal->user->id) {
            return Response::error('You can only edit your own messages.');
        }

        $thread->content = app(RichContentSanitizer::class)->sanitize($validated['content']);
        $thread->save();
        $thread->load(['sender', 'attachments']);

        return Response::structured([
            'thread' => $this->thread($thread),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()
                ->description('The SHIFT task thread comment ID.')
                ->required(),
            'content' => $schema->string()
                ->description('Replacement rich-text HTML comment content. Dangerous HTML is sanitized.')
                ->required(),
        ];
    }
}
