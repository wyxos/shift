<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Models\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListTaskThreadsTool extends Tool
{
    use FormatsShiftRecords;

    protected string $name = 'list_task_threads';

    protected string $description = 'List thread messages for a SHIFT task.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'type' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        if (! $access->tasksFor($principal)->whereKey($validated['task_id'])->exists()) {
            return Response::error("Task [{$validated['task_id']}] was not found or is not visible to the authenticated user.");
        }

        $limit = (int) ($validated['limit'] ?? 20);

        $threads = TaskThread::query()
            ->with(['sender', 'attachments'])
            ->where('task_id', $validated['task_id'])
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (TaskThread $thread): array => $this->thread($thread))
            ->values()
            ->all();

        return Response::structured([
            'task_id' => $validated['task_id'],
            'threads' => $threads,
            'count' => count($threads),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()
                ->description('The SHIFT task ID.')
                ->required(),
            'type' => $schema->string()
                ->description('Optional thread type filter: internal or external.'),
            'limit' => $schema->integer()
                ->description('Maximum number of thread messages to return, between 1 and 50.')
                ->default(20),
        ];
    }
}
