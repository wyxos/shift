<?php

namespace App\Services\AppErrors;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;

class AppErrorRecurrence
{
    public function reason(Project $project, Task $task, Carbon $receivedAt): ?string
    {
        if (! $task->exists) {
            return 'created';
        }

        if ($task->status === TaskStatus::Completed->value) {
            return 'reopened';
        }

        if (! $project->app_error_recurrence_enabled || $task->error_last_seen_at === null) {
            return null;
        }

        $quietBefore = $receivedAt->copy()->subHours(max(1, (int) $project->app_error_quiet_hours));

        if ($task->error_last_seen_at->greaterThan($quietBefore)) {
            return null;
        }

        if ($task->error_last_alert_at?->greaterThan($quietBefore)) {
            return null;
        }

        return 'recurring';
    }
}
