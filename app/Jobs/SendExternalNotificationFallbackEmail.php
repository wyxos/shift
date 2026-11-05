<?php

namespace App\Jobs;

use App\Models\ExternalNotificationDelivery;
use App\Models\TaskThread;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification as NotificationMessage;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendExternalNotificationFallbackEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $deliveryId,
        public string $email,
        public NotificationMessage $notification,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = ExternalNotificationDelivery::query()
            ->with('taskCollaboratorNotification')
            ->find($this->deliveryId);

        if (! $delivery || $delivery->completed_at || $delivery->cancelled_at) {
            return;
        }

        if ($delivery->task_thread_email_batch_id) {
            $batch = $delivery->taskThreadEmailBatch;
            $identity = $batch?->externalIdentity;
            $snapshot = $batch?->payload['_recipient'] ?? [];
            if (! $batch?->task || ! $identity
                || ! $batch->task->externalCollaborators()->whereKey($identity->id)->exists()
                || $identity->email !== $this->email
                || $identity->url !== ($snapshot['url'] ?? null)
                || $identity->environment !== ($snapshot['environment'] ?? null)
                || (string) $identity->external_id !== (string) ($snapshot['external_id'] ?? null)) {
                $delivery->markCancelled();

                return;
            }

            $messages = collect($batch->payload['messages'])->keyBy('thread_id');
            $threads = TaskThread::query()->where('task_id', $batch->task_id)->ofType('external')
                ->whereIn('id', $messages->keys())->get();
            if ($threads->count() !== $messages->count()
                || ! $threads->every(fn (TaskThread $thread) => $thread->content === $messages[$thread->id]['content'])) {
                $delivery->markCancelled();

                return;
            }
        }

        $pending = $delivery->taskCollaboratorNotification;

        if ($pending?->cancelled_at) {
            $delivery->markCancelled();

            return;
        }

        $claimed = ExternalNotificationDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('fallback_attempted_at')
            ->whereNull('fallback_sent_at')
            ->update([
                'fallback_attempted_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        try {
            Notification::route('mail', $this->email)
                ->notifyNow($this->notification);
        } catch (Throwable $exception) {
            $delivery->refresh();
            $delivery->markFailed('fallback_email');

            throw $exception;
        }

        $delivery->refresh();
        $delivery->forceFill([
            'fallback_sent_at' => now(),
            'completed_at' => now(),
            'failed_at' => null,
        ])->save();

        if ($pending && ! $pending->sent_at && ! $pending->cancelled_at) {
            $pending->markSent();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = ExternalNotificationDelivery::query()->find($this->deliveryId);

        if ($delivery && ! $delivery->completed_at && ! $delivery->cancelled_at) {
            $delivery->markFailed('fallback_email');
        }
    }
}
