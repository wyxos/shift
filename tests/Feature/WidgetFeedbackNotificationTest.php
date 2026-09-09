<?php

use App\Enums\OrganisationRole;
use App\Enums\TaskCollaboratorKind;
use App\Models\Client;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use App\Notifications\WidgetFeedbackNotification;
use App\Services\TaskCollaboratorNotificationScheduler;
use App\Services\TaskCollaboratorService;
use App\Services\WidgetFeedbackAudience;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    $this->widgetOwner = User::factory()->create();
    $this->widgetOrganisation = Organisation::factory()->create(['author_id' => $this->widgetOwner->id]);
    $this->widgetProject = Project::factory()->create([
        'organisation_id' => $this->widgetOrganisation->id,
        'client_id' => null,
        'author_id' => $this->widgetOwner->id,
        'token' => 'disposable-widget-project-token',
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);
    $this->widgetProject->environments()->create([
        'environment' => 'testing',
        'url' => 'https://feedback-fixture.test',
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);
});

function widgetRecipientMember(Organisation $organisation, OrganisationRole $role, ?Project $assignedProject = null, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $organisation->organisationUsers()->create([
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_name' => $user->name,
        'role' => $role,
    ]);
    if ($assignedProject) {
        $assignedProject->projectUser()->create([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
            'registration_status' => 'registered',
        ]);
    }

    return $user;
}

function submitWidgetFeedbackFixture(): Task
{
    $test = test();
    $token = $test->widgetOwner->createToken('disposable-widget-test')->plainTextToken;
    $response = $test->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/widget/tasks', [
        'project' => $test->widgetProject->token,
        'kind' => 'issue',
        'title' => 'Disposable feedback notification fixture',
        'description' => 'Verify internal feedback recipients.',
        'anonymous' => false,
        'metadata' => ['environment' => 'testing', 'url' => 'https://feedback-fixture.test/page'],
        'user' => [
            'id' => 'external-fixture',
            'name' => 'External Submitter',
            'email' => 'external-fixture@example.com',
            'environment' => 'testing',
            'url' => 'https://feedback-fixture.test',
            'authenticated' => true,
        ],
    ])->assertCreated();

    return Task::query()->findOrFail($response->json('id'));
}

function widgetPendingRecipientIds(Task $task): array
{
    return TaskCollaboratorNotification::query()->where('task_id', $task->id)
        ->where('event', TaskCollaboratorNotification::EVENT_WIDGET_FEEDBACK_CREATED)
        ->orderBy('user_id')->pluck('user_id')->all();
}

test('widget submission alerts assigned internal roles once and preserves external reply participation', function () {
    $recipients = collect([
        widgetRecipientMember($this->widgetOrganisation, OrganisationRole::LeadDeveloper, $this->widgetProject),
        widgetRecipientMember($this->widgetOrganisation, OrganisationRole::ClientProjectManager, $this->widgetProject),
        widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer, $this->widgetProject),
    ]);
    widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Administrator, $this->widgetProject);
    widgetRecipientMember($this->widgetOrganisation, OrganisationRole::LeadDeveloper);
    widgetRecipientMember(Organisation::factory()->create(), OrganisationRole::Developer, $this->widgetProject);
    // Duplicate assignment and membership rows must not duplicate a submission alert.
    widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer, $this->widgetProject, $recipients->last());
    $task = submitWidgetFeedbackFixture();
    app(TaskCollaboratorNotificationScheduler::class)->scheduleWidgetFeedbackCreated($task);

    expect(widgetPendingRecipientIds($task))->toBe($recipients->pluck('id')->sort()->values()->all())
        ->and(TaskCollaboratorNotification::query()->where('task_id', $task->id)->count())->toBe(3)
        ->and(TaskCollaboratorNotification::query()->where('kind', TaskCollaboratorKind::External->value)->exists())->toBeFalse()
        ->and($task->submitter)->toBeInstanceOf(ExternalUser::class)
        ->and(app(TaskCollaboratorService::class)->externalReplyAudience($task)->pluck('id')->all())->toBe([$task->submitter_id]);

    foreach ($recipients as $recipient) {
        $notification = new WidgetFeedbackNotification($task);
        $url = route('tasks.index', ['task' => $task->id]);
        expect($notification->toMail($recipient)->actionUrl)->toBe($url)
            ->and($notification->toArray($recipient)['url'])->toBe($url)
            ->and(Task::query()->whereKey($task->id)->visibleTo($recipient->id)->exists())->toBeTrue();
        $this->actingAs($recipient)->get($url)->assertOk();
        $this->getJson(route('tasks.show', $task))->assertOk()->assertJsonPath('id', $task->id);
    }
});

