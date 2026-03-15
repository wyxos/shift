<?php

use App\Enums\TaskCollaboratorKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('external_user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id'], 'task_collaborators_task_user_unique');
            $table->unique(['task_id', 'external_user_id'], 'task_collaborators_task_external_user_unique');
            $table->index(['task_id', 'kind'], 'task_collaborators_task_kind_index');
        });

        $driver = DB::getDriverName();
        if ($driver !== 'sqlite') {
            DB::statement("
                ALTER TABLE task_collaborators
                ADD CONSTRAINT task_collaborators_principal_check
                CHECK (
                    (kind = 'internal' AND user_id IS NOT NULL AND external_user_id IS NULL)
                    OR
                    (kind = 'external' AND user_id IS NULL AND external_user_id IS NOT NULL)
                )
            ");
        }

        Schema::create('project_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment');
            $table->string('url');
            $table->timestamps();

            $table->unique(['project_id', 'environment'], 'project_environments_project_environment_unique');
        });

        $this->backfillProjectEnvironments();
        $this->backfillExternalCollaborators();

        if (Schema::hasTable('external_access')) {
            Schema::drop('external_access');
        }

        $this->deduplicateExternalUsers();

        Schema::table('external_users', function (Blueprint $table) {
            $table->unique(['project_id', 'external_id', 'environment', 'url'], 'external_users_project_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::create('external_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['external_user_id', 'task_id']);
        });

        $rows = DB::table('task_collaborators')
            ->where('kind', TaskCollaboratorKind::External->value)
            ->get(['task_id', 'external_user_id', 'created_at', 'updated_at']);

        foreach ($rows as $row) {
            DB::table('external_access')->updateOrInsert(
                [
                    'task_id' => $row->task_id,
                    'external_user_id' => $row->external_user_id,
                ],
                [
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            );
        }

        Schema::dropIfExists('task_collaborators');

        Schema::table('external_users', function (Blueprint $table) {
            $table->dropUnique('external_users_project_identity_unique');
        });

        Schema::dropIfExists('project_environments');
    }

    private function backfillProjectEnvironments(): void
    {
        $rows = DB::table('external_users')
            ->select([
                'project_id',
                'environment',
                'url',
                DB::raw('MIN(created_at) as created_at'),
                DB::raw('MAX(updated_at) as updated_at'),
            ])
            ->whereNotNull('project_id')
            ->whereNotNull('environment')
            ->whereNotNull('url')
            ->groupBy('project_id', 'environment', 'url')
            ->get();

        foreach ($rows as $row) {
            DB::table('project_environments')->updateOrInsert(
                [
                    'project_id' => $row->project_id,
                    'environment' => $row->environment,
                ],
                [
                    'url' => rtrim((string) $row->url, '/'),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            );
        }
    }

    private function backfillExternalCollaborators(): void
    {
        $taskRows = DB::table('tasks')
            ->join('external_users', function ($join) {
                $join
                    ->on('external_users.id', '=', 'tasks.submitter_id')
                    ->where('tasks.submitter_type', '=', 'App\\Models\\ExternalUser');
            })
            ->select([
                'tasks.id as task_id',
                'tasks.project_id',
                'external_users.id as external_user_id',
            ])
            ->get();

        $assigned = [];

        foreach ($taskRows as $row) {
            $scopedExternalUserId = $this->ensureProjectScopedExternalUser((int) $row->external_user_id, (int) $row->project_id, $assigned);

            if ($scopedExternalUserId !== (int) $row->external_user_id) {
                DB::table('tasks')
                    ->where('id', $row->task_id)
                    ->update(['submitter_id' => $scopedExternalUserId]);
            }
        }

        if (! Schema::hasTable('external_access')) {
            return;
        }

        $externalAccessRows = DB::table('external_access')
            ->join('tasks', 'tasks.id', '=', 'external_access.task_id')
            ->select([
                'external_access.task_id',
                'tasks.project_id',
                'external_access.external_user_id',
                'external_access.created_at',
                'external_access.updated_at',
            ])
            ->get();

        foreach ($externalAccessRows as $row) {
            $scopedExternalUserId = $this->ensureProjectScopedExternalUser((int) $row->external_user_id, (int) $row->project_id, $assigned);

            DB::table('task_collaborators')->updateOrInsert(
                [
                    'task_id' => $row->task_id,
                    'external_user_id' => $scopedExternalUserId,
                ],
                [
                    'kind' => TaskCollaboratorKind::External->value,
                    'user_id' => null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            );
        }
    }

    private function ensureProjectScopedExternalUser(int $externalUserId, int $projectId, array &$assigned): int
    {
        $key = "{$externalUserId}:{$projectId}";

        if (array_key_exists($key, $assigned)) {
            return $assigned[$key];
        }

        $externalUser = DB::table('external_users')->where('id', $externalUserId)->first();

        if ($externalUser === null) {
            return $externalUserId;
        }

        if ((int) ($externalUser->project_id ?? 0) === $projectId) {
            $assigned[$key] = $externalUserId;

            return $externalUserId;
        }

        $existing = DB::table('external_users')
            ->where('project_id', $projectId)
            ->where('external_id', $externalUser->external_id)
            ->where('environment', $externalUser->environment)
            ->where('url', $externalUser->url)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            $assigned[$key] = (int) $existing->id;

            return (int) $existing->id;
        }

        $alreadyAssignedProject = $assigned[$externalUserId] ?? null;

        if ($externalUser->project_id === null && $alreadyAssignedProject === null) {
            DB::table('external_users')
                ->where('id', $externalUserId)
                ->update(['project_id' => $projectId]);

            $assigned[$externalUserId] = $projectId;
            $assigned[$key] = $externalUserId;

            return $externalUserId;
        }

        $newId = DB::table('external_users')->insertGetId([
            'project_id' => $projectId,
            'external_id' => $externalUser->external_id,
            'name' => $externalUser->name,
            'email' => $externalUser->email,
            'environment' => $externalUser->environment,
            'url' => $externalUser->url,
            'created_at' => $externalUser->created_at,
            'updated_at' => $externalUser->updated_at,
        ]);

        $assigned[$key] = (int) $newId;

        return (int) $newId;
    }

    private function deduplicateExternalUsers(): void
    {
        $duplicates = DB::table('external_users')
            ->select([
                'project_id',
                'external_id',
                'environment',
                'url',
                DB::raw('MIN(id) as keeper_id'),
                DB::raw('COUNT(*) as duplicate_count'),
            ])
            ->whereNotNull('project_id')
            ->groupBy('project_id', 'external_id', 'environment', 'url')
            ->having('duplicate_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateIds = DB::table('external_users')
                ->where('project_id', $duplicate->project_id)
                ->where('external_id', $duplicate->external_id)
                ->where('environment', $duplicate->environment)
                ->where('url', $duplicate->url)
                ->where('id', '!=', $duplicate->keeper_id)
                ->pluck('id');

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('tasks')
                ->where('submitter_type', 'App\\Models\\ExternalUser')
                ->whereIn('submitter_id', $duplicateIds)
                ->update(['submitter_id' => $duplicate->keeper_id]);

            $collaboratorRows = DB::table('task_collaborators')
                ->whereIn('external_user_id', $duplicateIds)
                ->get();

            foreach ($collaboratorRows as $row) {
                DB::table('task_collaborators')->updateOrInsert(
                    [
                        'task_id' => $row->task_id,
                        'external_user_id' => $duplicate->keeper_id,
                    ],
                    [
                        'kind' => TaskCollaboratorKind::External->value,
                        'user_id' => null,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ],
                );
            }

            DB::table('task_collaborators')
                ->whereIn('external_user_id', $duplicateIds)
                ->delete();

            DB::table('external_users')
                ->whereIn('id', $duplicateIds)
                ->delete();
        }
    }
};
