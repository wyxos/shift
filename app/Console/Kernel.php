<?php

namespace App\Console;

use App\Console\Commands\CleanTempAttachments;
use App\Console\Commands\NotifyTasksAwaitingFeedback;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        CleanTempAttachments::class,
        NotifyTasksAwaitingFeedback::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Clean temporary attachments daily
        $schedule->command('attachments:clean-temp')->daily();

        // Check for tasks awaiting feedback and notify external users daily
        $schedule->command('tasks:notify-awaiting-feedback')->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
