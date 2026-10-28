<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequirementStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchTaskCollaboratorsRequest;
use App\Models\Attachment;
use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Services\ExternalActorResolver;
use App\Services\ExternalCollaboratorService;
use App\Services\ExternalIdentityService;
use App\Services\ExternalTaskAccess;
use App\Services\ProjectEnvironmentService;
use App\Services\TaskCollaboratorNotificationScheduler;
use App\Services\TaskCollaboratorService;
use App\Services\TemporaryAttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExternalTaskController extends Controller
{
    use Concerns\PersistsExternalTaskAttachments;
    use Concerns\ReadsExternalTasks;

    public function __construct(
        private readonly ExternalIdentityService $externalIdentityService,
        private readonly ExternalActorResolver $actors,
        private readonly ExternalCollaboratorService $externalCollaborators,
        private readonly ExternalTaskAccess $externalTaskAccess,
        private readonly TaskCollaboratorService $taskCollaboratorService,
        private readonly TaskCollaboratorNotificationScheduler $collaboratorNotifications,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly TemporaryAttachmentStorage $temporaryAttachments,
    ) {}

    private function resolveProjectFromRequest(): ?Project
    {
        return Project::query()
            ->visibleTo(auth()->id())
            ->where('token', request('project'))
            ->first();
    }

    private function currentExternalIdentity(Project $project, bool $create = false): ?ExternalIdentity
    {
        return $this->actors->fromRequest($project, request(), $create)?->identity;
    }

    private function externalIdentityHasAccess(Task $task, ExternalIdentity $externalIdentity): bool
    {
        return $this->externalTaskAccess->canViewProjectItem($task, $externalIdentity);
    }

    private function canManageCollaborators(Task $task, ?ExternalIdentity $externalIdentity): bool
    {
        return $this->taskCollaboratorService->canManageForExternalIdentity($task, $externalIdentity);
    }

    private function resolveTaskEnvironment(Project $project, array $attributes, ?string $fallbackEnvironment = null): ?string
    {
        $rawEnvironment = array_key_exists('environment', $attributes)
            ? ($attributes['environment'] ?? null)
            : ($attributes['metadata']['environment'] ?? $attributes['user']['environment'] ?? $fallbackEnvironment);

        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($rawEnvironment);

        if ($normalizedEnvironment === null) {
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

    private function resolveExternalCollaborators(Project $project, ?string $environment, array $attributes, Task $task): \Illuminate\Support\Collection
    {
        return $this->externalCollaborators->resolveCollaborators(
            $project,
            $environment,
            $attributes['external_collaborators'] ?? [],
            $task->externalCollaborators,
        );
    }

    private function syncCollaborators(
        Task $task,
        Project $project,
        array $attributes,
        ?string $environment,
        bool $initialize = false,
    ): array {
        $internalIds = $this->taskCollaboratorService->validateInternalCollaboratorIds(
            $project,
            $attributes['internal_collaborator_ids'] ?? [],
        );

        try {
            $externalIdentities = $this->resolveExternalCollaborators($project, $environment, $attributes, $task);
        } catch (\RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'external_collaborators' => $exception->getMessage(),
            ]);
        }

        return $initialize
            ? $this->taskCollaboratorService->initialize(
                $task,
                $internalIds,
                $externalIdentities,
                (bool) ($attributes['include_submitter_as_collaborator'] ?? true),
            )
            : $this->taskCollaboratorService->syncWithResult($task, $internalIds, $externalIdentities);
    }

    private function sendCollaboratorAddedNotifications(Task $task, array $syncResult): void
    {
        $this->collaboratorNotifications->scheduleCollaboratorAdded(
            $task,
            $syncResult,
            route('tasks.index', ['task' => $task->id]),
        );
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
            'temp_identifier' => ['nullable', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
            'external_collaborators.*.environment' => 'nullable|string|max:255',
            'include_submitter_as_collaborator' => 'sometimes|boolean',
        ]);

        if (isset($attributes['description'])) {
            $attributes['description'] = $this->sanitizeRichContent(
                $this->normalizeDownloadUrlsToInternal((string) $attributes['description'])
            );
        }

        $project = $this->resolveProjectFromRequest();
        if ($project === null) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $project->load('environments');
        $selectedEnvironment = $this->resolveTaskEnvironment($project, $attributes);

        $externalIdentity = null;

        if (isset($attributes['user'])) {
            $externalIdentity = $this->externalIdentityService->upsert($project, [
                'external_id' => $attributes['user']['id'],
                'environment' => $attributes['user']['environment'],
                'url' => $attributes['user']['url'],
                'name' => $attributes['user']['name'] ?? null,
                'email' => $attributes['user']['email'] ?? null,
            ]);
        }

        $task = Task::create([
            ...$attributes,
            'project_id' => $project->id,
            'status' => $attributes['status'] ?? 'pending',
            'priority' => $attributes['priority'] ?? 'medium',
        ]);

        if ($externalIdentity instanceof ExternalIdentity) {
            $task->submitter()->associate($externalIdentity)->save();
        }

        $environmentUrl = request('metadata.url') ?? request('user.url');
        if ($selectedEnvironment !== null || isset($attributes['metadata'])) {
            $this->syncTaskEnvironment($task, $selectedEnvironment, $environmentUrl);
        }

        $this->syncCollaborators($task, $project, $attributes, $selectedEnvironment, initialize: true);

        $this->sendTaskCreationNotifications($task);

        $this->promoteTemporaryAttachments($request, $task, $attributes);

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        $clientUrl = (string) (request('metadata.url') ?? request('user.url') ?? config('app.url'));

        return response()->json(
            $this->serializeTaskPayload($task, $clientUrl, $externalIdentity),
            201,
        );
    }

    /**
     * Send creation notifications only to explicitly tagged collaborators, excluding the creator.
     */
    private function sendTaskCreationNotifications(Task $task): void
    {
        $this->collaboratorNotifications->scheduleTaskCreated(
            $task,
            route('tasks.index', ['task' => $task->id]),
        );
    }

    public function internalCollaborators(SearchTaskCollaboratorsRequest $request): JsonResponse
    {
        $project = $this->resolveProjectFromRequest();

        if ($project === null) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $search = trim((string) $request->input('search', ''));
        $project->loadMissing(['client.organisation:id,name', 'organisation:id,name']);
        $organisationName = $project->organisation?->name ?? $project->client?->organisation?->name;

        return response()->json([
            'organisation_name' => $organisationName,
            'users' => $this->taskCollaboratorService
                ->searchInternalCandidates($project, $search)
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

        $externalIdentity = $this->currentExternalIdentity($project);

        if (! $externalIdentity) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalIdentityHasAccess($task, $externalIdentity)) {
            return response()->json(['error' => 'Unauthorized to update this task'], 403);
        }

        if (! $this->canManageCollaborators($task, $externalIdentity)) {
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
            'external_collaborators.*.environment' => 'nullable|string|max:255',
        ]);

        $syncResult = $this->syncCollaborators($task, $project, $attributes, $externalIdentity->environment);
        $this->sendCollaboratorAddedNotifications($task, $syncResult);

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);

        return response()->json(
            $this->serializeTaskPayload(
                $task,
                (string) ($task->metadata?->url ?? $externalIdentity->url ?? config('app.url')),
                $externalIdentity,
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

        $externalIdentity = $this->currentExternalIdentity($project);

        if (! $externalIdentity) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalIdentityHasAccess($task, $externalIdentity)) {
            return response()->json(['error' => 'Unauthorized to update this task'], 403);
        }

        if (! $this->externalTaskAccess->canMutateProjectItem($task, $externalIdentity)) {
            return response()->json(['error' => 'You can only update tasks you submitted'], 403);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'requirement_status' => ['nullable', Rule::enum(RequirementStatus::class)],
            'temp_identifier' => ['nullable', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
        ]);

        if (isset($attributes['description'])) {
            $attributes['description'] = $this->sanitizeRichContent(
                $this->normalizeDownloadUrlsToInternal((string) $attributes['description'])
            );
        }

        $task->update([
            ...collect($attributes)->except('requirement_status')->all(),
            'status' => $attributes['status'] ?? $task->status,
            'priority' => $attributes['priority'] ?? $task->priority,
        ]);

        if ($task->isRequirementPhase() && array_key_exists('requirement_status', $attributes)) {
            $task->metadata()->updateOrCreate(
                ['task_id' => $task->id],
                ['requirement_status' => $attributes['requirement_status'] ?? RequirementStatus::Submitted->value],
            );
        }

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

        $this->promoteTemporaryAttachments($request, $task, $attributes);

        $task->load(['submitter', 'metadata', 'project', 'attachments', 'internalCollaborators', 'externalCollaborators']);
        $clientUrl = (string) (request('metadata.url') ?? request('user.url') ?? config('app.url'));

        return response()->json(
            $this->serializeTaskPayload(
                $task,
                $clientUrl,
                $externalIdentity,
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

        $externalIdentity = $this->currentExternalIdentity($project);

        if (! $externalIdentity) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalIdentityHasAccess($task, $externalIdentity)) {
            return response()->json(['error' => 'Unauthorized to delete this task'], 403);
        }

        if (! $this->externalTaskAccess->canMutateProjectItem($task, $externalIdentity)) {
            return response()->json(['error' => 'You can only delete tasks you submitted'], 403);
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

        $externalIdentity = $this->currentExternalIdentity($project);

        if (! $externalIdentity) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalIdentityHasAccess($task, $externalIdentity)) {
            return response()->json(['error' => 'Unauthorized to update this task status'], 403);
        }

        if (! $this->externalTaskAccess->canMutateProjectItem($task, $externalIdentity)) {
            return response()->json(['error' => 'You can only update status for tasks you submitted'], 403);
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

        $externalIdentity = $this->currentExternalIdentity($project);

        if (! $externalIdentity) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        if (! $this->externalIdentityHasAccess($task, $externalIdentity)) {
            return response()->json(['error' => 'Unauthorized to update this task priority'], 403);
        }

        if (! $this->externalTaskAccess->canMutateProjectItem($task, $externalIdentity)) {
            return response()->json(['error' => 'You can only update priority for tasks you submitted'], 403);
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
}
