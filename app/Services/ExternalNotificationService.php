<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ExternalNotificationService
{
    public const SIGNATURE_HEADER = 'X-Shift-Signature';

    public const TIMESTAMP_HEADER = 'X-Shift-Timestamp';

    /**
     * Send a notification to an external API endpoint.
     */
    public function sendNotification(string $url, string $handler, array $payload, array $source = [], ?string $signingSecret = null): ?Response
    {
        try {
            $data = [
                'handler' => $handler,
                'payload' => $payload,
            ];

            // Add source information if provided
            if (! empty($source)) {
                $data['source'] = $source;
            } else {
                // Use default source information if not provided
                $data['source'] = [
                    'url' => config('app.url'),
                    'environment' => app()->environment(),
                ];
            }

            $body = json_encode($data, JSON_THROW_ON_ERROR);
            $request = Http::acceptJson()
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

            $response = $request->post($url.'/shift/api/notifications');

            if ($response->successful()) {
                Log::info("Notification sent to external API: {$handler}", [
                    'response' => $response->json(),
                ]);
            } else {
                Log::warning("Failed to send notification to external API: {$handler}", [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }

            return $response;
        } catch (Exception $e) {
            Log::error("Exception when sending notification to external API: {$e->getMessage()}", [
                'handler' => $handler,
                'exception' => $e,
            ]);

            return null;
        }
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
