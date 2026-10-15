<?php

use App\Models\Attachment;
use App\Models\Client;
use App\Models\ExternalIdentity;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\OrganisationTransfer\OrganisationTransferPurger;
use App\Services\OrganisationTransfer\OrganisationTransferSelection;
use App\Services\ProjectParticipantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

afterEach(function () {
    if (isset($this->transferDirectory)) {
        File::deleteDirectory($this->transferDirectory);
    }
});

test('an organisation transfer round trips only public tenant data and attachment files', function () {
    $longTaskDescription = str_repeat('Task detail ', 8000);
    $longThreadContent = str_repeat('Thread detail ', 45000);
    $lawUser = User::factory()->create([
        'name' => 'Law Maintainer',
        'email' => 'law@example.com',
        'password' => bcrypt('known-password'),
    ]);
    $lawMember = User::factory()->create(['email' => 'member@example.com']);
    $personalUser = User::factory()->create(['email' => 'personal@example.com']);

    $organisation = Organisation::factory()->create([
        'name' => 'LawCreative',
        'author_id' => $lawUser->id,
    ]);
    DB::table('organisation_users')->insert([
        'organisation_id' => $organisation->id,
        'user_id' => $lawMember->id,
        'user_email' => $lawMember->email,
        'user_name' => $lawMember->name,
        'role' => 'developer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $client = Client::factory()->create(['organisation_id' => $organisation->id]);
    $lawProject = Project::factory()->create([
        'name' => 'Voidcare',
        'client_id' => $client->id,
        'author_id' => $lawUser->id,
        'token' => 'preserved-project-token',
    ]);
    $personalProject = Project::factory()->create([
        'name' => 'Personal',
        'author_id' => $personalUser->id,
    ]);
    DB::table('project_users')->insert([
        'project_id' => $lawProject->id,
        'user_id' => $lawMember->id,
        'user_email' => $lawMember->email,
        'user_name' => $lawMember->name,
        'registration_status' => 'registered',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lawTask = Task::factory()->create([
        'project_id' => $lawProject->id,
        'submitter_type' => User::class,
        'submitter_id' => $lawUser->id,
        'description' => $longTaskDescription,
    ]);
    $personalTask = Task::factory()->create([
        'project_id' => $personalProject->id,
        'submitter_type' => User::class,
        'submitter_id' => $personalUser->id,
    ]);
    $lawThread = TaskThread::query()->create([
        'task_id' => $lawTask->id,
        'type' => 'internal',
        'content' => $longThreadContent,
        'sender_name' => $lawMember->name,
        'sender_type' => User::class,
        'sender_id' => $lawMember->id,
    ]);

    $lawAttachment = Attachment::query()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $lawTask->id,
        'original_filename' => 'law.txt',
        'path' => "attachments/{$lawTask->id}/law.txt",
    ]);
    $threadAttachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class,
        'attachable_id' => $lawThread->id,
        'original_filename' => 'thread.txt',
        'path' => "attachments/task_threads/{$lawThread->id}/thread.txt",
    ]);
    $personalAttachment = Attachment::query()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $personalTask->id,
        'original_filename' => 'personal.txt',
        'path' => "attachments/{$personalTask->id}/personal.txt",
    ]);
    Storage::put($lawAttachment->path, 'law attachment');
    Storage::put($threadAttachment->path, 'thread attachment');
    Storage::put($personalAttachment->path, 'personal attachment');

    DB::table('activity_log')->delete();
    insertActivity($lawTask, $lawUser);
    insertActivity($personalTask, $lawUser);
    insertNotification($lawUser, [
        'task_id' => $lawTask->id,
        'project_id' => $lawProject->id,
        'url' => 'https://shift.wyxos.com/tasks?task='.$lawTask->id,
    ]);
    insertNotification($lawUser, ['task_id' => $personalTask->id, 'project_id' => $personalProject->id]);
    insertNotification($lawUser, ['project_id' => $lawProject->id, 'organisation_id' => $organisation->id]);

    config()->set('app.url', 'https://shift.wyxos.com');
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());
    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => 'LawCreative',
        '--output' => $this->transferDirectory,
    ])->assertSuccessful();

    $manifest = json_decode(File::get($this->transferDirectory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['format'])->toBe('shift-public-organisation-transfer')
        ->and($manifest['version'])->toBe(4)
        ->and($manifest['tables']['users']['rows'])->toBe(2)
        ->and($manifest['tables']['projects']['rows'])->toBe(1)
        ->and($manifest['tables']['tasks']['rows'])->toBe(1)
        ->and($manifest['tables']['activity_log']['rows'])->toBe(1)
        ->and($manifest['tables']['notifications']['rows'])->toBe(2)
        ->and($manifest['attachments']['count'])->toBe(2)
        ->and($manifest['attachments']['available_count'])->toBe(2)
        ->and($manifest['attachments']['missing_count'])->toBe(0)
        ->and(collect($manifest['attachments']['files'])->pluck('availability')->unique()->all())->toBe(['available'])
        ->and($manifest['hosted_only_tables'] ?? null)->toBeNull();

    Storage::deleteDirectory('attachments');
    clearTransferTables();
    config()->set('app.url', 'https://shift.lawcreative.dev');

    $this->artisan('shift:organisation-transfer:import', [
        'directory' => $this->transferDirectory,
        '--confirm' => 'IMPORT',
    ])->assertSuccessful();

    expect(User::query()->pluck('email')->sort()->values()->all())->toBe(['law@example.com', 'member@example.com'])
        ->and(Organisation::query()->sole()->name)->toBe('LawCreative')
        ->and(Project::query()->sole()->token)->toBe('preserved-project-token')
        ->and(Task::query()->sole()->description)->toBe($longTaskDescription)
        ->and(TaskThread::query()->sole()->content)->toBe($longThreadContent)
        ->and(DB::table('activity_log')->count())->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(2)
        ->and(DB::table('notifications')->where('data', 'like', '%shift.lawcreative.dev%')->count())->toBe(1)
        ->and(DB::table('notifications')->where('data', 'like', '%shift.wyxos.com%')->count())->toBe(0)
        ->and(Storage::get($lawAttachment->path))->toBe('law attachment')
        ->and(Storage::get($threadAttachment->path))->toBe('thread attachment')
        ->and(Storage::exists($personalAttachment->path))->toBeFalse()
        ->and(File::isFile($this->transferDirectory.'/import-receipt.json'))->toBeTrue();

    $this->artisan('shift:organisation-transfer:verify', [
        'directory' => $this->transferDirectory,
    ])->assertSuccessful();
});

