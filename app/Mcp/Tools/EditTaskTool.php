<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Support\ShiftMcpAccess;
use App\Mcp\Tools\Concerns\FormatsShiftRecords;
use App\Mcp\Tools\Concerns\HandlesTaskEnvironments;
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
class EditTaskTool extends Tool
{
    use FormatsShiftRecords;
    use HandlesTaskEnvironments;

    protected string $name = 'edit_task';

    protected string $description = 'Partially edit a visible SHIFT task. Requires task-scope edit permission and the mcp:write token ability.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
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

        $task = $access->tasksFor($principal)
            ->with(['project', 'submitter', 'metadata', 'attachments', 'collaborators.user', 'collaborators.externalUser'])
            ->find($validated['task_id']);

        if (! $task instanceof Task) {
            return Response::error("Task [{$validated['task_id']}] was not found or is not visible to the authenticated user.");
        }

        if (! app(ShiftPermissionService::class)->canEditTask($task, $principal->user->id)) {
            return Response::error("You do not have permission to edit task [{$task->id}].");
        }

        if ($task->isRequirementPhase()) {
            return Response::error('Requirement-phase items cannot be edited with the normal task edit tool.');
        }

        $fields = collect(['title', 'description', 'status', 'priority', 'environment'])
            ->filter(fn (string $field): bool => array_key_exists($field, $validated))
            ->values();

        if ($fields->isEmpty()) {
            return Response::error('Provide at least one editable task field: title, description, status, priority, or environment.');
        }

        $environment = null;
        $hasEnvironmentField = $fields->contains('environment');

        if ($hasEnvironmentField) {
            [$environment, $environmentError] = $this->resolveMcpProjectEnvironment($task->project, $validated['environment'] ?? null);

            if ($environmentError !== null) {
                return Response::error($environmentError);
            }
        }

        foreach ($fields->reject(fn (string $field): bool => $field === 'environment') as $field) {
            $task->{$field} = $field === 'description'
                ? app(RichContentSanitizer::class)->sanitize($validated[$field] ?? null)
                : $validated[$field];
        }

        $task->save();

        if ($hasEnvironmentField) {
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
            'task_id' => $schema->integer()
                ->description('The SHIFT task ID.')
                ->required(),
            'title' => $schema->string()
                ->description('Optional replacement task title.'),
            'description' => $schema->string()
                ->description('Optional replacement rich-text HTML task description. Dangerous HTML is sanitized.'),
            'status' => $schema->string()
                ->description('Optional replacement task status.')
                ->enum(TaskStatus::values()),
            'priority' => $schema->string()
                ->description('Optional replacement task priority.')
                ->enum(TaskPriority::values()),
            'environment' => $schema->string()
                ->description('Optional registered project environment key to store on task metadata. Omit to preserve metadata; pass blank to clear it.'),
        ];
    }
}
