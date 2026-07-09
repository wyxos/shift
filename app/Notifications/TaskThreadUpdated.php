<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Shift\Core\Notifications\TaskThreadUpdated as CoreTaskThreadUpdated;

class TaskThreadUpdated extends CoreTaskThreadUpdated implements ShouldQueue
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public array $data) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $taskTitle = $this->data['task_title'] ?? 'Task #'.$this->data['task_id'];
        $snippet = Str::limit($this->previewContent(), 120);
        $url = $this->resolveUrl();

        $message = (new MailMessage)
            ->subject("SHIFT: New reply on {$taskTitle}")
            ->line('A new message was posted.')
            ->line("Preview: \"{$snippet}\"");

        if (! empty($url)) {
            $message->action('View Thread', $url);
        }

        return $message->line('Please do not reply to this email directly.');
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
            'task_title' => $this->data['task_title'] ?? 'Task #'.$this->data['task_id'],
            'type' => $this->data['type'],
            'content' => $this->data['content'],
            'url' => $this->data['url'],
            'thread_id' => $this->data['thread_id'] ?? null,
        ];
    }

    private function previewContent(): string
    {
        $content = (string) ($this->data['content'] ?? '');
        $content = preg_replace('/<\s*\/?(?:p|div|br|li|ul|ol|blockquote|h[1-6]|tr|td|th)\b[^>]*>/i', ' ', $content) ?? $content;
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
