<?php

namespace App\Jobs;

use App\Exceptions\ExternalNotificationException;
use App\Models\TaskThread;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalInboxService;
use App\Services\ExternalNotificationService;
use App\Services\ExternalThreadRecipient;
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

        if (! $thread || $thread->type !== 'external') {
            app(ExternalThreadRecipient::class)->findDelivery($this->threadId, $this->externalUserData)?->markCancelled();

            Log::info('Thread notification cancelled - thread no longer exists', [
                'thread_id' => $this->threadId,
            ]);

            return;
        }

        $thread->loadMissing('task.project');

        $recipients = app(ExternalThreadRecipient::class);
        $identity = $recipients->identity($thread, $this->externalUserData);
        if ($identity) {
            $this->externalUserData['external_identity_id'] = $identity->id;
        }
        $delivery = $recipients->delivery($thread, $this->externalUserData, $identity);
        $project = $thread->task?->project;

        if (! $project) {
            $delivery->markFailed('missing_project');

            return;
        }

        if ($identity) {
            app(ExternalInboxService::class)->recordThread($thread, $identity);
        }

        if (! $delivery->callback_delivered_at) {
            $delivery->recordAttempt();

            try {
                $response = $notificationService->sendNotification(
                    $project,
                    (string) $this->externalUserData['url'],
                    'thread.update',
                    $this->payload,
                    [],
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
        $delivery = app(ExternalThreadRecipient::class)->findDelivery($this->threadId, $this->externalUserData);

        if (! $delivery || $delivery->completed_at || $delivery->cancelled_at) {
            return;
        }

        if ($exception instanceof ExternalNotificationException) {
            $delivery->markFailed($exception->failureType, $exception->statusCode);

            return;
        }

        $delivery->markFailed('job_exhausted');
    }
}
