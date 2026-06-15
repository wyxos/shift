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

test('external account identity migration backfills contacts and environment references without auto linking rows', function () {
    $migration = require database_path('migrations/2026_06_11_180000_add_external_contacts_and_environment_links.php');
    $migration->down();

    $project = Project::factory()->create();

    $firstExternalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'shared-client-id',
        'name' => 'Shared Client',
        'email' => 'shared-client@example.com',
        'environment' => 'production',
        'url' => 'https://client-production.test/',
    ]);
    $secondExternalUser = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'shared-client-id',
        'name' => 'Shared Client',
        'email' => 'shared-client@example.com',
        'environment' => 'staging',
        'url' => 'https://client-staging.test/',
    ]);

    DB::table('project_environments')
        ->where('project_id', $project->id)
        ->delete();

    $migration->up();

    $firstExternalUserRow = DB::table('external_users')->where('id', $firstExternalUser->id)->first();
    $secondExternalUserRow = DB::table('external_users')->where('id', $secondExternalUser->id)->first();

    expect($firstExternalUserRow->external_contact_id)->not->toBeNull();
    expect($secondExternalUserRow->external_contact_id)->not->toBeNull();
    expect($firstExternalUserRow->external_contact_id)->not->toBe($secondExternalUserRow->external_contact_id);
    expect($firstExternalUserRow->project_environment_id)->not->toBeNull();
    expect($secondExternalUserRow->project_environment_id)->not->toBeNull();

    expect(DB::table('external_contacts')
        ->where('project_id', $project->id)
        ->pluck('id')
        ->sort()
        ->values()
        ->all())->toBe(collect([
            $firstExternalUserRow->external_contact_id,
            $secondExternalUserRow->external_contact_id,
        ])->sort()->values()->all());

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
