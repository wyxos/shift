<?php

use App\Enums\OrganisationRole;
use App\Enums\RequirementStatus;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Models\User;

function createProjectMember(Project $project, User $user, OrganisationRole $role): OrganisationUser
{
    $organisation = $project->accessOrganisation();

    $organisationUser = OrganisationUser::query()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
        'role' => $role->value,
    ]);

    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
        'registration_status' => 'registered',
    ]);

    return $organisationUser;
}

function createRequirementTask(Project $project): Task
{
    $batch = RequirementBatch::query()->create([
        'project_id' => $project->id,
        'title' => 'Client pack',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Original requirement',
        'description' => '<p>Original scope</p>',
    ]);

    $task->metadata()->create([
        'environment' => 'production',
        'url' => 'https://portal.test',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'phase' => 'requirement',
        'requirement_batch_id' => $batch->id,
        'submitted_title' => 'Original requirement',
        'submitted_description' => '<p>Original scope</p>',
    ]);

    return $task->fresh(['metadata']);
}

test('organisation creator is stored as administrator', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organisations.store'), [
            'name' => 'Role Managed Organisation',
        ])
        ->assertRedirect(route('organisations.index'));

    $organisation = Organisation::query()->where('name', 'Role Managed Organisation')->firstOrFail();

    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
        'role' => OrganisationRole::Administrator->value,
    ]);
});

test('lead developer can invite organisation members and grant project access', function () {
    $owner = User::factory()->create();
    $leadDeveloper = User::factory()->create();
    $invitee = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
        'author_id' => $owner->id,
    ]);

    createProjectMember($project, $leadDeveloper, OrganisationRole::LeadDeveloper);

    $this->actingAs($leadDeveloper)
        ->postJson(route('project-users.store', $project), [
            'email' => $invitee->email,
            'name' => $invitee->name,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $organisation->id,
        'user_id' => $invitee->id,
        'role' => OrganisationRole::Developer->value,
    ]);

    $this->assertDatabaseHas('project_users', [
        'project_id' => $project->id,
        'user_id' => $invitee->id,
    ]);
});

test('task-scope leads can create requirements from the requirements review screen', function (OrganisationRole $role) {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
        'author_id' => $owner->id,
        'name' => 'Portal Review',
    ]);

    createProjectMember($project, $member, $role);

    $this->actingAs($member)
        ->get(route('organisation.requirements', $organisation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->has('projects', 1)
            ->where('projects.0.id', $project->id)
            ->where('projects.0.can_create_task', true)
            ->where('surface', 'requirements')
        );
})->with([
    'administrator' => [OrganisationRole::Administrator],
    'team lead' => [OrganisationRole::ClientProjectManager],
    'developer lead' => [OrganisationRole::LeadDeveloper],
]);

test('developer can comment but cannot edit delete or finalize visible requirements', function () {
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
        'author_id' => $owner->id,
    ]);
    $requirement = createRequirementTask($project);

    createProjectMember($project, $developer, OrganisationRole::Developer);

    $this->actingAs($developer)
        ->postJson(route('task-threads.store', $requirement), [
            'content' => '<p>Can you clarify?</p>',
            'type' => 'internal',
        ])
        ->assertCreated();

    $this->actingAs($developer)
        ->putJson(route('tasks.v2.update', $requirement), [
            'status' => 'completed',
        ])
        ->assertForbidden();

    $this->actingAs($developer)
        ->patchJson(route('requirements.v2.finalize', $requirement), [
            'title' => 'Accepted requirement',
            'description' => '<p>Accepted scope</p>',
        ])
        ->assertForbidden();

    $this->actingAs($developer)
        ->deleteJson(route('tasks.v2.destroy', $requirement))
        ->assertForbidden();
});

test('client project manager can edit and finalize visible requirements', function () {
    $owner = User::factory()->create();
    $projectManager = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'organisation_id' => $organisation->id,
        'client_id' => null,
        'author_id' => $owner->id,
    ]);
    $requirement = createRequirementTask($project);
    $requirement->metadata->forceFill([
        'requirement_status' => RequirementStatus::ReadyToFinalize->value,
    ])->save();

    createProjectMember($project, $projectManager, OrganisationRole::ClientProjectManager);

    $this->actingAs($projectManager)
        ->patchJson(route('requirements.v2.finalize', $requirement), [
            'title' => 'Accepted requirement',
            'description' => '<p>Accepted scope</p>',
        ])
        ->assertOk()
        ->assertJsonPath('task.phase', 'task');

    $this->assertDatabaseHas('task_metadata', [
        'task_id' => $requirement->id,
        'phase' => 'task',
        'finalized_by' => $projectManager->id,
    ]);
});
