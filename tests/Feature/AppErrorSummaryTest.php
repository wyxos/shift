<?php

use App\Enums\TaskStatus;
use App\Jobs\SendAppErrorSummary;
use App\Models\AppErrorSummary;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppErrorSummaryNotification;
use App\Services\AppErrors\AppErrorSummaryBuilder;
use App\Services\AppErrors\AppErrorSummaryScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function summaryProject(array $attributes = []): Project
{
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => null,
        ...$attributes,
    ]);
    $project->appErrorNotificationUsers()->attach($owner->id);

    return $project->refresh();
}

function summaryTask(Project $project, array $attributes = []): Task
{
    return Task::factory()->for($project)->create([
        'status' => TaskStatus::Pending->value,
        'error_signature' => bin2hex(random_bytes(32)),
        'error_environment' => 'development',
        ...$attributes,
    ]);
}

function summaryEvent(Task $task, string $receivedAt): void
{
    $task->errorOccurrences()->create([
        'number' => $task->errorOccurrences()->count() + 1,
        'source' => 'backend',
        'received_at' => $receivedAt,
        'payload' => [],
    ]);
}

function summaryRun(Project $project, array $attributes = []): AppErrorSummary
{
    return AppErrorSummary::query()->create([
        'project_id' => $project->id,
        'scheduled_for' => '2026-10-30 09:00:00',
        'summary_time' => $project->app_error_daily_summary_time,
        'summary_timezone' => $project->app_error_daily_summary_timezone,
        ...$attributes,
    ]);
}

