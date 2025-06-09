<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TaskThreadUpdated extends Notification implements ShouldQueue
{
    use Queueable;

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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $taskTitle = $this->data['task_title'] ?? 'Task #' . $this->data['task_id'];
        $threadType = ucfirst($this->data['type']) . ' thread';
        $snippet = Str::limit($this->data['content'], 120);
        $url = $this->data['url'];

        return (new MailMessage)
            ->subject("New reply in {$threadType} for {$taskTitle}")
            ->line("A new message was posted.")
            ->line("Preview: \"{$snippet}\"")
            ->action('View Thread', $url)
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
            'task_id' => $this->data['task_id'],
            'task_title' => $this->data['task_title'] ?? 'Task #' . $this->data['task_id'],
            'type' => $this->data['type'],
            'content' => $this->data['content'],
            'url' => $this->data['url'],
            'thread_id' => $this->data['thread_id'] ?? null,
        ];
    }
}
