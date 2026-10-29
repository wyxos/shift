<?php

namespace App\Jobs;

use App\Models\AppErrorSummary;
use App\Notifications\AppErrorSummaryNotification;
use App\Services\AppErrors\AppErrorSummaryBuilder;
use App\Services\AppErrors\AppErrorSummaryScheduler;
use App\Services\ProjectAppErrorNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendAppErrorSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $summaryId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(
        AppErrorSummaryBuilder $builder,
        AppErrorSummaryScheduler $scheduler,
        ProjectAppErrorNotificationService $notifications,
    ): void {
        $summary = DB::transaction(function (): ?AppErrorSummary {
            $summary = AppErrorSummary::query()->lockForUpdate()->find($this->summaryId);

            if (! $summary || $summary->completed_at || $summary->cancelled_at) {
                return null;
            }

            if ($summary->scheduled_for->isFuture()) {
                $this->release((int) now()->diffInSeconds($summary->scheduled_for));

                return null;
            }

            if ($summary->delivery_started_at?->greaterThan(now()->subMinutes(5))) {
                $leaseExpiresAt = $summary->delivery_started_at->addMinutes(5);
                $this->release((int) ceil(now()->diffInSeconds($leaseExpiresAt)) + 1);

                return null;
            }

            $local = $summary->scheduled_for->setTimezone($summary->summary_timezone);
            $alreadySent = AppErrorSummary::query()
                ->where('project_id', $summary->project_id)
                ->whereNotNull('completed_at')
                ->where('scheduled_for', '>=', $local->startOfDay()->utc())
                ->where('scheduled_for', '<', $local->addDay()->startOfDay()->utc())
                ->exists();

            if ($alreadySent) {
                $summary->update(['cancelled_at' => now()]);

                return null;
            }

            $summary->update(['delivery_started_at' => now()]);

            return $summary;
        }, 3);

        if ($summary === null) {
            return;
        }

        $project = $summary->project;

        if (! $project || ! $project->app_error_daily_summary_enabled) {
            $summary->update(['cancelled_at' => now()]);

            return;
        }

        if ($summary->summary_time !== $project->app_error_daily_summary_time
            || $summary->summary_timezone !== $project->app_error_daily_summary_timezone) {
            $summary->update(['cancelled_at' => now()]);
            $scheduler->schedule($project);

            return;
        }

        try {
            $activity = $builder->build($project, $summary->scheduled_for);
            $recipients = $notifications->recipients($project);

            if ($activity['task_count'] > 0 && $recipients->isNotEmpty()) {
                Notification::send($recipients, new AppErrorSummaryNotification(
                    $project->id,
                    $project->name,
                    $summary->scheduled_for,
                    $summary->summary_timezone,
                    $activity,
                ));
            }

            $summary->update(['completed_at' => now()]);
        } catch (Throwable $exception) {
            $summary->update(['delivery_started_at' => null]);

            throw $exception;
        }
    }
}
