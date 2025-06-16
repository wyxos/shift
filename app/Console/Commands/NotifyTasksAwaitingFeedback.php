<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\ExternalUser;
use App\Notifications\TaskAwaitingFeedbackNotification;
use App\Services\ExternalNotificationService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyTasksAwaitingFeedback extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:notify-awaiting-feedback';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify external users about tasks that are awaiting their feedback';

    /**
     * Execute the console command.
     */
    public function handle(ExternalNotificationService $notificationService)
    {
        $this->info('Checking for tasks awaiting feedback...');

        // Get all tasks with 'awaiting-feedback' status
        $tasks = Task::withStatus('awaiting-feedback')->get();

        if ($tasks->isEmpty()) {
            $this->info('No tasks awaiting feedback found.');
            return 0;
        }

        $this->info('Found ' . $tasks->count() . ' tasks awaiting feedback.');

        // Group tasks by external user
        $tasksByExternalUser = [];

        /** @var Task $task */
        foreach ($tasks as $task) {
            // Only process tasks submitted by external users
            if (!$task->isExternallySubmitted()) {
                continue;
            }

            $externalUser = $task->submitter;

            if (!isset($tasksByExternalUser[$externalUser->id])) {
                $tasksByExternalUser[$externalUser->id] = [
                    'user' => $externalUser,
                    'tasks' => []
                ];
            }

            $tasksByExternalUser[$externalUser->id]['tasks'][] = $task;
        }

        // Send notifications to each external user
        foreach ($tasksByExternalUser as $userData) {
            $externalUser = $userData['user'];
            $userTasks = $userData['tasks'];

            if (empty($userTasks)) {
                continue;
            }

            try {
                // Prepare task IDs for the payload
                $taskIds = collect($userTasks)->pluck('id')->toArray();

                // Prepare payload for external notification
                $payload = [
                    'type' => 'tasks_awaiting_feedback',
                    'user_id' => $externalUser->external_id,
                    'task_ids' => $taskIds,
                    'task_count' => count($userTasks)
                ];

                // Send notification to the external API
                $response = $notificationService->sendNotification(
                    $externalUser->url,
                    'tasks.awaiting_feedback',
                    $payload
                );

                // Generate URL with filter for awaiting-feedback tasks in the consuming app
                $url = $externalUser->url . '/shift/?status=awaiting-feedback';

                // Send fallback email notification if the app is not in production
                $notificationService->sendFallbackEmailIfNeeded(
                    $response,
                    $externalUser->email,
                    new TaskAwaitingFeedbackNotification($userTasks, $url)
                );

                $this->info('Sent notification to ' . $externalUser->email . ' about ' . count($userTasks) . ' tasks.');

                // Log the notification
                Log::info('Sent awaiting feedback notification', [
                    'external_user_id' => $externalUser->id,
                    'external_user_email' => $externalUser->email,
                    'task_count' => count($userTasks),
                    'task_ids' => $taskIds
                ]);
            } catch (Exception $e) {
                $this->error('Failed to send notification to ' . $externalUser->email . ': ' . $e->getMessage());

                Log::error('Failed to send awaiting feedback notification', [
                    'external_user_id' => $externalUser->id,
                    'external_user_email' => $externalUser->email,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info('Notification process completed.');

        return 0;
    }
}
