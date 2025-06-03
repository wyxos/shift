<?php

namespace Tests\Feature;

use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskThreadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $task;
    protected $externalUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user
        $this->user = User::factory()->create();

        // Create an external user
        $this->externalUser = ExternalUser::factory()->create([
            'external_id' => 'ext-123',
            'environment' => 'testing',
            'url' => 'https://example.com',
            'name' => 'External Test User',
            'email' => 'external@example.com',
        ]);

        // Create a task submitted by the external user
        $this->task = Task::factory()->create();
        $this->task->submitter()->associate($this->externalUser)->save();
    }

    public function test_external_thread_creation_sends_notification_to_external_user_in_non_production()
    {
        Notification::fake();

        // Mock the HTTP call to the external system
        Http::fake([
            'https://example.com/shift/api/notifications' => Http::response([
                'success' => true,
                'production' => false // This will trigger the notification
            ], 200)
        ]);

        // Create a thread message as the authenticated user
        $response = $this->actingAs($this->user)
            ->postJson(route('task-threads.store', $this->task), [
                'content' => 'This is a test message',
                'type' => 'external',
            ]);

        $response->assertStatus(201);

        // Assert that a notification was sent to the external user
        Notification::assertSentOnDemand(
            TaskThreadUpdated::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === $this->externalUser->email;
            }
        );
    }

    public function test_external_thread_creation_does_not_send_notification_in_production()
    {
        Notification::fake();

        // Mock the HTTP call to the external system
        Http::fake([
            'https://example.com/shift/api/notifications' => Http::response([
                'success' => true,
                'production' => true // This will prevent the notification
            ], 200)
        ]);

        // Create a thread message as the authenticated user
        $response = $this->actingAs($this->user)
            ->postJson(route('task-threads.store', $this->task), [
                'content' => 'This is a test message',
                'type' => 'external',
            ]);

        $response->assertStatus(201);

        // Assert that no notification was sent
        Notification::assertNothingSent();
    }

    public function test_internal_thread_creation_does_not_send_notification()
    {
        Notification::fake();

        // Create a thread message as the authenticated user
        $response = $this->actingAs($this->user)
            ->postJson(route('task-threads.store', $this->task), [
                'content' => 'This is an internal test message',
                'type' => 'internal',
            ]);

        $response->assertStatus(201);

        // Assert that no notification was sent
        Notification::assertNothingSent();
    }

    public function test_external_thread_creation_with_non_external_submitter_sends_notification_to_external_users()
    {
        Notification::fake();

        // Create a task submitted by a regular user (not an external user)
        $regularUser = User::factory()->create();
        $task = Task::factory()->create();
        $task->submitter()->associate($regularUser)->save();

        // Add an external user with access to the task
        $task->externalUsers()->attach($this->externalUser);

        // Mock the HTTP call to the external system
        Http::fake([
            'https://example.com/shift/api/notifications' => Http::response([
                'success' => true,
                'production' => false // This will trigger the notification
            ], 200)
        ]);

        // Create a thread message as the authenticated user
        $response = $this->actingAs($this->user)
            ->postJson(route('task-threads.store', $task), [
                'content' => 'This is a test message',
                'type' => 'external',
            ]);

        $response->assertStatus(201);

        // Assert that a notification was sent to the external user
        Notification::assertSentOnDemand(
            TaskThreadUpdated::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === $this->externalUser->email;
            }
        );
    }

    public function test_external_thread_creation_sends_notification_to_multiple_external_users()
    {
        Notification::fake();

        // Create another external user
        $anotherExternalUser = ExternalUser::factory()->create([
            'external_id' => 'ext-456',
            'environment' => 'testing',
            'url' => 'https://another-example.com',
            'name' => 'Another External User',
            'email' => 'another-external@example.com',
        ]);

        // Add both external users to the task
        $this->task->externalUsers()->attach([$this->externalUser->id, $anotherExternalUser->id]);

        // Mock the HTTP calls to both external systems
        Http::fake([
            'https://example.com/shift/api/notifications' => Http::response([
                'success' => true,
                'production' => false // This will trigger the notification
            ], 200),
            'https://another-example.com/shift/api/notifications' => Http::response([
                'success' => true,
                'production' => false // This will trigger the notification
            ], 200)
        ]);

        // Create a thread message as the authenticated user
        $response = $this->actingAs($this->user)
            ->postJson(route('task-threads.store', $this->task), [
                'content' => 'This is a test message',
                'type' => 'external',
            ]);

        $response->assertStatus(201);

        // Assert that notifications were sent to both external users
        Notification::assertSentOnDemand(
            TaskThreadUpdated::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === $this->externalUser->email;
            }
        );

        Notification::assertSentOnDemand(
            TaskThreadUpdated::class,
            function ($notification, $channels, $notifiable) use ($anotherExternalUser) {
                return $notifiable->routes['mail'] === $anotherExternalUser->email;
            }
        );
    }
}
