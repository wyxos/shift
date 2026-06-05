<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\NotifyExternalCollaboratorAdded;
use App\Jobs\NotifyExternalUser;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Notifications\TaskCreationNotification;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use App\Services\TaskCollaboratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskCollaboratorService $taskCollaboratorService,
        private readonly ExternalUserService $externalUserService,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    private function visibleTasksQuery(): Builder
    {
        return Task::query()->visibleTo(auth()->id());
    }

    private function normalizeListFilter(mixed $value): array
    {
        if (is_array($value)) {
            $list = array_values(array_filter($value, fn ($item) => filled($item)));

            return array_map('strval', $list);
        }

        if (filled($value)) {
            return [strval($value)];
        }

        return [];
    }

    private function applyIndexFilters(Builder $query, array $filters): void
    {
        if (filled($filters['search'] ?? null)) {
            $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$filters['search'].'%']);
        }

        if (filled($filters['organisation_id'] ?? null)) {
            $query->whereHas('project', function (Builder $projectQuery) use ($filters) {
                $this->applyProjectOrganisationFilter($projectQuery, $filters['organisation_id']);
            });
        }

        if (filled($filters['project_id'] ?? null)) {
            $query->where('project_id', $filters['project_id']);
        }

        $priorities = $this->normalizeListFilter($filters['priority'] ?? null);
        if (! empty($priorities)) {
            $query->whereIn('priority', $priorities);
        }

        $statuses = $this->normalizeListFilter($filters['status'] ?? null);
        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }
    }

    private function ensureTaskVisible(Task $task): void
    {
        if (! $this->visibleTasksQuery()->whereKey($task->id)->exists()) {
            abort(404);
        }
    }

    private function visibleProjectsQuery(): Builder
    {
        return Project::query()->visibleTo(auth()->id());
    }

    private function applyProjectOrganisationFilter(Builder $query, mixed $organisationId): void
    {
        $query->where(function (Builder $projectQuery) use ($organisationId) {
            $projectQuery
                ->where('organisation_id', $organisationId)
                ->orWhereHas('client', function (Builder $clientQuery) use ($organisationId) {
                    $clientQuery->where('organisation_id', $organisationId);
                });
        });
    }

    private function externalCollaboratorsRequested(array $attributes): bool
    {
        return ! empty($attributes['external_collaborators'] ?? []);
    }

    private function resolveTaskEnvironment(Project $project, ?string $environment, bool $required): ?string
    {
        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($environment);

        if ($normalizedEnvironment === null) {
            if ($required) {
                throw ValidationException::withMessages([
                    'environment' => 'Select an environment before tagging external collaborators.',
                ]);
            }

            return null;
        }

        if (! $project->environments()->where('environment', $normalizedEnvironment)->exists()) {
            throw ValidationException::withMessages([
                'environment' => 'The selected environment is not registered for this project.',
            ]);
        }

        return $normalizedEnvironment;
    }

    private function syncTaskEnvironment(Task $task, ?string $environment, ?string $url = null): void
    {
        if ($environment === null) {
            $task->metadata()->delete();

            return;
        }

        $task->metadata()->updateOrCreate(
            ['task_id' => $task->id],
            [
                'environment' => $environment,
                'url' => $url ?? $task->metadata?->url,
            ],
        );
    }

    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'environments' => $project->environments
                ->sortBy('environment')
                ->values()
                ->map(fn ($environment) => [
                    'key' => $environment->environment,
                    'label' => $this->projectEnvironmentService->label($environment->environment),
                    'url' => $environment->url,
                ])
                ->all(),
        ];
    }

    private function validateStoreAttributes(Request $request): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'environment' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'temp_identifier' => 'nullable|string',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
        ];

        $attributes = $request->validate($rules);

        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = $this->sanitizeRichContent($attributes['description']);
        }

        if (! $this->visibleProjectsQuery()->whereKey($attributes['project_id'])->exists()) {
            throw ValidationException::withMessages([
                'project_id' => 'The selected project is invalid.',
            ]);
        }

        return $attributes;
    }

    private function resolveExternalCollaboratorsForProject(Project $project, ?string $environment, array $attributes): \Illuminate\Support\Collection
    {
        return $this->externalUserService->resolveCollaborators(
            $project,
            $environment,
            $attributes['external_collaborators'] ?? [],
        );
    }

    private function canManageCollaborators(Task $task): bool
    {
        return $this->taskCollaboratorService->canManageForInternalUser($task, auth()->id());
    }

    private function syncCollaborators(Task $task, array $attributes, ?string $environment): array
    {
        $project = $task->project()->firstOrFail();
        $internalIds = $this->taskCollaboratorService->validateInternalCollaboratorIds(
            $project,
            $attributes['internal_collaborator_ids'] ?? [],
        );
        try {
            $externalUsers = $this->resolveExternalCollaboratorsForProject($project, $environment, $attributes);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'external_collaborators' => $exception->getMessage(),
            ]);
        }

        return $this->taskCollaboratorService->syncWithResult($task, $internalIds, $externalUsers);
    }

    private function sendCollaboratorAddedNotifications(Task $task, array $syncResult): void
    {
        $task->loadMissing('project');

        $addedInternal = $syncResult['added_internal'] ?? collect();
        if ($addedInternal->isNotEmpty()) {
            Notification::send($addedInternal, new TaskCollaboratorAddedNotification($task));
        }

        foreach (($syncResult['added_external'] ?? collect()) as $externalUser) {
            if ($externalUser->email !== null || $externalUser->url !== null) {
                NotifyExternalCollaboratorAdded::dispatch($externalUser->id, $task->id);
            }
        }
    }

    private function createTaskFromAttributes(array $attributes): Task
    {
        $project = Project::query()
            ->with('environments')
            ->findOrFail($attributes['project_id']);
        $environment = $this->resolveTaskEnvironment(
            $project,
            $attributes['environment'] ?? null,
            $this->externalCollaboratorsRequested($attributes),
        );
        $environmentUrl = $environment !== null
            ? $project->environments->firstWhere('environment', $environment)?->url
            : null;

        $taskAttributes = [
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'project_id' => $project->id,
        ];

        if (isset($attributes['status'])) {
            $taskAttributes['status'] = $attributes['status'];
        }

        if (isset($attributes['priority'])) {
            $taskAttributes['priority'] = $attributes['priority'];
        }

        $task = Task::create($taskAttributes);

        $task->submitter()->associate(auth()->user())->save();
        $this->syncTaskEnvironment($task, $environment, $environmentUrl);
        $this->syncCollaborators($task, $attributes, $environment);

        $this->sendTaskCreationNotifications($task);

        if (! empty($attributes['temp_identifier'])) {
            $created = $this->persistTempAttachmentsForTask($task, $attributes['temp_identifier']);

            if ($task->description) {
                $task->description = $this->replaceTempUrlsInContent($task->description, $attributes['temp_identifier'], $created);
                $task->save();
            }
        }

        return $task->fresh();
    }

    /**
     * Move all temp attachments for a given identifier to this task, preserving basenames
     * so we can safely swap temp URLs in rich HTML.
     */
    private function persistTempAttachmentsForTask(Task $task, string $tempIdentifier)
    {
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (! Storage::exists($tempPath)) {
            return collect();
        }

        $files = Storage::files($tempPath);
        $created = collect();

        $permanentPath = "attachments/{$task->id}";
        if (! Storage::exists($permanentPath)) {
            Storage::makeDirectory($permanentPath);
        }

        foreach ($files as $file) {
            if (Str::endsWith($file, '.meta')) {
                continue;
            }

            $metadataPath = $file.'.meta';
            $originalFilename = basename($file);

            if (Storage::exists($metadataPath)) {
                $metadata = json_decode(Storage::get($metadataPath), true);
                if (isset($metadata['original_filename'])) {
                    $originalFilename = $metadata['original_filename'];
                }
            }

            $storedFilename = basename($file);
            $newPath = "{$permanentPath}/{$storedFilename}";

            Storage::move($file, $newPath);

            $created->push(Attachment::create([
                'attachable_id' => $task->id,
                'attachable_type' => Task::class,
                'original_filename' => $originalFilename,
                'path' => $newPath,
            ]));
        }

        Storage::deleteDirectory($tempPath);

        return $created;
    }

    /**
     * Replace temp attachment URLs in HTML content with final download URLs.
     */
    private function replaceTempUrlsInContent(string $content, string $tempIdentifier, $attachments): string
    {
        if (empty($content) || empty($tempIdentifier) || ! $attachments || $attachments->isEmpty()) {
            return $content;
        }

        $out = $content;
        foreach ($attachments as $attachment) {
            $finalUrl = route('attachments.download', $attachment, false);
            $basename = basename($attachment->path);
            $quotedTemp = preg_quote($tempIdentifier, '#');
            $quotedBase = preg_quote($basename, '#');
            $quotedBaseEnc = preg_quote(rawurlencode($basename), '#');

            $patterns = [
                "#https?://[^\\s\"'<>]+/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#https?://[^\\s\"'<>]+/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                "#/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#/attachments/temp/{$quotedTemp}/{$quotedBase}#",
            ];

            foreach ($patterns as $pattern) {
                $out = preg_replace($pattern, $finalUrl, $out) ?? $out;
            }
        }

        return $out;
    }

    private function serializeAttachments(Task $task): array
    {
        return $task->attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
            ];
        })->values()->all();
    }

    private function serializeCollaborators(Task $task): array
    {
        $externalCollaborators = $task->externalCollaborators
            ->map(fn (ExternalUser $user) => [
                'id' => $user->external_id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        if ($task->submitter instanceof ExternalUser) {
            $submitterAlreadyListed = $externalCollaborators
                ->contains(fn (array $collaborator) => (string) $collaborator['id'] === (string) $task->submitter->external_id);

            if (! $submitterAlreadyListed) {
                $externalCollaborators->prepend([
                    'id' => $task->submitter->external_id,
                    'name' => $task->submitter->name,
                    'email' => $task->submitter->email,
                ]);
            }
        }

        return [
            'internal_collaborators' => $task->internalCollaborators
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'external_collaborators' => $externalCollaborators
                ->values()
                ->all(),
        ];
    }

    private function taskEnvironment(Task $task): ?string
    {
        $environment = $task->metadata?->environment;

        if (! filled($environment) && $task->submitter) {
            $environment = $task->submitter->environment ?? null;
        }

        return $environment;
    }

    private function requirementBatchSummaries($tasks): array
    {
        $batchIds = $tasks
            ->pluck('metadata.requirement_batch_id')
            ->filter()
            ->unique()
            ->values();

        if ($batchIds->isEmpty()) {
            return [];
        }

        $batches = RequirementBatch::query()
            ->whereIn('id', $batchIds)
            ->get(['id', 'title', 'created_at'])
            ->keyBy('id');

        return $this->visibleTasksQuery()
            ->with(['metadata:id,task_id,phase,requirement_batch_id'])
            ->requirementIntake()
            ->whereHas('metadata', function (Builder $metadataQuery) use ($batchIds) {
                $metadataQuery->whereIn('requirement_batch_id', $batchIds);
            })
            ->get(['id'])
            ->groupBy(fn (Task $task) => $task->metadata?->requirement_batch_id)
            ->map(function ($batchTasks, int $batchId) use ($batches) {
                $batch = $batches->get($batchId);
                $requirementItems = $batchTasks->filter(fn (Task $task) => $task->isRequirementPhase())->count();
                $totalItems = $batchTasks->count();

                return [
                    'id' => $batchId,
                    'title' => $batch?->title,
                    'created_at' => $batch?->created_at?->toIso8601String(),
                    'total_items' => $totalItems,
                    'requirement_items' => $requirementItems,
                    'finalized_items' => max($totalItems - $requirementItems, 0),
                ];
            })
            ->all();
    }

    private function serializeTaskDetail(Task $task, bool $includeOwnerState = true): array
    {
        $payload = [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'description' => $task->description,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'environment' => $this->taskEnvironment($task),
            'phase' => $task->phase(),
            'finalized' => ! $task->isRequirementPhase(),
            'submitted_title' => $task->metadata?->submitted_title,
            'submitted_description' => $task->metadata?->submitted_description,
            'finalized_at' => $task->metadata?->finalized_at?->toIso8601String(),
            'submitter' => $task->submitter ? [
                'name' => $task->submitter->name ?? null,
                'email' => $task->submitter->email ?? null,
            ] : null,
            'attachments' => $this->serializeAttachments($task),
            ...$this->serializeCollaborators($task),
        ];

        if ($includeOwnerState) {
            $payload['is_owner'] = $task->submitter_type === User::class && $task->submitter_id === auth()->id();
            $payload['can_manage_collaborators'] = $this->canManageCollaborators($task);
        }

        return $payload;
    }

    public function indexV2(?Organisation $organisation = null)
    {
        $defaultStatuses = ['pending', 'in-progress', 'awaiting-feedback'];
        $allPriorities = ['low', 'medium', 'high'];
        $allowedSortBy = ['updated_at', 'created_at', 'priority'];
        $defaultSortBy = 'updated_at';

        $selectedStatuses = $this->normalizeListFilter(request('status'));
        if (empty($selectedStatuses)) {
            $selectedStatuses = $defaultStatuses;
        }

        $selectedPriorities = $this->normalizeListFilter(request('priority'));
        if (empty($selectedPriorities)) {
            $selectedPriorities = $allPriorities;
        }

        $organisationId = $organisation?->id ?? request('organisation_id');
        $search = trim((string) request('search', ''));
        $environment = trim((string) request('environment', ''));
        $sortBy = in_array((string) request('sort_by'), $allowedSortBy, true) ? (string) request('sort_by') : $defaultSortBy;

        $query = $this->visibleTasksQuery()
            ->with(['metadata:id,task_id,environment,phase,submitted_title,submitted_description,finalized_at', 'submitter'])
            ->withoutRequirementPhase();

        $query->whereIn('status', $selectedStatuses);

        if (count($selectedPriorities) > 0 && count($selectedPriorities) < count($allPriorities)) {
            $query->whereIn('priority', $selectedPriorities);
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$search.'%']);
        }

        if (filled($organisationId)) {
            $query->whereHas('project', function (Builder $projectQuery) use ($organisationId) {
                $this->applyProjectOrganisationFilter($projectQuery, $organisationId);
            });
        }

        if ($environment !== '') {
            $query->whereHas('metadata', function ($metadataQuery) use ($environment) {
                $metadataQuery->whereRaw('LOWER(environment) = LOWER(?)', [$environment]);
            });
        }

        if ($sortBy === 'priority') {
            $query
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
            $query
                ->orderByDesc($sortBy)
                ->orderByDesc('id');
        }

        $tasks = $query
            ->paginate(10)
            ->withQueryString();
        $tasks->through(function (Task $task) {
            return [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'environment' => $this->taskEnvironment($task),
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
            ];
        });

        return inertia('Tasks/Index')
            ->with([
                'filters' => [
                    'status' => $selectedStatuses,
                    'priority' => $selectedPriorities,
                    'search' => $search,
                    'environment' => $environment !== '' ? $environment : null,
                    'organisation_id' => filled($organisationId) ? (int) $organisationId : null,
                    'sort_by' => $sortBy,
                ],
                'tasks' => $tasks,
                'surface' => 'tasks',
                'projects' => $this->visibleProjectsQuery()
                    ->when(filled($organisationId), function (Builder $query) use ($organisationId) {
                        $this->applyProjectOrganisationFilter($query, $organisationId);
                    })
                    ->with('environments')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Project $project) => $this->serializeProject($project))
                    ->values()
                    ->all(),
            ]);
    }

    public function requirementsV2(?Organisation $organisation = null)
    {
        $defaultStatuses = ['pending', 'in-progress', 'awaiting-feedback'];
        $allPriorities = ['low', 'medium', 'high'];
        $allowedSortBy = ['updated_at', 'created_at', 'priority'];
        $defaultSortBy = 'updated_at';

        $selectedStatuses = $this->normalizeListFilter(request('status'));
        if (empty($selectedStatuses)) {
            $selectedStatuses = $defaultStatuses;
        }

        $selectedPriorities = $this->normalizeListFilter(request('priority'));
        if (empty($selectedPriorities)) {
            $selectedPriorities = $allPriorities;
        }

        $search = trim((string) request('search', ''));
        $environment = trim((string) request('environment', ''));
        $organisationId = $organisation?->id ?? request('organisation_id');
        $sortBy = in_array((string) request('sort_by'), $allowedSortBy, true) ? (string) request('sort_by') : $defaultSortBy;

        $query = $this->visibleTasksQuery()
            ->with([
                'metadata:id,task_id,environment,phase,submitted_title,submitted_description,finalized_at,requirement_batch_id',
                'metadata.requirementBatch:id,title,created_at',
                'submitter',
            ])
            ->requirementIntake()
            ->whereIn('status', $selectedStatuses);

        if (count($selectedPriorities) > 0 && count($selectedPriorities) < count($allPriorities)) {
            $query->whereIn('priority', $selectedPriorities);
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$search.'%']);
        }

        if (filled($organisationId)) {
            $query->whereHas('project', function (Builder $projectQuery) use ($organisationId) {
                $this->applyProjectOrganisationFilter($projectQuery, $organisationId);
            });
        }

        if ($environment !== '') {
            $query->whereHas('metadata', function ($metadataQuery) use ($environment) {
                $metadataQuery->whereRaw('LOWER(environment) = LOWER(?)', [$environment]);
            });
        }

        if ($sortBy === 'priority') {
            $query
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
            $query
                ->orderByDesc($sortBy)
                ->orderByDesc('id');
        }

        $tasks = $query
            ->paginate(10)
            ->withQueryString();
        $batchSummaries = $this->requirementBatchSummaries($tasks->getCollection());

        $tasks->through(function (Task $task) use ($batchSummaries) {
            $batchId = $task->metadata?->requirement_batch_id;

            return [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'environment' => $this->taskEnvironment($task),
                'phase' => $task->phase(),
                'finalized' => ! $task->isRequirementPhase(),
                'batch' => $batchId ? ($batchSummaries[$batchId] ?? null) : null,
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
            ];
        });

        return inertia('Tasks/Index')
            ->with([
                'filters' => [
                    'status' => $selectedStatuses,
                    'priority' => $selectedPriorities,
                    'search' => $search,
                    'environment' => $environment !== '' ? $environment : null,
                    'organisation_id' => filled($organisationId) ? (int) $organisationId : null,
                    'sort_by' => $sortBy,
                ],
                'tasks' => $tasks,
                'surface' => 'requirements',
                'projects' => $this->visibleProjectsQuery()
                    ->when(filled($organisationId), function (Builder $query) use ($organisationId) {
                        $this->applyProjectOrganisationFilter($query, $organisationId);
                    })
                    ->with('environments')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Project $project) => $this->serializeProject($project))
                    ->values()
                    ->all(),
            ]);
    }

    public function collaborators(Project $project, Request $request): JsonResponse
    {
        if (! $this->visibleProjectsQuery()->whereKey($project->id)->exists()) {
            abort(404);
        }

        $search = trim((string) $request->input('search', ''));
        $environment = $request->input('environment');

        $external = [];
        $externalAvailable = false;
        $externalError = null;

        if (! filled($environment)) {
            $externalError = 'Select an environment before tagging external collaborators.';
        } else {
            try {
                $lookup = $this->externalUserService->searchCollaborators($project, (string) $environment, $search);
                $external = $lookup['users'];
                $externalAvailable = true;
            } catch (\RuntimeException $exception) {
                $externalError = $exception->getMessage();
            }
        }

        return response()->json([
            'internal' => $this->taskCollaboratorService
                ->internalCandidates($project, $search)
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values(),
            'internal_available' => true,
            'internal_error' => null,
            'internal_label' => 'Team',
            'internal_description' => 'Registered SHIFT users on this project.',
            'external' => $external,
            'external_available' => $externalAvailable,
            'external_error' => $externalError,
            'external_label' => "{$project->name} users",
            'external_description' => 'Users available in the selected environment.',
        ]);
    }

    public function showV2(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $task->load(['submitter', 'attachments', 'metadata', 'internalCollaborators', 'externalCollaborators']);

        return response()->json($this->serializeTaskDetail($task));
    }

    public function updateV2(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $isOwner = $task->submitter_type === User::class && $task->submitter_id === auth()->id();
        if (! $isOwner) {
            $attributes = $request->validate([
                'status' => ['required', Rule::enum(TaskStatus::class)],
            ]);

            $task->status = $attributes['status'];
            $task->save();
            $task->load(['attachments', 'submitter', 'metadata', 'internalCollaborators', 'externalCollaborators']);

            return response()->json([
                'ok' => true,
                'task' => $this->serializeTaskDetail($task),
            ]);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'environment' => 'nullable|string|max:255',
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
        ]);
        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = $this->sanitizeRichContent($attributes['description']);
        }

        $selectedEnvironment = $this->resolveTaskEnvironment(
            $task->project()->with('environments')->firstOrFail(),
            $attributes['environment'] ?? null,
            $this->externalCollaboratorsRequested($attributes),
        );
        $selectedEnvironmentUrl = $selectedEnvironment !== null
            ? $task->project->environments()->where('environment', $selectedEnvironment)->value('url')
            : null;

        $task->title = $attributes['title'];
        $task->priority = $attributes['priority'];
        $task->status = $attributes['status'];
        $task->description = $attributes['description'] ?? null;
        $task->save();
        $this->syncTaskEnvironment($task, $selectedEnvironment, $selectedEnvironmentUrl);

        if (! empty($attributes['deleted_attachment_ids'])) {
            foreach ($attributes['deleted_attachment_ids'] as $attachmentId) {
                $attachment = Attachment::find($attachmentId);

                if ($attachment && $attachment->attachable_id === $task->id && $attachment->attachable_type === Task::class) {
                    if (Storage::exists($attachment->path)) {
                        Storage::delete($attachment->path);
                    }
                    $attachment->delete();
                }
            }
        }

        if (! empty($attributes['temp_identifier'])) {
            $created = $this->persistTempAttachmentsForTask($task, $attributes['temp_identifier']);

            if ($task->description) {
                $task->description = $this->replaceTempUrlsInContent($task->description, $attributes['temp_identifier'], $created);
                $task->save();
            }
        }

        $syncResult = $this->syncCollaborators($task, $attributes, $selectedEnvironment);
        $this->sendCollaboratorAddedNotifications($task, $syncResult);
        $task->load(['attachments', 'submitter', 'metadata', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'task' => $this->serializeTaskDetail($task),
        ]);
    }

    public function updateCollaboratorsV2(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        if (! $this->canManageCollaborators($task)) {
            abort(403);
        }

        $attributes = $request->validate([
            'environment' => 'nullable|string|max:255',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
        ]);

        $rawEnvironment = array_key_exists('environment', $attributes)
            ? ($attributes['environment'] ?? null)
            : $this->taskEnvironment($task);

        $selectedEnvironment = $this->resolveTaskEnvironment(
            $task->project()->with('environments')->firstOrFail(),
            $rawEnvironment,
            $this->externalCollaboratorsRequested($attributes) && ! filled($rawEnvironment),
        );

        $selectedEnvironmentUrl = $selectedEnvironment !== null
            ? $task->project->environments()->where('environment', $selectedEnvironment)->value('url')
            : null;

        $this->syncTaskEnvironment($task, $selectedEnvironment, $selectedEnvironmentUrl);
        $syncResult = $this->syncCollaborators($task, $attributes, $selectedEnvironment);
        $this->sendCollaboratorAddedNotifications($task, $syncResult);

        $task->load(['attachments', 'submitter', 'metadata', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'task' => $this->serializeTaskDetail($task),
        ]);
    }

    public function finalizeRequirementV2(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);
        $task->load(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        if (! $task->metadata || ! $task->isRequirementPhase()) {
            return response()->json([
                'message' => 'Only requirement-phase items can be finalized.',
            ], 422);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        DB::transaction(function () use ($task, $attributes) {
            $this->finalizeRequirementTask($task, $attributes['title'], $attributes['description'] ?? null);
        });

        $task->load(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'task' => $this->serializeTaskDetail($task),
        ]);
    }

    public function finalizeRequirementBatchV2(RequirementBatch $requirementBatch): JsonResponse
    {
        if (! $this->visibleProjectsQuery()->whereKey($requirementBatch->project_id)->exists()) {
            abort(404);
        }

        $tasks = $this->visibleTasksQuery()
            ->with(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators'])
            ->requirementIntake()
            ->whereHas('metadata', function (Builder $metadataQuery) use ($requirementBatch) {
                $metadataQuery
                    ->where('requirement_batch_id', $requirementBatch->id)
                    ->where('phase', 'requirement');
            })
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            return response()->json([
                'message' => 'No requirement-phase items are available to finalize for this pack.',
            ], 422);
        }

        DB::transaction(function () use ($tasks) {
            $tasks->each(function (Task $task) {
                $this->finalizeRequirementTask($task, $task->title, $task->description);
            });
        });

        $tasks->load(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'finalized_count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $task) => $this->serializeTaskDetail($task))->values()->all(),
        ]);
    }

    private function finalizeRequirementTask(Task $task, string $title, ?string $description): void
    {
        $task->title = $title;
        $task->description = $this->sanitizeRichContent($description);
        $task->save();

        $task->metadata->forceFill([
            'phase' => 'task',
            'finalized_at' => now(),
            'finalized_by' => auth()->id(),
        ])->save();

        $thread = new TaskThread([
            'type' => 'internal',
            'content' => '<p>Requirement finalized as task.</p>',
            'sender_name' => auth()->user()?->name ?? 'SHIFT',
        ]);
        $thread->sender()->associate(auth()->user());
        $task->threads()->save($thread);
    }

    public function destroyV2(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $task->delete();

        return response()->json(['ok' => true]);
    }

    public function storeV2(Request $request): JsonResponse
    {
        $attributes = $this->validateStoreAttributes($request);
        $task = $this->createTaskFromAttributes($attributes);
        $task->load(['metadata', 'submitter', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'data' => $this->serializeTaskDetail($task, false),
        ], 201);
    }

    /**
     * Send creation notifications only to the submitter and explicitly tagged collaborators.
     */
    protected function sendTaskCreationNotifications(Task $task)
    {
        $task->load(['submitter', 'internalCollaborators', 'externalCollaborators', 'project']);

        $usersToNotify = $this->taskCollaboratorService->internalTaskCreateAudience($task);
        if ($usersToNotify->isNotEmpty()) {
            Notification::send($usersToNotify, new TaskCreationNotification($task));
        }

        foreach ($this->taskCollaboratorService->externalTaskCreateAudience($task) as $externalUser) {
            if ($externalUser->email !== null || $externalUser->url !== null) {
                NotifyExternalUser::dispatch($externalUser->id, $task->id);
            }
        }
    }
}
