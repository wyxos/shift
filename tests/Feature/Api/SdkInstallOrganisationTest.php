<?php

use App\Enums\OrganisationRole;
use App\Events\SdkInstallSessionApproved;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\User;
use App\Services\SdkInstallSessionService;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Event::fake([SdkInstallSessionApproved::class]);
});

function approvedOrganisationInstall(User $user): array
{
    $service = app(SdkInstallSessionService::class);
    $session = $service->create('local', 'https://client-app.test');
    $service->approve($session['user_code'], $user);

    return $session;
}

function installOrganisationMember(Organisation $organisation, User $user, OrganisationRole $role): OrganisationUser
{
    return OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
        'role' => $role,
    ]);
}

test('installer lists only organisations the approving user can manage', function () {
    $user = User::factory()->create();
    $owned = Organisation::factory()->create(['author_id' => $user->id, 'name' => 'Alpha Agency']);
    $administered = Organisation::factory()->create(['name' => 'Beta Agency']);
    installOrganisationMember($administered, $user, OrganisationRole::Administrator);
    $memberOnly = Organisation::factory()->create();
    installOrganisationMember($memberOnly, $user, OrganisationRole::Developer);
    Organisation::factory()->create();
    $session = approvedOrganisationInstall($user);

    $this->postJson(route('api.sdk-install.projects'), ['device_code' => $session['device_code']])
        ->assertOk()
        ->assertJsonPath('organisations', [
            ['id' => $owned->id, 'name' => $owned->name],
            ['id' => $administered->id, 'name' => $administered->name],
        ]);
});

test('installer creates and finalizes a project inside the selected organisation', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    Organisation::factory()->create(['author_id' => $user->id]);
    $session = approvedOrganisationInstall($user);

    $projectId = $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'],
        'name' => 'Customer Portal',
        'organisation_id' => $organisation->id,
    ])->assertCreated()
        ->assertJsonPath('project.organisation_id', $organisation->id)
        ->assertJsonPath('project.organisation_name', $organisation->name)
        ->json('project.id');

    $this->assertDatabaseHas('projects', [
        'id' => $projectId, 'author_id' => $user->id, 'organisation_id' => $organisation->id, 'client_id' => null,
    ]);
    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'], 'project_id' => $projectId,
    ])->assertOk()->assertJsonStructure(['user_token', 'project_token']);
    $this->assertDatabaseHas('project_environments', ['project_id' => $projectId, 'environment' => 'local']);
    $this->actingAs($user)->get(route('organisation.projects', $organisation))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('projects.data', 1)->where('projects.data.0.id', $projectId));
});

test('older installers automatically use the sole manageable organisation', function (?int $requestedOrganisation) {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $user->id]);
    Organisation::factory()->create();
    $session = approvedOrganisationInstall($user);
    $payload = ['device_code' => $session['device_code'], 'name' => 'Customer Portal'];
    if ($requestedOrganisation === null) {
        $payload['organisation_id'] = null;
    }

    $this->postJson(route('api.sdk-install.projects.create'), $payload)->assertCreated()
        ->assertJsonPath('project.organisation_id', $organisation->id);
    $this->assertDatabaseHas('projects', ['name' => 'Customer Portal', 'organisation_id' => $organisation->id]);
})->with(['omitted' => [0], 'explicit null' => [null]]);

test('installers must choose when more than one manageable organisation exists', function () {
    $user = User::factory()->create();
    Organisation::factory()->count(2)->create(['author_id' => $user->id]);
    $session = approvedOrganisationInstall($user);

    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'], 'name' => 'Customer Portal',
    ])->assertUnprocessable()->assertJsonValidationErrors('organisation_id');
    $this->assertDatabaseCount('projects', 0);
});

test('organisation administrators can create assigned projects', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();
    installOrganisationMember($organisation, $user, OrganisationRole::Administrator);
    $session = approvedOrganisationInstall($user);

    $projectId = $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'], 'name' => 'Customer Portal', 'organisation_id' => $organisation->id,
    ])->assertCreated()->assertJsonPath('project.organisation_id', $organisation->id)->json('project.id');
    $this->postJson(route('api.sdk-install.finalize'), [
        'device_code' => $session['device_code'], 'project_id' => $projectId,
    ])->assertOk();
});

test('installer denies organisations without project creation permission', function (?OrganisationRole $role) {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();
    if ($role !== null) {
        installOrganisationMember($organisation, $user, $role);
    }
    $session = approvedOrganisationInstall($user);

    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'], 'name' => 'Customer Portal', 'organisation_id' => $organisation->id,
    ])->assertForbidden();
    $this->assertDatabaseCount('projects', 0);
})->with([null, OrganisationRole::Developer, OrganisationRole::LeadDeveloper, OrganisationRole::ClientProjectManager]);

test('installer rejects malformed organisation selections', function (mixed $organisationId) {
    $session = approvedOrganisationInstall(User::factory()->create());
    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'], 'name' => 'Customer Portal', 'organisation_id' => $organisationId,
    ])->assertUnprocessable()->assertJsonValidationErrors('organisation_id');
    $this->assertDatabaseCount('projects', 0);
})->with(['text' => ['text'], 'negative' => [-1], 'zero' => [0], 'array' => [[1]]]);

test('pending sessions cannot list organisations or create assigned projects', function () {
    $organisation = Organisation::factory()->create();
    $session = app(SdkInstallSessionService::class)->create('local', 'https://client-app.test');
    $this->postJson(route('api.sdk-install.projects'), ['device_code' => $session['device_code']])->assertConflict();
    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'], 'name' => 'Customer Portal', 'organisation_id' => $organisation->id,
    ])->assertConflict();
    $this->assertDatabaseCount('projects', 0);
});

test('personal installs remain available for accounts without manageable organisations', function () {
    $session = approvedOrganisationInstall(User::factory()->create());
    $this->postJson(route('api.sdk-install.projects'), ['device_code' => $session['device_code']])
        ->assertOk()->assertJsonPath('organisations', []);
    $this->postJson(route('api.sdk-install.projects.create'), [
        'device_code' => $session['device_code'], 'name' => 'Personal Portal',
    ])->assertCreated()->assertJsonPath('project.organisation_id', null);
    $this->assertDatabaseHas('projects', ['name' => 'Personal Portal', 'organisation_id' => null]);
});
