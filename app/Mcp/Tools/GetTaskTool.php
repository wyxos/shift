<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
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
class GetTaskTool extends Tool
{
    use FormatsShiftRecords;

    protected string $name = 'get_task';

    protected string $description = 'Get full read-only details for a SHIFT task, including description, submitter, collaborators, metadata, and attachments.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        $task = $access->tasksFor($principal)
            ->with([
                'project',
                'submitter',
                'metadata',
                'attachments',
                'collaborators.user',
                'collaborators.externalUser',
            ])
            ->withCount(['threads', 'internalCollaborators', 'externalCollaborators'])
            ->find($validated['task_id']);

        if (! $task) {
            return Response::error("Task [{$validated['task_id']}] was not found or is not visible to the authenticated user.");
        }

        return Response::structured([
            'task' => $this->taskDetails($task),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()
                ->description('The SHIFT task ID.')
                ->required(),
        ];
    }
}
