<?php

namespace App\Services;

use App\Exceptions\ExternalNotificationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class ExternalNotificationService
{
    public const SIGNATURE_HEADER = 'X-Shift-Signature';

    public const TIMESTAMP_HEADER = 'X-Shift-Timestamp';

    /**
     * Send a notification to an external API endpoint.
     */
    public function sendNotification(
        string $url,
        string $handler,
        array $payload,
        array $source = [],
        ?string $signingSecret = null,
        ?string $deliveryId = null,
    ): Response {
        $data = [
            'handler' => $handler,
            'payload' => $payload,
        ];

        if (filled($deliveryId)) {
            $data['delivery_id'] = $deliveryId;
        }

        $data['source'] = ! empty($source)
            ? $source
            : [
                'url' => config('app.url'),
                'environment' => app()->environment(),
            ];

        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $request = Http::acceptJson()
            ->connectTimeout((int) config('shift.notifications.callback_connect_timeout_seconds', 5))
            ->timeout((int) config('shift.notifications.callback_timeout_seconds', 15))
            ->withBody($body, 'application/json');

        if (filled($signingSecret)) {
            $timestamp = (string) now()->timestamp;

            $request = $request->withHeaders([
                self::TIMESTAMP_HEADER => $timestamp,
                self::SIGNATURE_HEADER => $this->signature($timestamp, $body, $signingSecret),
            ]);
        }

        if ($this->isLocalOrPrivateUrl($url)) {
            $request = $request->withoutVerifying();
        }

        try {
            $response = $request->post($url.'/shift/api/notifications');
        } catch (Throwable $exception) {
            Log::warning('External notification callback could not be reached', [
                'handler' => $handler,
                'failure_type' => 'connection',
            ]);

            throw new ExternalNotificationException(
                failureType: 'connection',
                retryable: true,
                previous: $exception,
            );
        }

        if ($response->serverError() || in_array($response->status(), [408, 425, 429], true)) {
            Log::warning('External notification callback returned a retryable failure', [
                'handler' => $handler,
                'status' => $response->status(),
            ]);

            throw new ExternalNotificationException(
                failureType: 'retryable_response',
                retryable: true,
                statusCode: $response->status(),
            );
        }

        if (! $response->successful()) {
            Log::warning('External notification callback returned a permanent failure', [
                'handler' => $handler,
                'status' => $response->status(),
            ]);

            throw new ExternalNotificationException(
                failureType: 'permanent_response',
                retryable: false,
                statusCode: $response->status(),
            );
        }

        if (! is_bool($response->json('production'))) {
            Log::warning('External notification callback returned an invalid success response', [
                'handler' => $handler,
                'status' => $response->status(),
            ]);

            throw new ExternalNotificationException(
                failureType: 'malformed_response',
                retryable: false,
                statusCode: $response->status(),
            );
        }

        Log::info("Notification sent to external API: {$handler}", [
            'status' => $response->status(),
        ]);

        return $response;
    }

    /**
     * Send a fallback email notification if the external API indicates it's not in production.
     */
    public function sendFallbackEmailIfNeeded(?Response $response, string $email, object $notification): bool
    {
        if (! $response) {
            return false;
        }

        $isNotProduction = ! $response->json('production');

        if ($isNotProduction) {
            // Queue the notification by dispatching it to the queue
            dispatch(function () use ($email, $notification) {
                Notification::route('mail', $email)
                    ->notify($notification);
            });

            return true;
        }

        return false;
    }

    private function isLocalOrPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (Str::endsWith($host, ['.test', '.local'])) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }

    private function signature(string $timestamp, string $body, string $signingSecret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $signingSecret);
    }
}
