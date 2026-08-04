<?php

namespace App\Services\OrganisationTransfer;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use RuntimeException;

class OrganisationTransferSelection
{
    /**
     * Tables are deliberately explicit. Operational and Hosted-only tables must never
     * enter an organisation transfer.
     *
     * @var list<string>
     */
    public const TABLES = [
        'users',
        'organisations',
        'organisation_users',
        'clients',
        'projects',
        'project_users',
        'project_environments',
        'external_contacts',
        'external_users',
        'requirement_batches',
        'tasks',
        'task_metadata',
        'task_threads',
        'task_collaborators',
        'task_error_occurrences',
        'project_app_error_notification_users',
        'task_collaborator_notifications',
        'task_thread_mentions',
        'external_notification_deliveries',
        'attachments',
        'activity_log',
        'notifications',
    ];

    /** @var array<string, Enumerable<int, array<string, mixed>>> */
    private array $rows = [];

    /** @var list<int> */
    private array $projectIds = [];

    /** @var list<int> */
    private array $taskIds = [];

    /** @var list<int> */
    private array $threadIds = [];

    /** @var list<int> */
    private array $userIds = [];

    public function __construct(public readonly Organisation $organisation)
    {
        $this->build();
    }

    /** @return Enumerable<int, array<string, mixed>> */
    public function rows(string $table): Enumerable
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException("Table [{$table}] is not part of the public SHIFT organisation-transfer contract.");
        }

        return $this->rows[$table] ?? collect();
    }

    /** @return list<int> */
    public function projectIds(): array
    {
        return $this->projectIds;
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return $this->userIds;
    }

    private function build(): void
    {
        $organisationId = (int) $this->organisation->getKey();

        $clientIds = $this->ids(DB::table('clients')->where('organisation_id', $organisationId));
        $this->projectIds = $this->ids(
            DB::table('projects')->where(function (Builder $query) use ($organisationId, $clientIds): void {
                $query->where('organisation_id', $organisationId);

                if ($clientIds !== []) {
                    $query->orWhereIn('client_id', $clientIds);
                }
            }),
        );
        $this->taskIds = $this->idsFor('tasks', 'project_id', $this->projectIds);
        $this->threadIds = $this->idsFor('task_threads', 'task_id', $this->taskIds);

        $externalContactIds = $this->idsFor('external_contacts', 'project_id', $this->projectIds);
        $externalUserIds = $this->idsFor('external_users', 'project_id', $this->projectIds);
        $requirementBatchIds = $this->idsFor('requirement_batches', 'project_id', $this->projectIds);
        $collaboratorNotificationIds = $this->idsFor('task_collaborator_notifications', 'task_id', $this->taskIds);

        $this->userIds = $this->referencedUserIds($organisationId);

        $this->rows = [
            'users' => $this->tableRows('users', fn (Builder $query) => $this->whereIds($query, 'id', $this->userIds)),
            'organisations' => $this->tableRows('organisations', fn (Builder $query) => $query->where('id', $organisationId)),
            'organisation_users' => $this->tableRows('organisation_users', fn (Builder $query) => $query->where('organisation_id', $organisationId)),
            'clients' => $this->tableRows('clients', fn (Builder $query) => $this->whereIds($query, 'id', $clientIds)),
            'projects' => $this->tableRows('projects', fn (Builder $query) => $this->whereIds($query, 'id', $this->projectIds)),
            'project_users' => $this->tableRows('project_users', fn (Builder $query) => $this->whereIds($query, 'project_id', $this->projectIds)),
            'project_environments' => $this->tableRows('project_environments', fn (Builder $query) => $this->whereIds($query, 'project_id', $this->projectIds)),
            'external_contacts' => $this->tableRows('external_contacts', fn (Builder $query) => $this->whereIds($query, 'id', $externalContactIds)),
            'external_users' => $this->tableRows('external_users', fn (Builder $query) => $this->whereIds($query, 'id', $externalUserIds)),
            'requirement_batches' => $this->tableRows('requirement_batches', fn (Builder $query) => $this->whereIds($query, 'id', $requirementBatchIds)),
            'tasks' => $this->tableRows('tasks', fn (Builder $query) => $this->whereIds($query, 'id', $this->taskIds)),
            'task_metadata' => $this->tableRows('task_metadata', fn (Builder $query) => $this->whereIds($query, 'task_id', $this->taskIds)),
            'task_threads' => $this->tableRows('task_threads', fn (Builder $query) => $this->whereIds($query, 'id', $this->threadIds)),
            'task_collaborators' => $this->tableRows('task_collaborators', fn (Builder $query) => $this->whereIds($query, 'task_id', $this->taskIds)),
            'task_error_occurrences' => $this->tableRows('task_error_occurrences', fn (Builder $query) => $this->whereIds($query, 'task_id', $this->taskIds)),
            'project_app_error_notification_users' => $this->tableRows('project_app_error_notification_users', fn (Builder $query) => $this->whereIds($query, 'project_id', $this->projectIds)),
            'task_collaborator_notifications' => $this->tableRows('task_collaborator_notifications', fn (Builder $query) => $this->whereIds($query, 'id', $collaboratorNotificationIds)),
            'task_thread_mentions' => $this->tableRows('task_thread_mentions', fn (Builder $query) => $this->whereIds($query, 'task_thread_id', $this->threadIds)),
            'external_notification_deliveries' => $this->tableRows('external_notification_deliveries', function (Builder $query) use ($collaboratorNotificationIds): void {
                $query->where(function (Builder $scope) use ($collaboratorNotificationIds): void {
                    $this->whereIds($scope, 'task_collaborator_notification_id', $collaboratorNotificationIds);

                    if ($this->threadIds !== []) {
                        $scope->orWhereIn('task_thread_id', $this->threadIds);
                    }
                });
            }),
            'attachments' => $this->tableRows('attachments', function (Builder $query): void {
                $query->where(function (Builder $scope): void {
                    $this->whereMorphIds($scope, 'App\\Models\\Task', $this->taskIds);

                    if ($this->threadIds !== []) {
                        $scope->orWhere(function (Builder $threads): void {
                            $this->whereMorphIds($threads, 'App\\Models\\TaskThread', $this->threadIds);
                        });
                    }
                });
            }),
            'activity_log' => $this->tableRows('activity_log', fn (Builder $query) => $this->whereMorphIds($query, 'App\\Models\\Task', $this->taskIds, 'subject')),
            'notifications' => $this->notificationRows($organisationId),
        ];
    }

    /** @return list<int> */
    private function referencedUserIds(int $organisationId): array
    {
        $ids = collect([(int) $this->organisation->author_id]);

        $references = [
            ['organisation_users', 'user_id', 'organisation_id', [$organisationId]],
            ['projects', 'author_id', 'id', $this->projectIds],
            ['project_users', 'user_id', 'project_id', $this->projectIds],
            ['task_metadata', 'finalized_by', 'task_id', $this->taskIds],
            ['task_collaborators', 'user_id', 'task_id', $this->taskIds],
            ['project_app_error_notification_users', 'user_id', 'project_id', $this->projectIds],
            ['task_collaborator_notifications', 'user_id', 'task_id', $this->taskIds],
            ['task_thread_mentions', 'user_id', 'task_thread_id', $this->threadIds],
        ];

        foreach ($references as [$table, $column, $scopeColumn, $scopeIds]) {
            if ($scopeIds === []) {
                continue;
            }

            $ids->push(...DB::table($table)->whereIn($scopeColumn, $scopeIds)->whereNotNull($column)->pluck($column));
        }

        if ($this->taskIds !== []) {
            $ids->push(...DB::table('tasks')
                ->whereIn('id', $this->taskIds)
                ->where('submitter_type', User::class)
                ->whereNotNull('submitter_id')
                ->pluck('submitter_id'));
        }

        if ($this->threadIds !== []) {
            $ids->push(...DB::table('task_threads')
                ->whereIn('id', $this->threadIds)
                ->where('sender_type', User::class)
                ->whereNotNull('sender_id')
                ->pluck('sender_id'));
        }

        return $ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    private function notificationRows(int $organisationId): LazyCollection
    {
        return $this->tableRows('notifications', function (Builder $query): void {
            $query->where('notifiable_type', User::class);
            $this->whereIds($query, 'notifiable_id', $this->userIds);
        })->filter(function (array $row) use ($organisationId): bool {
            $data = json_decode((string) $row['data'], true);

            if (! is_array($data)) {
                throw new RuntimeException("Notification [{$row['id']}] contains invalid JSON.");
            }

            return (isset($data['task_id']) && in_array((int) $data['task_id'], $this->taskIds, true))
                || (isset($data['project_id']) && in_array((int) $data['project_id'], $this->projectIds, true))
                || (isset($data['organisation_id']) && (int) $data['organisation_id'] === $organisationId);
        })->values();
    }

    /**
     * @param  callable(Builder): void  $scope
     * @return LazyCollection<int, array<string, mixed>>
     */
    private function tableRows(string $table, callable $scope): LazyCollection
    {
        $query = DB::table($table);
        $scope($query);

        $query->orderBy('id');

        return $query->cursor()
            ->map(function (object $row) use ($table): array {
                $attributes = (array) $row;

                // shift-hosted adds this column to the shared users table. It is not
                // part of public SHIFT and must never be included in an export.
                if ($table === 'users') {
                    unset($attributes['is_admin']);
                }

                return $attributes;
            });
    }

    /** @return list<int> */
    private function ids(Builder $query): array
    {
        return $query->orderBy('id')->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function idsFor(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->ids(DB::table($table)->whereIn($column, $ids));
    }

    private function whereIds(Builder $query, string $column, array $ids): Builder
    {
        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
    }

    private function whereMorphIds(Builder $query, string $type, array $ids, string $prefix = 'attachable'): Builder
    {
        $query->where("{$prefix}_type", $type);

        return $this->whereIds($query, "{$prefix}_id", $ids);
    }
}
