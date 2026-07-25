<?php

namespace App\Jobs;

use App\Exceptions\ExternalNotificationException;
use App\Models\ExternalNotificationDelivery;
use App\Models\TaskThread;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTaskThreadNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $externalUserData
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        protected int $threadId,
        protected array $externalUserData,
        protected array $payload,
    ) {
        $this->delay(60);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ExternalNotificationService $notificationService): void
    {
        $thread = TaskThread::find($this->threadId);

        if (! $thread) {
            ExternalNotificationDelivery::findForTaskThread(
                $this->threadId,
                $this->recipientKey(),
            )?->markCancelled();

            Log::info('Thread notification cancelled - thread no longer exists', [
                'thread_id' => $this->threadId,
            ]);

            return;
        }

        $thread->loadMissing('task.project');

        $delivery = ExternalNotificationDelivery::forTaskThread(
            $thread,
            $this->recipientKey(),
            'thread.update',
        );

        if (! $delivery->callback_delivered_at) {
            $delivery->recordAttempt();

            try {
                $response = $notificationService->sendNotification(
                    (string) $this->externalUserData['url'],
                    'thread.update',
                    $this->payload,
                    [],
                    $thread->task?->project?->token,
                    $delivery->delivery_id,
                );
            } catch (ExternalNotificationException $exception) {
                $delivery->recordAttemptFailure($exception->failureType, $exception->statusCode);

                if ($exception->retryable) {
                    throw $exception;
                }

                $delivery->markFailed($exception->failureType, $exception->statusCode);

                Log::warning('Thread notification permanently failed', [
                    'thread_id' => $this->threadId,
                    'failure_type' => $exception->failureType,
                    'status' => $exception->statusCode,
                ]);

                return;
            }

            $delivery->markCallbackDelivered(
                (bool) $response->json('production'),
                $response->status(),
            );
        }

        $email = $this->externalUserData['email'] ?? null;

        if ($delivery->production === false && filled($email)) {
            if (! $delivery->fallback_dispatched_at) {
                $notificationData = array_merge($this->payload, [
                    'url' => rtrim((string) $this->externalUserData['url'], '/').'/shift/tasks?task='.$this->payload['task_id'],
                ]);

                SendExternalNotificationFallbackEmail::dispatch(
                    $delivery->id,
                    (string) $email,
                    new TaskThreadUpdated($notificationData),
                );

                $delivery->markFallbackDispatched();
            }

            Log::info('Thread callback delivered and fallback email queued', [
                'thread_id' => $this->threadId,
            ]);

            return;
        }

        $delivery->markCompleted();

        Log::info('Thread notification delivered after delay', [
            'thread_id' => $this->threadId,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = ExternalNotificationDelivery::findForTaskThread(
            $this->threadId,
            $this->recipientKey(),
        );

        if (! $delivery || $delivery->completed_at || $delivery->cancelled_at) {
            return;
        }

        if ($exception instanceof ExternalNotificationException) {
            $delivery->markFailed($exception->failureType, $exception->statusCode);

            return;
        }

        $delivery->markFailed('job_exhausted');
    }

    private function recipientKey(): string
    {
        return (string) (
            $this->externalUserData['external_id']
            ?? $this->externalUserData['email']
            ?? $this->externalUserData['url']
            ?? 'unknown'
        );
    }
}
