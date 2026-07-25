<?php

namespace App\Jobs;

use App\Enums\TaskCollaboratorKind;
use App\Exceptions\ExternalNotificationException;
use App\Models\ExternalNotificationDelivery;
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
use Throwable;

class SendPendingTaskCollaboratorNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public int $notificationId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ExternalNotificationService $notificationService): void
    {
        $pending = TaskCollaboratorNotification::query()
            ->with(['task.project', 'user', 'externalUser'])
            ->whereKey($this->notificationId)
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->first();

        if (! $pending) {
            $cancelled = TaskCollaboratorNotification::query()->find($this->notificationId);

            if ($cancelled?->cancelled_at) {
                ExternalNotificationDelivery::findForTaskCollaborator($this->notificationId)?->markCancelled();
            }

            return;
        }

        if ($pending->scheduled_at->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds($pending->scheduled_at, false)));

            return;
        }

        if (! $pending->task || ! $this->collaboratorStillAttached($pending) || $this->recipientIsSubmitter($pending)) {
            $pending->markCancelled();
            ExternalNotificationDelivery::findForTaskCollaborator($this->notificationId)?->markCancelled();

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

        $handler = $this->externalHandler($pending);
        $delivery = ExternalNotificationDelivery::forTaskCollaborator($pending, $handler);

        if (! $delivery->callback_delivered_at) {
            $delivery->recordAttempt();

            try {
                $response = $notificationService->sendNotification(
                    $externalUser->url,
                    $handler,
                    $payload,
                    [],
                    $task->project?->token,
                    $delivery->delivery_id,
                );
            } catch (ExternalNotificationException $exception) {
                $delivery->recordAttemptFailure($exception->failureType, $exception->statusCode);

                if ($exception->retryable) {
                    throw $exception;
                }

                $delivery->markFailed($exception->failureType, $exception->statusCode);

                return false;
            }

            $delivery->markCallbackDelivered(
                (bool) $response->json('production'),
                $response->status(),
            );
        }

        if ($delivery->production === false && filled($externalUser->email)) {
            if (! $delivery->fallback_dispatched_at) {
                $editUrl = rtrim($externalUser->url, '/').'/shift/tasks?task='.$task->id;

                SendExternalNotificationFallbackEmail::dispatch(
                    $delivery->id,
                    (string) $externalUser->email,
                    $this->notificationFor($pending, $editUrl),
                );

                $delivery->markFallbackDispatched();
            }

            return (bool) $delivery->fresh()->fallback_sent_at;
        }

        $delivery->markCompleted();

        return true;
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = ExternalNotificationDelivery::findForTaskCollaborator($this->notificationId);

        if (! $delivery || $delivery->completed_at || $delivery->cancelled_at) {
            return;
        }

        if ($exception instanceof ExternalNotificationException) {
            $delivery->markFailed($exception->failureType, $exception->statusCode);

            return;
        }

        $delivery->markFailed('job_exhausted');
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
