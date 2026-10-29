<?php

namespace App\Services\AppErrors;

use App\Jobs\SendAppErrorSummary;
use App\Models\AppErrorSummary;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AppErrorSummaryScheduler
{
    public function schedule(Project $project): void
    {
        if (! $project->app_error_daily_summary_enabled) {
            return;
        }

        DB::transaction(function () use ($project): void {
            $locked = Project::query()->lockForUpdate()->find($project->id);

            if (! $locked?->app_error_daily_summary_enabled) {
                return;
            }

            $due = $this->nextTime($locked);
            $summary = AppErrorSummary::query()->firstOrCreate([
                'project_id' => $locked->id,
                'scheduled_for' => $due,
            ], [
                'summary_time' => $locked->app_error_daily_summary_time,
                'summary_timezone' => $locked->app_error_daily_summary_timezone,
            ]);

            if ($summary->cancelled_at !== null) {
                $summary->fill([
                    'cancelled_at' => null,
                    'dispatch_requested_at' => null,
                    'dispatched_at' => null,
                    'delivery_started_at' => null,
                    'summary_time' => $locked->app_error_daily_summary_time,
                    'summary_timezone' => $locked->app_error_daily_summary_timezone,
                ]);
            }

            if ($summary->completed_at !== null || $summary->dispatched_at !== null
                || $summary->dispatch_requested_at?->greaterThan(now()->subMinutes(5))) {
                return;
            }

            $summary->dispatch_requested_at = now();
            $summary->save();

            DB::afterCommit(function () use ($summary, $due): void {
                try {
                    SendAppErrorSummary::dispatch($summary->id)->delay($due)->afterCommit();
                    $summary->update(['dispatched_at' => now()]);
                } catch (Throwable $exception) {
                    $summary->update(['dispatch_requested_at' => null]);

                    throw $exception;
                }
            });
        }, 3);
    }

    public function nextTime(Project $project): CarbonImmutable
    {
        $local = CarbonImmutable::now($project->app_error_daily_summary_timezone);
        [$hour, $minute] = array_map('intval', explode(':', $project->app_error_daily_summary_time));
        $due = $local->setTime($hour, $minute, 0);

        if ($due->lessThanOrEqualTo($local)) {
            $due = $local->addDay()->setTime($hour, $minute, 0);
        }

        $lastCompleted = AppErrorSummary::query()
            ->where('project_id', $project->id)
            ->whereNotNull('completed_at')
            ->max('scheduled_for');

        if ($lastCompleted !== null && CarbonImmutable::parse($lastCompleted, config('app.timezone'))
            ->setTimezone($project->app_error_daily_summary_timezone)->toDateString() >= $due->toDateString()) {
            $due = $due->addDay()->setTime($hour, $minute, 0);
        }

        return $due->utc();
    }
}
