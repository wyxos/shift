<?php

use App\Exceptions\ExternalNotificationException;
use App\Jobs\SendExternalNotificationFallbackEmail;
use App\Jobs\SendTaskThreadEmailBatch;
use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalIdentity;
use App\Models\ExternalInboxNotification;
use App\Models\ExternalNotificationDelivery;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\TaskThreadEmailBatch;
use App\Models\User;
use App\Notifications\TaskThreadEmailDigest;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalNotificationService;
use App\Services\TaskThreadEmailBatchService;
use App\Services\TaskThreadNotificationService;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
    Notification::fake();
    $this->sender = User::factory()->create();
    $this->recipient = User::factory()->create();
    $this->project = Project::factory()->withAuthor($this->sender->id)->create(['token' => 'synthetic-batch-signing-token']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Batch notification task']);
    $this->task->internalCollaborators()->attach([$this->sender->id, $this->recipient->id]);
    $this->emails = app(TaskThreadEmailBatchService::class);
    $this->notifications = app(TaskThreadNotificationService::class);
    $this->postReply = function (string $content, array $attributes = []): TaskThread {
        $thread = TaskThread::query()->create(array_merge([
            'task_id' => $this->task->id,
            'sender_type' => User::class,
            'sender_id' => $this->sender->id,
            'sender_name' => 'Example author',
            'content' => $content,
            'type' => 'internal',
        ], $attributes));
        $this->notifications->send($thread->task, $thread);

        return $thread;
    };
    $this->externalRecipient = function (): ExternalIdentity {
        ProjectEnvironment::query()->create([
            'project_id' => $this->project->id,
            'environment' => 'production',
            'url' => 'https://batch-client.test',
            'callback_trusted_at' => now(),
        ]);
        $identity = ExternalIdentity::factory()->create([
            'project_id' => $this->project->id,
            'environment' => 'production',
            'url' => 'https://batch-client.test',
            'external_id' => 'batch-recipient',
            'email' => 'batch-recipient@example.test',
        ]);
        $this->task->externalCollaborators()->attach($identity->id);

        return $identity;
    };
    $this->runExternalJobs = function (): void {
        foreach (Queue::pushed(SendTaskThreadNotification::class) as $job) {
            $job->handle(app(ExternalNotificationService::class));
        }
    };
});

test('five burst replies keep per-message notifications and send one mail after the latest reply is quiet', function () {
    $threads = [];
    for ($index = 1; $index <= 5; $index++) {
        $threads[] = ($this->postReply)('Reply '.$index);
        if ($index < 5) {
            $this->travel(20)->seconds();
        }
    }

    $batch = TaskThreadEmailBatch::sole();
    expect($batch->scheduled_at->equalTo(now()->addSeconds(120)))->toBeTrue();
    Notification::assertSentToTimes($this->recipient, TaskThreadUpdated::class, 5);
    Notification::assertNotSentTo($this->recipient, TaskThreadEmailDigest::class);
    Notification::assertSentTo($this->recipient, TaskThreadUpdated::class, fn ($notification) => ! in_array('mail', $notification->via($this->recipient)));
    Queue::assertPushed(SendTaskThreadEmailBatch::class, 5);
    Queue::assertPushed(SendTaskThreadEmailBatch::class, fn ($job) => $job->connection === 'database'
        && $job->delay->equalTo($batch->scheduled_at));

    $this->travel(119)->seconds();
    $this->emails->send($batch->id);
    Notification::assertNotSentTo($this->recipient, TaskThreadEmailDigest::class);
    $this->travel(1)->seconds();
    $this->emails->send($batch->id);
    $this->emails->send($batch->id);
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 1);
    expect(array_column($batch->fresh()->payload['messages'], 'thread_id'))->toBe(array_column($threads, 'id'));
});

