<?php

namespace Tests\Unit\Services;

use App\Services\ExternalNotificationService;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A simple notification class for testing purposes
 */
class TestNotification extends BaseNotification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->line('Test notification');
    }
}

class ExternalNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock HTTP and Notification facades
        Http::fake();
        Notification::fake();
        Log::spy();
    }

    public function test_send_notification_successful()
    {
        // Arrange
        Http::fake([
            'https://example.com/shift/api/notifications' => Http::response([
                'success' => true,
                'production' => true
            ], 200)
        ]);

        $service = new ExternalNotificationService();
        $url = 'https://example.com';
        $handler = 'test.handler';
        $payload = ['key' => 'value'];

        // Act
        $response = $service->sendNotification($url, $handler, $payload);

        // Assert
        $this->assertNotNull($response);
        $this->assertTrue($response->successful());

        Http::assertSent(function ($request) use ($url, $handler, $payload) {
            return $request->url() === $url . '/shift/api/notifications' &&
                   $request['handler'] === $handler &&
                   $request['payload'] === $payload &&
                   isset($request['source']) &&
                   $request['source']['url'] === config('app.url') &&
                   $request['source']['environment'] === app()->environment();
        });
    }

    public function test_send_notification_with_custom_source()
    {
        // Arrange
        Http::fake([
            'https://example.com/shift/api/notifications' => Http::response([
                'success' => true
            ], 200)
        ]);

        $service = new ExternalNotificationService();
        $url = 'https://example.com';
        $handler = 'test.handler';
        $payload = ['key' => 'value'];
        $source = ['custom' => 'source'];

        // Act
        $response = $service->sendNotification($url, $handler, $payload, $source);

        // Assert
        Http::assertSent(function ($request) use ($url, $source) {
            return $request->url() === $url . '/shift/api/notifications' &&
                   $request['source'] === $source;
        });
    }

    public function test_send_notification_handles_exception()
    {
        // Arrange
        Http::fake(function () {
            throw new \Exception('Test exception');
        });

        $service = new ExternalNotificationService();

        // Act
        $response = $service->sendNotification('https://example.com', 'test.handler', []);

        // Assert
        $this->assertNull($response);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_send_fallback_email_when_not_production()
    {
        // Arrange
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('json')->with('production')->willReturn(false);

        $service = new ExternalNotificationService();
        $email = 'test@example.com';
        $notification = new TestNotification();

        // Act
        $result = $service->sendFallbackEmailIfNeeded($mockResponse, $email, $notification);

        // Assert
        $this->assertTrue($result);
        Notification::assertSentOnDemand(
            TestNotification::class,
            function ($notification, $channels, $notifiable) use ($email) {
                return $notifiable->routes['mail'] === $email;
            }
        );
    }

    public function test_do_not_send_fallback_email_when_production()
    {
        // Arrange
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('json')->with('production')->willReturn(true);

        $service = new ExternalNotificationService();
        $email = 'test@example.com';
        $notification = new TestNotification();

        // Act
        $result = $service->sendFallbackEmailIfNeeded($mockResponse, $email, $notification);

        // Assert
        $this->assertFalse($result);
        Notification::assertNothingSent();
    }

    public function test_do_not_send_fallback_email_when_response_is_null()
    {
        // Arrange
        $service = new ExternalNotificationService();
        $email = 'test@example.com';
        $notification = new TestNotification();

        // Act
        $result = $service->sendFallbackEmailIfNeeded(null, $email, $notification);

        // Assert
        $this->assertFalse($result);
        Notification::assertNothingSent();
    }
}
