<?php

namespace App\Services;

use App\Enums\TaskCollaboratorKind;
use App\Models\ExternalIdentity;
use App\Models\ExternalInboxNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\TaskThread;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ExternalInboxService
{
    public function __construct(
        private readonly ExternalTaskAccess $taskAccess,
        private readonly TaskThreadAudienceService $threadAudiences,
    ) {}

    public function recordThread(TaskThread $thread, ExternalIdentity $recipient): void
    {
        $task = $thread->task;

        if (! $task instanceof Task
            || (int) $recipient->project_id !== (int) $task->project_id
            || $thread->type !== 'external'
            || ! $this->threadAudiences->isVisibleToExternalIdentities($thread)) {
            return;
        }

        $this->record($recipient, $task, 'thread:'.$thread->id, 'thread.update', $thread->content, $thread);
    }

    public function recordCollaborator(TaskCollaboratorNotification $pending): void
    {
        $recipient = $pending->externalIdentity;
        $task = $pending->task;

        if ($pending->kind !== TaskCollaboratorKind::External
            || $pending->cancelled_at !== null
            || ! $recipient instanceof ExternalIdentity
            || ! $task instanceof Task
            || (int) $recipient->project_id !== (int) $task->project_id) {
            return;
        }

        $type = match ($pending->event) {
            TaskCollaboratorNotification::EVENT_TASK_CREATED => 'task.created',
            TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED => 'task.collaborator_added',
            default => null,
        };

        if ($type === null) {
            return;
        }

        $key = $type === 'task.created' ? $type.':'.$task->id : $type.':'.$pending->id;

        $this->record($recipient, $task, $key, $type, $type === 'task.created'
            ? 'A task has been created and shared with you.'
            : 'You have been added as a collaborator.');
    }

    /** @return array<string, mixed> */
    public function page(?ExternalIdentity $identity, int $page = 1, int $perPage = 20): array
    {
        $identity = $identity === null ? null : $this->currentIdentity($identity);

        if ($identity === null) {
            return [
                'notifications' => [], 'unread_count' => 0, 'current_page' => $page,
                'last_page' => 1, 'total' => 0, 'per_page' => $perPage,
            ];
        }

        $groups = $this->visible($identity)
            ->selectRaw('MIN(external_inbox_notifications.id) AS id, event_key, MAX(created_at) AS event_created_at')
            ->selectRaw('CASE WHEN COUNT(read_at) = COUNT(*) THEN MAX(read_at) ELSE NULL END AS event_read_at')
            ->groupBy('event_key')
            ->orderByDesc('event_created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $records = $this->visible($identity)->with('externalIdentity')
            ->whereKey($groups->getCollection()->pluck('id'))->get()->keyBy('id');

        return [
            'notifications' => $groups->getCollection()->filter(fn (ExternalInboxNotification $group): bool => $records->has($group->id))
                ->values()->map(function (ExternalInboxNotification $group) use ($records): array {
                    $record = $records->get($group->id);

                    return [
                        'id' => $record->id,
                        'type' => $record->type,
                        'title' => $record->title,
                        'body' => $record->body,
                        'task_id' => $record->task_id,
                        'thread_id' => $record->task_thread_id,
                        'environment' => $record->externalIdentity?->environment,
                        'read_at' => $group->event_read_at === null ? null : Carbon::parse($group->event_read_at)->toISOString(),
                        'created_at' => Carbon::parse($group->event_created_at)->toISOString(),
                    ];
                })->all(),
            'unread_count' => $this->unreadCount($identity),
            'current_page' => $groups->currentPage(),
            'last_page' => $groups->lastPage(),
            'total' => $groups->total(),
            'per_page' => $groups->perPage(),
        ];
    }

    public function read(ExternalIdentity $identity, int $notificationId): void
    {
        $identity->getConnection()->transaction(function () use ($identity, $notificationId): void {
            $identity = $this->lockedIdentity($identity);
            $record = $this->visible($identity)->whereKey($notificationId)->firstOrFail();

            $this->visible($identity)->where('event_key', $record->event_key)->whereNull('read_at')
                ->update(['read_at' => now()]);
        }, 3);
    }

    public function readAll(ExternalIdentity $identity): void
    {
        $identity->getConnection()->transaction(function () use ($identity): void {
            $identity = $this->lockedIdentity($identity);
            $this->visible($identity)->whereNull('read_at')->update(['read_at' => now()]);
        }, 3);
    }

    public function unreadCount(ExternalIdentity $identity): int
    {
        $identity = $this->currentIdentity($identity);

        if ($identity === null) {
            return 0;
        }

        return $this->visible($identity)->whereNull('read_at')->distinct()->count('event_key');
    }

    private function currentIdentity(ExternalIdentity $identity): ?ExternalIdentity
    {
        return ExternalIdentity::query()->where('project_id', $identity->project_id)->find($identity->id);
    }

    private function lockedIdentity(ExternalIdentity $identity): ExternalIdentity
    {
        Project::query()->whereKey($identity->project_id)->lockForUpdate()->firstOrFail();

        return ExternalIdentity::query()->where('project_id', $identity->project_id)
            ->lockForUpdate()->findOrFail($identity->id);
    }

    private function visible(ExternalIdentity $identity): Builder
    {
        $identityIds = ExternalIdentity::query()->where('project_id', $identity->project_id)
            ->where(function (Builder $query) use ($identity): void {
                $query->whereKey($identity->id);
                if ($identity->project_participant_id !== null) {
                    $query->orWhere('project_participant_id', $identity->project_participant_id);
                }
            })->select('id');

        return ExternalInboxNotification::query()
            ->where('project_id', $identity->project_id)
            ->whereIn('external_identity_id', $identityIds)
            ->whereHas('task', function (Builder $tasks) use ($identity): void {
                $tasks->where('project_id', $identity->project_id);
                $this->taskAccess->constrainVisibleProjectItems($tasks, $identity);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('task_thread_id')->orWhereHas('taskThread', function (Builder $threads): void {
                    $threads->where('type', 'external')
                        ->whereColumn('task_threads.task_id', 'external_inbox_notifications.task_id');
                });
            });
    }

    private function record(
        ExternalIdentity $recipient,
        Task $task,
        string $key,
        string $type,
        string $body,
        ?TaskThread $thread = null,
    ): void {
        ExternalInboxNotification::query()->firstOrCreate([
            'external_identity_id' => $recipient->id,
            'event_key' => $key,
        ], [
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'task_thread_id' => $thread?->id,
            'type' => $type,
            'title' => $this->plainText($task->title, 255),
            'body' => $this->plainText($body, 280),
        ]);
    }

    private function plainText(string $value, int $limit): string
    {
        return Str::limit(Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $limit, '');
    }
}
