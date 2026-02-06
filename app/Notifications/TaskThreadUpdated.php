<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Shift\Core\Notifications\TaskThreadUpdated as CoreTaskThreadUpdated;

class TaskThreadUpdated extends CoreTaskThreadUpdated implements ShouldQueue
{

    /**
     * Create a new notification instance.
     */
    public function __construct(public array $data)
    {

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
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->data['task_id'],
            'task_title' => $this->data['task_title'] ?? 'Task #' . $this->data['task_id'],
            'type' => $this->data['type'],
            'content' => $this->data['content'],
            'url' => $this->data['url'],
            'thread_id' => $this->data['thread_id'] ?? null,
        ];
    }
}
