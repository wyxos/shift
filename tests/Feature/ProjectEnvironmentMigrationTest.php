<?php

use App\Models\ExternalUser;
use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('task collaborator migration backfills project environments from external users', function () {
    $project = Project::factory()->create();

    ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'client-1',
        'environment' => 'production',
        'url' => 'https://client-production.test',
    ]);
    ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'client-2',
        'environment' => 'staging',
        'url' => 'https://client-staging.test',
    ]);

    Schema::dropIfExists('project_environments');
    Schema::dropIfExists('task_collaborators');

    Schema::table('external_users', function (Blueprint $table) {
        $table->dropUnique('external_users_project_identity_unique');
    });

    $migration = require database_path('migrations/2026_03_09_120000_create_task_collaborators_table.php');
    $migration->up();

    expect(DB::table('project_environments')
        ->where('project_id', $project->id)
        ->orderBy('environment')
        ->get(['environment', 'url'])
        ->map(fn ($row) => [$row->environment, $row->url])
        ->all())->toBe([
            ['production', 'https://client-production.test'],
            ['staging', 'https://client-staging.test'],
        ]);
});

test('follow-up project environment migration backfills existing installs', function () {
    $project = Project::factory()->create();

    ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'client-1',
        'environment' => 'production',
        'url' => 'https://client-production.test',
    ]);
    ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'client-2',
        'environment' => 'staging',
        'url' => 'https://client-staging.test',
    ]);

    Schema::dropIfExists('project_environments');

    $migration = require database_path('migrations/2026_03_09_130000_create_project_environments_for_existing_installs.php');
    $migration->up();

    expect(DB::table('project_environments')
        ->where('project_id', $project->id)
        ->orderBy('environment')
        ->get(['environment', 'url'])
        ->map(fn ($row) => [$row->environment, $row->url])
        ->all())->toBe([
            ['production', 'https://client-production.test'],
            ['staging', 'https://client-staging.test'],
        ]);
});
