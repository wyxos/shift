<?php

namespace App\Services\OrganisationTransfer;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OrganisationTransferPurger
{
    /**
     * @return array{
     *     fingerprint: string,
     *     organisation: array{id: int, name: string},
     *     tables: array<string, int>,
     *     attachments: array{count: int, available_count: int, missing_count: int},
     *     users: array{requested: bool, candidate_ids: list<int>, delete_ids: list<int>, preserved: array<int, list<string>>},
     *     row_ids: array<string, list<int>>,
     *     attachment_files: list<array{id: int, path: string, available: bool}>
     * }
     */
    public function inspect(Organisation $organisation, bool $deleteUsers): array
    {
        $selection = new OrganisationTransferSelection($organisation);
        $rowIds = [];
        $counts = [];

        foreach (OrganisationTransferSelection::TABLES as $table) {
            $ids = $selection->rows($table)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $rowIds[$table] = $ids;
            $counts[$table] = count($ids);
        }

        $attachmentFiles = collect($selection->rows('attachments'))
            ->map(function (array $attachment): array {
                $id = (int) $attachment['id'];
                $path = (string) $attachment['path'];
                $this->assertSafeAttachmentPath($path, $id);

                return [
                    'id' => $id,
                    'path' => $path,
                    'available' => Storage::exists($path),
                ];
            })
            ->sortBy('id')
            ->values()
            ->all();

        $candidateUserIds = $selection->userIds();
        $preservedUsers = $deleteUsers
            ? $this->remainingDomainReferences($candidateUserIds, $rowIds)
            : [];
        $deleteUserIds = $deleteUsers
            ? array_values(array_diff($candidateUserIds, array_keys($preservedUsers)))
            : [];

        $report = [
            'organisation' => [
                'id' => (int) $organisation->getKey(),
                'name' => (string) $organisation->name,
            ],
            'tables' => $counts,
            'attachments' => [
                'count' => count($attachmentFiles),
                'available_count' => collect($attachmentFiles)->where('available', true)->count(),
                'missing_count' => collect($attachmentFiles)->where('available', false)->count(),
            ],
            'users' => [
                'requested' => $deleteUsers,
                'candidate_ids' => $candidateUserIds,
                'delete_ids' => $deleteUserIds,
                'preserved' => $preservedUsers,
            ],
            'row_ids' => $rowIds,
            'attachment_files' => $attachmentFiles,
        ];
        $report['fingerprint'] = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function purge(Organisation $organisation, bool $deleteUsers, string $expectedFingerprint): array
    {
        $report = $this->inspect($organisation, $deleteUsers);
        if (! hash_equals($expectedFingerprint, $report['fingerprint'])) {
            throw new RuntimeException('Purge refused because the source scope changed after review. Run the dry run again and review its new fingerprint.');
        }

        $quarantineDirectory = '.shift-purge/'.Str::uuid();
        $movedFiles = [];

        try {
            foreach ($report['attachment_files'] as $attachment) {
                if (! $attachment['available']) {
                    continue;
                }

                $quarantinePath = "{$quarantineDirectory}/{$attachment['id']}";
                if (Storage::exists($quarantinePath) || ! Storage::move($attachment['path'], $quarantinePath)) {
                    throw new RuntimeException("Unable to stage attachment [{$attachment['id']}] for deletion.");
                }

                $movedFiles[] = [
                    'source' => $attachment['path'],
                    'quarantine' => $quarantinePath,
                ];
            }

            DB::transaction(function () use ($report): void {
                foreach (array_reverse(OrganisationTransferSelection::TABLES) as $table) {
                    if ($table === 'users') {
                        continue;
                    }

                    $this->deleteRows($table, $report['row_ids'][$table]);
                }

                foreach ($report['users']['delete_ids'] as $userId) {
                    $this->deleteUserAccountData($userId);
                    DB::table('users')->where('id', $userId)->delete();
                }

                $this->assertDatabaseRowsDeleted($report);
            }, 1);
        } catch (Throwable $exception) {
            $this->restoreMovedFiles($movedFiles);

            throw $exception;
        }

        if (! Storage::deleteDirectory($quarantineDirectory)) {
            throw new RuntimeException("The database purge committed, but staged attachment files could not be removed from [{$quarantineDirectory}].");
        }

        return $report;
    }

    /**
     * @param  list<int>  $candidateUserIds
     * @param  array<string, list<int>>  $rowIds
     * @return array<int, list<string>>
     */
    private function remainingDomainReferences(array $candidateUserIds, array $rowIds): array
    {
        $references = [
            ['organisations', 'author_id', null, null],
            ['organisation_users', 'user_id', null, null],
            ['projects', 'author_id', null, null],
            ['project_users', 'user_id', null, null],
            ['task_metadata', 'finalized_by', null, null],
            ['task_collaborators', 'user_id', null, null],
            ['project_app_error_notification_users', 'user_id', null, null],
            ['task_collaborator_notifications', 'user_id', null, null],
            ['task_thread_mentions', 'user_id', null, null],
            ['tasks', 'submitter_id', 'submitter_type', User::class],
            ['task_threads', 'sender_id', 'sender_type', User::class],
            ['activity_log', 'causer_id', 'causer_type', User::class],
        ];
        $preserved = [];

        foreach ($candidateUserIds as $userId) {
            foreach ($references as [$table, $idColumn, $typeColumn, $type]) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $idColumn)) {
                    continue;
                }

                $query = DB::table($table)->where($idColumn, $userId);
                if ($typeColumn !== null) {
                    $query->where($typeColumn, $type);
                }
                if (($rowIds[$table] ?? []) !== []) {
                    $query->whereNotIn('id', $rowIds[$table]);
                }

                if ($query->exists()) {
                    $preserved[$userId][] = $table;
                }
            }
        }

        ksort($preserved);

        return $preserved;
    }

    /** @param list<int|string> $ids */
    private function deleteRows(string $table, array $ids): void
    {
        if ($ids !== []) {
            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }

    private function deleteUserAccountData(int $userId): void
    {
        $ownedClientIds = [];
        if (Schema::hasTable('oauth_clients')) {
            $ownedClientIds = DB::table('oauth_clients')
                ->where('owner_type', User::class)
                ->where('owner_id', $userId)
                ->pluck('id')
                ->all();
        }

        if (Schema::hasTable('oauth_access_tokens')) {
            $accessTokenIds = DB::table('oauth_access_tokens')
                ->where(function (Builder $query) use ($userId, $ownedClientIds): void {
                    $query->where('user_id', $userId);
                    if ($ownedClientIds !== []) {
                        $query->orWhereIn('client_id', $ownedClientIds);
                    }
                })
                ->pluck('id')
                ->all();
            if ($accessTokenIds !== [] && Schema::hasTable('oauth_refresh_tokens')) {
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $accessTokenIds)->delete();
            }

            $this->deleteRows('oauth_access_tokens', $accessTokenIds);
        }

        $this->deleteOAuthRows('oauth_auth_codes', $userId, $ownedClientIds);
        $this->deleteOAuthRows('oauth_device_codes', $userId, $ownedClientIds);
        $this->deleteByUserId('sessions', 'user_id', $userId);
        $this->deleteByUserId('user_subscriptions', 'user_id', $userId);
        $this->deleteByUserId('agent_conversation_messages', 'user_id', $userId);

        if (Schema::hasTable('agent_conversations')) {
            $conversationIds = DB::table('agent_conversations')->where('user_id', $userId)->pluck('id')->all();
            if ($conversationIds !== [] && Schema::hasTable('agent_conversation_messages')) {
                DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->delete();
            }
            DB::table('agent_conversations')->where('user_id', $userId)->delete();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $userId)
                ->delete();
        }

        if ($ownedClientIds !== []) {
            $this->deleteRows('oauth_clients', $ownedClientIds);
        }

        if (Schema::hasTable('notifications')) {
            DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $userId)
                ->delete();
        }

        if (Schema::hasTable('user_impersonation_sessions')) {
            DB::table('user_impersonation_sessions')
                ->where(fn (Builder $query) => $query->where('admin_user_id', $userId)->orWhere('target_user_id', $userId))
                ->delete();
        }

        if (Schema::hasTable('password_reset_tokens')) {
            $email = DB::table('users')->where('id', $userId)->value('email');
            if (is_string($email)) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }
        }
    }

    private function deleteByUserId(string $table, string $column, int $userId): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->where($column, $userId)->delete();
        }
    }

    /** @param list<string> $clientIds */
    private function deleteOAuthRows(string $table, int $userId, array $clientIds): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where(function (Builder $query) use ($userId, $clientIds): void {
                $query->where('user_id', $userId);
                if ($clientIds !== []) {
                    $query->orWhereIn('client_id', $clientIds);
                }
            })
            ->delete();
    }

    /** @param array<string, mixed> $report */
    private function assertDatabaseRowsDeleted(array $report): void
    {
        foreach (OrganisationTransferSelection::TABLES as $table) {
            if ($table === 'users' || $report['row_ids'][$table] === []) {
                continue;
            }

            if (DB::table($table)->whereIn('id', $report['row_ids'][$table])->exists()) {
                throw new RuntimeException("Purge verification failed for table [{$table}].");
            }
        }

        if ($report['users']['delete_ids'] !== []
            && DB::table('users')->whereIn('id', $report['users']['delete_ids'])->exists()) {
            throw new RuntimeException('Purge verification failed for one or more exclusive transferred users.');
        }
    }

    /** @param list<array{source: string, quarantine: string}> $movedFiles */
    private function restoreMovedFiles(array $movedFiles): void
    {
        foreach (array_reverse($movedFiles) as $file) {
            if (Storage::exists($file['quarantine'])) {
                Storage::move($file['quarantine'], $file['source']);
            }
        }
    }

    private function assertSafeAttachmentPath(string $path, int $id): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)
            || ! str_starts_with($path, 'attachments/')) {
            throw new RuntimeException("Attachment [{$id}] has an unsafe storage path.");
        }
    }
}