test('import requires an explicit confirmation and an empty target', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());

    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id,
        '--output' => $this->transferDirectory,
    ])->assertSuccessful();

    $this->artisan('shift:organisation-transfer:import', [
        'directory' => $this->transferDirectory,
    ])->expectsOutputToContain('Import refused')->assertFailed();

    $this->artisan('shift:organisation-transfer:import', [
        'directory' => $this->transferDirectory,
        '--confirm' => 'IMPORT',
    ])->expectsOutputToContain('The target is not empty')->assertFailed();
});

test('a transfer preserves an attachment record whose source file is already missing', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    $client = Client::factory()->create(['organisation_id' => $organisation->id]);
    $project = Project::factory()->create(['client_id' => $client->id, 'author_id' => $user->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $attachment = Attachment::query()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $task->id,
        'original_filename' => 'missing.txt',
        'path' => "attachments/{$task->id}/missing.txt",
    ]);
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());

    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id,
        '--output' => $this->transferDirectory,
    ])->expectsOutputToContain('1 missing at source')->assertSuccessful();

    $manifest = json_decode(File::get($this->transferDirectory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['attachments']['count'])->toBe(1)
        ->and($manifest['attachments']['available_count'])->toBe(0)
        ->and($manifest['attachments']['missing_count'])->toBe(1)
        ->and($manifest['attachments']['bytes'])->toBe(0)
        ->and($manifest['attachments']['files'])->toBe([[
            'id' => $attachment->id,
            'path' => $attachment->path,
            'availability' => 'missing_at_source',
        ]]);

    clearTransferTables();

    $this->artisan('shift:organisation-transfer:import', [
        'directory' => $this->transferDirectory,
        '--confirm' => 'IMPORT',
    ])->expectsOutputToContain('1 missing at source')->assertSuccessful();

    expect(Attachment::query()->sole()->path)->toBe($attachment->path)
        ->and(Storage::exists($attachment->path))->toBeFalse();

    $this->artisan('shift:organisation-transfer:verify', [
        'directory' => $this->transferDirectory,
    ])->expectsOutputToContain('1 missing at source')->assertSuccessful();
});

