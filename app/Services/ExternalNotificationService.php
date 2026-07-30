<?php

namespace App\Services;

use App\Exceptions\ExternalNotificationException;
use App\Models\Project;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

class ExternalNotificationService
{
    public const SIGNATURE_HEADER = 'X-Shift-Signature';

    public const TIMESTAMP_HEADER = 'X-Shift-Timestamp';

    public function __construct(
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
    ) {}

    /**
     * Send a notification to an external API endpoint.
     */
    public function sendNotification(
        Project $project,
        string $url,
        string $handler,
        array $payload,
        array $source = [],
        ?string $deliveryId = null,
    ): Response {
        $registration = $this->projectEnvironmentService->findTrustedByUrl($project, $url);

        if (! $registration) {
            Log::warning('External notification callback destination is not trusted.', [
                'project_id' => $project->id,
                'handler' => $handler,
            ]);

            throw new ExternalNotificationException(
                failureType: 'untrusted_destination',
                retryable: false,
            );
        }

        if (! filled($project->token)) {
            Log::warning('External notification project token is unavailable.', [
                'project_id' => $project->id,
                'handler' => $handler,
            ]);

            throw new ExternalNotificationException(
                failureType: 'missing_project_token',
                retryable: false,
            );
        }

        try {
            $destination = $this->outboundUrlPolicy->approveRequest($registration->url);
            $requestOptions = $this->outboundUrlPolicy->requestOptions($destination);
        } catch (InvalidArgumentException $exception) {
            Log::warning('External notification callback destination failed outbound validation.', [
                'project_id' => $project->id,
                'handler' => $handler,
            ]);

            throw new ExternalNotificationException(
                failureType: 'untrusted_destination',
                retryable: false,
                previous: $exception,
            );
        }

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
        $request = Http::withOptions($requestOptions)
            ->acceptJson()
            ->connectTimeout((int) config('shift.notifications.callback_connect_timeout_seconds', 5))
            ->timeout((int) config('shift.notifications.callback_timeout_seconds', 15))
            ->withBody($body, 'application/json');
        $timestamp = (string) now()->timestamp;

        $request = $request->withHeaders([
            self::TIMESTAMP_HEADER => $timestamp,
            self::SIGNATURE_HEADER => $this->signature($timestamp, $body, $project->token),
        ]);
        $callbackUrl = $destination['url'].'/shift/api/notifications';

        try {
            $response = $request->post($callbackUrl);
        } catch (Throwable $exception) {
            Log::warning('External notification callback could not be reached', [
                'project_id' => $project->id,
                'handler' => $handler,
                'failure_type' => 'connection',
            ]);

            throw new ExternalNotificationException(
                failureType: 'connection',
                retryable: true,
                previous: $exception,
            );
        }

        if ($this->outboundUrlPolicy->responseWasRedirected($response, $callbackUrl)) {
            Log::warning('External notification callback refused a redirect.', [
                'project_id' => $project->id,
                'handler' => $handler,
                'status' => $response->status(),
            ]);

            throw new ExternalNotificationException(
                failureType: 'redirect_response',
                retryable: false,
                statusCode: $response->status(),
            );
        }

        if ($response->serverError() || in_array($response->status(), [408, 425, 429], true)) {
            Log::warning('External notification callback returned a retryable failure', [
                'project_id' => $project->id,
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
                'project_id' => $project->id,
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
                'project_id' => $project->id,
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
            'project_id' => $project->id,
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

    private function signature(string $timestamp, string $body, string $signingSecret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $signingSecret);
    }
}
