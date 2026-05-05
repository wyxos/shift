<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
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
class SearchTasksTool extends Tool
{
    use FormatsShiftRecords;

    protected string $name = 'search_tasks';

    protected string $description = 'Search SHIFT tasks by text, project, status, priority, or external-submitter status. Results are summaries and omit full task descriptions.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'string', Rule::in(TaskStatus::values())],
            'priority' => ['nullable', 'string', Rule::in(TaskPriority::values())],
            'external_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        $limit = (int) ($validated['limit'] ?? 20);

        $tasks = $access->tasksFor($principal)
            ->with(['project', 'submitter'])
            ->withCount(['threads', 'internalCollaborators', 'externalCollaborators'])
            ->when($validated['query'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('project', fn ($projectQuery) => $projectQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($validated['project_id'] ?? null, fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->when((bool) ($validated['external_only'] ?? false), fn ($query) => $query->externallySubmitted())
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Task $task): array => $this->taskSummary($task))
            ->values()
            ->all();

        return Response::structured([
            'tasks' => $tasks,
            'count' => count($tasks),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional text to search in task title, description, or project name.'),
            'project_id' => $schema->integer()
                ->description('Optional SHIFT project ID.'),
            'status' => $schema->string()
                ->description('Optional task status filter.')
                ->enum(TaskStatus::values()),
            'priority' => $schema->string()
                ->description('Optional task priority filter.')
                ->enum(TaskPriority::values()),
            'external_only' => $schema->boolean()
                ->description('Only return tasks submitted by external users.')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Maximum number of tasks to return, between 1 and 50.')
                ->default(20),
        ];
    }
}