test('duplicate events do not extend the quiet period and stale batch jobs cannot send early', function () {
    $first = ($this->postReply)('First');
    $batch = TaskThreadEmailBatch::sole();
    $this->travel(60)->seconds();
    $this->notifications->send($this->task, $first);
    expect($batch->fresh()->scheduled_at->equalTo($first->created_at->addSeconds(120)))->toBeTrue();
    Queue::assertPushed(SendTaskThreadEmailBatch::class, 1);

    ($this->postReply)('Second');
    $this->travel(60)->seconds();
    (new SendTaskThreadEmailBatch($batch->id))->handle($this->emails);
    Notification::assertNotSentTo($this->recipient, TaskThreadEmailDigest::class);
    $this->travel(60)->seconds();
    (new SendTaskThreadEmailBatch($batch->id))->handle($this->emails);
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 1);
});

test('batches remain separate for each task and recipient and a later burst creates another mail', function () {
    $otherRecipient = User::factory()->create();
    $this->task->internalCollaborators()->attach($otherRecipient->id);
    $otherTask = Task::factory()->for($this->project)->create();
    $otherTask->internalCollaborators()->attach($this->recipient->id);
    ($this->postReply)('First task');
    ($this->postReply)('Other task', ['task_id' => $otherTask->id]);
    expect(TaskThreadEmailBatch::count())->toBe(3);
    $this->travel(120)->seconds();
    TaskThreadEmailBatch::all()->each(fn ($batch) => $this->emails->send($batch->id));
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 2);
    Notification::assertSentToTimes($otherRecipient, TaskThreadEmailDigest::class, 1);

    ($this->postReply)('Later burst');
    $this->travel(120)->seconds();
    TaskThreadEmailBatch::whereNull('sent_at')->get()->each(fn ($batch) => $this->emails->send($batch->id));
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 3);
    Notification::assertSentToTimes($otherRecipient, TaskThreadEmailDigest::class, 2);
});

test('drafts and removed messages are omitted and removed collaborators receive no queued mail', function () {
    ($this->postReply)('Private draft', ['is_draft' => true]);
    expect(TaskThreadEmailBatch::count())->toBe(0);
    $deleted = ($this->postReply)('Deleted reply');
    $hidden = ($this->postReply)('Returned to draft');
    $kept = ($this->postReply)('Still published');
    $deleted->delete();
    $hidden->update(['is_draft' => true]);
    $batch = TaskThreadEmailBatch::sole();
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    expect(array_column($batch->fresh()->payload['messages'], 'thread_id'))->toBe([$kept->id]);

    ($this->postReply)('After collaborator removal');
    $next = TaskThreadEmailBatch::whereNull('sent_at')->sole();
    $this->task->internalCollaborators()->detach($this->recipient->id);
    $this->travel(120)->seconds();
    $this->emails->send($next->id);
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 1);
    expect($next->fresh()->sent_at)->not->toBeNull();
});

test('mail failure retries the sealed snapshot while new arrivals form another batch', function () {
    $first = ($this->postReply)('Original reply');
    $batch = TaskThreadEmailBatch::sole();
    $this->travel(120)->seconds();
    $attempts = [];
    Notification::shouldReceive('send')->andReturnNull();
    Notification::shouldReceive('sendNow')->twice()->andReturnUsing(function ($recipient, $notification) use (&$attempts) {
        $attempts[] = $notification->data;
        if (count($attempts) === 1) {
            throw new RuntimeException('Synthetic mail transport failure');
        }
    });
    expect(fn () => $this->emails->send($batch->id))->toThrow(RuntimeException::class);
    expect($batch->fresh()->payload['messages'][0]['thread_id'])->toBe($first->id)
        ->and($batch->fresh()->sent_at)->toBeNull();
    ($this->postReply)('New arrival');
    expect(TaskThreadEmailBatch::count())->toBe(2);
    $this->emails->send($batch->id);
    expect($attempts[1])->toBe($attempts[0])
        ->and(count($attempts[1]['messages']))->toBe(1);
});

