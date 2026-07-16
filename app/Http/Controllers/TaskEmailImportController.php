<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportTaskEmailRequest;
use App\Models\Project;
use App\Services\TaskEmail\TaskEmailImportService;
use Illuminate\Http\JsonResponse;

class TaskEmailImportController extends Controller
{
    public function __invoke(ImportTaskEmailRequest $request, TaskEmailImportService $importer): JsonResponse
    {
        if (! config('ai_features.email_import.enabled', false)) {
            return response()->json([
                'error' => 'AI email import is disabled.',
            ], 404);
        }

        $project = Project::query()->findOrFail($request->integer('project_id'));

        return response()->json([
            'data' => $importer->import($request->file('email'), $project),
        ]);
    }
}
