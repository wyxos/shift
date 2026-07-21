<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('daily maintenance commands are registered with production safeguards', function () {
    $events = collect(app(Schedule::class)->events());

    foreach (['attachments:clean-temp', 'tasks:notify-awaiting-feedback'] as $command) {
        $commandEvents = $events->filter(
            fn (Event $event): bool => str_contains((string) $event->command, $command),
        );

        expect($commandEvents)->toHaveCount(1);

        $event = $commandEvents->sole();

        expect($event->expression)->toBe('0 0 * * *')
            ->and($event->timezone)->toBe(config('app.timezone'))
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->expiresAt)->toBe(60);
    }
});