test('external grouped callbacks keep individual inbox entries and stable signed bodies when retried', function () {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::sequence()
        ->push(['message' => 'Unavailable'], 503)->push(['production' => true], 200)]);
    ($this->postReply)('Public first', ['type' => 'external']);
    $this->travel(30)->seconds();
    ($this->postReply)('Public second', ['type' => 'external']);
    ($this->runExternalJobs)();
    ($this->runExternalJobs)();
    expect(ExternalInboxNotification::where('external_identity_id', $identity->id)->count())->toBe(2);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->travel(120)->seconds();
    expect(fn () => $this->emails->send($batch->id))->toThrow(ExternalNotificationException::class);
    $receipt = ExternalNotificationDelivery::where('task_thread_email_batch_id', $batch->id)->sole();
    expect($receipt->attempts)->toBe(1)
        ->and($receipt->last_failure_type)->toBe('retryable_response')
        ->and($receipt->last_status_code)->toBe(503);
    $deliveryId = $receipt->delivery_id;
    ($this->postReply)('New public arrival', ['type' => 'external']);
    $this->travel(10)->seconds();
    $this->emails->send($batch->id);
    $this->emails->send($batch->id);

    expect($receipt->fresh()->attempts)->toBe(2)
        ->and($receipt->fresh()->last_failure_type)->toBeNull();
    Http::assertSentCount(2);
    $requests = Http::recorded()->pluck(0);
    expect($requests[0]->body())->toBe($requests[1]->body())
        ->and($requests[1]['delivery_id'])->toBe($deliveryId)
        ->and($requests[1]['handler'])->toBe('thread.update')
        ->and(count($requests[1]['payload']['messages']))->toBe(2)
        ->and($requests[1]->hasHeader(ExternalNotificationService::SIGNATURE_HEADER))->toBeTrue();
    foreach ($requests as $request) {
        $timestamp = $request->header(ExternalNotificationService::TIMESTAMP_HEADER)[0];
        expect($request->header(ExternalNotificationService::SIGNATURE_HEADER)[0])
            ->toBe(hash_hmac('sha256', $timestamp.'.'.$request->body(), $this->project->token));
    }
});

test('external collaborator removal cancels a queued group and team replies do not enter it', function () {
    $identity = ($this->externalRecipient)();
    Http::fake();
    ($this->postReply)('Team only');
    Queue::assertNotPushed(SendTaskThreadNotification::class);
    ($this->postReply)('Public reply', ['type' => 'external']);
    ($this->runExternalJobs)();
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->task->externalCollaborators()->detach($identity->id);
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    Http::assertNothingSent();
    expect($batch->fresh()->sent_at)->not->toBeNull();
});

test('nonproduction callbacks queue one grouped fallback email that completes only once', function () {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::response(['production' => false], 200)]);
    ($this->postReply)('First fallback reply', ['type' => 'external']);
    ($this->postReply)('Second fallback reply', ['type' => 'external']);
    ($this->runExternalJobs)();
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    $this->emails->send($batch->id);
    Queue::assertPushed(SendExternalNotificationFallbackEmail::class, 1);
    $fallback = Queue::pushed(SendExternalNotificationFallbackEmail::class)->sole();
    $fallback->handle();
    $fallback->handle();
    Notification::assertSentOnDemandTimes(TaskThreadEmailDigest::class, 1);
    Http::assertSentCount(1);
});

test('recording a new reply extends the email deadline before delayed fanout jobs run', function () {
    config(['queue.default' => 'database']);
    $recordReply = function (string $content): TaskThread {
        $thread = TaskThread::query()->create([
            'task_id' => $this->task->id, 'type' => 'internal', 'content' => $content,
            'sender_type' => User::class, 'sender_id' => $this->sender->id,
            'sender_name' => 'Example author',
        ]);
        $this->notifications->schedule($thread);

        return $thread;
    };
    $first = $recordReply('First before fanout');
    $this->travel(80)->seconds();
    $second = $recordReply('Second before fanout');
    $this->notifications->schedule($second);
    $batch = TaskThreadEmailBatch::sole();
    expect($batch->scheduled_at->equalTo(now()->addSeconds(120)))->toBeTrue();
    Queue::assertPushed(\App\Jobs\SendTaskThreadNotifications::class, 2);
    $this->travel(40)->seconds();
    $this->emails->send($batch->id);
    Notification::assertNothingSent();
    $this->travel(80)->seconds();
    $this->emails->send($batch->id);
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 1);
    expect(array_column($batch->fresh()->payload['messages'], 'thread_id'))->toBe([$first->id, $second->id]);
});

