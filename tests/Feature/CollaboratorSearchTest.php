<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\TaskCollaboratorService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('collaborator_search.enabled', false);
});

function collaboratorSearchMember(Project $project, string $name, string $status = 'registered'): User
{
    $user = User::factory()->create(['name' => $name]);
    ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'user_name' => $user->name,
        'user_email' => $user->email,
        'registration_status' => $status,
    ]);

    return $user;
}

test('autocomplete requires typed search and only returns registered project candidates with a ten result limit', function () {
    Http::fake();
    $owner = User::factory()->create(['name' => 'Morgan Owner']);
    $project = Project::factory()->withAuthor($owner->id)->create();
    foreach (range(1, 15) as $number) {
        collaboratorSearchMember($project, 'Morgan '.$number);
    }
    collaboratorSearchMember($project, 'Morgan Pending', 'pending');
    User::factory()->create(['name' => 'Morgan Outside']);

    $this->actingAs($owner)->getJson(route('tasks.collaborators', $project))
        ->assertOk()->assertJsonCount(0, 'internal')->assertJsonCount(0, 'external');
    $response = $this->actingAs($owner)->getJson(route('tasks.collaborators', ['project' => $project, 'search' => 'morgan']));
    $response->assertOk()->assertJsonCount(10, 'internal')->assertJsonPath('internal_label', 'SHIFT team');
    expect(collect($response->json('internal'))->pluck('name')->all())->not->toContain('Morgan Pending', 'Morgan Outside');
    Http::assertNothingSent();
});

test('autocomplete keeps invisible projects hidden and validates search input', function () {
    Http::fake();
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();

    $this->actingAs($outsider)->getJson(route('tasks.collaborators', ['project' => $project, 'search' => 'a']))->assertNotFound();
    $this->actingAs($owner)->getJson(route('tasks.collaborators', ['project' => $project, 'search' => str_repeat('a', 256)]))
        ->assertUnprocessable()->assertJsonValidationErrors('search');
    Http::assertNothingSent();
});

test('autocomplete asks the trusted connected app for only ten users matching the typed search', function () {
    Http::fake([
        'https://consumer.test/shift/api/collaborators/external*' => Http::response([
            'url' => 'https://consumer.test', 'environment' => 'staging',
            'users' => collect(range(1, 12))->map(fn (int $id): array => [
                'id' => 'client-'.$id, 'name' => 'Client '.$id, 'email' => 'client'.$id.'@example.com',
            ])->all(),
        ]),
    ]);
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create(['token' => 'project-token']);
    $project->environments()->create(['environment' => 'staging', 'url' => 'https://consumer.test', 'callback_trusted_at' => now()]);

    $this->actingAs($owner)->getJson(route('tasks.collaborators', ['project' => $project, 'search' => 'Client', 'environment' => 'staging']))
        ->assertOk()->assertJsonCount(10, 'external');
    Http::assertSent(fn (Request $request): bool => $request['search'] === 'Client' && $request['per_page'] === 10 && $request['paginate'] === 1);
});

test('meilisearch uses a scoped index and never trusts a stale or foreign user hit', function () {
    config()->set('collaborator_search.enabled', true);
    config()->set('collaborator_search.host', 'http://meili.test');
    $owner = User::factory()->create(['name' => 'Avery Owner']);
    $project = Project::factory()->withAuthor($owner->id)->create();
    $member = collaboratorSearchMember($project, 'Avery Member');
    $outsider = User::factory()->create(['name' => 'Avery Outside']);
    $postedDocuments = [];
    Http::fake(function (Request $request) use ($member, $outsider, &$postedDocuments) {
        if (str_ends_with($request->url(), '/search')) {
            return Http::response(['hits' => [['id' => $outsider->id], ['id' => $member->id, 'name' => 'Stale Name']]]);
        }
        if (str_contains($request->url(), '/tasks/')) {
            return Http::response(['status' => 'succeeded']);
        }
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/documents')) {
            $postedDocuments = $request->data();
        }

        return Http::response(['taskUid' => 1], 202);
    });

    $matches = app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'Avery');
    expect($matches->pluck('id')->all())->toBe([$member->id]);
    expect($matches->first()->name)->toBe('Avery Member');
    expect(collect($postedDocuments)->pluck('id')->all())->toBe([$owner->id, $member->id]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '_project_'.$project->id.'/search')
        && $request['limit'] === 10 && str_starts_with($request['filter'], 'generation = '));

    $member->update(['name' => 'Avery Renamed']);
    app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'Avery');
    expect(collect($postedDocuments)->firstWhere('id', $member->id)['name'])->toBe('Avery Renamed');
    $project->projectUser()->where('user_id', $member->id)->delete();
    $matches = app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'Avery');
    expect($matches)->toHaveCount(0);
    expect(collect($postedDocuments)->pluck('id')->all())->toBe([$owner->id]);
});

test('autocomplete falls back to authorized database matches when meilisearch is unavailable', function () {
    config()->set('collaborator_search.enabled', true);
    config()->set('collaborator_search.host', 'http://meili.test');
    Http::fake(['http://meili.test/*' => Http::response(['message' => 'Unavailable'], 503)]);
    $owner = User::factory()->create(['name' => 'Taylor Owner']);
    $project = Project::factory()->withAuthor($owner->id)->create();
    $member = collaboratorSearchMember($project, 'Taylor Member');

    $matches = app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'taylor');
    expect($matches->pluck('id')->all())->toContain($owner->id, $member->id);
    app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'taylor');
    Http::assertSentCount(1);
});
