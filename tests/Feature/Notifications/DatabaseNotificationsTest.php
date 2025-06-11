<?php

namespace Tests\Feature\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\TestCase;

class DatabaseNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_stored_in_database(): void
    {
        // Create a user and a task
        $user = User::factory()->create();
        $task = Task::factory()->create();

        // Send notification
        $user->notify(new TaskCreationNotification($task));

        // Check if notification exists in database
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
            'type' => TaskCreationNotification::class,
        ]);

        // Check if notification data is correct
        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);

        $data = is_array($notification->data) ? $notification->data : json_decode($notification->data, true);
        $this->assertEquals($task->id, $data['task_id']);
        $this->assertEquals($task->title, $data['task_title']);
    }

    public function test_all_notification_classes_use_database_channel(): void
    {
        // Mock the notification to avoid actually sending it
        Notification::fake();

        // Create a user
        $user = User::factory()->create();

        // Get all notification classes in the app namespace
        $notificationClasses = collect(glob(app_path('Notifications/*.php')))
            ->map(function ($file) {
                $className = basename($file, '.php');
                return 'App\\Notifications\\' . $className;
            })
            ->filter(function ($class) {
                return class_exists($class);
            });

        // For each notification class, check if it uses the database channel
        foreach ($notificationClasses as $notificationClass) {
            // Create a reflection class to access the via method
            $reflection = new ReflectionClass($notificationClass);

            // Skip abstract classes
            if ($reflection->isAbstract()) {
                continue;
            }

            // Get the via method
            $viaMethod = $reflection->getMethod('via');

            // Create a mock notification instance
            $mockNotification = $this->getMockBuilder($notificationClass)
                ->disableOriginalConstructor()
                ->getMock();

            // Mock the via method to return its actual implementation
            $mockNotification->method('via')
                ->willReturnCallback(function ($notifiable) use ($viaMethod, $mockNotification) {
                    return $viaMethod->invoke($mockNotification, $notifiable);
                });

            // Check if the via method returns an array containing 'database'
            $channels = $mockNotification->via($user);
            $this->assertContains('database', $channels, "Notification class {$notificationClass} does not use the database channel");
        }
    }
}
