<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCreationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The task instance.
     *
     * @var Task
     */
    protected $task;

    /**
     * Optional custom URL for the notification.
     *
     * @var string|null
     */
    protected $url;

    /**
     * Create a new notification instance.
     *
     * @param Task $task
     * @param string|null $url Optional custom URL for external notifications
     */
    public function __construct(Task $task, string $url = null)
    {
        $this->task = $task;
        $this->url = $url;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->url;

        return (new MailMessage)
            ->subject('New Task Created: ' . $this->task->title)
            ->line('A new task has been created in the project: ' . $this->task->project->name)
            ->line('Task Title: ' . $this->task->title)
            ->line('Priority: ' . ucfirst($this->task->priority))
            ->line('Status: ' . ucfirst(str_replace('_', ' ', $this->task->status)))
            ->action('View Task', $url)
            ->line('Thank you for using our application!');
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
        ];
    }
}
