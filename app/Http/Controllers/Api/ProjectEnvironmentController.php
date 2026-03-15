<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectEnvironmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectEnvironmentController extends Controller
{
    public function __construct(
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => ['required', 'exists:projects,token'],
            'environment' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
        ]);

        $project = Project::query()
            ->with(['client.organisation', 'organisation'])
            ->where('token', $attributes['project'])
            ->firstOrFail();

        if (! $project->isManagedByUser(auth()->id())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $registration = $this->projectEnvironmentService->register(
            $project,
            $attributes['environment'],
            $attributes['url'],
        );

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'project_id' => $registration->project_id,
                'key' => $registration->environment,
                'label' => $this->projectEnvironmentService->label($registration->environment),
                'url' => $registration->url,
            ],
        ]);
    }
}
