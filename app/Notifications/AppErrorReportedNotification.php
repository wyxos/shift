<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ShiftPermissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppErrorReportedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(
        protected Task $task,
        protected string $reason,
        protected ?string $url = null,
    ) {}

    /**
     * Get the number of seconds to wait before retrying the notification.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        $project = Project::query()->find($this->task->project_id);

        if (! $project instanceof Project || ($this->reason === 'recurring' && ! $project->app_error_recurrence_enabled)) {
            return false;
        }

        return $project->appErrorNotificationUsers()
            ->whereKey($notifiable->id)
            ->exists()
            && app(ShiftPermissionService::class)->hasProjectAccess($project, $notifiable->id);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $event = match ($this->reason) {
            'reopened' => 'Regressed',
            'recurring' => 'Recurring',
            default => 'Reported',
        };
        $subject = $event !== 'Reported'
            ? $this->task->project->name.' '.$event.' - '.$this->task->title
            : $this->task->project->name.' - '.$this->task->title;

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting($this->task->title)
            ->line('Project: '.$this->task->project->name)
            ->line('Event: '.$event);

        if (filled($this->task->error_environment)) {
            $message->line('Environment: '.$this->task->error_environment);
        }

        if (filled($this->task->error_occurrences_count)) {
            $message->line('Occurrences: '.$this->task->error_occurrences_count);
        }

        $title = explode(' at ', $this->task->title, 2);
        $location = $this->task->error_culprit_file;
        if (filled($location) && filled($this->task->error_culprit_line)) {
            $location .= ':'.$this->task->error_culprit_line;
        }

        return $message
            ->markdown('notifications::email', [
                'notificationType' => '',
                'errorReport' => [
                    'heading' => 'Exception detected',
                    'event' => $event,
                    'exception' => $this->task->error_exception_class
                        ? class_basename($this->task->error_exception_class)
                        : ($this->task->error_name ?: $title[0]),
                    'location' => $location ?: ($title[1] ?? null),
                    'project' => $this->task->project->name,
                    'environment' => $this->task->error_environment,
                    'occurrences' => $this->task->error_occurrences_count,
                ],
            ])
            ->action('View Task', $this->resolveUrl())
            ->line('Please do not reply to this email directly.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'project_id' => $this->task->project_id,
            'project_name' => $this->task->project->name,
            'url' => $this->resolveUrl(),
            'reason' => $this->reason,
        ];
    }

    protected function resolveUrl(): string
    {
        if (filled($this->url)) {
            return (string) $this->url;
        }

        return route('app-errors.index', ['task' => $this->task->id]);
    }
}
