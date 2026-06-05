<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalRequirementController extends Controller
{
    public function __construct(
        private readonly ExternalUserService $externalUserService,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
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
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();
        $batchSummaries = $this->requirementBatchSummaries($project, $externalUser, $requirements->getCollection());

        $requirements->through(fn (Task $task) => $this->serializeRequirement($task, $batchSummaries));

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
            'items' => 'required|array|min:1|max:50',
            'items.*.title' => 'required|string|max:255',
            'items.*.description' => 'required|string',
        ]);

        $project = Project::query()
            ->with('environments')
            ->where('token', $attributes['project'])
            ->firstOrFail();

        $environment = $this->resolveEnvironment($project, $attributes);
        $sourceUrl = $this->sourceUrl($attributes);
        $externalUser = $this->currentExternalUser($project, $attributes, true);

        $created = DB::transaction(function () use ($attributes, $project, $environment, $sourceUrl, $externalUser) {
            $batch = RequirementBatch::query()->create([
                'project_id' => $project->id,
                'external_user_id' => $externalUser?->id,
                'title' => $attributes['title'] ?? null,
            ]);

            $items = collect($attributes['items'])->map(function (array $item) use ($project, $environment, $sourceUrl, $externalUser, $batch) {
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
                    'requirement_batch_id' => $batch->id,
                    'submitted_title' => $item['title'],
                    'submitted_description' => $description,
                ]);

                return $task->fresh(['metadata.requirementBatch', 'submitter']);
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
            'items' => $items->map(fn (Task $task) => $this->serializeRequirement($task, $batchSummaries))->values()->all(),
        ], 201);
    }

    private function visibleRequirementsQuery(Project $project, ExternalUser $externalUser): Builder
    {
        return Task::query()
            ->where('project_id', $project->id)
            ->requirementIntake()
            ->where(function (Builder $query) use ($externalUser) {
                $query
                    ->whereHasMorph('submitter', [ExternalUser::class], function (Builder $submitterQuery) use ($externalUser) {
                        $submitterQuery->where('external_users.id', $externalUser->id);
                    })
                    ->orWhereHas('externalCollaborators', function (Builder $collaboratorQuery) use ($externalUser) {
                        $collaboratorQuery->where('external_users.id', $externalUser->id);
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
            ->with(['metadata:id,task_id,phase,requirement_batch_id'])
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

    private function serializeRequirement(Task $task, array $batchSummaries = []): array
    {
        $metadata = $task->metadata;
        $phase = $metadata?->phase ?: 'task';
        $batchId = $metadata?->requirement_batch_id;

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'batch_id' => $batchId,
            'batch_title' => $metadata?->requirementBatch?->title,
            'batch' => $batchId ? ($batchSummaries[$batchId] ?? null) : null,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'phase' => $phase,
            'finalized' => $phase !== 'requirement',
            'submitted_title' => $metadata?->submitted_title,
            'submitted_description' => $metadata?->submitted_description,
            'environment' => $metadata?->environment,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'finalized_at' => $metadata?->finalized_at?->toIso8601String(),
        ];
    }
}
