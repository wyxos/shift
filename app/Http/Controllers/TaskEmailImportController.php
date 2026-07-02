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
        $project = Project::query()->findOrFail($request->integer('project_id'));

        return response()->json([
            'data' => $importer->import($request->file('email'), $project),
        ]);
    }
}