test('import rejects a transfer whose table data was changed after export', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());

    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id,
        '--output' => $this->transferDirectory,
    ])->assertSuccessful();

    File::append($this->transferDirectory.'/data/users.jsonl', "{}\n");
    clearTransferTables();

    $this->artisan('shift:organisation-transfer:import', [
        'directory' => $this->transferDirectory,
        '--confirm' => 'IMPORT',
    ])->expectsOutputToContain('failed checksum verification')->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Organisation::query()->count())->toBe(0);
});

test('participant identities and references survive current and legacy organisation archives', function (int $version) {
    $owner = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create(['author_id' => $owner->id, 'organisation_id' => $organisation->id, 'client_id' => null]);
    $environment = $project->environments()->create(['environment' => 'production', 'url' => 'https://voidcare.test']);
    $participant = ProjectParticipant::query()->create(['project_id' => $project->id]);
    $identity = ExternalIdentity::factory()->create([
        'project_id' => $project->id, 'project_environment_id' => $environment->id,
        'project_participant_id' => $participant->id, 'external_id' => '42',
        'environment' => 'production', 'url' => $environment->url,
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $task->submitter()->associate($identity)->save();
    $thread = TaskThread::query()->create([
        'task_id' => $task->id, 'type' => 'external', 'content' => 'David reply',
        'sender_type' => ExternalIdentity::class, 'sender_id' => $identity->id, 'sender_name' => $identity->name,
    ]);
    $task->externalCollaborators()->attach($identity->id);
    $batchId = DB::table('requirement_batches')->insertGetId([
        'project_id' => $project->id, 'external_identity_id' => $identity->id,
        'title' => 'David requirements', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('task_thread_mentions')->insert([
        'task_thread_id' => $thread->id, 'kind' => 'external', 'external_identity_id' => $identity->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('task_collaborator_notifications')->insert([
        'task_id' => $task->id, 'event' => 'task.created', 'kind' => 'external', 'external_identity_id' => $identity->id,
        'url' => $identity->url, 'scheduled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $attachment = Attachment::query()->create([
        'attachable_type' => Task::class, 'attachable_id' => $task->id,
        'original_filename' => 'report.txt', 'path' => 'attachments/'.$task->id.'/report.txt',
    ]);
    Storage::put($attachment->path, 'David attachment');
    DB::table('activity_log')->delete();
    insertActivity($task, $owner);
    DB::table('activity_log')->update(['causer_type' => ExternalIdentity::class, 'causer_id' => $identity->id]);
    insertNotification($owner, ['task_id' => $task->id, 'url' => 'https://shift.source.test/tasks?task='.$task->id]);
    $unreadInboxId = insertExternalInboxNotification($identity, $task, $thread);
    $readAt = now()->subMinute()->format('Y-m-d H:i:s');
    $readInboxId = insertExternalInboxNotification($identity, $task, null, $readAt);

    config()->set('app.url', 'https://shift.source.test');
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());
    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id, '--output' => $this->transferDirectory,
    ])->assertSuccessful();
    if ($version === 2) {
        convertParticipantArchiveToLegacyVersion($this->transferDirectory);
    } elseif ($version === 3) {
        convertParticipantArchiveToVersion3($this->transferDirectory);
    }
    $manifestContents = File::get($this->transferDirectory.'/manifest.json');
    $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
    $archiveHashes = collect(array_keys($manifest['tables']))->mapWithKeys(fn (string $table): array => [
        $table => hash_file('sha256', $this->transferDirectory.'/data/'.$table.'.jsonl'),
    ])->all();
    Storage::deleteDirectory('attachments');
    clearTransferTables();
    config()->set('app.url', 'https://shift.target.test');

    $this->artisan('shift:organisation-transfer:import', ['directory' => $this->transferDirectory, '--confirm' => 'IMPORT'])->assertSuccessful();
    $this->artisan('shift:organisation-transfer:verify', ['directory' => $this->transferDirectory])->assertSuccessful();

    expect(ExternalIdentity::query()->sole()->project_participant_id)->toBe($participant->id)
        ->and(Task::query()->sole()->submitter)->toBeInstanceOf(ExternalIdentity::class)
        ->and(TaskThread::query()->sole()->sender_type)->toBe(ExternalIdentity::class)
        ->and(TaskThread::query()->sole()->sender_id)->toBe($identity->id)
        ->and(DB::table('activity_log')->sole()->causer_type)->toBe(ExternalIdentity::class)
        ->and(DB::table('notifications')->sole()->data)->toContain('shift.target.test')
        ->and(Storage::get($attachment->path))->toBe('David attachment');
    foreach (['requirement_batches', 'task_collaborators', 'task_thread_mentions', 'task_collaborator_notifications'] as $table) {
        expect((int) DB::table($table)->sole()->external_identity_id)->toBe($identity->id);
    }
    $this->assertDatabaseHas('requirement_batches', ['id' => $batchId]);
    $receipt = json_decode(File::get($this->transferDirectory.'/import-receipt.json'), true, flags: JSON_THROW_ON_ERROR);
    $archiveIdentityTable = $version === 2 ? 'external_users' : 'external_identities';
    expect($receipt['version'])->toBe($version)
        ->and($receipt['manifest_sha256'])->toBe(hash('sha256', $manifestContents))
        ->and($receipt['verification']['tables'][$archiveIdentityTable])->toBe(1)
        ->and(File::get($this->transferDirectory.'/manifest.json'))->toBe($manifestContents);
    expect(DB::table('external_inbox_notifications')->count())->toBe($version === 4 ? 2 : 0)
        ->and(array_key_exists('external_inbox_notifications', $receipt['verification']['tables']))->toBe($version === 4);
    if ($version === 4) {
        $this->assertDatabaseHas('external_inbox_notifications', [
            'id' => $unreadInboxId, 'external_identity_id' => $identity->id, 'task_id' => $task->id,
            'task_thread_id' => $thread->id, 'read_at' => null,
        ]);
        $this->assertDatabaseHas('external_inbox_notifications', ['id' => $readInboxId, 'read_at' => $readAt]);
    }
    foreach ($archiveHashes as $table => $hash) {
        expect(hash_file('sha256', $this->transferDirectory.'/data/'.$table.'.jsonl'))->toBe($hash);
    }

    $purger = app(OrganisationTransferPurger::class);
    $importedOrganisation = Organisation::query()->sole();
    $report = $purger->inspect($importedOrganisation, true);
    expect($report['tables']['external_identities'])->toBe(1)->and($report['tables']['project_participants'])->toBe(1);
    $purger->purge($importedOrganisation, true, $report['fingerprint']);
    expect(ExternalIdentity::query()->count())->toBe(0)
        ->and(DB::table('external_inbox_notifications')->count())->toBe(0)
        ->and(ProjectParticipant::query()->count())->toBe(0)
        ->and(Organisation::query()->count())->toBe(0)
        ->and(Storage::exists($attachment->path))->toBeFalse();
})->with(['pre-refactor version 2' => 2, 'pre-inbox version 3' => 3, 'current version 4' => 4]);

test('participant audit transfers include their actors and scoped source purges retain unrelated audits', function (int $version) {
    $owner = User::factory()->create();
    $actor = User::factory()->create(['email' => 'historical-manager@example.com']);
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create(['author_id' => $owner->id, 'organisation_id' => $organisation->id, 'client_id' => null]);
    $participants = app(ProjectParticipantService::class);
    $source = ExternalIdentity::factory()->create([
        'project_id' => $project->id, 'project_participant_id' => $participants->create($project)->id,
    ]);
    $linked = ExternalIdentity::factory()->create([
        'project_id' => $project->id, 'project_participant_id' => $participants->create($project)->id,
    ]);
    $otherOwner = User::factory()->create();
    $otherOrganisation = Organisation::factory()->create(['author_id' => $otherOwner->id]);
    $otherProject = Project::factory()->create(['author_id' => $otherOwner->id, 'organisation_id' => $otherOrganisation->id, 'client_id' => null]);
    $otherParticipant = $participants->create($otherProject);
    DB::table('activity_log')->delete();
    $participants->link($source, $linked, $actor);
    $participants->unlink($source->fresh(), $linked->fresh(), $actor);
    $scopedAuditIds = DB::table('activity_log')->orderBy('id')->pluck('id')->all();
    $unrelatedAuditId = activity('project_participant')->performedOn($otherParticipant)->causedBy($actor)->event('accounts_linked')->log('unrelated')->id;

    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());
    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id, '--output' => $this->transferDirectory,
    ])->assertSuccessful();
    if ($version === 2) {
        convertParticipantArchiveToLegacyVersion($this->transferDirectory);
    } elseif ($version === 3) {
        convertParticipantArchiveToVersion3($this->transferDirectory);
    }
    $manifest = json_decode(File::get($this->transferDirectory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['tables']['activity_log']['rows'])->toBe(2)
        ->and($manifest['user_ids'])->toContain($owner->id, $actor->id)->not->toContain($otherOwner->id);
    $exportedAudits = collect(array_filter(explode("\n", File::get($this->transferDirectory.'/data/activity_log.jsonl'))))
        ->map(fn (string $row): array => json_decode($row, true, flags: JSON_THROW_ON_ERROR));
    expect($exportedAudits->pluck('id')->all())->toBe($scopedAuditIds)
        ->and($exportedAudits->pluck('subject_type')->unique()->all())->toBe([$version === 2 ? 'App\\Models\\ExternalContact' : ProjectParticipant::class])
        ->and($exportedAudits->pluck('causer_id')->unique()->all())->toBe([$actor->id]);

    $purger = app(OrganisationTransferPurger::class);
    $report = $purger->inspect($organisation, true);
    expect($report['row_ids']['activity_log'])->toBe($scopedAuditIds)
        ->and($report['users']['preserved'][$actor->id])->toContain('activity_log');
    $purger->purge($organisation, true, $report['fingerprint']);
    expect(DB::table('activity_log')->pluck('id')->all())->toBe([$unrelatedAuditId])
        ->and(User::query()->whereKey($actor->id)->exists())->toBeTrue()
        ->and(ProjectParticipant::query()->whereKey($otherParticipant->id)->exists())->toBeTrue();

    clearTransferTables();
    $this->artisan('shift:organisation-transfer:import', ['directory' => $this->transferDirectory, '--confirm' => 'IMPORT'])->assertSuccessful();
    $this->artisan('shift:organisation-transfer:verify', ['directory' => $this->transferDirectory])->assertSuccessful();
    expect(DB::table('activity_log')->orderBy('id')->pluck('id')->all())->toBe($scopedAuditIds)
        ->and(DB::table('activity_log')->pluck('subject_type')->unique()->all())->toBe([ProjectParticipant::class])
        ->and(User::query()->whereKey($actor->id)->sole()->email)->toBe('historical-manager@example.com');
})->with(['legacy audit archive' => 2, 'current audit archive' => 3]);

test('legacy archives retain checksum validation before compatibility transformations', function () {
    $owner = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());
    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id, '--output' => $this->transferDirectory,
    ])->assertSuccessful();
    convertParticipantArchiveToLegacyVersion($this->transferDirectory);
    File::append($this->transferDirectory.'/data/external_users.jsonl', "{}\n");
    clearTransferTables();

    $this->artisan('shift:organisation-transfer:import', [
        'directory' => $this->transferDirectory, '--confirm' => 'IMPORT',
    ])->expectsOutputToContain('failed checksum verification')->assertFailed();
    expect(Organisation::query()->count())->toBe(0)->and(ExternalIdentity::query()->count())->toBe(0);
});

