<?php

use App\Models\ExternalIdentity;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use App\Notifications\ProjectUserRegisteredNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function standaloneProject(User $owner, string $name = 'Personal project'): Project
{
    return Project::factory()->create([
        'name' => $name, 'author_id' => $owner->id, 'client_id' => null, 'organisation_id' => null,
    ]);
}

test('projects can be created and managed without an organisation even when the owner has one', function () {
    $owner = User::factory()->create();
    Organisation::factory()->create(['author_id' => $owner->id]);
    $this->actingAs($owner)->get('/projects')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects')->where('canCreateProject', true)->where('currentOrganisation', null));
    $this->post(route('projects.store'), ['name' => 'Personal project'])
        ->assertRedirect(route('projects.index', absolute: false));
    $project = Project::query()->where('name', 'Personal project')->sole();
    expect($project->organisation_id)->toBeNull()
        ->and($project->client_id)->toBeNull()->and($project->author_id)->toBe($owner->id);
    $this->put(route('projects.update', $project), ['name' => 'Renamed personal project'])
        ->assertRedirect(route('projects.index', absolute: false));
    expect($project->fresh()->name)->toBe('Renamed personal project');
    $this->delete(route('projects.destroy', $project))->assertRedirect(route('projects.index', absolute: false));
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

test('an existing user sees an invited standalone project and loses project access when removed', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $project = standaloneProject($owner);
    $identity = ExternalIdentity::factory()->create(['project_id' => $project->id]);
    standaloneProject($owner, 'Other private project');
    $task = Task::factory()->for($project)->create();
    $collaboratingTask = Task::factory()->for($project)->create();
    TaskCollaborator::query()->create([
        'task_id' => $collaboratingTask->id, 'kind' => 'internal', 'user_id' => $member->id,
    ]);
    $this->actingAs($owner)->postJson(route('project-users.store', $project), [
        'email' => strtoupper($member->email), 'name' => $member->name,
    ])->assertCreated();
    $membership = ProjectUser::query()->where('project_id', $project->id)->sole();
    expect($membership->user_id)->toBe($member->id)->and(OrganisationUser::query()->count())->toBe(0);
    Notification::assertNothingSent();
    $this->actingAs($member)->get('/projects')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Projects')->has('projects.data', 1)
        ->where('projects.data.0.id', $project->id)->where('projects.data.0.isOwner', false)
        ->where('projects.data.0.can_manage_project_access', false)
        ->where('projects.data.0.can_delete_project', false)
        ->where('projects.data.0.can_manage_technical_settings', false)
        ->missing('projects.data.0.token'));
    $this->getJson(route('tasks.show', $task))->assertOk();
    $this->get(route('project-participants.index', ['project_id' => $project->id]))
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('externalIdentities.data.0.can_edit_profile', false));
    $this->putJson(route('project-participants.update', $identity), [
        'name' => 'Changed profile', 'email' => 'changed@example.com',
    ])->assertForbidden();
    $this->postJson(route('task-threads.store', $task), [
        'content' => '<p>A shared project comment.</p>', 'type' => 'internal',
    ])->assertCreated();
    $this->putJson(route('projects.update', $project), ['name' => 'Unwanted change'])->assertForbidden();
    $this->postJson(route('project-users.store', $project), ['email' => $outsider->email, 'name' => $outsider->name])->assertForbidden();
    $this->actingAs($outsider)->get('/projects')->assertOk()->assertInertia(fn ($page) => $page->has('projects.data', 0));
    $this->getJson(route('tasks.show', $task))->assertNotFound();
    $this->actingAs($owner)->deleteJson(route('project-users.destroy', [$project, $membership]))->assertOk();
    $this->actingAs($member)->get('/projects')->assertOk()->assertInertia(fn ($page) => $page->has('projects.data', 0));
    $this->getJson(route('tasks.show', $task))->assertNotFound();
    $this->getJson(route('tasks.show', $collaboratingTask))->assertOk();
});

test('the global project list keeps permission queries bounded as its page fills', function () {
    $owner = User::factory()->create();
    $administrator = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    OrganisationUser::query()->create([
        'organisation_id' => $organisation->id, 'user_id' => $administrator->id,
        'user_email' => $administrator->email, 'user_name' => $administrator->name, 'role' => 'administrator',
    ]);
    $attributes = ['author_id' => $owner->id, 'organisation_id' => $organisation->id, 'client_id' => null];
    Project::factory()->count(2)->create($attributes);
    $this->actingAs($administrator);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/projects')->assertOk();
    $smallCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    Project::factory()->count(8)->create($attributes);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/projects')->assertOk()->assertInertia(fn ($page) => $page->has('projects.data', 10));
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($largeCount)->toBeLessThanOrEqual($smallCount + 1);
});

test('standalone invitations support signed registration verification and multiple pending projects', function () {
    Notification::fake();
    config(['shift.registration_mode' => 'invite_only']);
    $owner = User::factory()->create();
    foreach ([standaloneProject($owner), standaloneProject($owner, 'Second shared project')] as $project) {
        $this->actingAs($owner)->postJson(route('project-users.store', $project), [
            'email' => '2@example.com', 'name' => 'Invited member',
        ])->assertCreated();
    }
    $url = null;
    Notification::assertSentOnDemand(ProjectInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$url) {
        $url ??= $notification->toMail($notifiable)->actionUrl;

        return ! str_contains($notification->toMail($notifiable)->actionUrl, 'organisation_id');
    });
    auth()->logout();
    $this->get($url)->assertOk();
    parse_str(parse_url($url, PHP_URL_QUERY), $context);
    $this->post('/register', [
        'name' => 'Invited member', 'email' => '2@example.com', 'password' => 'password',
        'password_confirmation' => 'password', 'project_id' => $context['project_id'],
    ])->assertRedirect(route('projects.index', ['highlight' => $context['project_id']], absolute: false));
    $member = User::query()->where('email', '2@example.com')->sole();
    expect($member->email_verified_at)->toBeNull()
        ->and(ProjectUser::query()->where('user_id', $member->id)->count())->toBe(2)
        ->and(OrganisationUser::query()->count())->toBe(0);
    Notification::assertSentTo($member, VerifyEmail::class);
    Notification::assertSentTo($owner, ProjectUserRegisteredNotification::class, fn ($notification) => $notification->toMail($owner)->actionUrl === route('projects.index'));
    $this->get('/projects')->assertRedirect(route('verification.notice', absolute: false));
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
        'id' => $member->id, 'hash' => sha1($member->email),
    ]);
    $this->get($verificationUrl)->assertRedirect();
    $this->get('/projects')->assertOk()->assertInertia(fn ($page) => $page->has('projects.data', 2));
});

test('standalone invites cannot be duplicated or forged and unrelated users are not suggested', function () {
    Notification::fake();
    $owner = User::factory()->create();
    User::factory()->create(['email' => '3@example.com']);
    $project = standaloneProject($owner);
    $this->actingAs($owner)->get('/projects')->assertOk()->assertInertia(fn ($page) => $page->has('accessUsers', 0));
    $invite = ['email' => '2@example.com', 'name' => 'Invited member'];
    $this->postJson(route('project-users.store', $project), $invite)->assertCreated();
    $this->postJson(route('project-users.store', $project), $invite)->assertUnprocessable();
    expect(ProjectUser::query()->where('project_id', $project->id)->count())->toBe(1);
    auth()->logout();
    $this->get('/register?email=2@example.com&project_id='.$project->id)->assertForbidden();
});
