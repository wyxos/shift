<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IDENTITY_REFERENCES = [
        'task_collaborators',
        'task_thread_mentions',
        'task_collaborator_notifications',
        'requirement_batches',
    ];

    private const MORPH_COLUMNS = [
        'tasks' => ['submitter_type'],
        'task_threads' => ['sender_type'],
        'activity_log' => ['subject_type', 'causer_type'],
        'notifications' => ['notifiable_type'],
        'attachments' => ['attachable_type'],
    ];

    public function up(): void
    {
        $this->validateLegacyIdentities();
        $this->backfillParticipants();
        $this->renameSchema(true);

        Schema::table('external_identities', function (Blueprint $table) {
            $table->unique(['project_environment_id', 'external_id'], 'external_identities_environment_identity_unique');
        });

        $this->renameMorphTypes(true);
    }

    public function down(): void
    {
        $this->validateRollback();
        $this->renameMorphTypes(false);

        Schema::table('external_identities', function (Blueprint $table) {
            $table->dropUnique('external_identities_environment_identity_unique');
        });

        $this->renameSchema(false);
    }

    private function validateLegacyIdentities(): void
    {
        $duplicates = DB::table('external_users')
            ->select('project_environment_id', 'external_id')
            ->whereNotNull('project_environment_id')
            ->groupBy('project_environment_id', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicates !== null) {
            throw new RuntimeException('Participant migration stopped: multiple external accounts use registered environment '
                .$duplicates->project_environment_id.' and external ID '.$duplicates->external_id.'. Reconcile these identities explicitly before retrying; no rows have been changed.');
        }

        foreach (['external_contact_id' => 'external_contacts', 'project_environment_id' => 'project_environments'] as $column => $table) {
            $invalid = DB::table('external_users as identity')
                ->leftJoin($table.' as scope', 'scope.id', '=', 'identity.'.$column)
                ->whereNotNull('identity.'.$column)
                ->where(function ($query) {
                    $query->whereNull('scope.id')
                        ->orWhereNull('identity.project_id')
                        ->orWhereColumn('scope.project_id', '!=', 'identity.project_id');
                })
                ->value('identity.id');

            if ($invalid !== null) {
                throw new RuntimeException('Participant migration stopped: external account '.$invalid.' has an invalid or cross-project '.$column.'. Correct its scope before retrying; no rows have been changed.');
            }
        }
    }

    private function backfillParticipants(): void
    {
        DB::transaction(function () {
            DB::table('external_users')
                ->whereNotNull('project_id')
                ->whereNull('external_contact_id')
                ->orderBy('id')
                ->eachById(function (object $identity) {
                    $participantId = DB::table('external_contacts')->insertGetId([
                        'project_id' => $identity->project_id,
                        'created_at' => $identity->created_at ?? now(),
                        'updated_at' => $identity->updated_at ?? now(),
                    ]);

                    DB::table('external_users')->where('id', $identity->id)->update([
                        'external_contact_id' => $participantId,
                    ]);
                });
        });
    }

    private function validateRollback(): void
    {
        $duplicate = DB::table('external_identities')
            ->whereNotNull('project_id')
            ->whereNotNull('environment')
            ->whereNotNull('url')
            ->groupBy('project_id', 'external_id', 'environment', 'url')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('Participant rollback stopped: identities cannot satisfy the previous identity uniqueness rule. No rows have been changed.');
        }
    }

    private function renameSchema(bool $forward): void
    {
        $identities = $forward ? 'external_users' : 'external_identities';
        $participants = $forward ? 'external_contacts' : 'project_participants';
        $newIdentities = $forward ? 'external_identities' : 'external_users';
        $newParticipants = $forward ? 'project_participants' : 'external_contacts';
        $oldIdentityColumn = $forward ? 'external_user_id' : 'external_identity_id';
        $newIdentityColumn = $forward ? 'external_identity_id' : 'external_user_id';
        $oldParticipantColumn = $forward ? 'external_contact_id' : 'project_participant_id';
        $newParticipantColumn = $forward ? 'project_participant_id' : 'external_contact_id';

        $this->principalChecks($oldIdentityColumn, false);

        Schema::rename($participants, $newParticipants);
        Schema::rename($identities, $newIdentities);
        Schema::table($newIdentities, function (Blueprint $table) use ($oldParticipantColumn, $newParticipantColumn) {
            $table->renameColumn($oldParticipantColumn, $newParticipantColumn);
        });

        foreach (self::IDENTITY_REFERENCES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($oldIdentityColumn, $newIdentityColumn) {
                $table->renameColumn($oldIdentityColumn, $newIdentityColumn);
            });
        }

        $this->renameIndexesAndForeignKeys($newIdentities, $forward);
        $this->renameIndexesAndForeignKeys($newParticipants, $forward);
        foreach (self::IDENTITY_REFERENCES as $tableName) {
            $this->renameIndexesAndForeignKeys($tableName, $forward);
        }

        $this->principalChecks($newIdentityColumn, true);
    }

    private function renameIndexesAndForeignKeys(string $tableName, bool $forward): void
    {
        $replace = $forward
            ? ['external_users' => 'external_identities', 'external_contacts' => 'project_participants', 'external_contact_id' => 'project_participant_id', 'external_user' => 'external_identity']
            : ['external_identities' => 'external_users', 'project_participants' => 'external_contacts', 'project_participant_id' => 'external_contact_id', 'external_identity' => 'external_user'];

        foreach (Schema::getIndexes($tableName) as $index) {
            $newName = strtr($index['name'], $replace);
            if ($newName !== $index['name']) {
                Schema::table($tableName, fn (Blueprint $table) => $table->renameIndex($index['name'], $newName));
            }
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreign) {
            $newName = strtr($foreign['name'], $replace);
            if ($newName === $foreign['name']) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($foreign, $newName) {
                $table->dropForeign($foreign['name']);
                $table->foreign($foreign['columns'], $newName)
                    ->references($foreign['foreign_columns'])
                    ->on($foreign['foreign_table'])
                    ->onUpdate($foreign['on_update'])
                    ->onDelete($foreign['on_delete']);
            });
        }
    }

    private function principalChecks(string $column, bool $create): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (['task_collaborators', 'task_thread_mentions'] as $table) {
            if ($create) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_principal_check CHECK ((kind = 'internal' AND user_id IS NOT NULL AND {$column} IS NULL) OR (kind = 'external' AND user_id IS NULL AND {$column} IS NOT NULL))");
            } else {
                $connection = DB::connection();
                $drop = $connection instanceof MySqlConnection && $connection->isMaria() ? 'DROP CONSTRAINT' : 'DROP CHECK';
                DB::statement("ALTER TABLE {$table} {$drop} {$table}_principal_check");
            }
        }
    }

    private function renameMorphTypes(bool $forward): void
    {
        $types = $forward
            ? ['App\\Models\\ExternalUser' => 'App\\Models\\ExternalIdentity', 'App\\Models\\ExternalContact' => 'App\\Models\\ProjectParticipant']
            : ['App\\Models\\ExternalIdentity' => 'App\\Models\\ExternalUser', 'App\\Models\\ProjectParticipant' => 'App\\Models\\ExternalContact'];

        foreach (self::MORPH_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($types as $old => $new) {
                    DB::table($table)->where($column, $old)->update([$column => $new]);
                }
            }
        }
    }
};
