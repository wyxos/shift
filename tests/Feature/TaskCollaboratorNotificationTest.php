<?php

use App\Enums\TaskCollaboratorKind;
use App\Jobs\SendPendingTaskCollaboratorNotification;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

test('pending internal collaborator notification sends when collaborator is still attached', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($owner)->save();
    $task->internalCollaborators()->attach($collaborator->id);

    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $task->id,
        'event' => TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED,
        'kind' => TaskCollaboratorKind::Internal->value,
        'user_id' => $collaborator->id,
        'scheduled_at' => now()->subSecond(),
    ]);

    (new SendPendingTaskCollaboratorNotification($pending->id))
        ->handle(app(ExternalNotificationService::class));

    Notification::assertSentTo($collaborator, TaskCollaboratorAddedNotification::class);

    $pending->refresh();
    expect($pending->sent_at)->not->toBeNull()
        ->and($pending->cancelled_at)->toBeNull();
});

test('pending collaborator notification is cancelled when collaborator is no longer attached', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($owner)->save();

    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $task->id,
        'event' => TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED,
        'kind' => TaskCollaboratorKind::Internal->value,
        'user_id' => $collaborator->id,
        'scheduled_at' => now()->subSecond(),
    ]);

    (new SendPendingTaskCollaboratorNotification($pending->id))
        ->handle(app(ExternalNotificationService::class));

    Notification::assertNothingSent();

    $pending->refresh();
    expect($pending->sent_at)->toBeNull()
        ->and($pending->cancelled_at)->not->toBeNull();
});

test('pending external collaborator notification posts to the consuming app when still attached', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['production' => true], 200),
    ]);

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'token' => 'external-notification-token',
    ]);

    $externalUser = ExternalUser::query()->create([
        'external_id' => 'client-1',
        'project_id' => $project->id,
        'name' => 'Client User',
        'email' => 'client@example.com',
        'environment' => 'production',
        'url' => 'https://client-app.test',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($owner)->save();
    $task->externalCollaborators()->attach($externalUser->id);

    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $task->id,
        'event' => TaskCollaboratorNotification::EVENT_TASK_CREATED,
        'kind' => TaskCollaboratorKind::External->value,
        'external_user_id' => $externalUser->id,
        'scheduled_at' => now()->subSecond(),
    ]);

    (new SendPendingTaskCollaboratorNotification($pending->id))
        ->handle(app(ExternalNotificationService::class));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://client-app.test/shift/api/notifications'
        && $request['handler'] === 'task.created'
        && $request['payload']['user_id'] === 'client-1'
        && $request->hasHeader(ExternalNotificationService::SIGNATURE_HEADER));

    expect($pending->fresh()->sent_at)->not->toBeNull();
});
