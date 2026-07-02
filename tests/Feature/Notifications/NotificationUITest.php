<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use Illuminate\Notifications\DatabaseNotification;

test('notification endpoints return correct data', function () {
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
        'count',
    ]);
    $response->assertJson([
        'count' => 1,
    ]);

    // Get the notification ID from the response
    $notificationId = $response->json('notifications.0.id');

    // Test marking a notification as read
    $markAsReadResponse = $this->post(route('notifications.mark-as-read', ['id' => $notificationId]));
    $markAsReadResponse->assertStatus(200);
    $markAsReadResponse->assertJson([
        'success' => true,
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
        'success' => true,
    ]);

    // Verify all notifications are marked as read
    expect($user->unreadNotifications()->count())->toEqual(0);
});

test('notifications index page loads', function () {
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
});

test('marking notification as unread', function () {
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
        'success' => true,
    ]);

    // Verify the notification is now marked as unread
    $this->assertDatabaseHas('notifications', [
        'id' => $notificationId,
        'read_at' => null,
    ]);

    // Verify it appears in the unread notifications count
    expect($user->unreadNotifications()->count())->toEqual(1);
});

test('opening a task detail marks matching task notifications as read', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = Task::factory()->create();
    $task->submitter()->associate($user)->save();

    $otherTask = Task::factory()->create();
    $otherTask->submitter()->associate($user)->save();

    $user->notify(new TaskCreationNotification($task));
    $user->notify(new TaskCreationNotification($otherTask));

    $matchingNotification = $user->unreadNotifications()
        ->get()
        ->first(fn (DatabaseNotification $notification) => (int) $notification->data['task_id'] === $task->id);
    $otherNotification = $user->unreadNotifications()
        ->get()
        ->first(fn (DatabaseNotification $notification) => (int) $notification->data['task_id'] === $otherTask->id);

    $this->getJson(route('tasks.show', ['task' => $task->id]))
        ->assertOk();

    expect($matchingNotification->refresh()->read_at)->not->toBeNull()
        ->and($otherNotification->refresh()->read_at)->toBeNull();
});

test('missing task detail route returns a friendly not found message', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->getJson(route('tasks.show', ['task' => 999999]))
        ->assertNotFound()
        ->assertJson([
            'message' => 'This task is no longer available or you do not have access to it.',
        ]);
});
