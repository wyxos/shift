<?php

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\ExternalUserService;
use App\Services\OutboundUrlPolicy;
use Illuminate\Support\Facades\Http;

test('collaborator lookup refuses historical registrations without trust provenance', function () {
    $project = Project::factory()->create(['token' => 'project-token']);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client.example',
        'callback_trusted_at' => null,
    ]);
    Http::fake();

    expect(fn () => app(ExternalUserService::class)->searchCollaborators($project, 'production'))
        ->toThrow(\RuntimeException::class, 'has not been trusted');

    Http::assertNothingSent();
});

test('collaborator lookup refuses private dns before sending the project token', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->instance(
        OutboundUrlPolicy::class,
        new OutboundUrlPolicy(fn (): array => ['10.0.0.8']),
    );
    $project = Project::factory()->create(['token' => 'project-token']);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://private-client.example',
        'callback_trusted_at' => now(),
    ]);
    Http::fake();

    expect(fn () => app(ExternalUserService::class)->searchCollaborators($project, 'production'))
        ->toThrow(\RuntimeException::class, 'not trusted');

    Http::assertNothingSent();
});

test('collaborator lookup refuses redirects without forwarding the project token', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->instance(
        OutboundUrlPolicy::class,
        new OutboundUrlPolicy(fn (): array => ['93.184.216.34']),
    );
    $project = Project::factory()->create(['token' => 'project-token']);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client.example',
        'callback_trusted_at' => now(),
    ]);
    Http::fake([
        'https://client.example/shift/api/collaborators/external*' => Http::response('', 302, [
            'Location' => 'http://127.0.0.1/internal',
        ]),
        'http://127.0.0.1/*' => Http::response(['unexpected' => true]),
    ]);

    expect(fn () => app(ExternalUserService::class)->searchCollaborators($project, 'production'))
        ->toThrow(\RuntimeException::class, 'attempted a redirect');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://client.example/shift/api/collaborators/external'
        && $request->hasHeader('Authorization', 'Bearer project-token'));
    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'http://127.0.0.1'));
});

test('collaborator lookup requires a project callback token', function () {
    $project = Project::factory()->create(['token' => null]);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client.example',
        'callback_trusted_at' => now(),
    ]);
    Http::fake();

    expect(fn () => app(ExternalUserService::class)->searchCollaborators($project, 'production'))
        ->toThrow(\RuntimeException::class, 'does not have a callback token');

    Http::assertNothingSent();
});

test('collaborator lookup supports a valid public production destination', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->instance(
        OutboundUrlPolicy::class,
        new OutboundUrlPolicy(fn (): array => ['93.184.216.34']),
    );
    $project = Project::factory()->create(['token' => 'project-token']);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client.example',
        'callback_trusted_at' => now(),
    ]);
    Http::fake([
        'https://client.example/shift/api/collaborators/external*' => Http::response([
            'environment' => 'production',
            'url' => 'https://client.example',
            'users' => [
                ['id' => 'client-1', 'name' => 'Client User', 'email' => 'client@example.com'],
            ],
        ]),
    ]);

    $result = app(ExternalUserService::class)->searchCollaborators($project, 'production');

    expect($result['users'])->toBe([
        ['id' => 'client-1', 'name' => 'Client User', 'email' => 'client@example.com'],
    ]);
});

test('collaborator lookup supports intentional local destinations only in local execution', function () {
    $this->app->instance(
        OutboundUrlPolicy::class,
        new OutboundUrlPolicy(fn (): array => ['127.0.0.1']),
    );
    $project = Project::factory()->create(['token' => 'project-token']);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'local',
        'url' => 'https://client.test',
        'callback_trusted_at' => now(),
    ]);
    $capturedOptions = null;
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response([
            'environment' => 'local',
            'url' => 'https://client.test',
            'users' => [],
        ]);
    });

    $result = app(ExternalUserService::class)->searchCollaborators($project, 'local');

    expect($result['users'])->toBe([])
        ->and($capturedOptions['verify'] ?? null)->toBeFalse()
        ->and($capturedOptions['allow_redirects'] ?? null)->toBeFalse();
});
