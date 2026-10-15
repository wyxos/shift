<?php

use App\Enums\ExternalIdentityRole;
use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Services\ExternalActorResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

test('the first consuming app visit provisions an identity and participant without a native login', function () {
    $project = Project::factory()->create();
    $environment = $project->environments()->create(['environment' => 'production', 'url' => 'https://client.example.test']);
    $request = Request::create('/api/tasks', 'GET', ['user' => [
        'id' => '42', 'name' => 'Client account', 'email' => '1@example.test',
        'environment' => 'production', 'url' => $environment->url,
    ]]);
    $nativeUserCount = App\Models\User::query()->count();

    $actor = app(ExternalActorResolver::class)->fromRequest($project, $request, true);

    expect($actor->identity)->toBeInstanceOf(ExternalIdentity::class)
        ->and($actor->participant)->toBeInstanceOf(ProjectParticipant::class)
        ->and($actor->participant->project_id)->toBe($project->id)
        ->and($actor->participant->identities->modelKeys())->toBe([$actor->identity->id])
        ->and($actor->environment->id)->toBe($environment->id)
        ->and(App\Models\User::query()->count())->toBe($nativeUserCount);

    $actor->identity->update(['role' => ExternalIdentityRole::Owner]);
    $request->merge(['user' => array_replace($request->input('user'), ['name' => 'Updated account'])]);
    $updated = app(ExternalActorResolver::class)->fromRequest($project, $request, true);

    expect($updated->identity->id)->toBe($actor->identity->id)
        ->and($updated->participant->id)->toBe($actor->participant->id)
        ->and($updated->identity->name)->toBe('Updated account')
        ->and($updated->identity->role)->toBe(ExternalIdentityRole::Owner);
});

test('reading an unknown identity does not provision accounts or participants', function () {
    $project = Project::factory()->create();
    $request = Request::create('/api/tasks', 'GET', ['user' => [
        'id' => '42', 'environment' => 'production', 'url' => 'https://client.example.test',
    ]]);

    expect(app(ExternalActorResolver::class)->fromRequest($project, $request))->toBeNull()
        ->and(ExternalIdentity::query()->count())->toBe(0)
        ->and(ProjectParticipant::query()->count())->toBe(0);
});

test('identity resolution cannot change the registered application URL', function () {
    $project = Project::factory()->create();
    $environment = $project->environments()->create(['environment' => 'production', 'url' => 'https://client.example.test']);
    $request = Request::create('/api/tasks', 'GET', ['user' => [
        'id' => '42', 'environment' => 'production', 'url' => 'https://other.example.test',
    ]]);

    expect(app(ExternalActorResolver::class)->fromRequest($project, $request))->toBeNull();
    expect(fn () => app(ExternalActorResolver::class)->fromRequest($project, $request, true))
        ->toThrow(ValidationException::class);
    expect($environment->fresh()->url)->toBe('https://client.example.test')
        ->and(ExternalIdentity::query()->count())->toBe(0);
});

test('identities cannot be assigned a participant or registered environment from another project', function (string $column) {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $record = $column === 'project_participant_id'
        ? ProjectParticipant::query()->create(['project_id' => $otherProject->id])
        : $otherProject->environments()->create(['environment' => 'production', 'url' => 'https://other.example.test']);

    expect(fn () => ExternalIdentity::factory()->create(['project_id' => $project->id, $column => $record->id]))
        ->toThrow(ValidationException::class);
    expect(ExternalIdentity::query()->count())->toBe(0);
})->with(['project_participant_id', 'project_environment_id']);
