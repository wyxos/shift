<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\SdkInstallSessionService;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->service = app(SdkInstallSessionService::class);
});

test('create install session returns device flow payload and starts pending', function () {
    $response = $this->postJson(route('api.sdk-install.store'), [
        'environment' => ' Staging ',
        'url' => 'https://client-app.test/',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'device_code',
        'user_code',
        'verification_uri',
        'verification_uri_complete',
        'interval',
        'expires_at',
    ]);

    $payload = $response->json();

    expect($payload['user_code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');
    expect($payload['verification_uri'])->toBe(route('sdk-install.verify', absolute: true));
    expect($payload['verification_uri_complete'])->toBe(route('sdk-install.verify', ['user_code' => $payload['user_code']], absolute: true));

    $this->postJson(route('api.sdk-install.poll'), [
        'device_code' => $payload['device_code'],
    ])
        ->assertOk()
        ->assertJsonPath('state', 'pending')
        ->assertJsonPath('interval', 5);

    $session = $this->service->detailsForUserCode($payload['user_code']);

    expect($session)->not->toBeNull();
    expect($session['environment'])->toBe('staging');
    expect($session['url'])->toBe('https://client-app.test');
});

test('guests are redirected to login and return to the verification page after authenticating', function () {
    $session = $this->service->create('staging', 'https://client-app.test');
    $user = User::factory()->create();

    $this->get(route('sdk-install.verify', ['user_code' => $session['user_code']], absolute: false))
        ->assertRedirect(route('login', absolute: false));

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('sdk-install.verify', ['user_code' => $session['user_code']], absolute: false));
});

test('authenticated users can inspect and approve an install request', function () {
    $session = $this->service->create('staging', 'https://client-app.test');
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('sdk-install.verify', ['user_code' => $session['user_code']], absolute: false));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SdkInstall/Verify')
        ->where('userCode', $session['user_code'])
        ->where('session.user_code', $session['user_code'])
        ->where('session.state', 'pending')
        ->where('session.environment', 'staging')
        ->where('session.url', 'https://client-app.test')
    );

    $this->actingAs($user)
        ->post(route('sdk-install.approve', absolute: false), [
            'user_code' => strtolower($session['user_code']),
        ])
        ->assertRedirect(route('sdk-install.verify', ['user_code' => $session['user_code']], absolute: false));

    $this->postJson(route('api.sdk-install.poll'), [
        'device_code' => $session['device_code'],
    ])
        ->assertOk()
        ->assertJsonPath('state', 'approved');
});

test('approved install sessions cannot be rebound to another browser user', function () {
    $session = $this->service->create('staging', 'https://client-app.test');
    $approver = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($approver)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ])->assertRedirect();

    $this->actingAs($otherUser)
        ->from(route('sdk-install.verify', ['user_code' => $session['user_code']], absolute: false))
        ->post(route('sdk-install.approve', absolute: false), [
            'user_code' => $session['user_code'],
        ])
        ->assertSessionHasErrors([
            'user_code' => 'This install request has already been approved by another user.',
        ]);
});

test('approved sessions list only manageable projects and exclude shared projects', function () {
    $manager = User::factory()->create();
    $managedProject = Project::factory()->create([
        'author_id' => $manager->id,
        'client_id' => null,
        'organisation_id' => null,
        'name' => 'Managed Project',
    ]);

    $sharedOwner = User::factory()->create();
    $sharedProject = Project::factory()->create([
        'author_id' => $sharedOwner->id,
        'client_id' => null,
        'organisation_id' => null,
        'name' => 'Shared Project',
    ]);

    ProjectUser::factory()->create([
        'project_id' => $sharedProject->id,
        'user_id' => $manager->id,
        'user_email' => $manager->email,
        'user_name' => $manager->name,
        'registration_status' => 'registered',
    ]);

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->postJson(route('api.sdk-install.projects'), [
        'device_code' => $session['device_code'],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.id', $managedProject->id)
        ->assertJsonPath('projects.0.name', 'Managed Project');
});

test('approved sessions can create a standalone project when none exist yet', function () {
    $manager = User::factory()->create();

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->postJson(route('api.sdk-install.projects'), [
        'device_code' => $session['device_code'],
    ])
        ->assertOk()
        ->assertJsonPath('projects', []);

    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'],
        'name' => 'Atlas',
    ])
        ->assertCreated()
        ->assertJsonPath('project.name', 'Atlas')
        ->assertJsonPath('project.client_name', null)
        ->assertJsonPath('project.organisation_name', null)
        ->assertJsonPath('project.has_project_token', false);

    $this->assertDatabaseHas('projects', ['name' => 'Atlas', 'author_id' => $manager->id]);
});

