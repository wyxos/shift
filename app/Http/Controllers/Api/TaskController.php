<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Display a listing of the tasks.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $tasks = Task::query()
            ->with(['submitter', 'metadata', 'project'])
            ->latest()
            ->when(
                request('search'),
                fn ($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->paginate(10)
            ->withQueryString();

        // Only set the is_external flag, keeping the submitter object as is
        // Note: The UI will reference submitter directly instead of using submitter_info
        // This change was made to simplify the code and make it more maintainable
        // The frontend has been updated to reference the submitter object directly
        $tasks->through(function ($task) {
            $task->is_external = $task->isExternallySubmitted();
            return $task;
        });

        return response()->json($tasks);
    }

    /**
     * Display the specified task.
     *
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Task $task)
    {
        $task->load(['submitter', 'metadata', 'project']);

        // Only set the is_external flag, keeping the submitter object as is
        // Note: The UI will reference submitter directly instead of using submitter_info
        // This change was made to simplify the code and make it more maintainable
        // The frontend has been updated to reference the submitter object directly
        $task->is_external = $task->isExternallySubmitted();

        return response()->json($task);
    }

    /**
     * Store a newly created task in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        // Check if this is a web form submission (with project_id) or an API request (with project token)
        if ($request->has('project_id')) {
            $attributes = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'project_id' => 'required|exists:projects,id',
                'status' => 'nullable|string|in:pending,in_progress,completed',
                'priority' => 'nullable|string|in:low,medium,high',
            ]);

            $project = Project::findOrFail($attributes['project_id']);
        } else {
            $attributes = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'project' => 'required|exists:projects,token',
                'status' => 'nullable|string|in:pending,in_progress,completed',
                'priority' => 'nullable|string|in:low,medium,high',
                'external_user' => 'nullable|array',
                'external_user.name' => 'required_with:external_user|string',
                'external_user.email' => 'required_with:external_user|email',
                'external_user.id' => 'nullable',
                'metadata' => 'nullable|array',
                'metadata.source_url' => 'nullable|string',
                'metadata.environment' => 'nullable|string',
            ]);

            $project = Project::where('token', $attributes['project'])->firstOrFail();
        }

        // Create the task
        $task = $project->tasks()->create([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'pending',
            'priority' => $attributes['priority'] ?? 'low',
        ]);

        // Handle external user creation and association
        if (isset($attributes['external_user'])) {
            $externalUser = ExternalUser::updateOrCreate(
                ['email' => $attributes['external_user']['email']],
                [
                    'name' => $attributes['external_user']['name'],
                    'email' => $attributes['external_user']['email'],
                    'id' => $attributes['external_user']['id'] ?? null,
                ]
            );

            // Set the polymorphic relationship
            $task->submitter()->associate($externalUser);
            $task->save();
        } else if (auth()->check()) {
            // If the request is coming from an authenticated user, associate the task with the user
            $task->submitter()->associate(auth()->user());
            $task->save();
        }

        // Handle metadata creation
        if (isset($attributes['metadata'])) {
            $task->metadata()->create([
                'source_url' => $attributes['metadata']['source_url'] ?? null,
                'environment' => $attributes['metadata']['environment'] ?? null,
            ]);
        }

        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }

    /**
     * Update the specified task in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Task $task)
    {

    }

    /**
     * Remove the specified task from storage.
     *
     * @param  \App\Models\Task  $task
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function destroy(Task $task, Request $request)
    {

    }

    /**
     * Toggle the status of the specified task.
     *
     * @param  \App\Models\Task  $task
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'status' => 'required|string|in:pending,in_progress,completed',
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        return response()->json([
            'status' => $task->status,
            'message' => 'Task status updated successfully'
        ]);
    }

    /**
     * Toggle the priority of the specified task.
     *
     * @param  \App\Models\Task  $task
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function togglePriority(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'priority' => 'required|string|in:low,medium,high',
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        return response()->json([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully'
        ]);
    }
}
