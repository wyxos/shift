<?php

namespace Tests\Feature\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationUITest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_endpoints_return_correct_data(): void
    {
        // Create a user and authenticate
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a task
        $task = Task::factory()->create();

        // Send notification to the user
        $user->notify(new TaskCreationNotification($task));

        // Test the unread notifications endpoint
        $response = $this->get(route('notifications.unread'));
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'notifications',
            'count'
        ]);
        $response->assertJson([
            'count' => 1
        ]);

        // Get the notification ID from the response
        $notificationId = $response->json('notifications.0.id');

        // Test marking a notification as read
        $markAsReadResponse = $this->post(route('notifications.mark-as-read', ['id' => $notificationId]));
        $markAsReadResponse->assertStatus(200);
        $markAsReadResponse->assertJson([
            'success' => true
        ]);

        // Verify the notification is now marked as read
        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'read_at' => now()->startOfSecond(),
        ]);

        // Send another notification
        $user->notify(new TaskCreationNotification($task));

        // Test marking all notifications as read
        $markAllAsReadResponse = $this->post(route('notifications.mark-all-as-read'));
        $markAllAsReadResponse->assertStatus(200);
        $markAllAsReadResponse->assertJson([
            'success' => true
        ]);

        // Verify all notifications are marked as read
        $this->assertEquals(0, $user->unreadNotifications()->count());
    }

    public function test_notifications_index_page_loads(): void
    {
        // Create a user and authenticate
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a task
        $task = Task::factory()->create();

        // Send notification to the user
        $user->notify(new TaskCreationNotification($task));

        // Test the notifications index page
        $response = $this->get(route('notifications.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Notifications/Index')
            ->has('notifications')
        );
    }

    public function test_marking_notification_as_unread(): void
    {
        // Create a user and authenticate
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a task
        $task = Task::factory()->create();

        // Send notification to the user
        $user->notify(new TaskCreationNotification($task));

        // Get the notification ID
        $notification = $user->notifications()->first();
        $notificationId = $notification->id;

        // Mark the notification as read first
        $this->post(route('notifications.mark-as-read', ['id' => $notificationId]));

        // Verify the notification is marked as read
        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'read_at' => now()->startOfSecond(),
        ]);

        // Test marking the notification as unread
        $markAsUnreadResponse = $this->post(route('notifications.mark-as-unread', ['id' => $notificationId]));
        $markAsUnreadResponse->assertStatus(200);
        $markAsUnreadResponse->assertJson([
            'success' => true
        ]);

        // Verify the notification is now marked as unread
        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'read_at' => null,
        ]);

        // Verify it appears in the unread notifications count
        $this->assertEquals(1, $user->unreadNotifications()->count());
    }
}
