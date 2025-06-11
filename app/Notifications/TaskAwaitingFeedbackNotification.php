<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAwaitingFeedbackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The tasks awaiting feedback.
     *
     * @var array
     */
    protected $tasks;

    /**
     * The URL to view tasks awaiting feedback.
     *
     * @var string
     */
    protected $url;

    /**
     * Create a new notification instance.
     *
     * @param array $tasks
     * @param string $url
     */
    public function __construct(array $tasks, string $url)
    {
        $this->tasks = $tasks;
        $this->url = $url;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mailMessage = (new MailMessage)
            ->subject('Tasks Awaiting Your Feedback')
            ->line('You have tasks that are awaiting your feedback:');

        // Add each task to the email
        foreach ($this->tasks as $task) {
            $mailMessage->line('- ' . $task->title . ' (Project: ' . $task->project->name . ')');
        }

        $mailMessage->action('View Tasks', $this->url)
            ->line('Thank you for your attention to these tasks!');

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $taskData = [];

        foreach ($this->tasks as $task) {
            $taskData[] = [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'project_id' => $task->project_id,
                'project_name' => $task->project->name,
            ];
        }

        return [
            'tasks' => $taskData,
            'url' => $this->url,
        ];
    }
}
