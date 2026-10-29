<?php

namespace App\Services\AppErrors;

use App\Enums\TaskStatus;
use App\Models\AppErrorSummary;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskErrorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

class AppErrorSummaryBuilder
{
    public const MAX_TASKS = 20;

    /**
     * @return array{window_start: string, window_end: string, task_count: int, event_count: int, tasks: array<int, array{id: int, title: string, environment: ?string, event_count: int, last_seen_at: string}>}
     */
    public function build(Project $project, CarbonImmutable $due): array
    {
        $lastCompleted = AppErrorSummary::query()
            ->where('project_id', $project->id)
            ->whereNotNull('completed_at')
            ->where('scheduled_for', '<', $due)
            ->max('scheduled_for');
        $previous = $lastCompleted ? CarbonImmutable::parse($lastCompleted, config('app.timezone'))->utc() : null;
        $start = $previous?->greaterThanOrEqualTo($due->subHours(48))
            ? $previous
            : $due->setTimezone($project->app_error_daily_summary_timezone)->subDay()->utc();
        $activity = TaskErrorOccurrence::query()
            ->join('tasks', 'tasks.id', '=', 'task_error_occurrences.task_id')
            ->where('tasks.project_id', $project->id)
            ->whereNotNull('tasks.error_signature')
            ->where('tasks.status', '!=', TaskStatus::Completed->value)
            ->where('task_error_occurrences.received_at', '>=', $start)
            ->where('task_error_occurrences.received_at', '<', $due)
            ->selectRaw('task_error_occurrences.task_id, COUNT(*) AS summary_events, MAX(task_error_occurrences.received_at) AS summary_last_seen')
            ->groupBy('task_error_occurrences.task_id');

        $query = Task::query()->joinSub($activity, 'summary_activity', function (JoinClause $join): void {
            $join->on('tasks.id', '=', 'summary_activity.task_id');
        });
        $totals = (clone $query)->toBase()
            ->selectRaw('COUNT(*) AS task_count, COALESCE(SUM(summary_activity.summary_events), 0) AS event_count')
            ->first();
        $tasks = $query
            ->select(['tasks.id', 'tasks.title', 'tasks.error_environment', 'summary_activity.summary_events', 'summary_activity.summary_last_seen'])
            ->orderByDesc('summary_activity.summary_last_seen')
            ->orderByDesc('tasks.id')
            ->limit(self::MAX_TASKS)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'environment' => $task->error_environment,
                'event_count' => (int) $task->summary_events,
                'last_seen_at' => CarbonImmutable::parse($task->summary_last_seen, config('app.timezone'))->toIso8601String(),
            ])->all();

        return [
            'window_start' => $start->toIso8601String(),
            'window_end' => $due->toIso8601String(),
            'task_count' => (int) $totals->task_count,
            'event_count' => (int) $totals->event_count,
            'tasks' => $tasks,
        ];
    }
}
