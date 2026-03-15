<?php

namespace App\Jobs;

use App\Models\ExternalUser;
use App\Models\Task;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyExternalCollaboratorAdded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $externalUserId, public int $taskId)
    {
    }

    public function handle(ExternalNotificationService $notificationService): void
    {
        $externalUser = ExternalUser::find($this->externalUserId);
        $task = Task::find($this->taskId);

        if (! $externalUser || ! $task) {
            return;
        }

        $payload = [
            'type' => 'task',
            'user_id' => $externalUser->external_id,
            'task_id' => $task->id,
            'task_title' => $task->title,
            'task_description' => $task->description,
            'task_status' => $task->status,
            'task_priority' => $task->priority,
        ];

        $response = $notificationService->sendNotification(
            $externalUser->url,
            'task.collaborator_added',
            $payload
        );

        $editUrl = rtrim($externalUser->url, '/').'/shift/tasks?task='.$task->id;

        $notificationService->sendFallbackEmailIfNeeded(
            $response,
            $externalUser->email,
            new TaskCollaboratorAddedNotification($task, $editUrl)
        );
    }
}