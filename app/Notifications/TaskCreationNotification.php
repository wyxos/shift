<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TaskCreationNotification extends Notification
{
    use Queueable;

    /**
     * The task instance.
     *
     * @var Task
     */
    protected $task;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task)
    {
        $this->task = $task;
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
        $url = URL::route('tasks.edit', $this->task);

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
