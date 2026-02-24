<?php

namespace App\Jobs;

use App\Models\TaskThread;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTaskThreadNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The task thread instance.
     *
     * @var int
     */
    protected $threadId;

    /**
     * The external user data.
     *
     * @var array
     */
    protected $externalUserData;

    /**
     * The notification payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(int $threadId, array $externalUserData, array $payload)
    {
        $this->threadId = $threadId;
        $this->externalUserData = $externalUserData;
        $this->payload = $payload;
        $this->delay(60); // 1 minute delay
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if the thread still exists
        $thread = TaskThread::find($this->threadId);

        if (! $thread) {
            Log::info('Thread notification cancelled - thread no longer exists', [
                'thread_id' => $this->threadId,
            ]);

            return;
        }

        $notificationService = new ExternalNotificationService;
        $url = $this->externalUserData['url'];
        $email = $this->externalUserData['email'];

        // Send notification to the external API
        $response = $notificationService->sendNotification(
            $url,
            'thread.update',
            $this->payload
        );

        // Create notification object with additional URL for email
        $notificationData = array_merge($this->payload, [
            'url' => rtrim($this->externalUserData['url'], '/').'/shift/tasks-v2?task='.$this->payload['task_id'],
        ]);

        $notificationService->sendFallbackEmailIfNeeded(
            $response,
            $email,
            new TaskThreadUpdated($notificationData)
        );

        Log::info('Thread notification sent after delay', [
            'thread_id' => $this->threadId,
            'external_user_email' => $email,
        ]);
    }
}
