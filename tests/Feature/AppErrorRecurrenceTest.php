<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppErrorReportedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    Queue::fake();
    $this->travelTo(now()->startOfDay()->addHours(9));
    $this->alertUser = User::factory()->create();
    $this->alertProject = Project::factory()->withAuthor($this->alertUser->id)->create(['token' => 'recurrence-project']);
    $this->alertProject->appErrorNotificationUsers()->attach($this->alertUser);
    $this->withToken($this->alertUser->createToken('sdk')->plainTextToken);
    $this->alertPayload = [
        'project' => 'recurrence-project',
        'source' => 'backend',
        'environment' => 'development',
        'exception' => ['class' => RuntimeException::class, 'message' => 'Example failure'],
        'stacktrace' => ['frames' => [['file' => 'app/Example.php', 'line' => 12, 'in_app' => true]]],
    ];
});

it('alerts once when an open error returns at the default one hour boundary', function () {
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $this->travel(1)->hour();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $this->travel(1)->second();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();

    $alerts = Notification::sent($this->alertUser, AppErrorReportedNotification::class);
    expect($alerts)->toHaveCount(2)
        ->and($alerts->last()->toArray($this->alertUser)['reason'])->toBe('recurring')
        ->and(Task::query()->sole()->status)->toBe('pending')
        ->and(Task::query()->sole()->error_occurrences_count)->toBe(3);
});

it('measures silence from the latest received event rather than the latest alert', function () {
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    foreach ([59, 59, 59] as $minutes) {
        $this->travel($minutes)->minutes();
        $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    }
    Notification::assertSentToTimes($this->alertUser, AppErrorReportedNotification::class, 1);
    $this->travel(60)->minutes();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    Notification::assertSentToTimes($this->alertUser, AppErrorReportedNotification::class, 2);
});

it('uses the project quiet period and ignores client occurrence timestamps', function () {
    $this->alertProject->update(['app_error_quiet_hours' => 3]);
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $this->travel(2)->hours();
    $this->postJson('/api/errors', [...$this->alertPayload, 'occurred_at' => now()->addYear()->toIso8601String()])->assertCreated();
    Notification::assertSentToTimes($this->alertUser, AppErrorReportedNotification::class, 1);
    $this->travel(3)->hours();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    Notification::assertSentToTimes($this->alertUser, AppErrorReportedNotification::class, 2);
});

it('can disable recurrence while keeping creation and reopening alerts', function () {
    $this->alertProject->update(['app_error_recurrence_enabled' => false]);
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $this->travel(2)->hours();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    Notification::assertSentToTimes($this->alertUser, AppErrorReportedNotification::class, 1);
    Task::query()->sole()->update(['status' => 'completed']);
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $alerts = Notification::sent($this->alertUser, AppErrorReportedNotification::class);
    expect($alerts)->toHaveCount(2)
        ->and($alerts->last()->toArray($this->alertUser)['reason'])->toBe('reopened');
});

it('exposes notification history without claiming queued mail was delivered', function () {
    $response = $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $task = Task::query()->findOrFail($response->json('task.id'));
    $this->actingAs($this->alertUser)->getJson(route('task-error-occurrences.index', $task))
        ->assertOk()
        ->assertJsonPath('occurrences.0.notification.reason', 'created')
        ->assertJsonPath('occurrences.0.notification.status', 'queued');
});

it('keeps recording events when no recipient is configured', function () {
    $this->alertProject->appErrorNotificationUsers()->detach();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    Notification::assertNothingSent();
    expect(Task::query()->sole()->errorOccurrences()->sole()->notification_status)->toBe('no_recipients');
});

it('cancels a queued recurrence when recurrence alerts are disabled before delivery', function () {
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $this->travel(1)->hour();
    $this->postJson('/api/errors', $this->alertPayload)->assertCreated();
    $alerts = Notification::sent($this->alertUser, AppErrorReportedNotification::class);
    $recurrence = $alerts->last();
    expect($recurrence->shouldSend($this->alertUser, 'mail'))->toBeTrue();
    $this->alertProject->update(['app_error_recurrence_enabled' => false]);
    expect($recurrence->shouldSend($this->alertUser, 'mail'))->toBeFalse()
        ->and($alerts->first()->shouldSend($this->alertUser, 'mail'))->toBeTrue();
});
