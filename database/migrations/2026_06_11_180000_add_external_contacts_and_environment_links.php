<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureProjectEnvironmentsTable();

        if (! Schema::hasTable('external_contacts')) {
            Schema::create('external_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('external_users', function (Blueprint $table) {
            if (! Schema::hasColumn('external_users', 'external_contact_id')) {
                $table->foreignId('external_contact_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('external_contacts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('external_users', 'project_environment_id')) {
                $table->foreignId('project_environment_id')
                    ->nullable()
                    ->after('external_contact_id')
                    ->constrained('project_environments')
                    ->nullOnDelete();
            }
        });

        $this->backfillExternalUserIdentityLinks();
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_users', 'project_environment_id')) {
            Schema::table('external_users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_environment_id');
            });
        }

        if (Schema::hasColumn('external_users', 'external_contact_id')) {
            Schema::table('external_users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('external_contact_id');
            });
        }

        Schema::dropIfExists('external_contacts');
    }

    private function ensureProjectEnvironmentsTable(): void
    {
        if (Schema::hasTable('project_environments')) {
            return;
        }

        Schema::create('project_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment');
            $table->string('url');
            $table->timestamps();

            $table->unique(['project_id', 'environment'], 'project_environments_project_environment_unique');
        });
    }

    private function backfillExternalUserIdentityLinks(): void
    {
        DB::table('external_users')
            ->orderBy('id')
            ->select([
                'id',
                'project_id',
                'environment',
                'url',
                'external_contact_id',
                'project_environment_id',
                'created_at',
                'updated_at',
            ])
            ->each(function ($externalUser) {
                $updates = [];

                if ($externalUser->external_contact_id === null && $externalUser->project_id !== null) {
                    $updates['external_contact_id'] = DB::table('external_contacts')->insertGetId([
                        'project_id' => $externalUser->project_id,
                        'created_at' => $externalUser->created_at ?? now(),
                        'updated_at' => $externalUser->updated_at ?? now(),
                    ]);
                }

                if (
                    $externalUser->project_environment_id === null
                    && $externalUser->project_id !== null
                    && $externalUser->environment !== null
                    && $externalUser->url !== null
                ) {
                    $updates['project_environment_id'] = $this->projectEnvironmentId(
                        (int) $externalUser->project_id,
                        (string) $externalUser->environment,
                        (string) $externalUser->url,
                        $externalUser->created_at,
                        $externalUser->updated_at,
                    );
                }

                if ($updates === []) {
                    return;
                }

                $updates['updated_at'] = $externalUser->updated_at ?? now();

                DB::table('external_users')
                    ->where('id', $externalUser->id)
                    ->update($updates);
            });
    }

    private function projectEnvironmentId(
        int $projectId,
        string $environment,
        string $url,
        mixed $createdAt,
        mixed $updatedAt,
    ): int {
        $existing = DB::table('project_environments')
            ->where('project_id', $projectId)
            ->where('environment', $environment)
            ->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) DB::table('project_environments')->insertGetId([
            'project_id' => $projectId,
            'environment' => $environment,
            'url' => rtrim($url, '/'),
            'created_at' => $createdAt ?? now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }
};
