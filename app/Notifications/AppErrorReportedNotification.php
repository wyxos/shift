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

    public function __construct(
        protected Task $task,
        protected string $reason,
        protected ?string $url = null,
    ) {}

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

        if (! $project instanceof Project) {
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
        $reason = $this->reason === 'reopened' ? 'reopened' : 'created';
        $subject = $reason === 'reopened'
            ? 'App Error Reopened: '.$this->task->title
            : 'App Error Reported: '.$this->task->title;

        return (new MailMessage)
            ->subject($subject)
            ->line('An app error task was '.$reason.' in the project: '.$this->task->project->name)
            ->line('Task Title: '.$this->task->title)
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

        return route('tasks.index', ['task' => $this->task->id]);
    }
}
