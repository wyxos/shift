<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SdkInstallSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SdkInstallController extends Controller
{
    public function __construct(
        private readonly SdkInstallSessionService $sdkInstallSessionService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'environment' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url', 'regex:/^https?:\/\//i'],
        ]);

        $session = $this->sdkInstallSessionService->create(
            $attributes['environment'],
            $attributes['url'],
        );

        $payload = [
            'device_code' => $session['device_code'],
            'user_code' => $session['user_code'],
            'verification_uri' => route('sdk-install.verify', absolute: true),
            'verification_uri_complete' => route('sdk-install.verify', ['user_code' => $session['user_code']], absolute: true),
            'interval' => $this->sdkInstallSessionService->pollIntervalSeconds(),
            'expires_at' => $session['expires_at'],
        ];

        $realtime = $this->sdkInstallSessionService->realtimeMetadata($session['device_code']);

        if ($realtime !== null) {
            $payload['realtime'] = $realtime;
        }

        return response()->json($payload, 201);
    }

    public function poll(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->sdkInstallSessionService->poll($attributes['device_code'])
        );
    }

    public function projects(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
        ]);

        try {
            $projects = $this->sdkInstallSessionService
                ->projects($attributes['device_code'])
                ->values()
                ->all();
            $organisations = $this->sdkInstallSessionService->organisations($attributes['device_code'])->values()->all();
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'projects' => $projects,
            'organisations' => $organisations,
        ]);
    }

    public function createProject(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'organisation_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $project = $this->sdkInstallSessionService->createProject(
                $attributes['device_code'],
                $attributes['name'],
                isset($attributes['organisation_id']) ? (int) $attributes['organisation_id'] : null,
            );
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json(['project' => $project], 201);
    }

    public function finalize(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'device_code' => ['required', 'string', 'max:255'],
            'project_id' => ['required', 'integer'],
            'feedback_enabled' => ['sometimes', 'boolean'],
        ]);

        try {
            $credentials = $this->sdkInstallSessionService->finalize(
                $attributes['device_code'],
                (int) $attributes['project_id'],
                array_key_exists('feedback_enabled', $attributes) ? (bool) $attributes['feedback_enabled'] : null,
            );
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json($credentials);
    }

    private function errorResponse(HttpExceptionInterface $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
        ], $exception->getStatusCode(), $exception->getHeaders());
    }
}