test('finalize generates credentials once, creates project tokens when missing, and registers the environment', function () {
    $manager = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $manager->id,
        'client_id' => null,
        'organisation_id' => null,
        'token' => null,
        'name' => 'Portal',
    ]);

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'production',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $response = $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'],
        'project_id' => $project->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('project_id', $project->id)
        ->assertJsonPath('project_name', 'Portal')
        ->assertJsonPath('environment', 'production')
        ->assertJsonPath('url', 'https://client-app.test');

    $project->refresh();
    expect($project->token)->not->toBeNull();
    expect($response->json('project_token'))->toBe($project->token);

    $plainTextToken = $response->json('user_token');
    $personalAccessToken = PersonalAccessToken::findToken($plainTextToken);

    expect($personalAccessToken)->not->toBeNull();
    expect($personalAccessToken->tokenable_id)->toBe($manager->id);
    expect($personalAccessToken->tokenable_type)->toBe(User::class);

    $this->assertDatabaseHas('project_environments', [
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client-app.test',
    ]);

    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'],
        'project_id' => $project->id,
    ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Credentials have already been issued for this install session.');

    expect(PersonalAccessToken::query()->where('tokenable_id', $manager->id)->count())->toBe(1);
});

test('finalize reuses an existing project token', function () {
    $manager = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $manager->id,
        'client_id' => null,
        'organisation_id' => null,
        'token' => 'existing-project-token',
    ]);

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'],
        'project_id' => $project->id,
    ])
        ->assertOk()
        ->assertJsonPath('project_token', 'existing-project-token');

    expect($project->fresh()->token)->toBe('existing-project-token');
});

test('create project returns conflict after credentials are issued', function () {
    $manager = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $manager->id,
        'client_id' => null,
        'organisation_id' => null,
    ]);

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'],
        'project_id' => $project->id,
    ])->assertOk();

    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'],
        'name' => 'Another Project',
    ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Credentials have already been issued for this install session.');
});

test('poll returns expired after the install session lifetime passes', function () {
    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->travel(16)->minutes();

    $this->postJson(route('api.sdk-install.poll'), [
        'device_code' => $session['device_code'],
    ])
        ->assertOk()
        ->assertJsonPath('state', 'expired')
        ->assertJsonPath('expires_at', null);
});

test('create project cannot be used after credentials were issued for the install session', function () {
    $manager = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $manager->id,
        'client_id' => null,
        'organisation_id' => null,
    ]);

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'],
        'project_id' => $project->id,
    ])->assertOk();

    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'],
        'name' => 'Should Not Exist',
    ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Credentials have already been issued for this install session.');

    $this->assertDatabaseMissing('projects', ['name' => 'Should Not Exist']);
});

test('finalize rejects projects outside the approver visibility', function () {
    $manager = User::factory()->create();
    $unmanagedProject = Project::factory()->create([
        'author_id' => User::factory()->create()->id,
    ]);

    $session = $this->postJson(route('api.sdk-install.store'), [
        'environment' => 'staging',
        'url' => 'https://client-app.test',
    ])->json();

    $this->actingAs($manager)->post(route('sdk-install.approve', absolute: false), [
        'user_code' => $session['user_code'],
    ]);

    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'],
        'project_id' => $unmanagedProject->id,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to install against the selected project.');
});
