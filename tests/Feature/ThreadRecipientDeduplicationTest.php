<?php

use App\Enums\TaskCollaboratorKind;
use App\Jobs\SendPendingTaskCollaboratorNotification;
use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalIdentity;
use App\Models\ExternalInboxNotification;
use App\Models\ExternalNotificationDelivery;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\ExternalNotificationService;
use Illuminate\Support\Facades\Http;

function threadRecipientFixture(): array
{
    $owner = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $owner->id, 'token' => 'recipient-test']);
    $task = Task::factory()->for($project)->create();
    $thread = TaskThread::query()->create([
        'task_id' => $task->id, 'type' => 'external', 'content' => 'A public reply',
        'sender_type' => User::class, 'sender_id' => $owner->id, 'sender_name' => $owner->name,
    ]);
    $identities = collect(['production', 'development'])->map(function (string $environment) use ($project, $task) {
        $url = 'https://'.$environment.'.example.test';
        $registration = $project->environments()->create(['environment' => $environment, 'url' => $url, 'callback_trusted_at' => now()]);
        $identity = ExternalIdentity::factory()->create([
            'project_id' => $project->id, 'project_environment_id' => $registration->id,
            'external_id' => '42', 'environment' => $environment, 'url' => $url,
        ]);
        $task->externalCollaborators()->attach($identity->id);

        return $identity;
    });

    return compact('task', 'thread', 'identities');
}

function threadRecipientJob(TaskThread $thread, ExternalIdentity $identity, bool $legacy = false): SendTaskThreadNotification
{
    $data = ['external_id' => $identity->external_id, 'url' => $identity->url, 'email' => $identity->email];
    if (! $legacy) {
        $data['external_identity_id'] = $identity->id;
        $data['environment'] = $identity->environment;
    }

    return new SendTaskThreadNotification($thread->id, $data, [
        'user_id' => $identity->external_id, 'task_id' => $thread->task_id,
        'thread_id' => $thread->id, 'task_title' => $thread->task->title, 'content' => $thread->content,
    ]);
}

test('collaborator inbox waits for the grace period and survives delivery failure without duplicates', function () {
    Http::fake(['*' => Http::sequence()->push([], 503)->push(['production' => true])]);
    ['task' => $task, 'identities' => $identities] = threadRecipientFixture();
    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $task->id,
        'external_identity_id' => $identities->first()->id,
        'kind' => TaskCollaboratorKind::External->value,
        'event' => TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED,
        'scheduled_at' => now()->addMinute(),
    ]);
    $job = new SendPendingTaskCollaboratorNotification($pending->id);
    $service = app(ExternalNotificationService::class);

    $job->handle($service);
    Http::assertNothingSent();
    expect(ExternalInboxNotification::query()->count())->toBe(0);

    $this->travel(61)->seconds();
    expect(fn () => $job->handle($service))->toThrow(App\Exceptions\ExternalNotificationException::class);
    expect(ExternalInboxNotification::query()->count())->toBe(1);

    $job->handle($service);
    expect(ExternalInboxNotification::query()->count())->toBe(1)
        ->and($pending->fresh()->sent_at)->not->toBeNull();
});

test('removing a collaborator during the grace period creates no inbox notification', function () {
    Http::fake();
    ['task' => $task, 'identities' => $identities] = threadRecipientFixture();
    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $task->id,
        'external_identity_id' => $identities->first()->id,
        'kind' => TaskCollaboratorKind::External->value,
        'event' => TaskCollaboratorNotification::EVENT_TASK_CREATED,
        'scheduled_at' => now()->subSecond(),
    ]);
    $task->externalCollaborators()->detach($identities->first()->id);

    (new SendPendingTaskCollaboratorNotification($pending->id))->handle(app(ExternalNotificationService::class));

    Http::assertNothingSent();
    expect(ExternalInboxNotification::query()->count())->toBe(0)
        ->and($pending->fresh()->cancelled_at)->not->toBeNull();
});