test('inbox transfers and source purges retain unrelated organisations notifications', function () {
    $organisations = [];
    $notificationIds = [];
    foreach (range(1, 2) as $index) {
        $owner = User::factory()->create();
        $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
        $project = Project::factory()->create([
            'author_id' => $owner->id, 'organisation_id' => $organisation->id, 'client_id' => null,
        ]);
        $participant = ProjectParticipant::query()->create(['project_id' => $project->id]);
        $identity = ExternalIdentity::factory()->create([
            'project_id' => $project->id, 'project_participant_id' => $participant->id,
        ]);
        $task = Task::factory()->create(['project_id' => $project->id]);
        $organisations[] = $organisation;
        $notificationIds[] = insertExternalInboxNotification($identity, $task);
    }

    $selection = new OrganisationTransferSelection($organisations[0]);
    expect($selection->rows('external_inbox_notifications')->pluck('id')->all())->toBe([$notificationIds[0]]);

    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());
    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisations[0]->id, '--output' => $this->transferDirectory,
    ])->assertSuccessful();
    $manifest = json_decode(File::get($this->transferDirectory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['tables']['external_inbox_notifications']['rows'])->toBe(1);

    $purger = app(OrganisationTransferPurger::class);
    $report = $purger->inspect($organisations[0], true);
    expect($report['row_ids']['external_inbox_notifications'])->toBe([$notificationIds[0]]);
    $purger->purge($organisations[0], true, $report['fingerprint']);

    expect(DB::table('external_inbox_notifications')->pluck('id')->all())->toBe([$notificationIds[1]])
        ->and(Organisation::query()->whereKey($organisations[1]->id)->exists())->toBeTrue();
});

