<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\ExternalUser;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

trait FormatsShiftRecords
{
    /**
     * @return array<string, mixed>
     */
    protected function taskSummary(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $this->date($task->due_date),
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
            ] : null,
            'submitter' => $this->person($task->submitter),
            'thread_count' => $task->threads_count ?? null,
            'internal_collaborator_count' => $task->internal_collaborators_count ?? null,
            'external_collaborator_count' => $task->external_collaborators_count ?? null,
            'created_at' => $this->date($task->created_at),
            'updated_at' => $this->date($task->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskDetails(Task $task): array
    {
        return [
            ...$this->taskSummary($task),
            'description' => $task->description,
            'metadata' => $task->metadata ? [
                'environment' => $task->metadata->environment,
                'url' => $task->metadata->url,
            ] : null,
            'collaborators' => $task->collaborators
                ->map(fn (TaskCollaborator $collaborator): array => $this->collaborator($collaborator))
                ->values()
                ->all(),
            'attachments' => $task->attachments
                ->map(fn ($attachment): array => [
                    'id' => $attachment->id,
                    'original_filename' => $attachment->original_filename,
                    'created_at' => $this->date($attachment->created_at),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function thread(TaskThread $thread): array
    {
        return [
            'id' => $thread->id,
            'task_id' => $thread->task_id,
            'type' => $thread->type,
            'content' => $thread->content,
            'sender_name' => $thread->sender_name,
            'sender' => $this->person($thread->sender),
            'attachments' => $thread->attachments
                ->map(fn ($attachment): array => [
                    'id' => $attachment->id,
                    'original_filename' => $attachment->original_filename,
                ])
                ->values()
                ->all(),
            'created_at' => $this->date($thread->created_at),
            'updated_at' => $this->date($thread->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function collaborator(TaskCollaborator $collaborator): array
    {
        return [
            'id' => $collaborator->id,
            'kind' => $collaborator->kind?->value ?? $collaborator->kind,
            'user' => $this->person($collaborator->user),
            'external_user' => $this->person($collaborator->externalUser),
            'created_at' => $this->date($collaborator->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notification(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $taskIds = $this->notificationTaskIds($data);
        $projectIds = $this->notificationProjectIds($data);

        $summary = [
            'id' => $notification->id,
            'type' => $notification->type,
            'name' => class_basename($notification->type),
            'notifiable' => $this->person($notification->notifiable),
            'read_at' => $this->date($notification->read_at),
            'created_at' => $this->date($notification->created_at),
        ];

        if ($taskIds !== []) {
            $summary['task_ids'] = $taskIds;
        }

        if ($projectIds !== []) {
            $summary['project_ids'] = $projectIds;
        }

        if (isset($data['thread_id'])) {
            $summary['thread_id'] = (int) $data['thread_id'];
        }

        if (isset($data['type']) && is_string($data['type'])) {
            $summary['thread_type'] = $data['type'];
        }

        if (isset($data['tasks']) && is_array($data['tasks'])) {
            $summary['task_count'] = count($data['tasks']);
        }

        if ($link = $this->notificationLink($data)) {
            $summary['link'] = $link;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int>
     */
    protected function notificationTaskIds(array $data): array
    {
        $ids = [];

        if (isset($data['task_id'])) {
            $ids[] = (int) $data['task_id'];
        }

        if (isset($data['tasks']) && is_array($data['tasks'])) {
            foreach ($data['tasks'] as $task) {
                if (is_array($task) && isset($task['task_id'])) {
                    $ids[] = (int) $task['task_id'];
                }
            }
        }

        return $this->uniquePositiveIntegers($ids);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int>
     */
    protected function notificationProjectIds(array $data): array
    {
        $ids = [];

        if (isset($data['project_id'])) {
            $ids[] = (int) $data['project_id'];
        }

        if (isset($data['tasks']) && is_array($data['tasks'])) {
            foreach ($data['tasks'] as $task) {
                if (is_array($task) && isset($task['project_id'])) {
                    $ids[] = (int) $task['project_id'];
                }
            }
        }

        return $this->uniquePositiveIntegers($ids);
    }

    /**
     * @param  array<int>  $values
     * @return array<int>
     */
    protected function uniquePositiveIntegers(array $values): array
    {
        return array_values(array_unique(array_filter(
            $values,
            fn (int $value): bool => $value > 0
        )));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{site: string, host: string|null, path: string|null}|null
     */
    protected function notificationLink(array $data): ?array
    {
        $url = $data['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        return [
            'site' => $this->notificationLinkSite($url, is_string($host) ? $host : null),
            'host' => is_string($host) ? $host : null,
            'path' => is_string($path) ? $path : null,
        ];
    }

    protected function notificationLinkSite(string $url, ?string $host): string
    {
        if ($host === null) {
            return str_starts_with($url, '/') ? 'shift' : 'unknown';
        }

        $shiftHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($shiftHost) && strcasecmp($host, $shiftHost) === 0
            ? 'shift'
            : 'consuming_project';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function person(?Model $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'type' => match ($model::class) {
                User::class => 'internal_user',
                ExternalUser::class => 'external_user',
                ProjectUser::class => 'project_user',
                default => Str::snake(class_basename($model)),
            },
            'id' => $model->getKey(),
            'name' => $model->getAttribute('name') ?? $model->getAttribute('user_name'),
            'email' => $model->getAttribute('email') ?? $model->getAttribute('user_email'),
        ];
    }

    protected function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return method_exists($value, 'toISOString')
                ? $value->toISOString()
                : $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