test('reply delivery distinguishes equal application user IDs in different environments', function () {
    Http::fake(['*' => Http::response(['production' => true])]);
    ['thread' => $thread, 'identities' => $identities] = threadRecipientFixture();

    foreach ($identities as $identity) {
        $job = threadRecipientJob($thread, $identity);
        $job->handle(app(ExternalNotificationService::class));
        $job->handle(app(ExternalNotificationService::class));
    }

    Http::assertSentCount(2);
    foreach ($identities as $identity) {
        Http::assertSent(fn ($request) => $request->url() === $identity->url.'/shift/api/notifications'
            && (string) $request['payload']['user_id'] === '42');
    }
    expect(ExternalNotificationDelivery::query()->where('task_thread_id', $thread->id)->count())->toBe(2);
});

test('an unambiguous historical reply receipt is retained without sending another email', function () {
    Http::fake();
    ['thread' => $thread, 'identities' => $identities] = threadRecipientFixture();
    $recipient = $identities->first();
    $identities->last()->delete();
    $legacy = ExternalNotificationDelivery::forTaskThread($thread, '42', 'thread.update');
    $legacy->markCallbackDelivered(true, 200);
    $legacy->markCompleted();

    threadRecipientJob($thread, $recipient, true)->handle(app(ExternalNotificationService::class));

    Http::assertNothingSent();
    expect(ExternalNotificationDelivery::findForTaskThread($thread->id, 'identity:'.$recipient->id)->delivery_id)
        ->toBe($legacy->delivery_id)
        ->and(ExternalNotificationDelivery::query()->count())->toBe(1)
        ->and(ExternalInboxNotification::query()->count())->toBe(1);
});

test('historical jobs with a colliding receipt still deliver separately to their original source accounts', function () {
    Http::fake(['*' => Http::response(['production' => true])]);
    ['thread' => $thread, 'identities' => $identities] = threadRecipientFixture();
    $legacy = ExternalNotificationDelivery::forTaskThread($thread, '42', 'thread.update');
    $legacy->markCallbackDelivered(true, 200);
    $legacy->markCompleted();

    foreach ($identities as $recipient) {
        $job = unserialize(serialize(threadRecipientJob($thread, $recipient, true)));
        $job->handle(app(ExternalNotificationService::class));
        $job->handle(app(ExternalNotificationService::class));
    }

    Http::assertSentCount(2);
    expect(ExternalInboxNotification::query()->count())->toBe(2)
        ->and(ExternalInboxNotification::query()->pluck('external_identity_id')->sort()->values()->all())
        ->toBe($identities->pluck('id')->sort()->values()->all());
});

test('the inbox persists once even when reply email delivery fails', function (int $status) {
    Http::fake(['*' => Http::sequence()->push(['message' => 'Callback unavailable'], $status)->push(['production' => true])]);
    ['thread' => $thread, 'identities' => $identities] = threadRecipientFixture();
    $job = threadRecipientJob($thread, $identities->first());

    try {
        $job->handle(app(ExternalNotificationService::class));
    } catch (App\Exceptions\ExternalNotificationException $exception) {
        expect($exception->retryable)->toBeTrue();
    }

    expect(ExternalInboxNotification::query()->count())->toBe(1);
    $job->handle(app(ExternalNotificationService::class));
    expect(ExternalInboxNotification::query()->count())->toBe(1);
})->with([422, 503]);

test('delayed private or draft replies do not enter the external inbox or send email', function (array $changes) {
    Http::fake();
    ['thread' => $thread, 'identities' => $identities] = threadRecipientFixture();
    $job = threadRecipientJob($thread, $identities->first());
    $thread->update($changes);

    $job->handle(app(ExternalNotificationService::class));

    Http::assertNothingSent();
    expect(ExternalInboxNotification::query()->count())->toBe(0);
})->with([
    'team' => [['type' => 'internal']],
    'draft' => [['is_draft' => true]],
]);
