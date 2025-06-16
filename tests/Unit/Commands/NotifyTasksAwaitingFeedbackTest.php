<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\NotifyTasksAwaitingFeedback;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAwaitingFeedbackNotification;
use App\Services\ExternalNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class NotifyTasksAwaitingFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected $notificationService;

    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Mock the ExternalNotificationService
        $this->notificationService = Mockery::mock(ExternalNotificationService::class);
        $this->app->instance(ExternalNotificationService::class, $this->notificationService);
    }

    public function test_it_sends_notifications_to_external_users_with_tasks_awaiting_feedback()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user
        $externalUser = ExternalUser::factory()->create([
            'project_id' => $project->id,
            'url' => 'https://example.com'
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

        // Mock the response from the external API
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('json')->with('production')->andReturn(false);

        // Set up expectations for the notification service
        $this->notificationService->shouldReceive('sendNotification')
            ->once()
            ->with(
                'https://example.com',
                'tasks.awaiting_feedback',
                Mockery::on(function ($payload) use ($task) {
                    return $payload['type'] === 'tasks_awaiting_feedback' &&
                           in_array($task->id, $payload['task_ids']) &&
                           $payload['task_count'] === 1;
                })
            )
            ->andReturn($mockResponse);

        // Set up expectations for the fallback email
        $this->notificationService->shouldReceive('sendFallbackEmailIfNeeded')
            ->once()
            ->with(
                $mockResponse,
                $externalUser->email,
                Mockery::type(TaskAwaitingFeedbackNotification::class)
            )
            ->andReturn(true);

        // Run the command
        $this->artisan('tasks:notify-awaiting-feedback')
            ->expectsOutput('Checking for tasks awaiting feedback...')
            ->expectsOutput('Found 1 tasks awaiting feedback.')
            ->assertExitCode(0);
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

        // The notification service should not be called
        $this->notificationService->shouldNotReceive('sendNotification');
        $this->notificationService->shouldNotReceive('sendFallbackEmailIfNeeded');

        // Run the command
        $this->artisan('tasks:notify-awaiting-feedback')
            ->expectsOutput('Checking for tasks awaiting feedback...')
            ->expectsOutput('No tasks awaiting feedback found.')
            ->assertExitCode(0);
    }

    public function test_it_only_notifies_about_tasks_submitted_by_external_users()
    {
        // Create a project
        $project = Project::factory()->create();

        // Create an external user
        $externalUser = ExternalUser::factory()->create([
            'project_id' => $project->id,
            'url' => 'https://example.com'
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

        // Mock the response from the external API
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('json')->with('production')->andReturn(false);

        // Set up expectations for the notification service
        $this->notificationService->shouldReceive('sendNotification')
            ->once()
            ->with(
                'https://example.com',
                'tasks.awaiting_feedback',
                Mockery::on(function ($payload) use ($externalTask) {
                    return $payload['type'] === 'tasks_awaiting_feedback' &&
                           in_array($externalTask->id, $payload['task_ids']) &&
                           $payload['task_count'] === 1;
                })
            )
            ->andReturn($mockResponse);

        // Set up expectations for the fallback email
        $this->notificationService->shouldReceive('sendFallbackEmailIfNeeded')
            ->once()
            ->with(
                $mockResponse,
                $externalUser->email,
                Mockery::type(TaskAwaitingFeedbackNotification::class)
            )
            ->andReturn(true);

        // Run the command
        $this->artisan('tasks:notify-awaiting-feedback')
            ->expectsOutput('Checking for tasks awaiting feedback...')
            ->expectsOutput('Found 2 tasks awaiting feedback.')
            ->assertExitCode(0);
    }
}
