<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Mcp\Tools\Concerns\HandlesTaskEnvironments;
use App\Models\Project;
use App\Models\Task;
use App\Services\ShiftPermissionService;
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
class GetTaskWriteContextTool extends Tool
{
    use FormatsShiftRecords;
    use HandlesTaskEnvironments;

    protected string $name = 'get_task_write_context';

    protected string $description = 'Get SHIFT task and thread field options, allowed enum values, and user capabilities for drafting task or thread changes in Codex before writing them.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'task_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        $permissions = app(ShiftPermissionService::class);
        $task = null;
        $project = null;

        if (isset($validated['task_id'])) {
            $task = $access->tasksFor($principal)
                ->with(['project.environments', 'submitter', 'metadata', 'collaborators.user', 'collaborators.externalUser'])
                ->find($validated['task_id']);

            if (! $task instanceof Task) {
                return Response::error("Task [{$validated['task_id']}] was not found or is not visible to the authenticated user.");
            }

            $project = $task->project;
        } elseif (isset($validated['project_id'])) {
            $project = $access->projectsFor($principal)
                ->with('environments')
                ->find($validated['project_id']);

            if (! $project instanceof Project) {
                return Response::error("Project [{$validated['project_id']}] was not found or is not visible to the authenticated user.");
            }
        }

        return Response::structured([
            'schema' => $this->schemaContext(),
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'environments' => $this->mcpProjectEnvironmentOptions($project),
                'capabilities' => $permissions->projectCapabilities($project, $principal->user->id),
            ] : null,
            'task' => $task ? [
                ...$this->taskDetails($task),
                'capabilities' => $permissions->taskCapabilities($task, $principal->user->id),
            ] : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()
                ->description('Optional SHIFT task ID for edit/comment context.'),
            'project_id' => $schema->integer()
                ->description('Optional SHIFT project ID for task creation context. Ignored when task_id is provided.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaContext(): array
    {
        return [
            'editable_task_fields' => [
                'title' => ['type' => 'string', 'required_on_create' => true, 'max' => 255],
                'description' => ['type' => 'html_string', 'required_on_create' => false],
                'status' => ['type' => 'enum', 'values' => TaskStatus::values()],
                'priority' => ['type' => 'enum', 'values' => TaskPriority::values()],
                'environment' => [
                    'type' => 'string',
                    'required_on_create' => false,
                    'accepted_values_source' => 'project.environments[].environment',
                    'create_behavior' => 'Omit or pass blank to leave metadata empty.',
                    'edit_behavior' => 'Omit to preserve metadata; pass blank to clear it.',
                ],
            ],
            'thread_comment_fields' => [
                'content' => ['type' => 'html_string', 'required' => true],
                'type' => ['type' => 'enum', 'values' => ['internal', 'external'], 'default' => 'internal'],
            ],
            'unsupported_mcp_fields' => [
                'attachments',
                'temporary_uploads',
                'external_collaborator_lookup',
            ],
        ];
    }
}
