<?php

use App\Exceptions\ExternalNotificationException;
use App\Jobs\SendExternalNotificationFallbackEmail;
use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalNotificationDelivery;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalNotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('thread notification records callback success and does not send the same delivery twice', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['production' => true], 200),
    ]);

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();
    $job = new SendTaskThreadNotification($thread->id, $externalUserData, $payload);

    $job->handle(app(ExternalNotificationService::class));
    $job->handle(app(ExternalNotificationService::class));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => filled($request['delivery_id'])
        && $request['handler'] === 'thread.update'
        && $request['payload']['thread_id'] === $thread->id);

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery)->not->toBeNull()
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->callback_delivered_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull()
        ->and($delivery->failed_at)->toBeNull();
});

test('thread notification handles legacy queued payloads without an environment key', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['production' => true], 200),
    ]);

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();
    expect($externalUserData)->not->toHaveKey('environment');

    $job = unserialize(serialize(new SendTaskThreadNotification($thread->id, $externalUserData, $payload)));
    $job->handle(app(ExternalNotificationService::class));

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery->callback_delivered_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();
    Http::assertSentCount(1);
});

test('thread notification records historical untrusted destinations as terminal failures', function () {
    Http::fake();

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();
    $thread->task->project->environments()->update(['callback_trusted_at' => null]);

    (new SendTaskThreadNotification($thread->id, $externalUserData, $payload))
        ->handle(app(ExternalNotificationService::class));

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery->last_failure_type)->toBe('untrusted_destination')
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->completed_at)->toBeNull();
    Http::assertNothingSent();
});

test('thread notification retries a transient callback and records eventual success', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::sequence()
            ->push(['message' => 'Unavailable'], 503)
            ->push(['production' => true], 200),
    ]);

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();
    $job = new SendTaskThreadNotification($thread->id, $externalUserData, $payload);

    expect(fn () => $job->handle(app(ExternalNotificationService::class)))
        ->toThrow(ExternalNotificationException::class);

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery->completed_at)->toBeNull()
        ->and($delivery->failed_at)->toBeNull()
        ->and($delivery->last_failure_type)->toBe('retryable_response');

    $job->handle(app(ExternalNotificationService::class));
    $delivery->refresh();

    expect($delivery->attempts)->toBe(2)
        ->and($delivery->callback_delivered_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();
});

test('thread notification records exhausted retries and cancels if the thread disappears', function () {
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();
    $job = new SendTaskThreadNotification($thread->id, $externalUserData, $payload);
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

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery->attempts)->toBe(3)
        ->and($delivery->last_failure_type)->toBe('retryable_response')
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->completed_at)->toBeNull();

    $thread->delete();
    $job->handle(app(ExternalNotificationService::class));
    $delivery->refresh();

    expect($delivery->cancelled_at)->not->toBeNull()
        ->and($delivery->failed_at)->toBeNull()
        ->and($delivery->completed_at)->toBeNull();
});

test('thread notification records a permanent callback failure without logging success', function () {
    Log::spy();
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['message' => 'Invalid'], 422),
    ]);

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();

    (new SendTaskThreadNotification($thread->id, $externalUserData, $payload))
        ->handle(app(ExternalNotificationService::class));

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery->failed_at)->not->toBeNull()
        ->and($delivery->last_failure_type)->toBe('permanent_response')
        ->and($delivery->completed_at)->toBeNull();

    Log::shouldNotHaveReceived('info', [
        'Thread notification delivered after delay',
        \Mockery::any(),
    ]);
});

test('thread non-production fallback completes only after the queued email succeeds', function () {
    Queue::fake([SendExternalNotificationFallbackEmail::class]);
    Notification::fake();
    Http::fake([
        'https://client-app.test/shift/api/notifications' => Http::response(['production' => false], 200),
    ]);

    ['thread' => $thread, 'externalUserData' => $externalUserData, 'payload' => $payload] = threadNotificationFixture();

    (new SendTaskThreadNotification($thread->id, $externalUserData, $payload))
        ->handle(app(ExternalNotificationService::class));

    $fallbackJob = null;

    Queue::assertPushed(
        SendExternalNotificationFallbackEmail::class,
        function (SendExternalNotificationFallbackEmail $job) use (&$fallbackJob): bool {
            $fallbackJob = $job;

            return true;
        },
    );

    $delivery = ExternalNotificationDelivery::findForTaskThread($thread->id, 'external-123');

    expect($delivery->completed_at)->toBeNull()
        ->and($delivery->fallback_dispatched_at)->not->toBeNull();

    $fallbackJob->handle();
    $fallbackJob->handle();
    $delivery->refresh();

    Notification::assertSentOnDemandTimes(TaskThreadUpdated::class, 1);

    expect($delivery->fallback_sent_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();
});

/**
 * @return array{thread: TaskThread, externalUserData: array<string, string>, payload: array<string, mixed>}
 */
function threadNotificationFixture(): array
{
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'token' => 'external-notification-token',
    ]);
    ProjectEnvironment::query()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'url' => 'https://client-app.test',
        'callback_trusted_at' => now(),
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Thread delivery task',
    ]);
    $task->submitter()->associate($owner)->save();
    $thread = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => 'New reply',
        'sender_name' => $owner->name,
        'sender_type' => User::class,
        'sender_id' => $owner->id,
    ]);
    $externalUserData = [
        'url' => 'https://client-app.test',
        'email' => 'client@example.com',
        'external_id' => 'external-123',
    ];
    $payload = [
        'type' => 'task_thread',
        'user_id' => 'external-123',
        'task_id' => $task->id,
        'task_title' => $task->title,
        'thread_id' => $thread->id,
        'content' => $thread->content,
    ];

    return compact('thread', 'externalUserData', 'payload');
}
