<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

test('temporary attachment cleanup is registered with production safeguards', function () {
    $events = collect(app(Schedule::class)->events());
    $cleanupEvents = $events->filter(
        fn (Event $event): bool => str_contains((string) $event->command, 'attachments:clean-temp'),
    );

    expect($cleanupEvents)->toHaveCount(1);

    $event = $cleanupEvents->sole();

    expect($event->expression)->toBe('0 0 * * *')
        ->and($event->timezone)->toBe(config('app.timezone'))
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

test('awaiting feedback reminder command is not registered', function () {
    $events = collect(app(Schedule::class)->events());

    expect(Artisan::all())->not->toHaveKey('tasks:notify-awaiting-feedback')
        ->and($events->contains(
            fn (Event $event): bool => str_contains((string) $event->command, 'tasks:notify-awaiting-feedback'),
        ))->toBeFalse();
});
