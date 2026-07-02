<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ImportExternalTaskEmailRequest;
use App\Models\Project;
use App\Services\ShiftPermissionService;
use App\Services\TaskEmail\TaskEmailImportService;
use Illuminate\Http\JsonResponse;

class ExternalTaskEmailImportController extends Controller
{
    public function __construct(private readonly ShiftPermissionService $permissions) {}

    public function store(ImportExternalTaskEmailRequest $request, TaskEmailImportService $importer): JsonResponse
    {
        $attributes = $request->validated();

        $project = Project::query()
            ->visibleTo($request->user()?->id)
            ->where('token', $attributes['project'])
            ->first();

        if (! $project instanceof Project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        if (! $this->permissions->canCreateTaskForProject($project, $request->user()?->id)) {
            return response()->json([
                'error' => 'You do not have permission to create tasks for this project.',
            ], 403);
        }

        return response()->json([
            'data' => $importer->import($request->file('email'), $project),
        ]);
    }
}
