<?php

namespace App\Services;

use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Support\Facades\Notification;

class TaskThreadNotificationService
{
    public function __construct(
        private readonly TaskCollaboratorService $taskCollaboratorService,
    ) {}

    public function send(Task $task, TaskThread $thread): void
    {
        $internalSenderId = $thread->sender_type === User::class
            ? (int) $thread->sender_id
            : null;

        $internalAudience = $this->taskCollaboratorService->internalReplyAudience($task, $internalSenderId);

        if ($internalAudience->isNotEmpty()) {
            Notification::send(
                $internalAudience,
                new TaskThreadUpdated([
                    'type' => $thread->type,
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content,
                    'url' => route('tasks.index', ['task' => $task->id]),
                ])
            );
        }

        if ($thread->type !== 'external') {
            return;
        }

        $externalSenderId = $thread->sender_type === ExternalUser::class
            ? (int) $thread->sender_id
            : null;

        foreach ($this->taskCollaboratorService->externalReplyAudience($task, $externalSenderId) as $externalUser) {
            SendTaskThreadNotification::dispatch(
                $thread->id,
                [
                    'url' => $externalUser->url,
                    'email' => $externalUser->email,
                    'external_id' => $externalUser->external_id,
                ],
                [
                    'type' => 'task_thread',
                    'user_id' => $externalUser->external_id,
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content,
                ]
            );
        }
    }
}
