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
    /**
     * Send a notification to an external API endpoint.
     */
    public function sendNotification(string $url, string $handler, array $payload, array $source = []): ?Response
    {
        try {
            $data = [
                'handler' => $handler,
                'payload' => $payload,
            ];

            // Add source information if provided
            if (!empty($source)) {
                $data['source'] = $source;
            } else {
                // Use default source information if not provided
                $data['source'] = [
                    'url' => config('app.url'),
                    'environment' => app()->environment()
                ];
            }

            $request = Http::acceptJson();

            if ($this->isLocalOrPrivateUrl($url)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post($url . '/shift/api/notifications', $data);

            if ($response->successful()) {
                Log::info("Notification sent to external API: {$handler}", [
                    'response' => $response->json()
                ]);
            } else {
                Log::warning("Failed to send notification to external API: {$handler}", [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

            return $response;
        } catch (Exception $e) {
            Log::error("Exception when sending notification to external API: {$e->getMessage()}", [
                'handler' => $handler,
                'exception' => $e
            ]);

            return null;
        }
    }

    /**
     * Send a fallback email notification if the external API indicates it's not in production.
     */
    public function sendFallbackEmailIfNeeded(?Response $response, string $email, object $notification): bool
    {
        if (!$response) {
            return false;
        }

        $isNotProduction = !$response->json('production');

        if ($isNotProduction) {
            // Queue the notification by dispatching it to the queue
            dispatch(function() use ($email, $notification) {
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
}