test('widget fallback includes owning organisation administrators and implicit owner with existing access only', function () {
    $admin = widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Administrator);
    $leadWithoutAccess = widgetRecipientMember($this->widgetOrganisation, OrganisationRole::LeadDeveloper);
    widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer);
    widgetRecipientMember(Organisation::factory()->create(), OrganisationRole::Administrator);
    $task = submitWidgetFeedbackFixture();

    expect(widgetPendingRecipientIds($task))->toBe(collect([$this->widgetOwner->id, $admin->id])->sort()->values()->all())
        ->and($this->widgetOrganisation->organisationUsers()->where('user_id', $this->widgetOwner->id)->exists())->toBeFalse()
        ->and($task->internalCollaborators()->count())->toBe(0)
        ->and(Task::query()->whereKey($task->id)->visibleTo($leadWithoutAccess->id)->exists())->toBeFalse();

    foreach ([$this->widgetOwner, $admin] as $recipient) {
        $this->actingAs($recipient)->getJson(route('tasks.show', $task))->assertOk()->assertJsonPath('id', $task->id);
    }
    $this->actingAs($leadWithoutAccess)->getJson(route('tasks.show', $task))->assertNotFound();
});

test('widget fallback includes an organisation lead who already has task collaborator access', function () {
    $lead = widgetRecipientMember($this->widgetOrganisation, OrganisationRole::LeadDeveloper);
    $task = Task::factory()->create(['project_id' => $this->widgetProject->id]);
    $task->internalCollaborators()->attach($lead->id);

    expect(app(WidgetFeedbackAudience::class)->recipients($task)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->widgetOwner->id, $lead->id])->sort()->values()->all())
        ->and($this->widgetProject->projectUser()->count())->toBe(0);
});

test('widget recipient ownership uses direct organisation before client organisation and supports client ownership', function (bool $directOwnership) {
    $clientOrganisation = Organisation::factory()->create();
    $this->widgetProject->update([
        'client_id' => Client::factory()->create(['organisation_id' => $clientOrganisation->id])->id,
        'organisation_id' => $directOwnership ? $this->widgetOrganisation->id : null,
    ]);
    $directMember = widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer, $this->widgetProject);
    $clientMember = widgetRecipientMember($clientOrganisation, OrganisationRole::Developer, $this->widgetProject);
    $task = submitWidgetFeedbackFixture();

    expect(widgetPendingRecipientIds($task))->toBe([$directOwnership ? $directMember->id : $clientMember->id]);
})->with([true, false]);

test('widget fallback excludes pending assignments and treats an implicit owner as administrator', function () {
    widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer, $this->widgetProject, $this->widgetOwner);
    $pending = widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer, $this->widgetProject);
    $this->widgetProject->projectUser()->where('user_id', $pending->id)->update(['registration_status' => 'pending']);
    $admin = widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Administrator);
    $task = submitWidgetFeedbackFixture();

    expect(widgetPendingRecipientIds($task))->toBe(collect([$this->widgetOwner->id, $admin->id])->sort()->values()->all());
});

test('widget feedback with no owning organisation has no notification audience and grants no access', function () {
    $this->widgetProject->update(['organisation_id' => null, 'client_id' => null]);
    $task = submitWidgetFeedbackFixture();

    expect(widgetPendingRecipientIds($task))->toBe([])
        ->and($task->internalCollaborators()->count())->toBe(0);
});

test('widget alerts exclude an external submitters internal identity by case insensitive email', function () {
    $submitterIdentity = User::factory()->create(['email' => 'EXTERNAL-FIXTURE@example.com']);
    widgetRecipientMember($this->widgetOrganisation, OrganisationRole::Developer, $this->widgetProject, $submitterIdentity);
    $task = submitWidgetFeedbackFixture();

    expect(widgetPendingRecipientIds($task))->toBe([$this->widgetOwner->id])
        ->and(app(TaskCollaboratorService::class)->externalReplyAudience($task)->pluck('id')->all())->toBe([$task->submitter_id]);
});
