<?php

use App\Exceptions\ExternalNotificationException;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\ExternalNotificationService;
use App\Services\OutboundUrlPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

class TestNotification extends BaseNotification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }
}

beforeEach(function () {
    Notification::fake();
    Log::spy();

    $this->app->instance(OutboundUrlPolicy::class, new OutboundUrlPolicy(
        fn (string $host): array => match ($host) {
            'callback.internal.example' => ['10.0.0.5'],
            'shift-sdk-package.test' => ['127.0.0.1'],
            default => ['93.184.216.34'],
        },
    ));

    $this->project = Project::factory()->create([
        'token' => 'project-secret',
    ]);
    $this->environment = ProjectEnvironment::query()->create([
        'project_id' => $this->project->id,
        'environment' => 'production',
        'url' => 'https://example.com',
        'callback_trusted_at' => now(),
    ]);
    $this->service = app(ExternalNotificationService::class);
});

test('send notification successful', function () {
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => true,
        ], 200),
    ]);

    $url = 'https://example.com';
    $handler = 'test.handler';
    $payload = ['key' => 'value'];
    $deliveryId = 'f74cc3ba-4b0d-4794-aeeb-8b1ba5037e96';

    $response = $this->service->sendNotification(
        $this->project,
        $url,
        $handler,
        $payload,
        [],
        $deliveryId,
    );

    expect($response->successful())->toBeTrue();

    Http::assertSent(function ($request) use ($url, $handler, $payload, $deliveryId) {
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
            $signature === hash_hmac('sha256', $timestamp.'.'.$body, 'project-secret');
    });
});

test('send notification with custom source', function () {
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => true,
        ], 200),
    ]);

    $source = ['custom' => 'source'];

    $this->service->sendNotification(
        $this->project,
        'https://example.com',
        'test.handler',
        ['key' => 'value'],
        $source,
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/shift/api/notifications'
        && $request['source'] === $source);
});

test('send notification skips ssl verification for local consumer apps', function () {
    $this->environment->update([
        'environment' => 'local',
        'url' => 'https://shift-sdk-package.test',
    ]);
    $capturedOptions = null;

    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response([
            'success' => true,
            'production' => false,
        ], 200);
    });

    $response = $this->service->sendNotification(
        $this->project,
        'https://shift-sdk-package.test',
        'test.handler',
        ['key' => 'value'],
    );

    expect($response->successful())->toBeTrue()
        ->and($capturedOptions)->toBeArray()
        ->and($capturedOptions['verify'] ?? null)->toBeFalse()
        ->and($capturedOptions['allow_redirects'] ?? null)->toBeFalse()
        ->and($capturedOptions['connect_timeout'] ?? null)->toBe(5)
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

    $response = $this->service->sendNotification(
        $this->project,
        'https://example.com',
        'test.handler',
        ['key' => 'value'],
    );

    expect($response->successful())->toBeTrue()
        ->and($capturedOptions)->toBeArray()
        ->and($capturedOptions['verify'] ?? true)->not->toBeFalse()
        ->and($capturedOptions['allow_redirects'] ?? null)->toBeFalse()
        ->and($capturedOptions['curl'][CURLOPT_RESOLVE] ?? null)
        ->toBe(['example.com:443:93.184.216.34']);
});

test('historical callback registrations remain untrusted until re-registered', function () {
    $this->environment->update(['callback_trusted_at' => null]);
    Http::fake();

    $exception = null;

    try {
        $this->service->sendNotification(
            $this->project,
            'https://example.com',
            'test.handler',
            ['sensitive' => 'payload'],
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('untrusted_destination')
        ->and($exception->retryable)->toBeFalse();
    Http::assertNothingSent();
});

test('send notification refuses a changed callback url before signing or sending', function () {
    Http::fake();

    expect(fn () => $this->service->sendNotification(
        $this->project,
        'https://changed.example',
        'test.handler',
        ['sensitive' => 'payload'],
    ))->toThrow(ExternalNotificationException::class);

    Http::assertNothingSent();
});

test('send notification refuses private dns destinations in hosted production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->environment->update(['url' => 'https://callback.internal.example']);
    Http::fake();

    $exception = null;

    try {
        $this->service->sendNotification(
            $this->project,
            'https://callback.internal.example',
            'test.handler',
            ['sensitive' => 'payload'],
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('untrusted_destination')
        ->and($exception->retryable)->toBeFalse();
    Http::assertNothingSent();
});

test('send notification requires a project callback token', function () {
    $this->project->update(['token' => null]);
    Http::fake();

    $exception = null;

    try {
        $this->service->sendNotification(
            $this->project->fresh(),
            'https://example.com',
            'test.handler',
            ['sensitive' => 'payload'],
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('missing_project_token')
        ->and($exception->retryable)->toBeFalse();
    Http::assertNothingSent();
});

test('send notification refuses redirects without forwarding its signed payload', function () {
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response('', 302, [
            'Location' => 'http://127.0.0.1/internal',
        ]),
        'http://127.0.0.1/*' => Http::response(['unexpected' => true]),
    ]);

    $exception = null;

    try {
        $this->service->sendNotification(
            $this->project,
            'https://example.com',
            'test.handler',
            ['sensitive' => 'payload'],
        );
    } catch (ExternalNotificationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ExternalNotificationException::class)
        ->and($exception->failureType)->toBe('redirect_response')
        ->and($exception->retryable)->toBeFalse();
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'http://127.0.0.1'));
});

test('connection failures are retryable', function () {
    Http::fake(function () {
        throw new \Exception('Test exception');
    });

    $exception = null;

    try {
        $this->service->sendNotification($this->project, 'https://example.com', 'test.handler', []);
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
        $this->service->sendNotification(
            $this->project,
            'https://example.com',
            'test.handler',
            [],
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
        $this->service->sendNotification(
            $this->project,
            'https://example.com',
            'test.handler',
            [],
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
        $this->service->sendNotification(
            $this->project,
            'https://example.com',
            'test.handler',
            [],
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
    $mockResponse = $this->createStub(Response::class);
    $mockResponse->method('json')->with('production')->willReturn(false);

    $email = 'test@example.com';
    $notification = new TestNotification;

    $result = $this->service->sendFallbackEmailIfNeeded($mockResponse, $email, $notification);

    expect($result)->toBeTrue();
    Notification::assertSentOnDemand(
        TestNotification::class,
        function ($notification, $channels, $notifiable) use ($email) {
            return $notifiable->routes['mail'] === $email;
        }
    );
});

test('do not send fallback email when production', function () {
    $mockResponse = $this->createStub(Response::class);
    $mockResponse->method('json')->with('production')->willReturn(true);

    $email = 'test@example.com';
    $notification = new TestNotification;

    $result = $this->service->sendFallbackEmailIfNeeded($mockResponse, $email, $notification);

    expect($result)->toBeFalse();
    Notification::assertNothingSent();
});

test('do not send fallback email when response is null', function () {
    $email = 'test@example.com';
    $notification = new TestNotification;

    $result = $this->service->sendFallbackEmailIfNeeded(null, $email, $notification);

    expect($result)->toBeFalse();
    Notification::assertNothingSent();
});
