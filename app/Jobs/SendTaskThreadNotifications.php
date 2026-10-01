<?php

namespace App\Jobs;

use App\Models\TaskThread;
use App\Services\TaskThreadNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTaskThreadNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $threadId) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TaskThreadNotificationService $notifications): void
    {
        $thread = TaskThread::query()->with('task')->find($this->threadId);
        if (! $thread instanceof TaskThread || $thread->task === null) {
            return;
        }

        $notifications->send($thread->task, $thread);
    }
}
