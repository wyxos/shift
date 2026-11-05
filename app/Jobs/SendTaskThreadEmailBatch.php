<?php

namespace App\Jobs;

use App\Exceptions\ExternalNotificationException;
use App\Models\ExternalNotificationDelivery;
use App\Services\TaskThreadEmailBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTaskThreadEmailBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $batchId) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TaskThreadEmailBatchService $emails): void
    {
        $emails->send($this->batchId);
    }

    public function failed(?Throwable $error): void
    {
        $delivery = ExternalNotificationDelivery::query()->where('task_thread_email_batch_id', $this->batchId)->first();
        if ($delivery && ! $delivery->completed_at && ! $delivery->cancelled_at) {
            $delivery->markFailed($error instanceof ExternalNotificationException ? $error->failureType : 'job_exhausted');
        }
    }
}
