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
use App\Support\RichContentSanitizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld(false)]
class CreateTaskTool extends Tool
{
    use FormatsShiftRecords;
    use HandlesTaskEnvironments;

    protected string $name = 'create_task';

    protected string $description = 'Create a normal SHIFT task in an MCP-enabled project visible to the authenticated user. Requires the mcp:write token ability.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(TaskStatus::values())],
            'priority' => ['nullable', 'string', Rule::in(TaskPriority::values())],
            'environment' => ['nullable', 'string', 'max:255'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        if (! $access->canWrite($principal)) {
            return Response::error('This SHIFT MCP tool requires a token with the mcp:write ability.');
        }

        $project = $access->projectsFor($principal)->find($validated['project_id']);

        if (! $project instanceof Project) {
            return Response::error("Project [{$validated['project_id']}] was not found or is not visible to the authenticated user.");
        }

        if (! app(ShiftPermissionService::class)->canCreateTaskForProject($project, $principal->user->id)) {
            return Response::error("You do not have permission to create tasks for project [{$project->id}].");
        }

        $environment = null;

        if (array_key_exists('environment', $validated)) {
            [$environment, $environmentError] = $this->resolveMcpProjectEnvironment($project, $validated['environment'] ?? null);

            if ($environmentError !== null) {
                return Response::error($environmentError);
            }
        }

        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => $validated['title'],
            'description' => app(RichContentSanitizer::class)->sanitize($validated['description'] ?? null),
            'status' => $validated['status'] ?? TaskStatus::Pending->value,
            'priority' => $validated['priority'] ?? TaskPriority::Medium->value,
        ]);

        $task->submitter()->associate($principal->user)->save();
        if ($environment !== null) {
            $this->syncMcpTaskEnvironment($task, $environment);
        }

        $task->load(['project', 'submitter', 'metadata', 'attachments', 'collaborators.user', 'collaborators.externalUser']);

        return Response::structured([
            'task' => $this->taskDetails($task),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The MCP-enabled SHIFT project ID to create the task in.')
                ->required(),
            'title' => $schema->string()
                ->description('Task title.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional rich-text HTML task description. Dangerous HTML is sanitized.'),
            'status' => $schema->string()
                ->description('Optional task status.')
                ->enum(TaskStatus::values())
                ->default(TaskStatus::Pending->value),
            'priority' => $schema->string()
                ->description('Optional task priority.')
                ->enum(TaskPriority::values())
                ->default(TaskPriority::Medium->value),
            'environment' => $schema->string()
                ->description('Optional registered project environment key to store on task metadata, for example development or production. Blank leaves metadata empty.'),
        ];
    }
}
