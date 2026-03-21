<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyExternalCollaboratorAdded;
use App\Jobs\NotifyExternalUser;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Notifications\TaskCreationNotification;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use App\Services\TaskCollaboratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExternalTaskController extends Controller
{
    public function __construct(
        private readonly ExternalUserService $externalUserService,
        private readonly TaskCollaboratorService $taskCollaboratorService,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    private function resolveProjectFromRequest(): ?Project
    {
        return Project::query()
            ->where('token', request('project'))
            ->first();
    }

    private function currentExternalUser(Project $project, bool $create = false): ?ExternalUser
    {
        $attributes = [
            'external_id' => request()->offsetGet('user.id'),
            'name' => request()->offsetGet('user.name'),
            'email' => request()->offsetGet('user.email'),
            'environment' => request()->offsetGet('user.environment'),
            'url' => request()->offsetGet('user.url'),
        ];

        if ($create) {
            return $this->externalUserService->upsert($project, $attributes);
        }

        return $this->externalUserService->find(
            $project,
            $attributes['external_id'],
            $attributes['environment'],
            $attributes['url'],
        );
    }

    private function externalUserHasAccess(Task $task, ExternalUser $externalUser): bool
    {
        $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
        $hasAccess = $task->externalCollaborators()->where('external_users.id', $externalUser->id)->exists();

        return $isSubmitter || $hasAccess;
    }

    private function canManageCollaborators(Task $task, ?ExternalUser $externalUser): bool
    {
        return $this->taskCollaboratorService->canManageForExternalUser($task, $externalUser);
    }

    private function externalCollaboratorsRequested(array $attributes): bool
    {
        return ! empty($attributes['external_collaborators'] ?? []);
    }

    private function resolveTaskEnvironment(Project $project, array $attributes, ?string $fallbackEnvironment = null): ?string
    {
        $rawEnvironment = array_key_exists('environment', $attributes)
            ? ($attributes['environment'] ?? null)
            : ($attributes['metadata']['environment'] ?? $attributes['user']['environment'] ?? $fallbackEnvironment);

        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($rawEnvironment);

        if ($normalizedEnvironment === null) {
            if ($this->externalCollaboratorsRequested($attributes)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'environment' => 'Select an environment before tagging external collaborators.',
                ]);
            }

            return null;
        }

        if (! $project->environments()->where('environment', $normalizedEnvironment)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
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

    private function resolveExternalCollaborators(Project $project, ?string $environment, array $attributes): \Illuminate\Support\Collection
    {
        return $this->externalUserService->resolveCollaborators(
            $project,
            $environment,
            $attributes['external_collaborators'] ?? [],
        );
    }

    private function syncCollaborators(Task $task, Project $project, array $attributes, ?string $environment): array
    {
        $internalIds = $this->taskCollaboratorService->validateInternalCollaboratorIds(
            $project,
            $attributes['internal_collaborator_ids'] ?? [],
        );

        try {
            $externalUsers = $this->resolveExternalCollaborators($project, $environment, $attributes);
        } catch (\RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'external_collaborators' => $exception->getMessage(),
            ]);
        }

        return $this->taskCollaboratorService->syncWithResult($task, $internalIds, $externalUsers);
    }

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
                ->map(fn (ExternalUser $user) => [
                    'id' => $user->external_id,
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

    private function serializeTaskPayload(Task $task, string $clientUrl, bool $canManageCollaborators): array
    {
        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            'description' => $this->rewriteContentUrlsToClientProxyUrls((string) ($task->description ?? ''), $clientUrl),
            'status' => $task->status,
            'priority' => $task->priority,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'environment' => $this->taskEnvironment($task),
            'submitter' => $task->submitter ? [
                'name' => $task->submitter->name ?? null,
                'email' => $task->submitter->email ?? null,
            ] : null,
            'attachments' => $this->serializeAttachments($task, $clientUrl),
            'can_manage_collaborators' => $canManageCollaborators,
            ...$this->serializeCollaborators($task),
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

    private function sendCollaboratorAddedNotifications(Task $task, array $syncResult): void
    {
        $task->loadMissing('project');

        $addedInternal = $syncResult['added_internal'] ?? collect();
        if ($addedInternal->isNotEmpty()) {
            Notification::send(
                $addedInternal,
                new TaskCollaboratorAddedNotification($task, route('tasks.index', ['task' => $task->id]))
            );
        }

        foreach (($syncResult['added_external'] ?? collect()) as $externalUser) {
            if ($externalUser->email !== null || $externalUser->url !== null) {
                NotifyExternalCollaboratorAdded::dispatch($externalUser->id, $task->id);
            }
        }
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

        $externalUser = $this->currentExternalUser($project, true);

        $tasksQuery = Task::query()
            ->with(['submitter', 'metadata', 'project'])
            ->where('project_id', $project->id)
            ->where(function ($query) use ($externalUser) {
                // Tasks where the external user is the submitter
                $query->whereHasMorph('submitter', [ExternalUser::class], function ($query) use ($externalUser) {
                    $query->where('external_users.id', $externalUser->id);
                })
                    // OR tasks where the external user has been granted access
                    ->orWhereHas('externalCollaborators', function ($query) use ($externalUser) {
                        $query->where('external_users.id', $externalUser->id);
                    });
            })
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
        $tasks->through(function (Task $task) {
            $task->environment = $task->metadata?->environment ?? ($task->submitter->environment ?? null);

            return $task;
        });

        return response()->json($tasks);
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

        $externalUser = $this->currentExternalUser($project);

        if (! $externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalUserHasAccess($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to view this task'], 403);
        }

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        $clientUrl = (string) (request('metadata.url') ?? request('user.url') ?? config('app.url'));

        return response()->json($this->serializeTaskPayload(
            $task,
            $clientUrl,
            $this->canManageCollaborators($task, $externalUser),
        ));
    }

    /**
     * Store a newly created task in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project' => 'required|exists:projects,token',
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'environment' => 'nullable|string|max:255',
            'user.id' => 'nullable',
            'user.name' => 'nullable|string|max:255',
            'user.email' => 'nullable|email',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'temp_identifier' => 'nullable|string',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
        ]);

        if (isset($attributes['description'])) {
            $attributes['description'] = $this->normalizeDownloadUrlsToInternal((string) $attributes['description']);
        }

        $project = Project::query()->with('environments')->where('token', $attributes['project'])->firstOrFail();
        $selectedEnvironment = $this->resolveTaskEnvironment($project, $attributes);

        $task = Task::create([
            ...$attributes,
            'project_id' => $project->id,
            'status' => $attributes['status'] ?? 'pending',
            'priority' => $attributes['priority'] ?? 'medium',
        ]);

        if (isset($attributes['user'])) {
            $externalUser = $this->externalUserService->upsert($project, [
                'external_id' => $attributes['user']['id'],
                'environment' => $attributes['user']['environment'],
                'url' => $attributes['user']['url'],
                'name' => $attributes['user']['name'] ?? null,
                'email' => $attributes['user']['email'] ?? null,
            ]);

            $task->submitter()->associate($externalUser)->save();
        }

        $environmentUrl = request('metadata.url') ?? request('user.url');
        if ($selectedEnvironment !== null || isset($attributes['metadata'])) {
            $this->syncTaskEnvironment($task, $selectedEnvironment, $environmentUrl);
        }

        $this->syncCollaborators($task, $project, $attributes, $selectedEnvironment);

        $this->sendTaskCreationNotifications($task);

        if (isset($attributes['temp_identifier'])) {
            $tempIdentifier = $attributes['temp_identifier'];
            $tempPath = "temp_attachments/{$tempIdentifier}";

            if (Storage::exists($tempPath)) {
                $files = Storage::files($tempPath);

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

                    Attachment::create([
                        'attachable_id' => $task->id,
                        'attachable_type' => Task::class,
                        'original_filename' => $originalFilename,
                        'path' => $newPath,
                    ]);

                    if (Storage::exists($metadataPath)) {
                        Storage::delete($metadataPath);
                    }
                }

                Storage::deleteDirectory($tempPath);
            }
        }

        if (! empty($attributes['temp_identifier'])) {
            $task->load('attachments');
            $task->description = $this->replaceTempUrlsInContent(
                (string) ($task->description ?? ''),
                (string) $attributes['temp_identifier'],
                $task->attachments
            );
            $task->save();
        }

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        $clientUrl = (string) (request('metadata.url') ?? request('user.url') ?? config('app.url'));

        return response()->json(
            $this->serializeTaskPayload($task, $clientUrl, true),
            201,
        );
    }

    /**
     * Send creation notifications only to the submitter and explicitly tagged collaborators.
     */
    private function sendTaskCreationNotifications(Task $task): void
    {
        $task->load(['submitter', 'project', 'internalCollaborators', 'externalCollaborators']);
        $usersToNotify = $this->taskCollaboratorService->internalTaskCreateAudience($task);

        if ($usersToNotify->isNotEmpty()) {
            Notification::send(
                $usersToNotify,
                new TaskCreationNotification($task, route('tasks.index', ['task' => $task->id]))
            );
        }

        foreach ($this->taskCollaboratorService->externalTaskCreateAudience($task) as $externalUser) {
            if ($externalUser->email !== null || $externalUser->url !== null) {
                NotifyExternalUser::dispatch($externalUser->id, $task->id);
            }
        }
    }

    public function internalCollaborators(Request $request): JsonResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $search = trim((string) $request->input('search', ''));

        return response()->json([
            'users' => $this->taskCollaboratorService
                ->internalCandidates($project, $search)
                ->map(fn (\App\Models\User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function updateCollaborators(Request $request, Task $task): JsonResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null || $task->project_id !== $project->id) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalUser = $this->currentExternalUser($project);

        if (! $externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalUserHasAccess($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to update this task'], 403);
        }

        if (! $this->canManageCollaborators($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to update task collaborators'], 403);
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

        $selectedEnvironment = $this->resolveTaskEnvironment($project, $attributes, $this->taskEnvironment($task));
        $this->syncTaskEnvironment($task, $selectedEnvironment, $task->metadata?->url ?? $externalUser->url);
        $syncResult = $this->syncCollaborators($task, $project, $attributes, $selectedEnvironment);
        $this->sendCollaboratorAddedNotifications($task, $syncResult);

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        return response()->json(
            $this->serializeTaskPayload(
                $task,
                (string) ($task->metadata?->url ?? $externalUser->url ?? config('app.url')),
                $this->canManageCollaborators($task, $externalUser),
            )
        );
    }

    /**
     * Update the specified task in storage.
     */
    public function update(Request $request, Task $task): JsonResponse|RedirectResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null || $task->project_id !== $project->id) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalUser = $this->currentExternalUser($project);

        if (! $externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalUserHasAccess($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to update this task'], 403);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
        ]);

        if (isset($attributes['description'])) {
            $attributes['description'] = $this->normalizeDownloadUrlsToInternal((string) $attributes['description']);
        }

        $task->update([
            ...$attributes,
            'status' => $attributes['status'] ?? $task->status,
            'priority' => $attributes['priority'] ?? $task->priority,
        ]);

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

                    // Keep the temp filename stable so we can rewrite inline HTML URLs reliably.
                    $storedFilename = basename($file);
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

                    // Delete metadata file
                    if (Storage::exists($metadataPath)) {
                        Storage::delete($metadataPath);
                    }
                }

                // Remove the temp directory
                Storage::deleteDirectory($tempPath);
            }
        }

        // If this update included inline attachments, rewrite temp URLs to stable download routes.
        if (! empty($attributes['temp_identifier'])) {
            $task->load('attachments');
            $task->description = $this->replaceTempUrlsInContent(
                (string) ($task->description ?? ''),
                (string) $attributes['temp_identifier'],
                $task->attachments
            );
            $task->save();
        }

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);
        $clientUrl = (string) (request('metadata.url') ?? request('user.url') ?? config('app.url'));

        return response()->json(
            $this->serializeTaskPayload(
                $task,
                $clientUrl,
                $this->canManageCollaborators($task, $externalUser),
            ),
            200,
        );
    }

    /**
     * Remove the specified task from storage.
     */
    public function destroy(Task $task, Request $request): JsonResponse|RedirectResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null || $task->project_id !== $project->id) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalUser = $this->currentExternalUser($project);

        if (! $externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalUserHasAccess($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to delete this task'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Task deleted successfully'], 200);
    }

    /**
     * Toggle the status of the specified task.
     */
    public function toggleStatus(Task $task, Request $request): JsonResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null || $task->project_id !== $project->id) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalUser = $this->currentExternalUser($project);

        if (! $externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalUserHasAccess($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to update this task status'], 403);
        }

        $validatedData = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        return response()->json([
            'status' => $task->status,
            'message' => 'Task status updated successfully',
        ]);
    }

    /**
     * Toggle the priority of the specified task.
     */
    public function togglePriority(Task $task, Request $request): JsonResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null || $task->project_id !== $project->id) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalUser = $this->currentExternalUser($project);

        if (! $externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalUserHasAccess($task, $externalUser)) {
            return response()->json(['error' => 'Unauthorized to update this task priority'], 403);
        }

        $validatedData = $request->validate([
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        return response()->json([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully',
        ]);
    }

    /**
     * Replace temp attachment URLs in HTML content with final download URLs.
     *
     * External SDK clients embed images via their proxy route:
     * `/shift/api/attachments/temp/{temp}/{filename}`.
     * After we move files to permanent storage, rewrite those URLs to the internal
     * download route (`/attachments/{id}/download`), then rewrite to the client SDK
     * proxy URL at read time (see rewriteContentUrlsToClientProxyUrls()).
     */
    private function replaceTempUrlsInContent(string $content, string $tempIdentifier, $attachments): string
    {
        if (empty($content) || empty($tempIdentifier) || ! $attachments || $attachments->isEmpty()) {
            return $content;
        }

        $out = $content;
        foreach ($attachments as $attachment) {
            $finalUrl = route('attachments.download', $attachment, false);
            $basename = basename((string) $attachment->path);
            $quotedTemp = preg_quote($tempIdentifier, '#');
            $quotedBase = preg_quote($basename, '#');
            $quotedBaseEnc = preg_quote(rawurlencode($basename), '#');

            $patterns = [
                // SDK proxy route (absolute + relative)
                "#https?://[^\\s\"'<>]+/shift/api/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#https?://[^\\s\"'<>]+/shift/api/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                "#/shift/api/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#/shift/api/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                // Portal-style temp route (absolute + relative)
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

    /**
     * Normalize any attachment download URLs found in content to the internal download route.
     *
     * This ensures we never persist client-specific hostnames in task descriptions.
     */
    private function normalizeDownloadUrlsToInternal(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $patterns = [
            '#https?://[^\"\'<>]+/shift/api/attachments/(\\d+)/download#',
            '#/shift/api/attachments/(\\d+)/download#',
            '#https?://[^\"\'<>]+/attachments/(\\d+)/download#',
            '#/attachments/(\\d+)/download#',
        ];

        $replace = function (string $pattern, string $html) {
            return preg_replace_callback($pattern, function ($m) {
                $id = (int) $m[1];

                return route('attachments.download', ['attachment' => $id], false);
            }, $html) ?? $html;
        };

        $out = $content;
        foreach ($patterns as $pattern) {
            $out = $replace($pattern, $out);
        }

        return $out;
    }

    /**
     * Rewrite any internal attachment download URLs in HTML content to the client SDK proxy URL.
     */
    private function rewriteContentUrlsToClientProxyUrls(string $content, string $clientUrl): string
    {
        if ($content === '' || $clientUrl === '') {
            return $content;
        }

        $clientBase = rtrim($clientUrl, '/');

        $patterns = [
            '#https?://[^\"\'<>]+/attachments/(\\d+)/download#',
            // Only match truly-relative URLs, not the path portion of an absolute URL.
            '#(?<![A-Za-z0-9])/attachments/(\\d+)/download#',
            '#https?://[^\"\'<>]+/shift/api/attachments/(\\d+)/download#',
            // Only match truly-relative URLs, not the path portion of an absolute URL.
            '#(?<![A-Za-z0-9])/shift/api/attachments/(\\d+)/download#',
        ];

        $replace = function (string $pattern, string $html) use ($clientBase) {
            return preg_replace_callback($pattern, function ($m) use ($clientBase) {
                $id = (int) $m[1];

                return $clientBase.'/shift/api/attachments/'.$id.'/download';
            }, $html) ?? $html;
        };

        $out = $content;
        foreach ($patterns as $pattern) {
            $out = $replace($pattern, $out);
        }

        return $out;
    }
}