function convertParticipantArchiveToLegacyVersion(string $directory): void
{
    convertParticipantArchiveToVersion3($directory);
    $manifest = json_decode(File::get($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $tables = [];
    foreach ($manifest['tables'] as $table => $metadata) {
        $archiveTable = match ($table) {
            'project_participants' => 'external_contacts',
            'external_identities' => 'external_users',
            default => $table,
        };
        $path = $directory.'/data/'.$table.'.jsonl';
        $rows = array_filter(explode("\n", File::get($path)), fn (string $line): bool => trim($line) !== '');
        $contents = '';
        foreach ($rows as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            foreach (['project_participant_id' => 'external_contact_id', 'external_identity_id' => 'external_user_id'] as $new => $old) {
                if (array_key_exists($new, $row)) {
                    $row[$old] = $row[$new];
                    unset($row[$new]);
                }
            }
            foreach ($row as $column => $value) {
                if (str_ends_with($column, '_type')) {
                    $row[$column] = match ($value) {
                        ExternalIdentity::class => 'App\\Models\\ExternalUser',
                        ProjectParticipant::class => 'App\\Models\\ExternalContact',
                        default => $value,
                    };
                }
            }
            $contents .= json_encode($row, JSON_THROW_ON_ERROR)."\n";
        }
        File::delete($path);
        $archivePath = $directory.'/data/'.$archiveTable.'.jsonl';
        File::put($archivePath, $contents);
        $tables[$archiveTable] = ['rows' => $metadata['rows'], 'sha256' => hash_file('sha256', $archivePath)];
    }
    $manifest['version'] = 2;
    $manifest['tables'] = $tables;
    File::put($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
}

function convertParticipantArchiveToVersion3(string $directory): void
{
    $manifest = json_decode(File::get($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    unset($manifest['tables']['external_inbox_notifications']);
    File::delete($directory.'/data/external_inbox_notifications.jsonl');
    $manifest['version'] = 3;
    File::put($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
}

function insertExternalInboxNotification(ExternalIdentity $identity, Task $task, ?TaskThread $thread = null, ?string $readAt = null): int
{
    return DB::table('external_inbox_notifications')->insertGetId([
        'project_id' => $task->project_id,
        'external_identity_id' => $identity->id,
        'task_id' => $task->id,
        'task_thread_id' => $thread?->id,
        'event_key' => 'test:'.Str::uuid(),
        'type' => $thread ? 'thread.reply' : 'collaborator.added',
        'title' => 'Voidcare follow-up',
        'body' => 'A project reply is available.',
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertActivity(Task $task, User $causer): void
{
    DB::table('activity_log')->insert([
        'log_name' => 'task',
        'description' => 'updated',
        'subject_type' => Task::class,
        'subject_id' => $task->id,
        'causer_type' => User::class,
        'causer_id' => $causer->id,
        'properties' => '{}',
        'event' => 'updated',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param array<string, int|string> $data */
function insertNotification(User $user, array $data): void
{
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TaskCreationNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode($data, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function clearTransferTables(): void
{
    Schema::disableForeignKeyConstraints();

    try {
        foreach (array_reverse(OrganisationTransferSelection::TABLES) as $table) {
            DB::table($table)->delete();
        }
    } finally {
        Schema::enableForeignKeyConstraints();
    }
}
