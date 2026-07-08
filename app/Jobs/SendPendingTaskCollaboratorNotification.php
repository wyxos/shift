<?php

namespace App\Jobs;

use App\Enums\TaskCollaboratorKind;
use App\Models\ExternalUser;
use App\Models\TaskCollaborator;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Notifications\TaskCreationNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification;

class SendPendingTaskCollaboratorNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $notificationId) {}

    public function handle(ExternalNotificationService $notificationService): void
    {
        $pending = TaskCollaboratorNotification::query()
            ->with(['task.project', 'user', 'externalUser'])
            ->whereKey($this->notificationId)
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->first();

        if (! $pending) {
            return;
        }

        if ($pending->scheduled_at->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds($pending->scheduled_at, false)));

            return;
        }

        if (! $pending->task || ! $this->collaboratorStillAttached($pending) || $this->recipientIsSubmitter($pending)) {
            $pending->markCancelled();

            return;
        }

        $sent = $pending->kind === TaskCollaboratorKind::Internal
            ? $this->sendInternal($pending)
            : $this->sendExternal($pending, $notificationService);

        if ($sent) {
            $pending->markSent();
        }
    }

    private function sendInternal(TaskCollaboratorNotification $pending): bool
    {
        if (! $pending->user) {
            $pending->markCancelled();

            return false;
        }

        $pending->user->notify($this->notificationFor($pending));

        return true;
    }

    private function sendExternal(TaskCollaboratorNotification $pending, ExternalNotificationService $notificationService): bool
    {
        $externalUser = $pending->externalUser;
        $task = $pending->task;

        if (! $externalUser || ! $task || ! filled($externalUser->url)) {
            $pending->markCancelled();

            return false;
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
            $this->externalHandler($pending),
            $payload,
            [],
            $task->project?->token,
        );

        if (filled($externalUser->email)) {
            $editUrl = rtrim($externalUser->url, '/').'/shift/tasks?task='.$task->id;

            $notificationService->sendFallbackEmailIfNeeded(
                $response,
                (string) $externalUser->email,
                $this->notificationFor($pending, $editUrl),
            );
        }

        return true;
    }

    private function collaboratorStillAttached(TaskCollaboratorNotification $pending): bool
    {
        return TaskCollaborator::query()
            ->where('task_id', $pending->task_id)
            ->where('kind', $pending->kind->value)
            ->when(
                $pending->kind === TaskCollaboratorKind::Internal,
                fn ($query) => $query->where('user_id', $pending->user_id),
                fn ($query) => $query->where('external_user_id', $pending->external_user_id),
            )
            ->exists();
    }

    private function recipientIsSubmitter(TaskCollaboratorNotification $pending): bool
    {
        $task = $pending->task;

        if (! $task) {
            return false;
        }

        if ($pending->kind === TaskCollaboratorKind::Internal) {
            return $task->submitter_type === User::class && (int) $task->submitter_id === (int) $pending->user_id;
        }

        return $task->submitter_type === ExternalUser::class && (int) $task->submitter_id === (int) $pending->external_user_id;
    }

    private function externalHandler(TaskCollaboratorNotification $pending): string
    {
        return match ($pending->event) {
            TaskCollaboratorNotification::EVENT_TASK_CREATED => 'task.created',
            TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED => 'task.collaborator_added',
        };
    }

    private function notificationFor(TaskCollaboratorNotification $pending, ?string $url = null): Notification
    {
        return match ($pending->event) {
            TaskCollaboratorNotification::EVENT_TASK_CREATED => new TaskCreationNotification($pending->task, $url ?? $pending->url),
            TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED => new TaskCollaboratorAddedNotification($pending->task, $url ?? $pending->url),
        };
    }
}
