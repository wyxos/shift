<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\NotifyExternalCollaboratorAdded;
use App\Jobs\NotifyExternalUser;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Notifications\TaskCreationNotification;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use App\Services\TaskCollaboratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

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
        $userId = auth()->id();

        return Project::query()->where(function ($query) use ($userId) {
            $query
                ->whereHas('client.organisation', function ($organisationQuery) use ($userId) {
                    $organisationQuery->where('author_id', $userId);
                })
                ->orWhereHas('organisation', function ($organisationQuery) use ($userId) {
                    $organisationQuery->where('author_id', $userId);
                })
                ->orWhere('author_id', $userId)
                ->orWhereHas('projectUser', function ($projectUserQuery) use ($userId) {
                    $projectUserQuery->where('user_id', $userId);
                });
        });
    }

    private function externalCollaboratorsRequested(array $attributes): bool
    {
        return ! empty($attributes['external_collaborators'] ?? [])
            || ! empty($attributes['external_user_ids'] ?? []);
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

    private function validateStoreAttributes(Request $request, bool $allowLegacyExternalUserIds = false): array
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

        if ($allowLegacyExternalUserIds) {
            $rules['external_user_ids'] = 'nullable|array';
            $rules['external_user_ids.*'] = 'exists:external_users,id';
        }

        $attributes = $request->validate($rules);

        if (! $this->visibleProjectsQuery()->whereKey($attributes['project_id'])->exists()) {
            throw ValidationException::withMessages([
                'project_id' => 'The selected project is invalid.',
            ]);
        }

        return $attributes;
    }

    private function resolveExternalCollaboratorsForProject(Project $project, ?string $environment, array $attributes): \Illuminate\Support\Collection
    {
        if (! empty($attributes['external_user_ids'] ?? [])) {
            $externalUsers = ExternalUser::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $attributes['external_user_ids'])
                ->get();

            if ($externalUsers->count() !== count(array_unique(array_map('intval', $attributes['external_user_ids'])))) {
                throw ValidationException::withMessages([
                    'external_user_ids' => 'One or more external collaborators are invalid for this project.',
                ]);
            }

            return $externalUsers->values();
        }

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
        return [
            'internal_collaborators' => $task->internalCollaborators
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'external_collaborators' => $task->externalCollaborators
                ->map(fn (ExternalUser $user) => [
                    'id' => $user->external_id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
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

    public function index()
    {
        $query = $this->visibleTasksQuery()
            ->with(['submitter', 'metadata', 'project.organisation', 'project.client'])
            ->latest();

        $this->applyIndexFilters($query, request()->only(['search', 'project_id', 'priority', 'status']));

        $tasks = $query->paginate(10)->withQueryString();

        $tasks->through(function (Task $task) {
            $task->is_external = $task->isExternallySubmitted();

            return $task;
        });

        $projects = $this->visibleProjectsQuery()->get();

        return inertia('Tasks/IndexLegacy')
            ->with([
                'filters' => request()->only(['search', 'project_id', 'priority', 'status']),
                'tasks' => $tasks,
                'projects' => $projects,
            ]);
    }

    public function indexV2()
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
        $sortBy = in_array((string) request('sort_by'), $allowedSortBy, true) ? (string) request('sort_by') : $defaultSortBy;

        $query = $this->visibleTasksQuery()
            ->with(['metadata:id,task_id,environment', 'submitter']);

        $query->whereIn('status', $selectedStatuses);

        if (count($selectedPriorities) > 0 && count($selectedPriorities) < count($allPriorities)) {
            $query->whereIn('priority', $selectedPriorities);
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$search.'%']);
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
                    'sort_by' => $sortBy,
                ],
                'tasks' => $tasks,
                'projects' => $this->visibleProjectsQuery()
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
            'external' => $external,
            'external_available' => $externalAvailable,
            'external_error' => $externalError,
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

    public function destroyV2(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $task->delete();

        return response()->json(['ok' => true]);
    }

    // create task

    public function edit(Task $task)
    {
        $task->load(['project', 'attachments', 'externalUsers', 'submitter']);

        // Check if task was submitted by an external user
        $isExternallySubmitted = $task->isExternallySubmitted();

        if ($isExternallySubmitted) {
            // If submitted by external user, only show external users from the same environment
            $submitterEnvironment = $task->submitter->environment;
            $projectExternalUsers = $task->project->externalUsers()
                ->where('environment', $submitterEnvironment)
                ->get();
        } else {
            // If submitted by internal user, show all external users from the project
            $projectExternalUsers = $task->project->externalUsers;
        }

        // Get the IDs of external users that have access to this task
        $taskExternalUserIds = $task->externalUsers->pluck('id')->toArray();

        return inertia('Tasks/Edit')
            ->with([
                'task' => $task,
                'project' => $task->project,
                'projectExternalUsers' => $projectExternalUsers,
                'taskExternalUserIds' => $taskExternalUserIds,
                'attachments' => $task->attachments->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        'url' => route('attachments.download', $attachment),
                    ];
                }),
            ]);
    }

    // edit task

    public function destroy(Task $task)
    {
        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    // delete route

    public function update(Task $task)
    {
        // Handle web form submission
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'environment' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
            'external_user_ids' => 'nullable|array',
            'external_user_ids.*' => 'exists:external_users,id',
        ]);
        $selectedEnvironment = $this->resolveTaskEnvironment(
            $task->project()->with('environments')->firstOrFail(),
            $attributes['environment'] ?? null,
            $this->externalCollaboratorsRequested($attributes),
        );
        $selectedEnvironmentUrl = $selectedEnvironment !== null
            ? $task->project->environments()->where('environment', $selectedEnvironment)->value('url')
            : null;

        $task->update([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? $task->description,
            'status' => $attributes['status'] ?? $task->status,
            'priority' => $attributes['priority'] ?? $task->priority,
        ]);

        $this->syncTaskEnvironment($task, $selectedEnvironment, $selectedEnvironmentUrl);
        $syncResult = $this->syncCollaborators($task, $attributes, $selectedEnvironment);
        $this->sendCollaboratorAddedNotifications($task, $syncResult);

        // Handle deleted attachments
        if (isset($attributes['deleted_attachment_ids']) && count($attributes['deleted_attachment_ids']) > 0) {
            foreach ($attributes['deleted_attachment_ids'] as $attachmentId) {
                $attachment = Attachment::find($attachmentId);

                if ($attachment && $attachment->attachable_id === $task->id && $attachment->attachable_type === Task::class) {
                    // Delete the file if it exists
                    if (Storage::exists($attachment->path)) {
                        Storage::delete($attachment->path);
                    }

                    // Delete the attachment record
                    $attachment->delete();
                }
            }
        }

        // Handle new attachments if temp_identifier is provided
        if (isset($attributes['temp_identifier'])) {
            $tempIdentifier = $attributes['temp_identifier'];
            $tempPath = "temp_attachments/{$tempIdentifier}";

            // Check if temp directory exists
            if (Storage::exists($tempPath)) {
                // Get all files in the temp directory
                $files = Storage::files($tempPath);

                // Create permanent directory if it doesn't exist
                $permanentPath = "attachments/{$task->id}";
                if (! Storage::exists($permanentPath)) {
                    Storage::makeDirectory($permanentPath);
                }

                // Move each file to the permanent location and create attachment records
                foreach ($files as $file) {
                    // Skip metadata files
                    if (Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    // Try to get original filename from metadata
                    $metadataPath = $file.'.meta';
                    $originalFilename = basename($file);

                    if (Storage::exists($metadataPath)) {
                        $metadata = json_decode(Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    // Generate a unique filename for storage
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $storedFilename = pathinfo($originalFilename, PATHINFO_FILENAME).'_'.uniqid().'.'.$extension;
                    $newPath = "{$permanentPath}/{$storedFilename}";

                    // Move the file
                    Storage::move($file, $newPath);

                    // Create attachment record
                    Attachment::create([
                        'attachable_id' => $task->id,
                        'attachable_type' => Task::class,
                        'original_filename' => $originalFilename,
                        'path' => $newPath,
                    ]);
                }

                // Remove the temp directory
                Storage::deleteDirectory($tempPath);
            }
        }

        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    // put task

    public function create()
    {
        $projects = $this->visibleProjectsQuery()
            ->with('environments')
            ->get();

        // Load external users for each project
        $projects->each(function ($project) {
            $project->load('externalUsers');
        });

        return inertia('Tasks/Create')
            ->with([
                'projects' => $projects->map(fn (Project $project) => $this->serializeProject($project))->values()->all(),
            ]);
    }

    // create task

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

    public function store(Request $request)
    {
        $attributes = $this->validateStoreAttributes($request, true);
        $this->createTaskFromAttributes($attributes);

        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }

    /**
     * Send task creation notifications to project owner and users with access to the project.
     * Excludes the creator of the task from receiving notifications.
     * Also notifies external users attached to the task.
     *
     * @return void
     */
    protected function sendTaskCreationNotifications(Task $task)
    {
        $task->load(['submitter', 'internalCollaborators', 'externalCollaborators', 'project.author', 'project.projectUser.user']);

        $creatorId = $task->submitter_type === User::class ? $task->submitter_id : null;
        $usersToNotify = $this->taskCollaboratorService->internalAudience($task, $creatorId);
        if ($usersToNotify->isNotEmpty()) {
            Notification::send($usersToNotify, new TaskCreationNotification($task));
        }

        $creatorExternalUserId = $task->submitter instanceof ExternalUser ? $task->submitter->id : null;
        foreach ($this->taskCollaboratorService->externalAudience($task, $creatorExternalUserId) as $externalUser) {
            if ($externalUser->email !== null || $externalUser->url !== null) {
                NotifyExternalUser::dispatch($externalUser->id, $task->id);
            }
        }
    }

    public function show(Task $task)
    {
        return inertia('Tasks/Show')
            ->with([
                'task' => $task,
            ]);
    }

    /**
     * Update the status of a task.
     *
     * @return Response|RedirectResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        $this->ensureTaskVisible($task);

        $validatedData = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        return back()->with([
            'status' => $task->status,
            'message' => 'Task status updated successfully',
        ]);
    }

    /**
     * Update the priority of a task.
     *
     * @return Response|RedirectResponse
     */
    public function togglePriority(Task $task, Request $request)
    {
        $this->ensureTaskVisible($task);

        $validatedData = $request->validate([
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        return back()->with([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully',
        ]);
    }
}
