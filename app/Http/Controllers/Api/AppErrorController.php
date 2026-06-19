<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppErrorRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\AppErrors\AppErrorTaskIngestor;
use Illuminate\Http\JsonResponse;

class AppErrorController extends Controller
{
    public function store(StoreAppErrorRequest $request, AppErrorTaskIngestor $ingestor): JsonResponse
    {
        $attributes = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $project = Project::query()
            ->visibleTo($user->id)
            ->where('token', $attributes['project'])
            ->first();

        if (! $project instanceof Project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        [$task, $occurrence] = $ingestor->record($project, $user, $attributes);

        return response()->json([
            'task' => [
                'id' => $task->id,
                'signature' => $task->error_signature,
                'source' => $task->error_source,
                'environment' => $task->error_environment,
                'status' => $task->status,
                'priority' => $task->priority,
                'title' => $task->title,
                'occurrences_count' => $task->error_occurrences_count,
                'culprit' => [
                    'file' => $task->error_culprit_file,
                    'line' => $task->error_culprit_line,
                    'function' => $task->error_culprit_function,
                ],
                'first_seen_at' => $task->error_first_seen_at?->toIso8601String(),
                'last_seen_at' => $task->error_last_seen_at?->toIso8601String(),
            ],
            'occurrence' => [
                'id' => $occurrence->id,
                'number' => $task->error_occurrences_count,
                'task_id' => $task->id,
                'created_at' => $occurrence->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
