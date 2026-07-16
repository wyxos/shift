<?php

use App\Enums\ExternalUserRole;
use App\Enums\OrganisationRole;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->internalUser = User::factory()->create();
    $this->token = $this->internalUser->createToken('external-role-test')->plainTextToken;
    $this->organisation = Organisation::factory()->create(['author_id' => $this->internalUser->id]);
    $this->project = Project::factory()->create([
        'author_id' => $this->internalUser->id,
        'organisation_id' => $this->organisation->id,
        'client_id' => null,
        'token' => 'external-role-project',
    ]);
    $this->project->environments()->create([
        'environment' => 'testing',
        'url' => 'https://consumer.test',
    ]);
});

test('internal technical managers can assign external roles', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->putJson('/api/external-roles', [
            'project' => $this->project->token,
            'external_user' => [
                'id' => 'client-owner-1',
                'name' => 'Client Owner',
                'email' => 'owner@example.com',
            ],
            'role' => ExternalUserRole::Owner->value,
            'environment' => 'testing',
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://consumer.test',
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.id', 'client-owner-1')
        ->assertJsonPath('user.role', ExternalUserRole::Owner->value);

    $this->assertDatabaseHas('external_users', [
        'project_id' => $this->project->id,
        'external_id' => 'client-owner-1',
        'role' => ExternalUserRole::Owner->value,
    ]);
});

test('embedded shift developers cannot assign external roles', function () {
    $untrustedInternalUser = User::factory()->create();
    $untrustedToken = $untrustedInternalUser->createToken('untrusted')->plainTextToken;
    $shiftDeveloper = ExternalUser::query()->create([
        'project_id' => $this->project->id,
        'external_id' => 'shift-dev-1',
        'name' => 'SHIFT Developer',
        'email' => 'shift-dev@example.com',
        'environment' => 'testing',
        'url' => 'https://consumer.test',
        'role' => ExternalUserRole::ShiftDeveloper->value,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$untrustedToken)
        ->putJson('/api/external-roles', [
            'project' => $this->project->token,
            'user' => [
                'id' => $shiftDeveloper->external_id,
                'name' => $shiftDeveloper->name,
                'email' => $shiftDeveloper->email,
                'environment' => $shiftDeveloper->environment,
                'url' => $shiftDeveloper->url,
            ],
            'external_user' => [
                'id' => 'client-dev-1',
                'name' => 'Client Developer',
                'email' => 'client-dev@example.com',
            ],
            'role' => ExternalUserRole::ClientDeveloper->value,
            'environment' => 'testing',
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://consumer.test',
            ],
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('external_users', [
        'project_id' => $this->project->id,
        'external_id' => 'client-dev-1',
    ]);
});

test('developer internal users cannot assign external roles', function () {
    $viewer = ExternalUser::query()->create([
        'project_id' => $this->project->id,
        'external_id' => 'viewer-1',
        'name' => 'Viewer',
        'email' => 'viewer@example.com',
        'environment' => 'testing',
        'url' => 'https://consumer.test',
        'role' => ExternalUserRole::User->value,
    ]);
    $plainInternalUser = User::factory()->create();
    OrganisationUser::query()->create([
        'organisation_id' => $this->organisation->id,
        'user_id' => $plainInternalUser->id,
        'user_email' => $plainInternalUser->email,
        'user_name' => $plainInternalUser->name,
        'role' => OrganisationRole::Developer->value,
    ]);
    ProjectUser::query()->create([
        'project_id' => $this->project->id,
        'user_id' => $plainInternalUser->id,
        'user_email' => $plainInternalUser->email,
        'user_name' => $plainInternalUser->name,
        'registration_status' => 'registered',
    ]);
    $plainToken = $plainInternalUser->createToken('plain')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$plainToken)
        ->putJson('/api/external-roles', [
            'project' => $this->project->token,
            'user' => [
                'id' => $viewer->external_id,
                'name' => $viewer->name,
                'email' => $viewer->email,
                'environment' => $viewer->environment,
                'url' => $viewer->url,
            ],
            'external_user' => [
                'id' => 'client-dev-2',
                'name' => 'Client Developer',
                'email' => 'client-dev-2@example.com',
            ],
            'role' => ExternalUserRole::ClientDeveloper->value,
            'environment' => 'testing',
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://consumer.test',
            ],
        ])
        ->assertForbidden();
});

test('client project managers can assign external roles for accessible projects', function () {
    $projectManager = User::factory()->create();
    OrganisationUser::query()->create([
        'organisation_id' => $this->organisation->id,
        'user_id' => $projectManager->id,
        'user_email' => $projectManager->email,
        'user_name' => $projectManager->name,
        'role' => OrganisationRole::ClientProjectManager->value,
    ]);
    ProjectUser::query()->create([
        'project_id' => $this->project->id,
        'user_id' => $projectManager->id,
        'user_email' => $projectManager->email,
        'user_name' => $projectManager->name,
        'registration_status' => 'registered',
    ]);
    $managerToken = $projectManager->createToken('client-project-manager')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$managerToken)
        ->putJson('/api/external-roles', [
            'project' => $this->project->token,
            'external_user' => [
                'id' => 'client-owner-2',
                'name' => 'Client Owner',
                'email' => 'owner-2@example.com',
            ],
            'role' => ExternalUserRole::Owner->value,
            'environment' => 'testing',
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://consumer.test',
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.id', 'client-owner-2')
        ->assertJsonPath('user.role', ExternalUserRole::Owner->value);

    $this->assertDatabaseHas('external_users', [
        'project_id' => $this->project->id,
        'external_id' => 'client-owner-2',
        'role' => ExternalUserRole::Owner->value,
    ]);
});

test('external role options use consuming app labels without changing role values', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/external-roles/capabilities?'.http_build_query([
            'project' => $this->project->token,
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://consumer.test',
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('roles.0.value', ExternalUserRole::Owner->value)
        ->assertJsonPath('roles.0.label', 'Owner')
        ->assertJsonPath('roles.1.value', ExternalUserRole::ClientDeveloper->value)
        ->assertJsonPath('roles.1.label', 'Developer')
        ->assertJsonPath('roles.2.label', 'SHIFT Lead Developer')
        ->assertJsonPath('roles.3.label', 'SHIFT Developer');

    expect(ExternalUserRole::Owner->label())->toBe('Client Owner')
        ->and(ExternalUserRole::ClientDeveloper->label())->toBe('Client Developer');
});

test('external role index returns collaborator candidates with stored roles', function () {
    Http::fake([
        'https://consumer.test/shift/api/collaborators/external*' => Http::response([
            'environment' => 'testing',
            'url' => 'https://consumer.test',
            'users' => [
                [
                    'id' => 'client-owner-1',
                    'name' => 'Client Owner',
                    'email' => 'owner@example.com',
                ],
            ],
        ]),
    ]);

    ExternalUser::query()->create([
        'project_id' => $this->project->id,
        'external_id' => 'client-owner-1',
        'name' => 'Client Owner',
        'email' => 'owner@example.com',
        'environment' => 'testing',
        'url' => 'https://consumer.test',
        'role' => ExternalUserRole::Owner->value,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/external-roles?'.http_build_query([
            'project' => $this->project->token,
            'environment' => 'testing',
            'search' => 'owner',
        ]))
        ->assertOk()
        ->assertJsonPath('capabilities.can_manage_external_roles', true)
        ->assertJsonPath('users.0.id', 'client-owner-1')
        ->assertJsonPath('users.0.role', ExternalUserRole::Owner->value);
});
