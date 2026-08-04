<?php

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\OrganisationTransfer\OrganisationTransferSelection;
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
    ]);
    $personalTask = Task::factory()->create([
        'project_id' => $personalProject->id,
        'submitter_type' => User::class,
        'submitter_id' => $personalUser->id,
    ]);
    $lawThread = TaskThread::query()->create([
        'task_id' => $lawTask->id,
        'type' => 'internal',
        'content' => 'Law thread',
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
        ->and($manifest['tables']['users']['rows'])->toBe(2)
        ->and($manifest['tables']['projects']['rows'])->toBe(1)
        ->and($manifest['tables']['tasks']['rows'])->toBe(1)
        ->and($manifest['tables']['activity_log']['rows'])->toBe(1)
        ->and($manifest['tables']['notifications']['rows'])->toBe(2)
        ->and($manifest['attachments']['count'])->toBe(2)
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
        ->and(Task::query()->count())->toBe(1)
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

test('export aborts cleanly when a selected attachment file is missing', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    $client = Client::factory()->create(['organisation_id' => $organisation->id]);
    $project = Project::factory()->create(['client_id' => $client->id, 'author_id' => $user->id]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    Attachment::query()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $task->id,
        'original_filename' => 'missing.txt',
        'path' => "attachments/{$task->id}/missing.txt",
    ]);
    $this->transferDirectory = storage_path('framework/testing/organisation-transfer-'.Str::uuid());

    $this->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id,
        '--output' => $this->transferDirectory,
    ])->expectsOutputToContain('is missing from storage')->assertFailed();

    expect(File::exists($this->transferDirectory))->toBeFalse();
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
