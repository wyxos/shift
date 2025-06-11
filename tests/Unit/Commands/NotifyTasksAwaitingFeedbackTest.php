<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\NotifyTasksAwaitingFeedback;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAwaitingFeedbackNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyTasksAwaitingFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_it_sends_notifications_to_external_users_with_tasks_awaiting_feedback()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user
        $externalUser = ExternalUser::factory()->create([
            'project_id' => $project->id
        ]);

        // Create a task with awaiting-feedback status submitted by the external user
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'awaiting-feedback',
            'submitter_type' => ExternalUser::class,
            'submitter_id' => $externalUser->id
        ]);

        // Create another task with a different status (should not trigger notification)
        $otherTask = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'in-progress',
            'submitter_type' => ExternalUser::class,
            'submitter_id' => $externalUser->id
        ]);

        // Run the command
        $this->artisan('tasks:notify-awaiting-feedback')
            ->expectsOutput('Checking for tasks awaiting feedback...')
            ->expectsOutput('Found 1 tasks awaiting feedback.')
            ->assertExitCode(0);

        // Assert notification was sent to the external user
        Notification::assertSentTo(
            $externalUser,
            TaskAwaitingFeedbackNotification::class,
            function ($notification, $channels) use ($task) {
                return in_array('mail', $channels);
            }
        );
    }

    public function test_it_does_not_send_notifications_when_no_tasks_are_awaiting_feedback()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user
        $externalUser = ExternalUser::factory()->create([
            'project_id' => $project->id
        ]);

        // Create a task with a status other than awaiting-feedback
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'in-progress',
            'submitter_type' => ExternalUser::class,
            'submitter_id' => $externalUser->id
        ]);

        // Run the command
        $this->artisan('tasks:notify-awaiting-feedback')
            ->expectsOutput('Checking for tasks awaiting feedback...')
            ->expectsOutput('No tasks awaiting feedback found.')
            ->assertExitCode(0);

        // Assert no notification was sent
        Notification::assertNothingSent();
    }

    public function test_it_only_notifies_about_tasks_submitted_by_external_users()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user
        $externalUser = ExternalUser::factory()->create([
            'project_id' => $project->id
        ]);

        // Create a regular user
        $user = User::factory()->create();

        // Create a task with awaiting-feedback status submitted by the external user
        $externalTask = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'awaiting-feedback',
            'submitter_type' => ExternalUser::class,
            'submitter_id' => $externalUser->id
        ]);

        // Create a task with awaiting-feedback status submitted by a regular user
        $internalTask = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'awaiting-feedback',
            'submitter_type' => User::class,
            'submitter_id' => $user->id
        ]);

        // Run the command
        $this->artisan('tasks:notify-awaiting-feedback')
            ->expectsOutput('Checking for tasks awaiting feedback...')
            ->expectsOutput('Found 2 tasks awaiting feedback.')
            ->assertExitCode(0);

        // Assert notification was sent only for the external task
        Notification::assertSentTo(
            $externalUser,
            TaskAwaitingFeedbackNotification::class,
            function ($notification, $channels) use ($externalTask) {
                return in_array('mail', $channels);
            }
        );
    }
}
