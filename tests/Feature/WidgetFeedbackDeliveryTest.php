<?php

use App\Enums\OrganisationRole;
use App\Enums\TaskCollaboratorKind;
use App\Jobs\SendPendingTaskCollaboratorNotification;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use App\Notifications\WidgetFeedbackNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Transport\TransportInterface;

function widgetDeliveryFixture(): array
{
    $queueManager = app('queue');
    Queue::fake([SendQueuedNotifications::class]);
    $organisation = Organisation::factory()->create();
    $recipient = User::factory()->create();
    $organisation->organisationUsers()->create([
        'user_id' => $recipient->id,
        'user_email' => $recipient->email,
        'user_name' => $recipient->name,
        'role' => OrganisationRole::Developer,
    ]);
    $project = Project::factory()->create(['organisation_id' => $organisation->id]);
    $project->projectUser()->create([
        'user_id' => $recipient->id,
        'user_email' => $recipient->email,
        'user_name' => $recipient->name,
        'registration_status' => 'registered',
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $task->id,
        'event' => TaskCollaboratorNotification::EVENT_WIDGET_FEEDBACK_CREATED,
        'kind' => TaskCollaboratorKind::Internal,
        'user_id' => $recipient->id,
        'scheduled_at' => now()->subSecond(),
    ]);
    $dispatch = new SendPendingTaskCollaboratorNotification($pending->id);
    $dispatch->handle(app(ExternalNotificationService::class));
    $dispatch->handle(app(ExternalNotificationService::class));

    Queue::assertPushed(SendQueuedNotifications::class, 3);
    $jobs = Queue::pushed(SendQueuedNotifications::class)->keyBy(fn ($job) => $job->channels[0]);
    Queue::swap($queueManager);

    return compact('organisation', 'recipient', 'project', 'task', 'pending', 'jobs');
}

test('widget alerts queue separate channels and retry mail without duplicating database alerts or widening recipients', function () {
    ['recipient' => $recipient, 'task' => $task, 'jobs' => $jobs] = widgetDeliveryFixture();
    $manager = app(ChannelManager::class);
    $jobs['database']->handle($manager);
    $mailJob = $jobs['mail'];
    expect($mailJob->notification)->toBeInstanceOf(WidgetFeedbackNotification::class)
        ->and($mailJob->tries)->toBe(3)
        ->and($mailJob->backoff())->toBe([60, 300]);

    $mailer = Mail::mailer();
    $arrayTransport = $mailer->getSymfonyTransport();
    $transport = Mockery::mock(TransportInterface::class);
    $transport->shouldReceive('send')->once()->ordered()->andThrow(new RuntimeException('Controlled mail failure'));
    $transport->shouldReceive('send')->once()->ordered()->andReturnUsing(fn ($message, $envelope) => $arrayTransport->send($message, $envelope));
    $mailer->setSymfonyTransport($transport);

    expect(fn () => $mailJob->handle($manager))->toThrow(RuntimeException::class, 'Controlled mail failure');
    $mailJob->handle($manager);

    expect($arrayTransport->messages())->toHaveCount(1)
        ->and($recipient->notifications()->count())->toBe(1)
        ->and($recipient->notifications()->first()->type)->toBe(\App\Notifications\TaskCreationNotification::class)
        ->and($mailJob->notification->broadcastType())->toBe(\App\Notifications\TaskCreationNotification::class)
        ->and($recipient->notifications()->first()->data['url'])->toBe(route('tasks.index', ['task' => $task->id]))
        ->and(TaskCollaboratorNotification::query()->count())->toBe(1);
});

test('exhausted widget mail retries produce an operational failed job without creating fallback recipients', function () {
    ['jobs' => $jobs] = widgetDeliveryFixture();
    $this->mock(ExceptionHandler::class, function ($handler) {
        $handler->shouldReceive('report')->with(Mockery::type(Throwable::class));
    });
    $transport = Mockery::mock(TransportInterface::class);
    $transport->shouldReceive('send')->times(3)->andThrow(new RuntimeException('Controlled persistent mail failure'));
    Mail::mailer()->setSymfonyTransport($transport);
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'sqlite']);
    app('queue')->connection('database')->push($jobs['mail']);

    foreach ([0, 61, 301] as $seconds) {
        $this->travel($seconds)->seconds();
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
        ])->assertSuccessful();
    }

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->first()->exception)->toContain('Controlled persistent mail failure')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(TaskCollaboratorNotification::query()->count())->toBe(1);
});

test('widget channel delivery rechecks access after being queued', function () {
    ['project' => $project, 'recipient' => $recipient, 'jobs' => $jobs] = widgetDeliveryFixture();
    $project->projectUser()->delete();
    $jobs['mail']->handle(app(ChannelManager::class));
    $jobs['database']->handle(app(ChannelManager::class));

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($recipient->notifications()->count())->toBe(0)
        ->and(TaskCollaboratorNotification::query()->count())->toBe(1);
});

test('removing a task collaborator does not cancel an alert owned by project assignment', function () {
    ['task' => $task, 'recipient' => $recipient, 'pending' => $pending] = widgetDeliveryFixture();
    $pending->forceFill(['sent_at' => null])->save();

    app(\App\Services\TaskCollaboratorNotificationScheduler::class)->scheduleCollaboratorAdded($task, [
        'removed_internal_ids' => [$recipient->id],
    ]);

    expect($pending->fresh()->cancelled_at)->toBeNull();
});

test('deleting feedback before queued channel delivery suppresses the alert', function () {
    ['task' => $task, 'recipient' => $recipient, 'jobs' => $jobs] = widgetDeliveryFixture();
    $task->delete();
    $jobs['mail']->handle(app(ChannelManager::class));
    $jobs['database']->handle(app(ChannelManager::class));

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($recipient->notifications()->count())->toBe(0);
});
