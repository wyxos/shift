<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAppErrorNotificationService;
use App\Services\ShiftPermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppErrorSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    /**
     * @param  array{task_count: int, event_count: int, tasks: array<int, array{id: int, title: string, environment: ?string, event_count: int, last_seen_at: string}>}  $activity
     */
    public function __construct(
        public int $projectId,
        public string $projectName,
        public CarbonImmutable $scheduledFor,
        public string $timezone,
        public array $activity,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $project = Project::query()->find($this->projectId);

        return $notifiable instanceof User
            && $project?->app_error_daily_summary_enabled
            && $project->app_error_daily_summary_timezone === $this->timezone
            && $project->app_error_daily_summary_time === $this->scheduledFor->setTimezone($this->timezone)->format('H:i')
            && app(ProjectAppErrorNotificationService::class)->selectedUserIds($project)->contains($notifiable->id)
            && app(ShiftPermissionService::class)->hasProjectAccess($project, $notifiable->id);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'task_title' => $this->projectName.' · Daily error summary',
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'url' => route('app-errors.index', ['project_id' => $this->projectId]),
            'reason' => 'daily_summary',
            'task_count' => $this->activity['task_count'],
            'event_count' => $this->activity['event_count'],
            'window_start' => $this->activity['window_start'] ?? $this->scheduledFor->subHours(24)->toIso8601String(),
            'window_end' => $this->scheduledFor->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = CarbonImmutable::parse($this->activity['window_start'] ?? $this->scheduledFor->subHours(24)->toIso8601String())
            ->setTimezone($this->timezone);
        $mail = (new MailMessage)
            ->subject($this->projectName.' · Daily error summary')
            ->greeting($this->projectName.' · Daily error summary')
            ->line($this->activity['task_count'].' active errors · '.$this->activity['event_count'].' new events')
            ->line('Activity from '.$start->format('j M Y, H:i').' to '.$this->scheduledFor->setTimezone($this->timezone)->format('j M Y, H:i').' ('.$this->timezone.').');

        foreach ($this->activity['tasks'] as $task) {
            $lastSeen = CarbonImmutable::parse($task['last_seen_at'])->setTimezone($this->timezone)->format('H:i');
            $mail->line($task['title'])
                ->line(($task['environment'] ?: 'N/A').' · #'.$task['id'].' · '.$task['event_count'].' new events · last seen '.$lastSeen)
                ->line('View error: '.route('app-errors.index', ['task' => $task['id']]));
        }

        if ($this->activity['task_count'] > count($this->activity['tasks'])) {
            $mail->line('Showing the 20 most recently active errors. Open SHIFT to see all '.$this->activity['task_count'].'.');
        }

        return $mail->action('View errors', route('app-errors.index', ['project_id' => $this->projectId]));
    }
}
