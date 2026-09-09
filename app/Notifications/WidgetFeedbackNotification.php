<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\WidgetFeedbackAudience;

class WidgetFeedbackNotification extends TaskCreationNotification
{
    public int $tries = 3;

    public int $timeout = 45;

    public function databaseType(object $notifiable): string
    {
        return TaskCreationNotification::class;
    }

    public function broadcastType(): string
    {
        return TaskCreationNotification::class;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $task = $this->task->fresh();

        return $notifiable instanceof User
            && $task !== null
            && app(WidgetFeedbackAudience::class)->recipients($task)->contains('id', $notifiable->id);
    }
}
