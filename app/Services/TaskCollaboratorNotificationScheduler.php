<?php

namespace App\Services;

use App\Enums\TaskCollaboratorKind;
use App\Jobs\SendPendingTaskCollaboratorNotification;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use Illuminate\Support\Collection;

class TaskCollaboratorNotificationScheduler
{
    public function __construct(private readonly TaskCollaboratorService $collaborators) {}

    public function scheduleTaskCreated(Task $task, ?string $url = null): void
    {
        $task->load(['submitter', 'internalCollaborators', 'externalCollaborators', 'project']);

        $this->scheduleInternal(
            $task,
            $this->collaborators->internalTaskCreateAudience($task),
            TaskCollaboratorNotification::EVENT_TASK_CREATED,
            $url,
        );

        $this->scheduleExternal(
            $task,
            $this->collaborators->externalTaskCreateAudience($task),
            TaskCollaboratorNotification::EVENT_TASK_CREATED,
        );
    }

    public function scheduleCollaboratorAdded(Task $task, array $syncResult, ?string $url = null): void
    {
        $this->cancelRemoved($task, $syncResult);

        $addedInternal = ($syncResult['added_internal'] ?? collect())
            ->reject(fn (User $user): bool => $task->submitter_type === User::class && (int) $task->submitter_id === $user->id)
            ->values();

        $this->scheduleInternal(
            $task,
            $addedInternal,
            TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED,
            $url,
        );

        $addedExternal = ($syncResult['added_external'] ?? collect())
            ->reject(fn (ExternalUser $externalUser): bool => $task->submitter_type === ExternalUser::class && (int) $task->submitter_id === $externalUser->id)
            ->values();

        $this->scheduleExternal(
            $task,
            $addedExternal,
            TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED,
        );
    }

    private function scheduleInternal(Task $task, Collection $users, string $event, ?string $url): void
    {
        foreach ($users as $user) {
            $this->schedule($task, $event, TaskCollaboratorKind::Internal, (int) $user->id, null, $url);
        }
    }

    private function scheduleExternal(Task $task, Collection $externalUsers, string $event): void
    {
        foreach ($externalUsers as $externalUser) {
            if ($externalUser->email === null && $externalUser->url === null) {
                continue;
            }

            $this->schedule($task, $event, TaskCollaboratorKind::External, null, (int) $externalUser->id, null);
        }
    }

    private function schedule(
        Task $task,
        string $event,
        TaskCollaboratorKind $kind,
        ?int $userId,
        ?int $externalUserId,
        ?string $url,
    ): void {
        $scheduledAt = now()->addSeconds($this->gracePeriodSeconds());

        $notification = TaskCollaboratorNotification::query()->create([
            'task_id' => $task->id,
            'event' => $event,
            'kind' => $kind->value,
            'user_id' => $userId,
            'external_user_id' => $externalUserId,
            'url' => $url,
            'scheduled_at' => $scheduledAt,
        ]);

        SendPendingTaskCollaboratorNotification::dispatch($notification->id)->delay($scheduledAt);
    }

    private function cancelRemoved(Task $task, array $syncResult): void
    {
        $this->cancelPending($task, TaskCollaboratorKind::Internal, $syncResult['removed_internal_ids'] ?? []);
        $this->cancelPending($task, TaskCollaboratorKind::External, $syncResult['removed_external_ids'] ?? []);
    }

    private function cancelPending(Task $task, TaskCollaboratorKind $kind, array $recipientIds): void
    {
        foreach ($recipientIds as $recipientId) {
            TaskCollaboratorNotification::query()
                ->where('task_id', $task->id)
                ->where('kind', $kind->value)
                ->when(
                    $kind === TaskCollaboratorKind::Internal,
                    fn ($query) => $query->where('user_id', (int) $recipientId),
                    fn ($query) => $query->where('external_user_id', (int) $recipientId),
                )
                ->whereNull('sent_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now(), 'updated_at' => now()]);
        }
    }

    private function gracePeriodSeconds(): int
    {
        return max(0, (int) config('shift.notifications.collaborator_grace_period_seconds', 300));
    }
}
