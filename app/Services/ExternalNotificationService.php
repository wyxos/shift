<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ExternalNotificationService
{
    /**
     * Send a notification to an external API endpoint.
     *
     * @param string $url The base URL of the external API
     * @param string $handler The notification handler (e.g., 'task.created', 'thread.update')
     * @param array $payload The notification payload
     * @param array $source Optional source information
     * @return Response|null The HTTP response or null if the request failed
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

            $response = Http::post($url . '/shift/api/notifications', $data);

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
     *
     * @param Response|null $response The HTTP response from the external API
     * @param string $email The email address to send the notification to
     * @param object $notification The notification object to send
     * @return bool Whether a fallback notification was sent
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
}
