<?php

// Uses configured globally in tests/Pest.php for Unit suite

use App\Exceptions\ExternalNotificationException;
use App\Services\ExternalNotificationService;
use Illuminate\Notifications\Notification as BaseNotification;

class TestNotification extends BaseNotification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }
}
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Log::spy();
});

test('send notification successful', function () {
    // Arrange
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => true,
        ], 200),
    ]);

    $service = new ExternalNotificationService;
    $url = 'https://example.com';
    $handler = 'test.handler';
    $payload = ['key' => 'value'];
    $signingSecret = 'project-secret';
    $deliveryId = 'f74cc3ba-4b0d-4794-aeeb-8b1ba5037e96';

    // Act
    $response = $service->sendNotification($url, $handler, $payload, [], $signingSecret, $deliveryId);

    // Assert
    expect($response)->not->toBeNull();
    expect($response->successful())->toBeTrue();

    Http::assertSent(function ($request) use ($url, $handler, $payload, $signingSecret, $deliveryId) {
        $timestamp = $request->header(ExternalNotificationService::TIMESTAMP_HEADER)[0] ?? null;
        $signature = $request->header(ExternalNotificationService::SIGNATURE_HEADER)[0] ?? null;
        $body = $request->body();

        return $request->url() === $url.'/shift/api/notifications' &&
            $request['handler'] === $handler &&
            $request['payload'] === $payload &&
            $request['delivery_id'] === $deliveryId &&
            isset($request['source']) &&
            $request['source']['url'] === config('app.url') &&
            $request['source']['environment'] === app()->environment() &&
            is_string($timestamp) && $timestamp !== '' &&
            $signature === hash_hmac('sha256', $timestamp.'.'.$body, $signingSecret);
    });
});

test('send notification with custom source', function () {
    // Arrange
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => true,
        ], 200),
    ]);

    $service = new ExternalNotificationService;
    $url = 'https://example.com';
    $handler = 'test.handler';
    $payload = ['key' => 'value'];
    $source = ['custom' => 'source'];
    $signingSecret = 'project-secret';

    // Act
    $response = $service->sendNotification($url, $handler, $payload, $source, $signingSecret);

    // Assert
    Http::assertSent(function ($request) use ($url, $source) {
        return $request->url() === $url.'/shift/api/notifications' &&
            $request['source'] === $source;
    });
});

test('send notification skips ssl verification for local consumer apps', function () {
    $capturedOptions = null;

    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response([
            'success' => true,
            'production' => false,
        ], 200);
    });

    $service = new ExternalNotificationService;

    $response = $service->sendNotification('https://shift-sdk-package.test', 'test.handler', ['key' => 'value'], [], 'project-secret');

    expect($response)->not->toBeNull();
    expect($capturedOptions)->toBeArray();
    expect($capturedOptions['verify'] ?? null)->toBeFalse();
    expect($capturedOptions['connect_timeout'] ?? null)->toBe(5)
        ->and($capturedOptions['timeout'] ?? null)->toBe(15);
});

test('send notification keeps ssl verification for public hosts', function () {
    $capturedOptions = null;

    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response([
            'success' => true,
            'production' => true,
        ], 200);
    });

    $service = new ExternalNotificationService;

    $response = $service->sendNotification('https://example.com', 'test.handler', ['key' => 'value'], [], 'project-secret');

    expect($response)->not->toBeNull();
    expect($capturedOptions)->toBeArray();
    expect($capturedOptions['verify'] ?? true)->not->toBeFalse();
});

test('connection failures are retryable', function () {
    // Arrange
    Http::fake(function () {
        throw new \Exception('Test exception');
    });

    $service = new ExternalNotificationService;

    $exception = null;

    try {
        $service->sendNotification('https://example.com', 'test.handler', [], [], 'project-secret');
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('connection')
        ->and($exception->retryable)->toBeTrue()
        ->and($exception->statusCode)->toBeNull();
});

test('server failures are retryable', function () {
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    $exception = null;

    try {
        (new ExternalNotificationService)->sendNotification(
            'https://example.com',
            'test.handler',
            [],
            [],
            'project-secret',
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('retryable_response')
        ->and($exception->retryable)->toBeTrue()
        ->and($exception->statusCode)->toBe(503);
});

test('client failures are permanent', function () {
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response(['message' => 'Invalid'], 422),
    ]);

    $exception = null;

    try {
        (new ExternalNotificationService)->sendNotification(
            'https://example.com',
            'test.handler',
            [],
            [],
            'project-secret',
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('permanent_response')
        ->and($exception->retryable)->toBeFalse()
        ->and($exception->statusCode)->toBe(422);
});

test('malformed success responses are permanent failures', function () {
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response(['message' => 'Missing environment'], 200),
    ]);

    $exception = null;

    try {
        (new ExternalNotificationService)->sendNotification(
            'https://example.com',
            'test.handler',
            [],
            [],
            'project-secret',
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('malformed_response')
        ->and($exception->retryable)->toBeFalse()
        ->and($exception->statusCode)->toBe(200);
});

test('send fallback email when not production', function () {
    // Arrange
    $mockResponse = $this->createStub(Response::class);
    $mockResponse->method('json')->with('production')->willReturn(false);

    $service = new ExternalNotificationService;
    $email = 'test@example.com';
    $notification = new TestNotification;

    // Act
    $result = $service->sendFallbackEmailIfNeeded($mockResponse, $email, $notification);

    // Assert
    expect($result)->toBeTrue();
    Notification::assertSentOnDemand(
        TestNotification::class,
        function ($notification, $channels, $notifiable) use ($email) {
            return $notifiable->routes['mail'] === $email;
        }
    );
});

test('do not send fallback email when production', function () {
    // Arrange
    $mockResponse = $this->createStub(Response::class);
    $mockResponse->method('json')->with('production')->willReturn(true);

    $service = new ExternalNotificationService;
    $email = 'test@example.com';
    $notification = new TestNotification;

    // Act
    $result = $service->sendFallbackEmailIfNeeded($mockResponse, $email, $notification);

    // Assert
    expect($result)->toBeFalse();
    Notification::assertNothingSent();
});

test('do not send fallback email when response is null', function () {
    // Arrange
    $service = new ExternalNotificationService;
    $email = 'test@example.com';
    $notification = new TestNotification;

    // Act
    $result = $service->sendFallbackEmailIfNeeded(null, $email, $notification);

    // Assert
    expect($result)->toBeFalse();
    Notification::assertNothingSent();
});
