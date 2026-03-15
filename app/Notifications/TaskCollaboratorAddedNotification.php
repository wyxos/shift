<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCollaboratorAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Task $task;

    protected ?string $url;

    public function __construct(Task $task, ?string $url = null)
    {
        $this->task = $task;
        $this->url = $url;
    }

    protected function resolveUrl(): string
    {
        if (filled($this->url)) {
            return (string) $this->url;
        }

        return route('tasks.index', ['task' => $this->task->id]);
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->resolveUrl();

        return (new MailMessage)
            ->subject('Task Access Granted: '.$this->task->title)
            ->line('You have been added as a collaborator on an existing task in the project: '.$this->task->project->name)
            ->line('Task Title: '.$this->task->title)
            ->line('Priority: '.ucfirst($this->task->priority))
            ->line('Status: '.ucfirst(str_replace('_', ' ', $this->task->status)))
            ->action('View Task', $url)
            ->line('Thank you for using our application!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'project_id' => $this->task->project_id,
            'project_name' => $this->task->project->name,
            'url' => $this->resolveUrl(),
        ];
    }
}