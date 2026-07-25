<?php

use App\Enums\TaskCollaboratorKind;
use App\Exceptions\ExternalNotificationException;
use App\Jobs\SendExternalNotificationFallbackEmail;
use App\Jobs\SendPendingTaskCollaboratorNotification;
use App\Models\ExternalNotificationDelivery;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use App\Notifications\TaskCollaboratorAddedNotification;
use App\Notifications\TaskCreationNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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
        && filled($request['delivery_id'])
        && $request->hasHeader(ExternalNotificationService::SIGNATURE_HEADER));

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);

    expect($pending->fresh()->sent_at)->not->toBeNull()
        ->and($delivery)->not->toBeNull()
        ->and($delivery->callback_delivered_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull()
        ->and($delivery->failed_at)->toBeNull();
});

test('retryable callback failure keeps collaborator notification pending until eventual success', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::sequence()
            ->push(['message' => 'Unavailable'], 503)
            ->push(['production' => true], 200),
    ]);

    ['pending' => $pending] = externalCollaboratorNotificationFixture();
    $job = new SendPendingTaskCollaboratorNotification($pending->id);

    expect(fn () => $job->handle(app(ExternalNotificationService::class)))
        ->toThrow(ExternalNotificationException::class);

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);

    expect($pending->fresh()->sent_at)->toBeNull()
        ->and($pending->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_failure_type)->toBe('retryable_response')
        ->and($delivery->failed_at)->toBeNull();

    $job->handle(app(ExternalNotificationService::class));
    $delivery->refresh();

    expect($pending->fresh()->sent_at)->not->toBeNull()
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->callback_delivered_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();
    Http::assertSentCount(2);
});

test('connection failures remain pending and record an exhausted retry failure', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::failedConnection(),
    ]);

    ['pending' => $pending] = externalCollaboratorNotificationFixture();
    $job = new SendPendingTaskCollaboratorNotification($pending->id);
    $exception = null;

    for ($attempt = 1; $attempt <= $job->tries; $attempt++) {
        try {
            $job->handle(app(ExternalNotificationService::class));
        } catch (ExternalNotificationException $caught) {
            $exception = $caught;
        }
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($job->backoff())->toBe([60, 300])
        ->and($job->timeout)->toBe(45)
        ->and($job->failOnTimeout)->toBeTrue();

    $job->failed($exception);

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);

    expect($pending->fresh()->sent_at)->toBeNull()
        ->and($pending->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->last_failure_type)->toBe('connection');
});

test('retrying a removed collaborator records cancellation instead of delivery', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    ['pending' => $pending, 'externalUser' => $externalUser, 'task' => $task] = externalCollaboratorNotificationFixture();
    $job = new SendPendingTaskCollaboratorNotification($pending->id);

    expect(fn () => $job->handle(app(ExternalNotificationService::class)))
        ->toThrow(ExternalNotificationException::class);

    $task->externalCollaborators()->detach($externalUser->id);
    $job->handle(app(ExternalNotificationService::class));

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);
    $pending->refresh();

    expect($pending->sent_at)->toBeNull()
        ->and($pending->cancelled_at)->not->toBeNull()
        ->and($delivery->cancelled_at)->not->toBeNull()
        ->and($delivery->failed_at)->toBeNull()
        ->and($delivery->completed_at)->toBeNull();
});

test('permanent and malformed callback failures are recorded without marking sent', function (int $status, array $body, string $failureType) {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response($body, $status),
    ]);

    ['pending' => $pending] = externalCollaboratorNotificationFixture();

    (new SendPendingTaskCollaboratorNotification($pending->id))
        ->handle(app(ExternalNotificationService::class));

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);

    expect($pending->fresh()->sent_at)->toBeNull()
        ->and($pending->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->last_failure_type)->toBe($failureType)
        ->and($delivery->last_status_code)->toBe($status);
})->with([
    'client error' => [422, ['message' => 'Invalid payload'], 'permanent_response'],
    'malformed success' => [200, ['message' => 'Missing production flag'], 'malformed_response'],
]);

test('non-production fallback email marks the collaborator notification sent only after mail succeeds', function () {
    Queue::fake([SendExternalNotificationFallbackEmail::class]);
    Notification::fake();
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['production' => false], 200),
    ]);

    ['pending' => $pending] = externalCollaboratorNotificationFixture();

    (new SendPendingTaskCollaboratorNotification($pending->id))
        ->handle(app(ExternalNotificationService::class));

    $fallbackJob = null;

    Queue::assertPushed(
        SendExternalNotificationFallbackEmail::class,
        function (SendExternalNotificationFallbackEmail $job) use (&$fallbackJob): bool {
            $fallbackJob = $job;

            return true;
        },
    );

    expect($pending->fresh()->sent_at)->toBeNull();

    $fallbackJob->handle();
    $fallbackJob->handle();

    Notification::assertSentOnDemandTimes(TaskCreationNotification::class, 1);

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);

    expect($pending->fresh()->sent_at)->not->toBeNull()
        ->and($pending->fresh()->cancelled_at)->toBeNull()
        ->and($delivery->fallback_sent_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();
});

test('cancelled collaborator fallback email does not send or mark sent', function () {
    Queue::fake([SendExternalNotificationFallbackEmail::class]);
    Notification::fake();
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['production' => false], 200),
    ]);

    ['pending' => $pending] = externalCollaboratorNotificationFixture();

    (new SendPendingTaskCollaboratorNotification($pending->id))
        ->handle(app(ExternalNotificationService::class));

    $fallbackJob = null;

    Queue::assertPushed(
        SendExternalNotificationFallbackEmail::class,
        function (SendExternalNotificationFallbackEmail $job) use (&$fallbackJob): bool {
            $fallbackJob = $job;

            return true;
        },
    );

    $pending->markCancelled();
    $fallbackJob->handle();

    Notification::assertNothingSent();

    $delivery = ExternalNotificationDelivery::findForTaskCollaborator($pending->id);

    expect($pending->fresh()->sent_at)->toBeNull()
        ->and($pending->fresh()->cancelled_at)->not->toBeNull()
        ->and($delivery->cancelled_at)->not->toBeNull()
        ->and($delivery->completed_at)->toBeNull();
});

test('fallback email failure is recorded once without risking duplicate mail', function () {
    ['pending' => $pending] = externalCollaboratorNotificationFixture();
    $delivery = ExternalNotificationDelivery::forTaskCollaborator($pending, 'task.created');
    $delivery->markCallbackDelivered(false, 200);

    $notification = new class extends \Illuminate\Notifications\Notification
    {
        public function via(object $notifiable): array
        {
            throw new RuntimeException('Mail transport failed before delivery');
        }
    };
    $job = new SendExternalNotificationFallbackEmail(
        $delivery->id,
        'client@example.com',
        $notification,
    );

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    $delivery->refresh();

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(45)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($delivery->fallback_attempted_at)->not->toBeNull()
        ->and($delivery->fallback_sent_at)->toBeNull()
        ->and($delivery->completed_at)->toBeNull()
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->last_failure_type)->toBe('fallback_email');

    $job->handle();

    expect($delivery->fresh()->fallback_sent_at)->toBeNull()
        ->and($pending->fresh()->sent_at)->toBeNull();
});

/**
 * @return array{pending: TaskCollaboratorNotification, externalUser: ExternalUser, task: Task}
 */
function externalCollaboratorNotificationFixture(): array
{
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

    return compact('pending', 'externalUser', 'task');
}