test('an external destination changed during the quiet period cannot receive the earlier group', function (string $field, string $value) {
    $identity = ($this->externalRecipient)();
    Http::fake();
    ($this->postReply)('Public before destination change', ['type' => 'external']);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $identity->update([$field => $value]);
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    Http::assertNothingSent();
})->with([
    ['url', 'https://changed-client.example.test'],
    ['email', 'changed@example.test'],
    ['external_id', 'different-recipient'],
]);

test('a sealed internal mail retry checks that the recipient still collaborates', function () {
    ($this->postReply)('Original visible reply');
    $batch = TaskThreadEmailBatch::sole();
    $this->travel(120)->seconds();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Synthetic mail failure'));
    expect(fn () => $this->emails->send($batch->id))->toThrow(RuntimeException::class);
    $this->task->internalCollaborators()->detach($this->recipient->id);
    $this->emails->send($batch->id);
    expect($batch->fresh()->sent_at)->not->toBeNull();
});

test('an external fallback queued before collaborator removal is cancelled without sending mail', function () {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::response(['production' => false], 200)]);
    ($this->postReply)('Fallback before removal', ['type' => 'external']);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    $fallback = Queue::pushed(SendExternalNotificationFallbackEmail::class)->sole();
    $this->task->externalCollaborators()->detach($identity->id);
    $fallback->handle();
    Notification::assertSentOnDemandTimes(TaskThreadEmailDigest::class, 0);
    expect(ExternalNotificationDelivery::find($fallback->deliveryId)->cancelled_at)->not->toBeNull();
});

test('a database queue worker executes the serialized batch exactly once after its deadline', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default')]);
    $queue = new QueueManager(app());
    $queue->addConnector('database', fn () => new \Illuminate\Queue\Connectors\DatabaseConnector(app('db')));
    Queue::swap($queue);
    ($this->postReply)('Durable queued reply');
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(json_decode(DB::table('jobs')->value('payload'), true)['displayName'])->toBe(SendTaskThreadEmailBatch::class);
    $this->travel(119)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    expect(DB::table('jobs')->count())->toBe(1);
    Notification::assertNotSentTo($this->recipient, TaskThreadEmailDigest::class);

    $this->travel(1)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(TaskThreadEmailBatch::sole()->sent_at)->not->toBeNull();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 1);
});

test('an external callback retry cancels its snapshot if a quoted message becomes private or disappears', function (string $change) {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::sequence()
        ->push(['message' => 'Unavailable'], 503)->push(['production' => true], 200)]);
    $changed = ($this->postReply)('Content that must be excluded', ['type' => 'external']);
    $survivor = ($this->postReply)('Still visible content', ['type' => 'external']);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->travel(120)->seconds();
    expect(fn () => $this->emails->send($batch->id))->toThrow(ExternalNotificationException::class);
    $receipt = ExternalNotificationDelivery::where('task_thread_email_batch_id', $batch->id)->sole();
    if ($change === 'team') {
        $changed->update(['type' => 'internal']);
    } else {
        $changed->delete();
    }

    $this->emails->send($batch->id);
    Http::assertSentCount(1);
    expect($receipt->fresh()->cancelled_at)->not->toBeNull()
        ->and($batch->fresh()->sent_at)->not->toBeNull();
    $freshBatch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->whereNull('sent_at')->sole();
    $this->emails->send($freshBatch->id);
    Http::assertSentCount(2);
    $retry = Http::recorded()->last()[0];
    expect(array_column($retry['payload']['messages'], 'thread_id'))->toBe([$survivor->id])
        ->and($retry['payload']['content'])->toBe('Still visible content')
        ->and($retry['delivery_id'])->not->toBe($receipt->delivery_id);
})->with(['team', 'deleted']);

