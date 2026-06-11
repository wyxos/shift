<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequirementStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use App\Services\TaskCollaboratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExternalRequirementController extends Controller
{
    public function __construct(
        private readonly ExternalUserService $externalUserService,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly TaskCollaboratorService $taskCollaboratorService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'user.id' => 'required',
            'user.name' => 'nullable|string|max:255',
            'user.email' => 'nullable|email',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'metadata.url' => 'nullable|url',
            'search' => 'nullable|string|max:255',
            'environment' => 'nullable|string|max:255',
            'status' => 'nullable',
            'lifecycle' => 'nullable',
            'page' => 'nullable|integer|min:1',
        ]);

        $project = Project::query()
            ->where('token', $attributes['project'])
            ->firstOrFail();

        $externalUser = $this->currentExternalUser($project, $attributes, false);
        if (! $externalUser) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'total' => 0,
                'from' => null,
                'to' => null,
            ]);
        }

        $environment = trim((string) ($attributes['environment'] ?? ''));
        $statuses = $this->normalizeRequirementStatusFilter($attributes['lifecycle'] ?? $attributes['status'] ?? null);

        $requirements = $this->visibleRequirementsQuery($project, $externalUser)
            ->with(['metadata.requirementBatch', 'submitter'])
            ->when(
                filled($attributes['search'] ?? null),
                fn (Builder $query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$attributes['search'].'%'])
            )
            ->when(
                $environment !== '',
                fn (Builder $query) => $query->whereHas('metadata', fn (Builder $metadataQuery) => $metadataQuery->whereRaw('LOWER(environment) = LOWER(?)', [$environment]))
            )
            ->when(
                ! empty($statuses),
                fn (Builder $query) => $this->applyRequirementStatusFilter($query, $statuses)
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();
        $batchSummaries = $this->requirementBatchSummaries($project, $externalUser, $requirements->getCollection());

        $requirements->through(fn (Task $task) => $this->serializeRequirement($task, $batchSummaries, $externalUser));

        return response()->json($requirements);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'title' => 'nullable|string|max:255',
            'user.id' => 'required',
            'user.name' => 'required|string|max:255',
            'user.email' => 'required|email',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'metadata.url' => 'nullable|url',
            'internal_collaborator_ids' => 'nullable|array',
            'internal_collaborator_ids.*' => 'integer',
            'external_collaborators' => 'nullable|array',
            'external_collaborators.*.id' => 'required',
            'external_collaborators.*.name' => 'required|string|max:255',
            'external_collaborators.*.email' => 'required|email',
            'items' => 'required|array|min:1|max:50',
            'items.*.title' => 'required|string|max:255',
            'items.*.description' => 'required|string',
            'items.*.temp_identifier' => 'nullable|string',
            'items.*.internal_collaborator_ids' => 'nullable|array',
            'items.*.internal_collaborator_ids.*' => 'integer',
            'items.*.external_collaborators' => 'nullable|array',
            'items.*.external_collaborators.*.id' => 'required',
            'items.*.external_collaborators.*.name' => 'required|string|max:255',
            'items.*.external_collaborators.*.email' => 'required|email',
        ]);

        $project = Project::query()
            ->with('environments')
            ->where('token', $attributes['project'])
            ->firstOrFail();

        $environment = $this->resolveEnvironment($project, $attributes);
        $sourceUrl = $this->sourceUrl($attributes);
        $externalUser = $this->currentExternalUser($project, $attributes, true);

        if (! $this->externalUserService->canSubmitRequirements($externalUser)) {
            return response()->json(['error' => 'Unauthorized to submit requirements for this project'], 403);
        }

        $created = DB::transaction(function () use ($attributes, $project, $environment, $sourceUrl, $externalUser) {
            $batch = RequirementBatch::query()->create([
                'project_id' => $project->id,
                'external_user_id' => $externalUser?->id,
                'title' => $attributes['title'] ?? null,
            ]);

            $items = collect($attributes['items'])->map(function (array $item) use ($attributes, $project, $environment, $sourceUrl, $externalUser, $batch) {
                $description = $this->sanitizeRichContent($item['description']);
                $task = Task::query()->create([
                    'title' => $item['title'],
                    'description' => $description,
                    'project_id' => $project->id,
                    'status' => TaskStatus::Pending->value,
                    'priority' => TaskPriority::Medium->value,
                ]);

                if ($externalUser instanceof ExternalUser) {
                    $task->submitter()->associate($externalUser)->save();
                }

                $task->metadata()->create([
                    'environment' => $environment ?? 'production',
                    'url' => $sourceUrl,
                    'source' => 'embedded_requirement_pack',
                    'intake_type' => 'requirement',
                    'phase' => 'requirement',
                    'requirement_status' => RequirementStatus::Submitted->value,
                    'requirement_batch_id' => $batch->id,
                    'submitted_title' => $item['title'],
                    'submitted_description' => $description,
                ]);

                $this->syncCollaborators($task, $project, $this->collaboratorAttributesForItem($attributes, $item), $environment);
                $this->persistTempAttachments($task, $item['temp_identifier'] ?? null);

                if (! empty($item['temp_identifier'])) {
                    $task->load('attachments');
                    $task->description = $this->replaceTempUrlsInContent(
                        (string) ($task->description ?? ''),
                        (string) $item['temp_identifier'],
                        $task->attachments
                    );
                    $task->save();
                    $task->metadata()->update([
                        'submitted_description' => $task->description,
                    ]);
                }

                return $task->fresh(['metadata.requirementBatch', 'submitter', 'internalCollaborators', 'externalCollaborators', 'attachments']);
            });

            return [$batch, $items];
        });

        [$batch, $items] = $created;
        $batchSummaries = $this->requirementBatchSummaries($project, $externalUser, $items);

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'project_id' => $batch->project_id,
                'title' => $batch->title,
                'created_at' => $batch->created_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (Task $task) => $this->serializeRequirement($task, $batchSummaries, $externalUser))->values()->all(),
        ], 201);
    }

    private function collaboratorAttributesForItem(array $batchAttributes, array $item): array
    {
        return [
            'internal_collaborator_ids' => $item['internal_collaborator_ids'] ?? $batchAttributes['internal_collaborator_ids'] ?? [],
            'external_collaborators' => $item['external_collaborators'] ?? $batchAttributes['external_collaborators'] ?? [],
        ];
    }

    private function syncCollaborators(Task $task, Project $project, array $attributes, ?string $environment): void
    {
        $internalIds = $this->taskCollaboratorService->validateInternalCollaboratorIds(
            $project,
            $attributes['internal_collaborator_ids'] ?? [],
        );

        try {
            $externalUsers = $this->externalUserService->resolveCollaborators(
                $project,
                $environment,
                $attributes['external_collaborators'] ?? [],
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'external_collaborators' => $exception->getMessage(),
            ]);
        }

        $this->taskCollaboratorService->sync($task, $internalIds, $externalUsers);
    }

    private function persistTempAttachments(Task $task, ?string $tempIdentifier): void
    {
        if (! filled($tempIdentifier)) {
            return;
        }

        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (! Storage::exists($tempPath)) {
            return;
        }

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

            Attachment::query()->create([
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
                "#https?://[^\\s\"'<>]+/shift/api/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#https?://[^\\s\"'<>]+/shift/api/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                "#/shift/api/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#/shift/api/attachments/temp/{$quotedTemp}/{$quotedBase}#",
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

    private function visibleRequirementsQuery(Project $project, ExternalUser $externalUser): Builder
    {
        $query = Task::query()
            ->where('project_id', $project->id)
            ->requirementIntake();

        return $this->externalUserService->constrainVisibleProjectItems($query, $externalUser);
    }

    private function normalizeRequirementStatusFilter(mixed $value): array
    {
        $values = is_array($value) ? $value : (filled($value) ? [$value] : []);
        $values = array_map('strval', array_values(array_filter($values, fn ($item) => filled($item))));

        return array_values(array_intersect($values, RequirementStatus::values()));
    }

    private function applyRequirementStatusFilter(Builder $query, array $statuses): void
    {
        $query->whereHas('metadata', function (Builder $metadataQuery) use ($statuses) {
            $metadataQuery->where(function (Builder $statusQuery) use ($statuses) {
                $statusQuery->whereIn('requirement_status', $statuses);

                if (in_array(RequirementStatus::Submitted->value, $statuses, true)) {
                    $statusQuery->orWhereNull('requirement_status');
                }
            });
        });
    }

    private function requirementBatchSummaries(Project $project, ExternalUser $externalUser, $tasks): array
    {
        $batchIds = collect($tasks)
            ->pluck('metadata.requirement_batch_id')
            ->filter()
            ->unique()
            ->values();

        if ($batchIds->isEmpty()) {
            return [];
        }

        $batches = RequirementBatch::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $batchIds)
            ->get(['id', 'title', 'created_at'])
            ->keyBy('id');

        return $this->visibleRequirementsQuery($project, $externalUser)
            ->with(['metadata:id,task_id,phase,requirement_status,requirement_batch_id'])
            ->whereHas('metadata', function (Builder $metadataQuery) use ($batchIds) {
                $metadataQuery->whereIn('requirement_batch_id', $batchIds);
            })
            ->get(['id'])
            ->groupBy(fn (Task $task) => $task->metadata?->requirement_batch_id)
            ->map(function ($batchTasks, int $batchId) use ($batches) {
                $batch = $batches->get($batchId);
                $requirementItems = $batchTasks->filter(fn (Task $task) => $task->isRequirementPhase())->count();
                $readyItems = $batchTasks
                    ->filter(fn (Task $task) => $task->isReadyToFinalizeRequirement())
                    ->count();
                $totalItems = $batchTasks->count();

                return [
                    'id' => $batchId,
                    'title' => $batch?->title,
                    'created_at' => $batch?->created_at?->toIso8601String(),
                    'total_items' => $totalItems,
                    'requirement_items' => $requirementItems,
                    'ready_items' => $readyItems,
                    'finalized_items' => max($totalItems - $requirementItems, 0),
                ];
            })
            ->all();
    }

    private function currentExternalUser(Project $project, array $attributes, bool $create): ?ExternalUser
    {
        $payload = [
            'external_id' => data_get($attributes, 'user.id'),
            'name' => data_get($attributes, 'user.name'),
            'email' => data_get($attributes, 'user.email'),
            'environment' => data_get($attributes, 'user.environment') ?? data_get($attributes, 'metadata.environment'),
            'url' => data_get($attributes, 'user.url') ?? data_get($attributes, 'metadata.url'),
        ];

        if ($create) {
            return $this->externalUserService->upsert($project, $payload);
        }

        return $this->externalUserService->find(
            $project,
            $payload['external_id'],
            $payload['environment'],
            $payload['url'],
        );
    }

    private function resolveEnvironment(Project $project, array $attributes): ?string
    {
        $environment = $this->projectEnvironmentService->normalizeEnvironment(
            data_get($attributes, 'metadata.environment') ?? data_get($attributes, 'user.environment')
        );

        if ($environment === null) {
            return null;
        }

        if (! $project->environments->contains('environment', $environment)) {
            throw ValidationException::withMessages([
                'metadata.environment' => 'The selected environment is not registered for this project.',
            ]);
        }

        return $environment;
    }

    private function sourceUrl(array $attributes): string
    {
        return $this->projectEnvironmentService->normalizeUrl(
            data_get($attributes, 'metadata.url') ?? data_get($attributes, 'user.url') ?? config('app.url')
        ) ?? (string) config('app.url');
    }

    private function serializeRequirement(Task $task, array $batchSummaries = [], ?ExternalUser $externalUser = null): array
    {
        $metadata = $task->metadata;
        $phase = $metadata?->phase ?: 'task';
        $batchId = $metadata?->requirement_batch_id;
        $capabilities = $this->externalUserService->capabilityFlags($task, $externalUser);

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'batch_id' => $batchId,
            'batch_title' => $metadata?->requirementBatch?->title,
            'batch' => $batchId ? ($batchSummaries[$batchId] ?? null) : null,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'requirement_status' => $task->requirementStatus(),
            'priority' => $task->priority,
            'phase' => $phase,
            'finalized' => $phase !== 'requirement',
            'submitted_title' => $metadata?->submitted_title,
            'submitted_description' => $metadata?->submitted_description,
            'environment' => $metadata?->environment,
            ...$capabilities,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'finalized_at' => $metadata?->finalized_at?->toIso8601String(),
        ];
    }
}
