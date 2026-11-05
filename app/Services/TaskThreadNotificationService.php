<?php

namespace App\Services;

use App\Enums\TaskThreadAudience;
use App\Jobs\SendTaskThreadNotification;
use App\Jobs\SendTaskThreadNotifications;
use App\Models\ExternalIdentity;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

class TaskThreadNotificationService
{
    public function __construct(
        private readonly TaskCollaboratorService $taskCollaboratorService,
    ) {}

    public function schedule(TaskThread $thread): void
    {
        $connection = $thread->getConnection();
        $queueConnection = (string) config('queue.default');
        $queueConfig = config('queue.connections.'.$queueConnection, []);
        $queueDatabaseConnection = $queueConfig['connection'] ?? config('database.default');

        $enqueue = function () use ($thread, $connection, $queueConnection): void {
            $connection->transaction(function () use ($thread, $queueConnection): void {
                $lockedThread = TaskThread::query()->lockForUpdate()->findOrFail($thread->id);
                if ($lockedThread->notifications_queued_at !== null) {
                    return;
                }

                $this->scheduleEmails($lockedThread);
                Bus::dispatch((new SendTaskThreadNotifications($lockedThread->id))
                    ->onConnection($queueConnection)->beforeCommit());
                $lockedThread->forceFill(['notifications_queued_at' => now()])->save();
            });
        };

        if (($queueConfig['driver'] ?? null) === 'database'
            && $queueDatabaseConnection === $connection->getName()) {
            $enqueue();

            return;
        }

        $connection->afterCommit($enqueue);
    }

    public function send(Task $task, TaskThread $thread): void
    {
        if ($thread->is_draft) {
            return;
        }

        $this->scheduleEmails($thread);
        $internalSenderId = $thread->sender_type === User::class
            ? (int) $thread->sender_id
            : null;

        $internalAudience = $this->taskCollaboratorService->internalReplyAudience($task, $internalSenderId);

        if ($internalAudience->isNotEmpty()) {
            Notification::send(
                $internalAudience,
                new TaskThreadUpdated([
                    'type' => $thread->type,
                    'audience' => TaskThreadAudience::fromStoredType((string) $thread->type)->value,
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content,
                    'url' => route('tasks.index', ['task' => $task->id]),
                ], includeMail: false)
            );
        }

        if ($thread->type !== 'external') {
            return;
        }

        $externalSenderId = $thread->sender_type === ExternalIdentity::class
            ? (int) $thread->sender_id
            : null;

        foreach ($this->taskCollaboratorService->externalReplyAudience($task, $externalSenderId) as $externalIdentity) {
            SendTaskThreadNotification::dispatch(
                $thread->id,
                [
                    'external_identity_id' => $externalIdentity->id,
                    'environment' => $externalIdentity->environment,
                    'url' => $externalIdentity->url,
                    'email' => $externalIdentity->email,
                    'external_id' => $externalIdentity->external_id,
                ],
                [
                    'type' => 'task_thread',
                    'user_id' => $externalIdentity->external_id,
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content,
                ],
                groupEmail: true,
            );
        }
    }

    private function scheduleEmails(TaskThread $thread): void
    {
        if ($thread->is_draft) {
            return;
        }

        $thread->getConnection()->transaction(function () use ($thread): void {
            $thread = TaskThread::query()->lockForUpdate()->find($thread->id);
            if (! $thread || $thread->emails_queued_at !== null || ! $thread->task) {
                return;
            }
            $thread->forceFill(['emails_queued_at' => now()])->save();
            $senderId = $thread->sender_type === User::class ? (int) $thread->sender_id : null;
            foreach ($this->taskCollaboratorService->internalReplyAudience($thread->task, $senderId) as $recipient) {
                app(TaskThreadEmailBatchService::class)->schedule($thread, $recipient);
            }
            if ($thread->type !== 'external') {
                return;
            }
            $senderId = $thread->sender_type === ExternalIdentity::class ? (int) $thread->sender_id : null;
            foreach ($this->taskCollaboratorService->externalReplyAudience($thread->task, $senderId) as $recipient) {
                app(TaskThreadEmailBatchService::class)->schedule($thread, $recipient);
            }
        }, attempts: 3);
    }
}
