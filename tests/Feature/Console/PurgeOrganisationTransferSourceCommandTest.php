<?php

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\OrganisationTransfer\OrganisationTransferPurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

test('purge source is a dry run unless its reviewed fingerprint is confirmed', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    $client = Client::factory()->create(['organisation_id' => $organisation->id]);
    Project::factory()->create(['client_id' => $client->id, 'author_id' => $user->id]);

    $this->artisan('shift:organisation-transfer:purge-source', [
        'organisation' => (string) $organisation->id,
        '--delete-users' => true,
    ])->expectsOutputToContain('Dry run only')->assertSuccessful();

    expect(Organisation::query()->whereKey($organisation->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();

    $this->artisan('shift:organisation-transfer:purge-source', [
        'organisation' => (string) $organisation->id,
        '--delete-users' => true,
        '--confirm' => 'PURGE',
        '--expected-fingerprint' => str_repeat('0', 64),
    ])->expectsOutputToContain('source scope changed after review')->assertFailed();

    expect(Organisation::query()->whereKey($organisation->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('purge source deletes only the transferred tenant files and exclusive users', function () {
    $exclusiveUser = User::factory()->create();
    $sharedUser = User::factory()->create();
    $personalUser = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $exclusiveUser->id]);
    DB::table('organisation_users')->insert([
        'organisation_id' => $organisation->id,
        'user_id' => $sharedUser->id,
        'user_email' => $sharedUser->email,
        'user_name' => $sharedUser->name,
        'role' => 'developer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $client = Client::factory()->create(['organisation_id' => $organisation->id]);
    $tenantProject = Project::factory()->create([
        'client_id' => $client->id,
        'author_id' => $exclusiveUser->id,
    ]);
    $personalProject = Project::factory()->create(['author_id' => $sharedUser->id]);
    $tenantTask = Task::factory()->create(['project_id' => $tenantProject->id]);
    $personalTask = Task::factory()->create([
        'project_id' => $personalProject->id,
        'submitter_type' => User::class,
        'submitter_id' => $sharedUser->id,
    ]);
    $tenantThread = TaskThread::query()->create([
        'task_id' => $tenantTask->id,
        'type' => 'internal',
        'content' => 'Transferred thread',
        'sender_name' => $exclusiveUser->name,
        'sender_type' => User::class,
        'sender_id' => $exclusiveUser->id,
    ]);
    $tenantAttachment = Attachment::query()->create([
        'attachable_type' => TaskThread::class,
        'attachable_id' => $tenantThread->id,
        'original_filename' => 'tenant.txt',
        'path' => "attachments/task_threads/{$tenantThread->id}/tenant.txt",
    ]);
    $missingAttachment = Attachment::query()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $tenantTask->id,
        'original_filename' => 'missing.txt',
        'path' => "attachments/{$tenantTask->id}/missing.txt",
    ]);
    $personalAttachment = Attachment::query()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $personalTask->id,
        'original_filename' => 'personal.txt',
        'path' => "attachments/{$personalTask->id}/personal.txt",
    ]);
    Storage::put($tenantAttachment->path, 'tenant');
    Storage::put($personalAttachment->path, 'personal');
    DB::table('sessions')->insert([
        'id' => 'exclusive-session',
        'user_id' => $exclusiveUser->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('activity_log')->insert([
        'log_name' => 'task',
        'description' => 'updated',
        'subject_type' => Task::class,
        'subject_id' => 999999,
        'causer_type' => User::class,
        'causer_id' => $exclusiveUser->id,
        'properties' => '{}',
        'event' => 'updated',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $oauthClientId = (string) Str::uuid();
    DB::table('oauth_clients')->insert([
        'id' => $oauthClientId,
        'owner_type' => User::class,
        'owner_id' => $exclusiveUser->id,
        'name' => 'Exclusive client',
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => '[]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('oauth_access_tokens')->insert([
        'id' => 'exclusive-access-token',
        'user_id' => null,
        'client_id' => $oauthClientId,
        'name' => null,
        'scopes' => '[]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => 'exclusive-refresh-token',
        'access_token_id' => 'exclusive-access-token',
        'revoked' => false,
        'expires_at' => now()->addHour(),
    ]);

    $purger = app(OrganisationTransferPurger::class);
    $report = $purger->inspect($organisation, true);

    expect($report['attachments'])->toBe([
        'count' => 2,
        'available_count' => 1,
        'missing_count' => 1,
    ])->and($report['users']['delete_ids'])->toBe([$exclusiveUser->id])
        ->and($report['users']['preserved'])->toHaveKey($sharedUser->id);

    $this->artisan('shift:organisation-transfer:purge-source', [
        'organisation' => (string) $organisation->id,
        '--delete-users' => true,
        '--confirm' => 'PURGE',
        '--expected-fingerprint' => $report['fingerprint'],
    ])->expectsOutputToContain('Purged organisation')->assertSuccessful();

    expect(Organisation::query()->whereKey($organisation->id)->exists())->toBeFalse()
        ->and(Project::query()->whereKey($tenantProject->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($tenantTask->id)->exists())->toBeFalse()
        ->and(Attachment::query()->whereKey($missingAttachment->id)->exists())->toBeFalse()
        ->and(Storage::exists($tenantAttachment->path))->toBeFalse()
        ->and(User::query()->whereKey($exclusiveUser->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $exclusiveUser->id)->exists())->toBeFalse()
        ->and(DB::table('activity_log')->where('causer_id', $exclusiveUser->id)->exists())->toBeFalse()
        ->and(DB::table('oauth_clients')->where('id', $oauthClientId)->exists())->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('id', 'exclusive-access-token')->exists())->toBeFalse()
        ->and(DB::table('oauth_refresh_tokens')->where('id', 'exclusive-refresh-token')->exists())->toBeFalse()
        ->and(User::query()->whereKey($sharedUser->id)->exists())->toBeTrue()
        ->and(Project::query()->whereKey($personalProject->id)->exists())->toBeTrue()
        ->and(Task::query()->whereKey($personalTask->id)->exists())->toBeTrue()
        ->and(Attachment::query()->whereKey($personalAttachment->id)->exists())->toBeTrue()
        ->and(Storage::get($personalAttachment->path))->toBe('personal')
        ->and(User::query()->whereKey($personalUser->id)->exists())->toBeTrue();
});
