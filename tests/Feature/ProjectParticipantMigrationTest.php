<?php

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function participantMigration(): Illuminate\Database\Migrations\Migration
{
    return require database_path('migrations/2026_10_15_120000_rename_external_accounts_to_project_participants.php');
}

function legacyParticipantIdentity(int $projectId, array $attributes = []): int
{
    return DB::table('external_users')->insertGetId(array_merge([
        'project_id' => $projectId,
        'external_id' => '42',
        'name' => 'David',
        'email' => 'david@example.com',
        'role' => 'user',
        'environment' => 'production',
        'url' => 'https://voidcare.example',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

test('participant migration preserves identities actor anchors recipients and links through upgrade and rollback', function () {
    participantMigration()->down();
    $project = Project::factory()->create();
    $environment = ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://voidcare.example',
    ]);
    $participantId = DB::table('external_contacts')->insertGetId([
        'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $identityId = legacyParticipantIdentity($project->id, [
        'external_contact_id' => $participantId,
        'project_environment_id' => $environment->id,
    ]);
    $task = Task::factory()->for($project)->create([
        'submitter_type' => 'App\\Models\\ExternalUser', 'submitter_id' => $identityId,
    ]);
    $threadId = DB::table('task_threads')->insertGetId([
        'task_id' => $task->id, 'type' => 'external', 'content' => 'A report',
        'sender_name' => 'David', 'sender_type' => 'App\\Models\\ExternalUser', 'sender_id' => $identityId,
    ]);
    foreach (['task_collaborators', 'task_thread_mentions', 'task_collaborator_notifications', 'requirement_batches'] as $table) {
        $row = ['external_user_id' => $identityId];
        $row += match ($table) {
            'task_collaborators' => ['task_id' => $task->id, 'kind' => 'external'],
            'task_thread_mentions' => ['task_thread_id' => $threadId, 'kind' => 'external'],
            'task_collaborator_notifications' => ['task_id' => $task->id, 'event' => 'created', 'kind' => 'external', 'scheduled_at' => now(), 'url' => 'https://voidcare.example'],
            'requirement_batches' => ['project_id' => $project->id],
        };
        DB::table($table)->insert($row);
    }
    $activityId = DB::table('activity_log')->insertGetId([
        'description' => 'linked', 'subject_type' => 'App\\Models\\ExternalContact', 'subject_id' => $participantId,
        'causer_type' => 'App\\Models\\ExternalUser', 'causer_id' => $identityId,
    ]);
    DB::table('notifications')->insert([
        'id' => fake()->uuid(), 'type' => 'test', 'notifiable_type' => 'App\\Models\\ExternalUser',
        'notifiable_id' => $identityId, 'data' => '{"user_id":42}',
    ]);

    participantMigration()->up();

    expect(Schema::hasTable('external_users'))->toBeFalse()
        ->and(DB::table('external_identities')->where('id', $identityId)->value('project_participant_id'))->toBe($participantId)
        ->and(DB::table('external_identities')->where('id', $identityId)->value('project_environment_id'))->toBe($environment->id)
        ->and(DB::table('tasks')->where('id', $task->id)->value('submitter_type'))->toBe('App\\Models\\ExternalIdentity')
        ->and(DB::table('tasks')->where('id', $task->id)->value('submitter_id'))->toBe($identityId)
        ->and(DB::table('task_threads')->where('id', $threadId)->value('sender_type'))->toBe('App\\Models\\ExternalIdentity')
        ->and(DB::table('activity_log')->where('id', $activityId)->value('subject_type'))->toBe('App\\Models\\ProjectParticipant')
        ->and(DB::table('activity_log')->where('id', $activityId)->value('causer_type'))->toBe('App\\Models\\ExternalIdentity')
        ->and(DB::table('notifications')->value('notifiable_type'))->toBe('App\\Models\\ExternalIdentity')
        ->and(DB::table('notifications')->value('data'))->toBe('{"user_id":42}');
    foreach (['task_collaborators', 'task_thread_mentions', 'task_collaborator_notifications', 'requirement_batches'] as $table) {
        expect(DB::table($table)->value('external_identity_id'))->toBe($identityId);
        $foreign = collect(Schema::getForeignKeys($table))->firstWhere('columns', ['external_identity_id']);
        expect($foreign['foreign_table'])->toBe('external_identities')
            ->and($foreign['foreign_columns'])->toBe(['id'])
            ->and(strtolower($foreign['on_delete']))->toBe($table === 'requirement_batches' ? 'set null' : 'cascade');
    }

    participantMigration()->down();

    expect(DB::table('external_users')->where('id', $identityId)->value('external_contact_id'))->toBe($participantId)
        ->and(DB::table('tasks')->where('id', $task->id)->value('submitter_type'))->toBe('App\\Models\\ExternalUser')
        ->and(DB::table('task_threads')->where('id', $threadId)->value('sender_type'))->toBe('App\\Models\\ExternalUser')
        ->and(DB::table('activity_log')->where('id', $activityId)->value('subject_type'))->toBe('App\\Models\\ExternalContact');
    foreach (['task_collaborators', 'task_thread_mentions', 'task_collaborator_notifications', 'requirement_batches'] as $table) {
        expect(DB::table($table)->value('external_user_id'))->toBe($identityId);
    }
    participantMigration()->up();
    DB::table('external_identities')->where('id', $identityId)->delete();
    expect(DB::table('task_collaborators')->count())->toBe(0)
        ->and(DB::table('task_thread_mentions')->count())->toBe(0)
        ->and(DB::table('task_collaborator_notifications')->count())->toBe(0)
        ->and(DB::table('requirement_batches')->value('external_identity_id'))->toBeNull();
});

test('participant migration provisions separate participants without linking matching emails or registering legacy environments', function () {
    participantMigration()->down();
    $project = Project::factory()->create();
    $first = legacyParticipantIdentity($project->id);
    $second = legacyParticipantIdentity($project->id, ['environment' => 'sandbox']);
    $unscoped = legacyParticipantIdentity($project->id, ['project_id' => null, 'external_id' => 'unscoped']);

    participantMigration()->up();

    $rows = DB::table('external_identities')->whereIn('id', [$first, $second])->get();
    expect($rows->pluck('project_participant_id')->unique()->count())->toBe(2)
        ->and($rows->pluck('project_participant_id')->filter()->count())->toBe(2)
        ->and($rows->pluck('project_environment_id')->filter()->count())->toBe(0)
        ->and(DB::table('external_identities')->where('id', $unscoped)->value('project_participant_id'))->toBeNull();
});

test('registered identity uniqueness follows environment records while legacy identity uniqueness remains', function () {
    $project = Project::factory()->create();
    $environment = ProjectEnvironment::query()->create([
        'project_id' => $project->id, 'environment' => 'production', 'url' => 'https://voidcare.example',
    ]);
    $row = [
        'project_id' => $project->id, 'project_environment_id' => $environment->id, 'external_id' => '42',
        'name' => 'David', 'email' => 'david@example.com', 'role' => 'user', 'environment' => 'production',
        'url' => 'https://old.voidcare.example',
    ];
    DB::table('external_identities')->insert($row);
    expect(fn () => DB::table('external_identities')->insert(array_merge($row, ['url' => 'https://voidcare.example'])))->toThrow(QueryException::class);
    DB::table('external_identities')->insert(array_merge($row, ['project_environment_id' => null, 'environment' => 'legacy']));
    expect(fn () => DB::table('external_identities')->insert(array_merge($row, ['project_environment_id' => null, 'environment' => 'legacy'])))->toThrow(QueryException::class);
});

test('participant migration refuses ambiguous registered identities before changing any rows or schema', function () {
    participantMigration()->down();
    $project = Project::factory()->create();
    $environment = ProjectEnvironment::query()->create([
        'project_id' => $project->id, 'environment' => 'production', 'url' => 'https://voidcare.example',
    ]);
    $first = legacyParticipantIdentity($project->id, ['project_environment_id' => $environment->id]);
    $second = legacyParticipantIdentity($project->id, ['project_environment_id' => $environment->id, 'url' => 'https://old.voidcare.example']);

    expect(fn () => participantMigration()->up())->toThrow(RuntimeException::class, 'multiple external accounts')
        ->and(Schema::hasTable('external_users'))->toBeTrue()
        ->and(Schema::hasTable('external_identities'))->toBeFalse()
        ->and(DB::table('external_contacts')->count())->toBe(0)
        ->and(DB::table('external_users')->whereIn('id', [$first, $second])->whereNull('external_contact_id')->count())->toBe(2);
});

test('participant migration refuses cross-project references before changing data', function (string $column, string $table) {
    participantMigration()->down();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $scope = ['project_id' => $otherProject->id];
    if ($table === 'project_environments') {
        $scope += ['environment' => 'production', 'url' => 'https://other.example'];
    }
    $scopeId = DB::table($table)->insertGetId($scope);
    legacyParticipantIdentity($project->id, [$column => $scopeId]);

    expect(fn () => participantMigration()->up())->toThrow(RuntimeException::class, 'cross-project '.$column)
        ->and(Schema::hasTable('external_users'))->toBeTrue()
        ->and(Schema::hasTable('external_identities'))->toBeFalse()
        ->and(DB::table('external_users')->value($column))->toBe($scopeId);
})->with([
    ['external_contact_id', 'external_contacts'],
    ['project_environment_id', 'project_environments'],
]);

test('participant backfill processes every legacy identity across chunk boundaries', function () {
    participantMigration()->down();
    $project = Project::factory()->create();
    $rows = [];
    for ($id = 1; $id <= 1001; $id++) {
        $rows[] = [
            'project_id' => $project->id,
            'external_id' => 'legacy-'.$id,
            'name' => 'David',
            'email' => 'david@example.com',
            'role' => 'user',
            'environment' => 'legacy',
            'url' => 'https://voidcare.example',
        ];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('external_users')->insert($chunk);
    }

    participantMigration()->up();

    expect(DB::table('external_identities')->whereNull('project_participant_id')->count())->toBe(0)
        ->and(DB::table('project_participants')->count())->toBe(1001);
});
