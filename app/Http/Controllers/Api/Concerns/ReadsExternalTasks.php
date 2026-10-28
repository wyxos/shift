<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Attachment;
use App\Models\ExternalIdentity;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

trait ReadsExternalTasks
{
    private function serializeCollaborators(Task $task): array
    {
        return [
            'internal_collaborators' => $task->internalCollaborators
                ->map(fn (\App\Models\User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'external_collaborators' => $task->externalCollaborators
                ->map(fn (ExternalIdentity $user) => [
                    'id' => $user->external_id,
                    'environment' => $user->environment,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
        ];
    }

    private function serializeAttachments(Task $task, string $clientUrl): array
    {
        return $task->attachments
            ->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => rtrim($clientUrl, '/').'/shift/api/attachments/'.$attachment->id.'/download',
                'created_at' => $attachment->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function serializeTaskType(Task $task): array
    {
        if (filled($task->error_signature)) {
            return [
                'type' => 'app_error',
                'type_label' => 'App error',
            ];
        }

        return [
            'type' => 'task',
            'type_label' => 'Task',
        ];
    }

    private function serializeTaskPayload(Task $task, string $clientUrl, ?ExternalIdentity $externalIdentity): array
    {
        $capabilities = $this->externalTaskAccess->capabilityFlags($task, $externalIdentity);

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            ...$this->serializeTaskType($task),
            'description' => $this->rewriteContentUrlsToClientProxyUrls((string) ($task->description ?? ''), $clientUrl),
            'status' => $task->status,
            'priority' => $task->priority,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'environment' => $this->taskEnvironment($task),
            'phase' => $task->phase(),
            'finalized' => ! $task->isRequirementPhase(),
            'requirement_status' => $task->requirementStatus(),
            'submitted_title' => $task->metadata?->submitted_title,
            'submitted_description' => $task->metadata?->submitted_description,
            'finalized_at' => $task->metadata?->finalized_at?->toIso8601String(),
            'submitter' => $task->submitter ? [
                'name' => $task->submitter->name ?? null,
                'email' => $task->submitter->email ?? null,
            ] : null,
            'attachments' => $this->serializeAttachments($task, $clientUrl),
            ...$capabilities,
            'can_manage_collaborators' => $externalIdentity instanceof ExternalIdentity
                && $this->canManageCollaborators($task, $externalIdentity),
            ...$this->serializeCollaborators($task),
        ];
    }

    private function taskEnvironment(Task $task): ?string
    {
        return $task->metadata?->environment;
    }

    /**
     * Display a listing of the tasks.
     */
    public function index(): JsonResponse
    {
        $allowedSortBy = ['updated_at', 'created_at', 'priority'];
        $sortBy = in_array((string) request('sort_by'), $allowedSortBy, true) ? (string) request('sort_by') : 'updated_at';
        $environment = trim((string) request('environment', ''));
        $project = $this->resolveProjectFromRequest();

        if ($project === null) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $externalIdentity = $this->currentExternalIdentity($project, true);

        $tasksQuery = Task::query()
            ->with(['submitter', 'metadata', 'project'])
            ->where('project_id', $project->id)
            ->withoutRequirementPhase();

        $this->externalTaskAccess->constrainVisibleProjectItems($tasksQuery, $externalIdentity);

        $tasksQuery
            ->when(
                request('search'),
                fn ($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.request('search').'%'])
            )
            ->when(
                $environment !== '',
                fn ($query) => $query->whereHas('metadata', fn ($metadataQuery) => $metadataQuery->whereRaw('LOWER(environment) = LOWER(?)', [$environment]))
            )
            ->when(
                request()->has('status'),
                function ($query) {
                    $status = request('status');
                    if (is_array($status)) {
                        $status = array_values(array_filter($status, fn ($value) => filled($value)));
                        if (count($status) > 0) {
                            $query->whereIn('status', $status);
                        }

                        return;
                    }

                    if (filled($status)) {
                        $query->where('status', $status);
                    }
                }
            )
            ->when(
                request()->has('priority'),
                function ($query) {
                    $priority = request('priority');
                    if (is_array($priority)) {
                        $priority = array_values(array_filter($priority, fn ($value) => filled($value)));
                        if (count($priority) > 0) {
                            $query->whereIn('priority', $priority);
                        }

                        return;
                    }

                    if (filled($priority)) {
                        $query->where('priority', $priority);
                    }
                }
            );

        if ($sortBy === 'priority') {
            $tasksQuery
                ->orderByRaw("
                    CASE priority
                        WHEN 'high' THEN 1
                        WHEN 'medium' THEN 2
                        WHEN 'low' THEN 3
                        ELSE 4
                    END
                ")
                ->orderByDesc('updated_at')
                ->orderByDesc('id');
        } else {
            $tasksQuery
                ->orderByDesc($sortBy)
                ->orderByDesc('id');
        }

        $tasks = $tasksQuery
            ->paginate(10)
            ->withQueryString();
        $tasks->through(function (Task $task) use ($externalIdentity) {
            $task->environment = $this->taskEnvironment($task);
            foreach ($this->externalTaskAccess->capabilityFlags($task, $externalIdentity) as $key => $value) {
                $task->setAttribute($key, $value);
            }
            foreach ($this->serializeTaskType($task) as $key => $value) {
                $task->setAttribute($key, $value);
            }

            $payload = $task->toArray();
            if ($task->submitter_type === ExternalIdentity::class) {
                $payload['submitter_type'] = 'App\\Models\\ExternalUser';
                if (is_array($payload['submitter'] ?? null)) {
                    $payload['submitter']['external_contact_id'] = $payload['submitter']['project_participant_id'] ?? null;
                    unset($payload['submitter']['project_participant_id']);
                }
            }

            return $payload;
        });

        return response()->json([
            ...$tasks->toArray(),
            'project_environments' => $this->projectEnvironmentService->options($project),
        ]);
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task): JsonResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null || $task->project_id !== $project->id) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalIdentity = $this->currentExternalIdentity($project);

        if (! $externalIdentity) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalIdentityHasAccess($task, $externalIdentity)) {
            return response()->json(['error' => 'Unauthorized to view this task'], 403);
        }

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        $clientUrl = (string) (request('metadata.url') ?? request('user.url') ?? config('app.url'));

        return response()->json($this->serializeTaskPayload(
            $task,
            $clientUrl,
            $externalIdentity,
        ));
    }
}
