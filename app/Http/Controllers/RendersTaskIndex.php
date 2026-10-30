<?php

namespace App\Http\Controllers;

use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

trait RendersTaskIndex
{
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

    private function applyRequirementStatusFilter(Builder $query, array $statuses): void
    {
        $allowed = RequirementStatus::values();
        $statuses = array_values(array_intersect($statuses, $allowed));

        if (empty($statuses)) {
            $statuses = $allowed;
        }

        $query->whereHas('metadata', function (Builder $metadataQuery) use ($statuses) {
            $metadataQuery->where(function (Builder $statusQuery) use ($statuses) {
                $statusQuery->whereIn('requirement_status', $statuses);

                if (in_array(RequirementStatus::Submitted->value, $statuses, true)) {
                    $statusQuery->orWhereNull('requirement_status');
                }
            });
        });
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
            ->with(['metadata:id,task_id,phase,requirement_status,requirement_batch_id'])
            ->requirementIntake()
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

    public function index(?Organisation $organisation = null): Response|RedirectResponse
    {
        $this->authorizeOrganisationSurface($organisation);

        if (request()->query('type') === 'app_errors') {
            $parameters = request()->query();
            unset($parameters['type']);

            if ($organisation) {
                $parameters['organisation'] = $organisation;
            }

            return redirect()->route(
                $organisation ? 'organisation.app-errors' : 'app-errors.index',
                $parameters,
            );
        }

        return $this->renderIndex($organisation, 'tasks');
    }

    public function appErrors(?Organisation $organisation = null): Response
    {
        $this->authorizeOrganisationSurface($organisation);

        return $this->renderIndex($organisation, 'app-errors');
    }

    private function authorizeOrganisationSurface(?Organisation $organisation): void
    {
        if ($organisation && ! $organisation->isVisibleToUser(auth()->id())) {
            abort(404);
        }
    }

    private function renderIndex(?Organisation $organisation, string $surface): Response
    {
        $defaultStatuses = TaskStatus::defaultOpenValues();
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
        $projectId = request('project_id');
        $search = trim((string) request('search', ''));
        $environment = trim((string) request('environment', ''));
        $type = $surface === 'app-errors' ? 'app_errors' : 'tasks';
        $component = $surface === 'app-errors' && in_array(request('component'), ['shift_sdk', 'application'], true)
            ? request('component') : null;
        $sortBy = in_array((string) request('sort_by'), $allowedSortBy, true) ? (string) request('sort_by') : $defaultSortBy;

        $query = $this->visibleTasksQuery()
            ->with([
                'project:id,name,author_id,organisation_id,client_id',
                'project.organisation:id,author_id',
                'project.client:id,organisation_id',
                'project.client.organisation:id,author_id',
                'metadata:id,task_id,environment,phase,requirement_status,submitted_title,submitted_description,finalized_at',
                'submitter',
            ])
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

        if (filled($projectId)) {
            $query->where('project_id', $projectId);
        }

        if ($environment !== '') {
            $query->whereHas('metadata', function ($metadataQuery) use ($environment) {
                $metadataQuery->whereRaw('LOWER(environment) = LOWER(?)', [$environment]);
            });
        }

        if ($surface === 'app-errors') {
            $query->errorIntake();

            if ($component === 'shift_sdk') {
                $query->where('error_component', 'shift_sdk');
            } elseif ($component === 'application') {
                $query->whereNull('error_component');
            }
        } else {
            $query->whereNull('error_signature');
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
                'project_id' => $task->project_id,
                'project' => $this->serializeTaskProject($task->project),
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'environment' => $this->taskEnvironment($task),
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
                ...$this->serializeTaskType($task),
                ...$this->permissions->taskCapabilities($task, auth()->id()),
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
                    'project_id' => filled($projectId) ? (int) $projectId : null,
                    'type' => $type,
                    'component' => $component,
                    'sort_by' => $sortBy,
                ],
                'tasks' => $tasks,
                'surface' => $surface,
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

    public function requirements(?Organisation $organisation = null)
    {
        $defaultStatuses = RequirementStatus::values();
        $allPriorities = ['low', 'medium', 'high'];
        $allowedSortBy = ['updated_at', 'created_at', 'priority'];
        $defaultSortBy = 'updated_at';

        $selectedStatuses = $this->normalizeListFilter(request('status'));
        $selectedStatuses = array_values(array_intersect($selectedStatuses, RequirementStatus::values()));
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
        $projectId = request('project_id');
        $sortBy = in_array((string) request('sort_by'), $allowedSortBy, true) ? (string) request('sort_by') : $defaultSortBy;

        $query = $this->visibleTasksQuery()
            ->with([
                'project:id,name,author_id,organisation_id,client_id',
                'project.organisation:id,author_id',
                'project.client:id,organisation_id',
                'project.client.organisation:id,author_id',
                'metadata:id,task_id,environment,phase,requirement_status,submitted_title,submitted_description,finalized_at,requirement_batch_id',
                'metadata.requirementBatch:id,title,created_at',
                'submitter',
            ])
            ->requirementIntake();

        $this->applyRequirementStatusFilter($query, $selectedStatuses);

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

        if (filled($projectId)) {
            $query->where('project_id', $projectId);
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
                'project_id' => $task->project_id,
                'project' => $this->serializeTaskProject($task->project),
                'title' => $task->title,
                'status' => $task->status,
                'requirement_status' => $task->requirementStatus(),
                'priority' => $task->priority,
                'environment' => $this->taskEnvironment($task),
                'phase' => $task->phase(),
                'finalized' => ! $task->isRequirementPhase(),
                'batch' => $batchId ? ($batchSummaries[$batchId] ?? null) : null,
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
                ...$this->permissions->taskCapabilities($task, auth()->id()),
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
                    'project_id' => filled($projectId) ? (int) $projectId : null,
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
}
