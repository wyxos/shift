<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        $baseQuery = Task::query()
            ->where(function ($query) use ($userId) {
                $query
                    ->whereHas('project.projectUser', function ($query) use ($userId) {
                        $query->where('user_id', $userId);
                    })
                    ->orWhereHas('project', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHas('project.organisation', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHas('project.client.organisation', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHasMorph('submitter', [User::class], function ($query) use ($userId) {
                        $query->where('users.id', $userId);
                    });
            });

        $tasks = (clone $baseQuery)
            ->with([
                'metadata:id,task_id,environment',
                'project:id,name',
            ])
            ->get([
                'id',
                'project_id',
                'status',
                'priority',
                'created_at',
                'updated_at',
            ]);

        $statusLabels = [
            'pending' => 'Pending',
            'in-progress' => 'In Progress',
            'awaiting-feedback' => 'Awaiting Feedback',
            'completed' => 'Completed',
            'closed' => 'Closed',
        ];
        $priorityLabels = [
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ];
        $openStatuses = ['pending', 'in-progress', 'awaiting-feedback'];

        $total = $tasks->count();
        $pending = $tasks->where('status', 'pending')->count();
        $inProgress = $tasks->where('status', 'in-progress')->count();
        $completed = $tasks->where('status', 'completed')->count();
        $open = $tasks->whereIn('status', $openStatuses)->count();
        $awaitingFeedback = $tasks->where('status', 'awaiting-feedback')->count();
        $highPriorityOpen = $tasks
            ->where('priority', 'high')
            ->whereIn('status', $openStatuses)
            ->count();

        $statusBreakdown = collect($statusLabels)
            ->map(function (string $label, string $key) use ($tasks) {
                return [
                    'key' => $key,
                    'label' => $label,
                    'count' => $tasks->where('status', $key)->count(),
                ];
            })
            ->values();

        $priorityBreakdown = collect($priorityLabels)
            ->map(function (string $label, string $key) use ($tasks) {
                return [
                    'key' => $key,
                    'label' => $label,
                    'count' => $tasks->where('priority', $key)->count(),
                ];
            })
            ->values();

        $weekAnchor = now()->startOfWeek();
        $weekBuckets = collect(range(7, 0))
            ->map(fn (int $offset) => $weekAnchor->copy()->subWeeks($offset));

        $createdByWeek = $tasks
            ->filter(fn (Task $task) => $task->created_at instanceof Carbon)
            ->countBy(fn (Task $task) => $task->created_at->copy()->startOfWeek()->toDateString());

        $completedByWeek = $tasks
            ->filter(fn (Task $task) => $task->status === 'completed' && $task->updated_at instanceof Carbon)
            ->countBy(fn (Task $task) => $task->updated_at->copy()->startOfWeek()->toDateString());

        $throughput = $weekBuckets
            ->map(function (Carbon $weekStart) use ($createdByWeek, $completedByWeek) {
                $bucket = $weekStart->toDateString();

                return [
                    'week_start' => $bucket,
                    'label' => $weekStart->format('M j'),
                    'created' => (int) ($createdByWeek[$bucket] ?? 0),
                    'completed' => (int) ($completedByWeek[$bucket] ?? 0),
                ];
            })
            ->values();

        $environmentBreakdown = $tasks
            ->map(function (Task $task) {
                $environment = $task->metadata?->environment;

                return $environment ? Str::lower($environment) : 'unknown';
            })
            ->countBy()
            ->sortDesc()
            ->take(6)
            ->map(function (int $count, string $environment) {
                return [
                    'key' => $environment,
                    'label' => Str::headline($environment),
                    'count' => $count,
                ];
            })
            ->values();

        $projectBreakdown = $tasks
            ->filter(fn (Task $task) => $task->project_id !== null)
            ->groupBy('project_id')
            ->map(function ($projectTasks) {
                $name = (string) ($projectTasks->first()->project?->name ?? 'Unknown');
                $name = trim($name) !== '' ? $name : 'Unknown';

                return [
                    'project' => $name,
                    'count' => $projectTasks->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        $metrics = [
            'total' => $total,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'open' => $open,
            'awaiting_feedback' => $awaitingFeedback,
            'high_priority_open' => $highPriorityOpen,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
        ];

        return Inertia::render('Dashboard', [
            'metrics' => $metrics,
            'charts' => [
                'status' => $statusBreakdown,
                'priority' => $priorityBreakdown,
                'throughput' => $throughput,
                'environments' => $environmentBreakdown,
                'projects' => $projectBreakdown,
            ],
        ]);
    }
}
