<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class TaskThreadEmailDigest extends TaskThreadUpdated
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $messages = $this->data['messages'];
        $count = count($messages);
        $title = $this->data['task_title'];
        $mail = (new MailMessage)
            ->subject($count === 1 ? "SHIFT: New reply on {$title}" : "SHIFT: {$count} new replies on {$title}")
            ->line($count === 1 ? 'A new message was posted.' : "{$count} new messages were posted.");

        foreach ($messages as $message) {
            $text = preg_replace('/<\s*\/?(?:p|div|br|li|ul|ol|blockquote|h[1-6]|tr|td|th)\b[^>]*>/i', ' ', $message['content']);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/', ' ', $text));
            $author = $message['author'] ?: 'User';
            $mail->line($author.' — '.Str::limit($text, 240));
        }

        return $mail->action('View Thread', $this->data['url'])->line('Please do not reply to this email directly.');
    }
}
