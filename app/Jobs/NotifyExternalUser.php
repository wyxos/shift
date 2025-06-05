<?php

namespace App\Jobs;

use App\Models\ExternalUser;
use App\Models\Task;
use App\Notifications\TaskCreationNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyExternalUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $externalUserId, public int $taskId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ExternalNotificationService $notificationService): void
    {
        $externalUser = ExternalUser::find($this->externalUserId);
        $task = Task::find($this->taskId);

        if (!$externalUser || !$task) {
            return;
        }

        $payload = [
            'type'            => 'task',
            'user_id'         => $externalUser->external_id,
            'task_id'         => $task->id,
            'task_title'      => $task->title,
            'task_description'=> $task->description,
            'task_status'     => $task->status,
            'task_priority'   => $task->priority,
        ];

        $response = $notificationService->sendNotification(
            $externalUser->url,
            'task.created',
            $payload
        );

        $editUrl = $externalUser->url . '/shift/tasks/' . $task->id . '/edit';

        $notificationService->sendFallbackEmailIfNeeded(
            $response,
            $externalUser->email,
            new TaskCreationNotification($task, $editUrl)
        );
    }
}