test('an exhausted grouped callback records its terminal failure', function () {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::response(['message' => 'Unavailable'], 503)]);
    ($this->postReply)('Reply with exhausted callback', ['type' => 'external']);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->travel(120)->seconds();
    $job = new SendTaskThreadEmailBatch($batch->id);
    $failure = null;
    try {
        $job->handle($this->emails);
    } catch (ExternalNotificationException $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeInstanceOf(ExternalNotificationException::class);
    $job->failed($failure);
    $receipt = ExternalNotificationDelivery::where('task_thread_email_batch_id', $batch->id)->sole();
    expect($receipt->attempts)->toBe(1)
        ->and($receipt->failed_at)->not->toBeNull()
        ->and($receipt->last_failure_type)->toBe('retryable_response')
        ->and($receipt->completed_at)->toBeNull();
});

test('external recipient snapshots remain compatible with reordered JSON object keys', function () {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::response(['production' => true], 200)]);
    ($this->postReply)('Reply with portable snapshot', ['type' => 'external']);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $batch->update(['recipient_snapshot' => array_reverse($batch->recipient_snapshot, true)]);
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    Http::assertSentCount(1);
    expect($batch->fresh()->sent_at)->not->toBeNull();
});

test('a queued fallback cancels when a message becomes private or disappears', function (string $change) {
    $identity = ($this->externalRecipient)();
    Http::fake(['https://batch-client.test/shift/api/notifications' => Http::response(['production' => false], 200)]);
    $changed = ($this->postReply)('Public content before queued fallback', ['type' => 'external']);
    ($this->postReply)('Another public message', ['type' => 'external']);
    $batch = TaskThreadEmailBatch::where('external_identity_id', $identity->id)->sole();
    $this->travel(120)->seconds();
    $this->emails->send($batch->id);
    Queue::assertPushed(SendExternalNotificationFallbackEmail::class, 1);
    $fallback = Queue::pushed(SendExternalNotificationFallbackEmail::class)->sole();
    if ($change === 'team') {
        $changed->update(['type' => 'internal']);
    } else {
        $changed->delete();
    }

    expect($this->task->externalCollaborators()->whereKey($identity->id)->exists())->toBeTrue();
    $fallback->handle();
    $fallback->handle();
    Notification::assertSentOnDemandTimes(TaskThreadEmailDigest::class, 0);
    Http::assertSentCount(1);
    $receipt = ExternalNotificationDelivery::findOrFail($fallback->deliveryId);
    expect($receipt->cancelled_at)->not->toBeNull()
        ->and($receipt->fallback_sent_at)->toBeNull()
        ->and($receipt->completed_at)->toBeNull();
})->with(['team', 'deleted']);

test('a legacy queued fanout schedules email even when its old notification marker was already set', function () {
    $thread = TaskThread::query()->create([
        'task_id' => $this->task->id, 'type' => 'internal', 'content' => 'Queued before batching was installed',
        'sender_type' => User::class, 'sender_id' => $this->sender->id, 'sender_name' => 'Example author',
        'notifications_queued_at' => now(),
    ]);
    $job = new \App\Jobs\SendTaskThreadNotifications($thread->id);
    $job->handle($this->notifications);
    $job->handle($this->notifications);
    expect($thread->fresh()->emails_queued_at)->not->toBeNull();
    Queue::assertPushed(SendTaskThreadEmailBatch::class, 1);
    $this->travel(120)->seconds();
    $this->emails->send(TaskThreadEmailBatch::sole()->id);
    Notification::assertSentToTimes($this->recipient, TaskThreadEmailDigest::class, 1);
});
