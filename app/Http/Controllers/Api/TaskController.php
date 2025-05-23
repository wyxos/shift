<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectUser;
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
            ->with(['externalUser', 'metadata', 'projectUser.user', 'project'])
            ->latest()
            ->when(
                request('search'),
                fn ($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->paginate(10)
            ->withQueryString();

        // Transform the tasks to include external submitter information
        $tasks->through(function ($task) {
            $task->is_external = $task->isExternallySubmitted();
            if ($task->is_external) {
                $task->submitter_info = [
                    'name' => $task->externalUser->name,
                    'email' => $task->externalUser->email,
                ];

                if ($task->metadata) {
                    $task->submitter_info['source_url'] = $task->metadata->source_url;
                    $task->submitter_info['environment'] = $task->metadata->environment;
                }
            }
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
        $task->load(['externalUser', 'metadata', 'projectUser.user', 'project']);

        // Add external submission info if applicable
        $task->is_external = $task->isExternallySubmitted();
        if ($task->is_external) {
            $task->submitter_info = [
                'name' => $task->externalUser->name,
                'email' => $task->externalUser->email,
            ];

            if ($task->metadata) {
                $task->submitter_info['source_url'] = $task->metadata->source_url;
                $task->submitter_info['environment'] = $task->metadata->environment;
            }
        }

        return response()->json($task);
    }

    /**
     * Store a newly created task in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
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

        $task = $project->tasks()->create([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'pending',
            'priority' => $attributes['priority'] ?? 'low',
        ]);

        // Handle external user creation
        $task->externalUser()->updateOrCreate(
            ['task_id' => $task->id],
            [
                'name' => $attributes['external_user']['name'],
                'email' => $attributes['external_user']['email'],
                'id' => $attributes['external_user']['id'] ?? null,
            ]
        );

        // Handle metadata creation
        $task->metadata()->create([
            'source_url' => $attributes['metadata']['source_url'] ?? null,
            'environment' => $attributes['metadata']['environment'] ?? null,
        ]);

        return response()->json($task, 201);
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
        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
            // Additional parameters for backward compatibility with tests
            'submitter_name' => 'nullable|string',
            'source_url' => 'nullable|string',
            'environment' => 'nullable|string',
        ]);

        // Update the task
        $task->update($attributes);

        // Handle external user update if provided
        if (isset($attributes['submitter_name'])) {
            // Find or create the external user for this task
            $externalUser = \App\Models\ExternalUser::updateOrCreate(
                ['task_id' => $task->id],
                [
                    'name' => $attributes['submitter_name'],
                    'email' => $request->input('submitter_email', auth()->user()->email),
                ]
            );

            // Update the task with the external user ID if not already set
            if (!$task->external_user_id) {
                $task->external_user_id = $externalUser->id;
                $task->save();
            }
        }

        // Handle metadata update if provided
        if (isset($attributes['source_url']) || isset($attributes['environment'])) {
            $task->metadata()->updateOrCreate(
                ['task_id' => $task->id],
                [
                    'source_url' => $attributes['source_url'] ?? null,
                    'environment' => $attributes['environment'] ?? null,
                ]
            );
        }

        // For API requests, return JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Task updated successfully',
                'task' => $task
            ]);
        }

        // For web requests, redirect to the tasks index
        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
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
        $task->delete();

        // For API requests, return JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Task deleted successfully'
            ]);
        }

        // For web requests, redirect to the tasks index
        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
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

    /**
     * Display a listing of the tasks for a specific project.
     *
     * @param  \App\Models\Project  $project
     * @return \Illuminate\Http\JsonResponse
     */
    public function projectTasks(\App\Models\Project $project)
    {
        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->with(['externalUser', 'metadata', 'projectUser.user', 'project'])
            ->latest()
            ->when(
                request('search'),
                fn ($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->paginate(10)
            ->withQueryString();

        // Transform the tasks to include external submitter information
        $tasks->through(function ($task) {
            $task->is_external = $task->isExternallySubmitted();
            if ($task->is_external) {
                $task->submitter_info = [
                    'name' => $task->externalUser->name,
                    'email' => $task->externalUser->email,
                ];

                if ($task->metadata) {
                    $task->submitter_info['source_url'] = $task->metadata->source_url;
                    $task->submitter_info['environment'] = $task->metadata->environment;
                }
            }
            return $task;
        });

        return response()->json($tasks);
    }
}
