<?php

namespace App\Http\Controllers;

use App\Enums\RequirementStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\SearchTaskCollaboratorsRequest;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Notifications\TaskCreationNotification;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalCollaboratorService;
use App\Services\ProjectEnvironmentService;
use App\Services\ShiftPermissionService;
use App\Services\TaskCollaboratorNotificationScheduler;
use App\Services\TaskCollaboratorService;
use App\Services\TemporaryAttachmentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    use ManagesPortalTaskAttachments;
    use RendersTaskIndex;
    use SerializesPortalTasks;

    public const TASK_NOT_FOUND_MESSAGE = 'This task is no longer available or you do not have access to it.';

    public function __construct(
        private readonly ExternalCollaboratorService $externalCollaborators,
        private readonly TaskCollaboratorService $taskCollaboratorService,
        private readonly TaskCollaboratorNotificationScheduler $collaboratorNotifications,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly ShiftPermissionService $permissions,
        private readonly TemporaryAttachmentStorage $temporaryAttachments,
    ) {}

    private function ensureTaskVisible(Task $task): void
    {
        if (! $this->visibleTasksQuery()->whereKey($task->id)->exists()) {
            abort(404, self::TASK_NOT_FOUND_MESSAGE);
        }
    }

    private function markTaskNotificationsAsRead(Task $task): void
    {
        auth()->user()?->unreadNotifications()
            ->whereIn('type', [
                TaskCreationNotification::class,
                TaskCollaboratorAddedNotification::class,
                TaskThreadUpdated::class,
            ])
            ->where('data->task_id', $task->id)
            ->get()
            ->markAsRead();
    }

    private function externalCollaboratorsRequested(array $attributes): bool
    {
        return ! empty($attributes['external_collaborators'] ?? []);
    }

    private function resolveTaskEnvironment(Project $project, ?string $environment): ?string
    {
        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($environment);

        if ($normalizedEnvironment === null) {
            return null;
        }

        if (! $project->environments()->where('environment', $normalizedEnvironment)->exists()) {
            throw ValidationException::withMessages([
                'environment' => 'The selected environment is not registered for this project.',
            ]);
        }

        return $normalizedEnvironment;
    }

    private function syncTaskEnvironment(Task $task, ?string $environment, ?string $url = null, bool $preserveMetadata = false): void
    {
        if ($environment === null) {
            if ($preserveMetadata) {
                return;
            }

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

    private function validateStoreAttributes(Request $request): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'environment' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'phase' => ['nullable', Rule::in(['task', 'requirement'])],
            'temp_identifier' => ['nullable', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
            'external_collaborators.*.environment' => 'nullable|string|max:255',
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

        $project = Project::query()->findOrFail($attributes['project_id']);
        if (! $this->permissions->canCreateTaskForProject($project, auth()->id())) {
            throw ValidationException::withMessages([
                'project_id' => 'You do not have permission to create tasks for this project.',
            ]);
        }

        return $attributes;
    }

    private function resolveExternalCollaboratorsForProject(Project $project, ?string $environment, array $attributes, Task $task): \Illuminate\Support\Collection
    {
        return $this->externalCollaborators->resolveCollaborators(
            $project,
            $environment,
            $attributes['external_collaborators'] ?? [],
            $task->externalCollaborators,
        );
    }

    private function canManageCollaborators(Task $task): bool
    {
        return $this->permissions->canManageTaskCollaborators($task, auth()->id());
    }

    private function syncCollaborators(Task $task, array $attributes, ?string $environment): array
    {
        $project = $task->project()->firstOrFail();
        $internalIds = $this->taskCollaboratorService->validateInternalCollaboratorIds(
            $project,
            $attributes['internal_collaborator_ids'] ?? [],
        );
        try {
            $externalIdentities = $this->resolveExternalCollaboratorsForProject($project, $environment, $attributes, $task);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'external_collaborators' => $exception->getMessage(),
            ]);
        }

        return $this->taskCollaboratorService->syncWithResult($task, $internalIds, $externalIdentities);
    }

    private function sendCollaboratorAddedNotifications(Task $task, array $syncResult): void
    {
        $this->collaboratorNotifications->scheduleCollaboratorAdded($task, $syncResult);
    }

    private function createTaskFromAttributes(array $attributes): Task
    {
        $project = Project::query()
            ->with('environments')
            ->findOrFail($attributes['project_id']);
        $environment = $this->resolveTaskEnvironment(
            $project,
            $attributes['environment'] ?? null,
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
        if (($attributes['phase'] ?? 'task') === 'requirement') {
            $this->syncPortalRequirementMetadata($task, [
                ...$attributes,
                'url' => $environmentUrl ?? config('app.url'),
            ]);
        }
        $this->syncCollaborators($task, $attributes, $environment);

        $this->sendTaskCreationNotifications($task);

        if (! empty($attributes['temp_identifier'])) {
            $created = $this->persistTempAttachmentsForTask($task, $attributes['temp_identifier']);

            if ($task->description) {
                $task->description = $this->replaceTempUrlsInContent($task->description, $attributes['temp_identifier'], $created);
                $task->save();

                if (($attributes['phase'] ?? 'task') === 'requirement') {
                    $this->syncPortalRequirementMetadata($task, [
                        ...$attributes,
                        'description' => $task->description,
                        'url' => $environmentUrl ?? config('app.url'),
                    ]);
                }
            }
        }

        return $task->fresh();
    }

    private function syncPortalRequirementMetadata(Task $task, array $attributes): void
    {
        $task->metadata()->updateOrCreate(
            ['task_id' => $task->id],
            [
                'phase' => 'requirement',
                'url' => $attributes['url'] ?? $task->metadata?->url ?? config('app.url'),
                'source' => 'embedded_requirement_pack',
                'intake_type' => 'requirement',
                'requirement_status' => RequirementStatus::Submitted->value,
                'submitted_title' => $attributes['title'],
                'submitted_description' => $attributes['description'] ?? null,
            ],
        );
    }

    /**
     * Move all temp attachments for a given identifier to this task, preserving basenames
     * so we can safely swap temp URLs in rich HTML.
     */
    public function collaborators(Project $project, SearchTaskCollaboratorsRequest $request): JsonResponse
    {
        if (! $this->visibleProjectsQuery()->whereKey($project->id)->exists()) {
            abort(404);
        }

        $search = trim((string) $request->input('search', ''));
        $environment = $request->input('environment');

        $external = [];
        $externalAvailable = false;
        $externalError = null;

        if ($search !== '') {
            $lookup = $this->externalCollaborators->searchProjectCollaborators($project, $environment, $search);
            $external = $lookup['users'];
            $externalAvailable = $lookup['available'];
            $externalError = $lookup['error'];
        }

        return response()->json([
            'internal' => $this->taskCollaboratorService
                ->searchInternalCandidates($project, $search)
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values(),
            'internal_available' => true,
            'internal_error' => null,
            'internal_label' => 'SHIFT team',
            'internal_description' => 'Registered SHIFT users on this project.',
            'external' => $external,
            'external_available' => $externalAvailable,
            'external_error' => $externalError,
            'external_label' => "{$project->name} users",
            'external_description' => filled($environment) ? 'Users available in the selected environment.' : 'Users available across this project’s environments.',
        ]);
    }

    public function show(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);
        $this->markTaskNotificationsAsRead($task);

        $task->load(['submitter', 'attachments', 'metadata', 'internalCollaborators', 'externalCollaborators']);

        return response()->json($this->serializeTaskDetail($task));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        abort_unless($this->permissions->canEditTask($task, auth()->id()), 403);

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'requirement_status' => ['nullable', Rule::enum(RequirementStatus::class)],
            'environment' => 'nullable|string|max:255',
            'temp_identifier' => ['nullable', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
            'external_collaborators.*.environment' => 'nullable|string|max:255',
        ]);
        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = $this->sanitizeRichContent($attributes['description']);
        }

        $project = $task->project()->with('environments')->firstOrFail();
        $hasEnvironmentField = array_key_exists('environment', $attributes);
        $hasCollaboratorFields = array_key_exists('internal_collaborator_ids', $attributes)
            || array_key_exists('external_collaborators', $attributes);
        $externalCollaboratorsRequested = $this->externalCollaboratorsRequested($attributes);
        $rawEnvironment = $hasEnvironmentField
            ? ($attributes['environment'] ?? null)
            : ($externalCollaboratorsRequested ? $this->taskEnvironment($task) : null);

        $selectedEnvironment = null;
        if ($hasEnvironmentField || $externalCollaboratorsRequested) {
            $selectedEnvironment = $this->resolveTaskEnvironment(
                $project,
                $rawEnvironment,
            );
        }
        $selectedEnvironmentUrl = $selectedEnvironment !== null
            ? $project->environments()->where('environment', $selectedEnvironment)->value('url')
            : null;

        $task->title = $attributes['title'];
        $task->priority = $attributes['priority'];
        $task->status = $attributes['status'];
        $task->description = $attributes['description'] ?? null;
        $task->save();
        $wasRequirementPhase = $task->isRequirementPhase();

        if ($hasEnvironmentField) {
            $this->syncTaskEnvironment($task, $selectedEnvironment, $selectedEnvironmentUrl, $wasRequirementPhase);
        }
        if ($wasRequirementPhase && array_key_exists('requirement_status', $attributes)) {
            $task->metadata()->updateOrCreate(
                ['task_id' => $task->id],
                ['requirement_status' => $attributes['requirement_status'] ?? RequirementStatus::Submitted->value],
            );
        }

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

        if ($hasCollaboratorFields) {
            $syncResult = $this->syncCollaborators($task, $attributes, $selectedEnvironment);
            $this->sendCollaboratorAddedNotifications($task, $syncResult);
        }
        $task->load(['attachments', 'submitter', 'metadata', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'task' => $this->serializeTaskDetail($task),
        ]);
    }

    public function updateCollaborators(Request $request, Task $task): JsonResponse
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
            'external_collaborators.*.environment' => 'nullable|string|max:255',
        ]);

        $syncResult = $this->syncCollaborators($task, $attributes, $this->taskEnvironment($task));
        $this->sendCollaboratorAddedNotifications($task, $syncResult);

        $task->load(['attachments', 'submitter', 'metadata', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'task' => $this->serializeTaskDetail($task),
        ]);
    }

    public function finalizeRequirement(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);
        abort_unless($this->permissions->canFinalizeRequirement($task, auth()->id()), 403);

        $task->load(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        if (! $task->metadata || ! $task->isRequirementPhase()) {
            return response()->json([
                'message' => 'Only requirement-phase items can be finalized.',
            ], 422);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'requirement_status' => ['nullable', Rule::enum(RequirementStatus::class)],
        ]);

        $requirementStatus = $attributes['requirement_status'] ?? $task->requirementStatus();
        if ($requirementStatus !== RequirementStatus::ReadyToFinalize->value) {
            return response()->json([
                'message' => 'Only ready-to-finalize requirements can be finalized.',
            ], 422);
        }

        DB::transaction(function () use ($task, $attributes) {
            $this->finalizeRequirementTask($task, $attributes['title'], $attributes['description'] ?? null);
        });

        $task->load(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        return response()->json([
            'ok' => true,
            'task' => $this->serializeTaskDetail($task),
        ]);
    }

    public function finalizeRequirementBatch(RequirementBatch $requirementBatch): JsonResponse
    {
        if (! $this->visibleProjectsQuery()->whereKey($requirementBatch->project_id)->exists()) {
            abort(404);
        }

        $project = Project::query()->findOrFail($requirementBatch->project_id);
        abort_unless($this->permissions->canManageProjectAccess($project, auth()->id()), 403);

        $tasks = $this->visibleTasksQuery()
            ->with(['metadata', 'submitter', 'attachments', 'internalCollaborators', 'externalCollaborators'])
            ->requirementIntake()
            ->whereHas('metadata', function (Builder $metadataQuery) use ($requirementBatch) {
                $metadataQuery
                    ->where('requirement_batch_id', $requirementBatch->id)
                    ->where('phase', 'requirement')
                    ->where('requirement_status', RequirementStatus::ReadyToFinalize->value);
            })
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            return response()->json([
                'message' => 'No ready-to-finalize requirement items are available to finalize for this group.',
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
            'requirement_status' => RequirementStatus::ReadyToFinalize->value,
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

    public function destroy(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);
        abort_unless($this->permissions->canDeleteTask($task, auth()->id()), 403);

        $task->delete();

        return response()->json(['ok' => true]);
    }

    public function store(Request $request): JsonResponse
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
     * Send creation notifications only to explicitly tagged collaborators, excluding the creator.
     */
    protected function sendTaskCreationNotifications(Task $task)
    {
        $this->collaboratorNotifications->scheduleTaskCreated($task);
    }
}