it('schedules one delayed digest per project and due time without a recurring scheduler', function () {
    Bus::fake([SendAppErrorSummary::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 08:00:00', 'UTC'));
    $project = summaryProject();
    $scheduler = app(AppErrorSummaryScheduler::class);

    $scheduler->schedule($project);
    $scheduler->schedule($project);

    expect(AppErrorSummary::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(SendAppErrorSummary::class, 1);
    Bus::assertDispatched(SendAppErrorSummary::class, fn ($job) => $job->afterCommit
        && $job->delay->format('Y-m-d H:i:s') === '2026-10-29 09:00:00');
});

it('does not schedule disabled summaries', function () {
    Bus::fake([SendAppErrorSummary::class]);
    app(AppErrorSummaryScheduler::class)->schedule(summaryProject(['app_error_daily_summary_enabled' => false]));

    expect(AppErrorSummary::query()->count())->toBe(0);
    Bus::assertNotDispatched(SendAppErrorSummary::class);
});

it('schedules at the configured local time across daylight savings changes', function () {
    $project = summaryProject(['app_error_daily_summary_timezone' => 'America/New_York']);
    $scheduler = app(AppErrorSummaryScheduler::class);
    $this->travelTo(CarbonImmutable::parse('2026-03-07 15:00:00', 'UTC'));

    expect($scheduler->nextTime($project)->format('Y-m-d H:i:s'))->toBe('2026-03-08 13:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-10-31 15:00:00', 'UTC'));
    expect($scheduler->nextTime($project)->format('Y-m-d H:i:s'))->toBe('2026-11-01 14:00:00');
});

it('groups received events by active task and excludes unrelated resolved and out-of-window activity', function () {
    $project = summaryProject();
    $task = summaryTask($project);
    summaryEvent($task, '2026-10-29 09:00:00');
    summaryEvent($task, '2026-10-30 08:59:59');
    summaryEvent($task, '2026-10-29 08:59:59');
    summaryEvent($task, '2026-10-30 09:00:00');
    summaryEvent(summaryTask($project, ['status' => TaskStatus::Completed->value]), '2026-10-30 08:00:00');
    summaryEvent(summaryTask(summaryProject()), '2026-10-30 08:00:00');
    summaryEvent(summaryTask($project, ['error_signature' => null]), '2026-10-30 08:00:00');

    $summary = app(AppErrorSummaryBuilder::class)->build($project, CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));

    expect($summary['task_count'])->toBe(1)
        ->and($summary['event_count'])->toBe(2)
        ->and($summary['tasks'])->toHaveCount(1)
        ->and($summary['tasks'][0])->toMatchArray(['id' => $task->id, 'event_count' => 2]);
});

it('bounds task materialization while retaining accurate totals and a fixed query count', function () {
    $project = summaryProject();
    foreach (range(1, 30) as $index) {
        $task = summaryTask($project);
        summaryEvent($task, '2026-10-30 08:00:00');
        summaryEvent($task, '2026-10-30 08:30:00');
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $summary = app(AppErrorSummaryBuilder::class)->build($project, CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($summary['task_count'])->toBe(30)
        ->and($summary['event_count'])->toBe(60)
        ->and($summary['tasks'])->toHaveCount(20)
        ->and($queries)->toHaveCount(3);
});

it('dispatches a summary once and does not schedule a silent following day', function () {
    Notification::fake();
    Bus::fake([SendAppErrorSummary::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $project = summaryProject();
    summaryEvent(summaryTask($project), '2026-10-30 08:00:00');
    $run = summaryRun($project);
    $job = new SendAppErrorSummary($run->id);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Notification::assertSentToTimes($project->author, AppErrorSummaryNotification::class, 1);
    expect($run->fresh()->completed_at)->not->toBeNull();
    Bus::assertNotDispatched(SendAppErrorSummary::class);
});

it('suppresses summaries for no activity no recipients or disabled reporting', function (string $scenario) {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $project = summaryProject();
    $run = summaryRun($project);
    if ($scenario !== 'empty') {
        summaryEvent(summaryTask($project), '2026-10-30 08:00:00');
    }
    if ($scenario === 'recipients') {
        $project->appErrorNotificationUsers()->detach();
    }
    if ($scenario === 'disabled') {
        $project->update(['app_error_daily_summary_enabled' => false]);
    }

    app()->call([new SendAppErrorSummary($run->id), 'handle']);

    Notification::assertNothingSent();
})->with(['empty', 'recipients', 'disabled']);

it('cancels stale schedule settings and replans the next configured time', function () {
    Notification::fake();
    Bus::fake([SendAppErrorSummary::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $project = summaryProject();
    $run = summaryRun($project);
    $project->update(['app_error_daily_summary_time' => '10:00']);

    app()->call([new SendAppErrorSummary($run->id), 'handle']);

    Notification::assertNothingSent();
    expect($run->fresh()->cancelled_at)->not->toBeNull();
    Bus::assertDispatched(SendAppErrorSummary::class, fn ($job) => $job->delay->format('H:i') === '10:00');
});

it('rechecks current recipient eligibility and summary activation before queued email delivery', function () {
    $project = summaryProject();
    $recipient = $project->author;
    $notification = new AppErrorSummaryNotification($project->id, $project->name, CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'), 'UTC', [
        'task_count' => 1, 'event_count' => 1, 'tasks' => [],
    ]);

    expect($notification->shouldSend($recipient, 'mail'))->toBeTrue();
    $project->appErrorNotificationUsers()->detach();
    expect($notification->shouldSend($recipient, 'mail'))->toBeFalse();
    $project->appErrorNotificationUsers()->attach($recipient->id);
    $project->update(['app_error_daily_summary_enabled' => false]);
    expect($notification->shouldSend($recipient, 'mail'))->toBeFalse();
});

it('renders grouped event counts and task links without raw event payloads', function () {
    $project = summaryProject(['name' => 'Example']);
    $notification = new AppErrorSummaryNotification($project->id, $project->name, CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'), 'Asia/Dubai', [
        'task_count' => 1,
        'event_count' => 3,
        'tasks' => [[
            'id' => 281, 'title' => 'Exception at app/SalesForce.php:311', 'environment' => 'development',
            'event_count' => 3, 'last_seen_at' => '2026-10-30T08:00:00+00:00',
        ]],
    ]);
    $mail = $notification->toMail($project->author);
    $html = (string) $mail->render();

    expect($notification->via($project->author))->toBe(['mail', 'database', 'broadcast'])
        ->and($mail->subject)->toBe('Example · Daily error summary')
        ->and($html)->toContain('Exception at app/SalesForce.php:311', 'View error:', 'task=281')
        ->and(implode(' ', $mail->introLines))->toContain('1 active errors · 3 new events', 'Asia/Dubai', 'task=281');
});

it('removes pending summary records with their project', function () {
    $project = summaryProject();
    $run = summaryRun($project);
    $project->delete();

    expect(AppErrorSummary::query()->find($run->id))->toBeNull();
    app()->call([new SendAppErrorSummary($run->id), 'handle']);
});

it('recovers a failed queue publication when the next event schedules the same slot', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 08:00:00', 'UTC'));
    $project = summaryProject();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));

    expect(fn () => app(AppErrorSummaryScheduler::class)->schedule($project))->toThrow(RuntimeException::class, 'Queue unavailable');
    $run = AppErrorSummary::query()->sole();
    expect($run->dispatched_at)->toBeNull()->and($run->dispatch_requested_at)->toBeNull();

    Bus::fake([SendAppErrorSummary::class]);
    app(AppErrorSummaryScheduler::class)->schedule($project);

    Bus::assertDispatchedTimes(SendAppErrorSummary::class, 1);
    expect(AppErrorSummary::query()->count())->toBe(1)->and($run->fresh()->dispatched_at)->not->toBeNull();
});

it('revives a cancelled future slot when settings return to that slot', function () {
    Bus::fake([SendAppErrorSummary::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 08:00:00', 'UTC'));
    $project = summaryProject();
    $run = summaryRun($project, ['cancelled_at' => now(), 'dispatched_at' => now(), 'delivery_started_at' => now()]);

    app(AppErrorSummaryScheduler::class)->schedule($project);

    expect($run->fresh()->cancelled_at)->toBeNull()->and($run->fresh()->delivery_started_at)->toBeNull();
    Bus::assertDispatchedTimes(SendAppErrorSummary::class, 1);
});

it('retries summary publication after notification queue dispatch fails', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $project = summaryProject();
    summaryEvent(summaryTask($project), '2026-10-30 08:00:00');
    $run = summaryRun($project);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue unavailable'));

    expect(fn () => app()->call([new SendAppErrorSummary($run->id), 'handle']))->toThrow(RuntimeException::class);
    expect($run->fresh()->completed_at)->toBeNull()->and($run->fresh()->delivery_started_at)->toBeNull();

    Notification::fake();
    app()->call([new SendAppErrorSummary($run->id), 'handle']);
    Notification::assertSentToTimes($project->author, AppErrorSummaryNotification::class, 1);
});

it('does not send a second summary on a completed local day after a time change', function () {
    Bus::fake([SendAppErrorSummary::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:30:00', 'UTC'));
    $project = summaryProject(['app_error_daily_summary_time' => '10:00']);
    summaryRun($project, ['completed_at' => now()]);

    app(AppErrorSummaryScheduler::class)->schedule($project);

    Bus::assertDispatched(SendAppErrorSummary::class, fn ($job) => $job->delay->format('Y-m-d H:i:s') === '2026-10-31 10:00:00');
});

it('uses the previous delivered boundary across a DST change without counting a boundary event twice', function () {
    $project = summaryProject(['app_error_daily_summary_timezone' => 'America/New_York']);
    summaryRun($project, ['scheduled_for' => '2026-10-31 13:00:00', 'completed_at' => '2026-10-31 13:00:01']);
    $task = summaryTask($project);
    summaryEvent($task, '2026-10-31 13:00:00');
    summaryEvent($task, '2026-10-31 13:30:00');
    summaryEvent($task, '2026-11-01 14:00:00');

    $summary = app(AppErrorSummaryBuilder::class)->build($project, CarbonImmutable::parse('2026-11-01 14:00:00', 'UTC'));

    expect($summary['window_start'])->toBe('2026-10-31T13:00:00+00:00')->and($summary['event_count'])->toBe(2);
});

it('runs the delayed digest through the actual database queue worker', function () {
    Notification::fake();
    config(['queue.default' => 'database']);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 08:30:00', 'UTC'));
    $project = summaryProject();
    summaryEvent(summaryTask($project), '2026-10-30 08:00:00');
    app(AppErrorSummaryScheduler::class)->schedule($project);
    expect(DB::table('jobs')->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();

    Notification::assertSentToTimes($project->author, AppErrorSummaryNotification::class, 1);
    expect(AppErrorSummary::query()->sole()->completed_at)->not->toBeNull()->and(DB::table('jobs')->count())->toBe(0);
});

it('recovers a crashed delivery on its final queue attempt after waiting for the lease to expire', function () {
    Notification::fake();
    config(['queue.default' => 'database']);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:01:00', 'UTC'));
    $project = summaryProject();
    summaryEvent(summaryTask($project), '2026-10-30 08:00:00');
    $run = summaryRun($project, ['delivery_started_at' => '2026-10-30 09:00:00']);
    SendAppErrorSummary::dispatch($run->id);
    DB::table('jobs')->update(['attempts' => 1]);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3])->assertSuccessful();

    $queued = DB::table('jobs')->sole();
    expect($queued->attempts)->toBe(2)
        ->and($queued->available_at)->toBe(CarbonImmutable::parse('2026-10-30 09:05:01', 'UTC')->timestamp);
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::createFromTimestampUTC($queued->available_at));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3])->assertSuccessful();

    Notification::assertSentToTimes($project->author, AppErrorSummaryNotification::class, 1);
    expect($run->fresh()->completed_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('stores a summary payload usable by the notification list and realtime badge', function () {
    $project = summaryProject(['name' => 'Example']);
    $recipient = $project->author;
    $notification = new AppErrorSummaryNotification($project->id, $project->name, CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'), 'UTC', [
        'task_count' => 2,
        'event_count' => 19,
        'tasks' => [],
        'window_start' => '2026-10-29T09:00:00+00:00',
    ]);
    Notification::sendNow($recipient, $notification, ['database']);
    $stored = $recipient->notifications()->sole();

    expect($stored->type)->toBe(AppErrorSummaryNotification::class)
        ->and($stored->data)->toMatchArray([
            'task_title' => 'Example · Daily error summary',
            'project_id' => $project->id,
            'project_name' => 'Example',
            'url' => route('app-errors.index', ['project_id' => $project->id]),
            'reason' => 'daily_summary',
            'task_count' => 2,
            'event_count' => 19,
        ])
        ->and($stored->data)->not->toHaveKey('task_id')
        ->and($notification->toArray($recipient))->toBe($stored->data);

    $this->actingAs($recipient)->getJson(route('notifications.unread'))
        ->assertOk()
        ->assertJsonPath('notifications.0.type', 'AppErrorSummaryNotification')
        ->assertJsonPath('notifications.0.data.task_title', 'Example · Daily error summary')
        ->assertJsonPath('notifications.0.data.url', route('app-errors.index', ['project_id' => $project->id]));
});
