<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(int $project = null)
    {
        if(request()->expectsJson()){
            $project = \App\Models\Project::where('token', request('project'))->first();

            // return tasks for the project
            $tasks = $project->tasks()
                ->latest()
                ->when(
                    request('search'),
                    fn ($query)  => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
                )
                ->paginate(10)
                ->withQueryString();

            return response()->json($tasks);
        }

        $tasks = \App\Models\Task::query()
            ->with(['externalTaskSource', 'projectUser.user', 'project'])
            ->latest()
            ->when(
                request('search'),
                fn ($query)  => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->paginate(10)
            ->withQueryString();

        // Transform the tasks to include external submitter information
        $tasks->through(function ($task) {
            $task->is_external = $task->isExternallySubmitted();
            if ($task->is_external) {
                $task->submitter_info = [
                    'name' => $task->externalTaskSource->submitter_name,
                    'source_url' => $task->externalTaskSource->source_url,
                    'environment' => $task->externalTaskSource->environment,
                ];
            }
            return $task;
        });

        return inertia('Tasks/Index')
            ->with([
                'filters' => request()->only(['search']),
                'tasks' => $tasks,
            ]);
    }

    // create task
    public function create()
    {
        $projects = Project::where(function($query) {
            $query->whereHas('client.organisation', function ($query) {
                $query->where('author_id', auth()->user()->id);
            })
            ->orWhereHas('projectUser', function($query) {
                $query->where('user_id', auth()->user()->id);
            });
        })->get();

        return inertia('Tasks/Create')
            ->with([
                'projects' => $projects
            ]);
    }

    // edit task
    public function edit(\App\Models\Task $task)
    {
        $task->load('project');

        return inertia('Tasks/Edit')
            ->with([
                'task' => $task,
                'project' => $task->project
            ]);
    }

    // delete route
    public function destroy(\App\Models\Task $task)
    {
        $task->delete();
        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    // put task
    public function update(\App\Models\Task $task)
    {
        // If the request expects JSON, handle it as an API request
        if (request()->expectsJson()) {
            // Check if this is an external submission
            $isExternalSubmission = request()->has('submitter_name') && request()->has('source_url');

            if ($isExternalSubmission) {
                // Validate external submission data
                $externalData = request()->validate([
                    'submitter_name' => 'required|string|max:255',
                    'source_url' => 'required|string|max:255',
                    'environment' => 'nullable|string|max:50',
                ]);

                // Update task
                $task->update(request()->validate([
                    'title' => 'required|string|max:255',
                    'description' => 'nullable|string',
                    'status' => 'nullable|string|in:pending,in_progress,completed',
                    'priority' => 'nullable|string|in:low,medium,high',
                ]));

                // Update or create external task source record
                $task->externalTaskSource()->updateOrCreate(
                    ['task_id' => $task->id],
                    [
                        'submitter_name' => $externalData['submitter_name'],
                        'source_url' => $externalData['source_url'],
                        'environment' => $externalData['environment'] ?? 'production',
                    ]
                );

                return response()->json($task);
            } else {
                // Regular submission from a Shift user
                if (request()->has('user_id') && request()->has('user_email') && request()->has('user_name')) {
                    // Update or create ProjectUser record
                    $projectUser = ProjectUser::updateOrCreate([
                        'project_id' => $task->project_id,
                        'user_id' => request('user_id'),
                    ], [
                        'user_email' => request('user_email'),
                        'user_name' => request('user_name')
                    ]);

                    // Update task
                    $task->update(request()->validate([
                        'title' => 'required|string|max:255',
                        'description' => 'nullable|string',
                        'status' => 'nullable|string|in:pending,in_progress,completed',
                        'priority' => 'nullable|string|in:low,medium,high',
                    ]));

                    // Associate the task with the project user if not already associated
                    if ($task->project_user_id !== $projectUser->id) {
                        $task->projectUser()->associate($projectUser)->save();
                    }

                    return response()->json($task);
                } else {
                    // Missing required user information
                    return response()->json(['error' => 'Missing required user information'], 422);
                }
            }
        }

        // Handle web form submission
        $task->update(request()->validate([
            'title' => 'required|string|max:255',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
        ]));
        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    // create task
    public function store()
    {
        // if expects json
        if (request()->expectsJson()) {
            // Check if this is an external submission
            $isExternalSubmission = request()->has('submitter_name') && request()->has('source_url');

            if ($isExternalSubmission) {
                // Validate external submission data
                $externalData = request()->validate([
                    'submitter_name' => 'required|string|max:255',
                    'source_url' => 'required|string|max:255',
                    'environment' => 'nullable|string|max:50',
                ]);

                // Create task without associating with a project user
                $taskAttributes = [
                    ...request()->validate([
                        'title' => 'required|string|max:255',
                        'description' => 'nullable|string',
                        'project' => 'required|exists:projects,token',
                        'status' => 'nullable|string|in:pending,in_progress,completed',
                        'priority' => 'nullable|string|in:low,medium,high',
                    ]),
                    'author_id' => auth()->id(), // API token owner
                ];

                $project = Project::where('token', $taskAttributes['project'])->first();

                $task = $project->tasks()->create($taskAttributes);

                // Create external task source record
                $task->externalTaskSource()->create([
                    'submitter_name' => $externalData['submitter_name'],
                    'source_url' => $externalData['source_url'],
                    'environment' => $externalData['environment'] ?? 'production',
                ]);
            } else {
                // Regular submission from a Shift user
                $projectUser = ProjectUser::updateOrCreate([
                    ...request()->validate([
                        'project' => 'required|exists:projects,token',
                        'user_id' => 'required',
                    ])
                ], [
                    ...request()->validate([
                        'user_email' => 'required|string',
                        'user_name' => 'required|string'
                    ])
                ]);

                $taskAttributes = [
                    ...request()->validate([
                        'title' => 'required|string|max:255',
                        'description' => 'nullable|string',
                        'project_id' => 'required|exists:projects,id',
                        'status' => 'nullable|string|in:pending,in_progress,completed',
                        'priority' => 'nullable|string|in:low,medium,high',
                    ]),
                    'author_id' => auth()->id(),
                ];

                $task = \App\Models\Task::create($taskAttributes);

                $task->projectUser()->associate($projectUser)->save();
            }

            return response()->json($task, 201);
        }

        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        // Create or find a ProjectUser record for the authenticated user
        $projectUser = ProjectUser::updateOrCreate([
            'project_id' => $attributes['project_id'],
            'user_id' => auth()->id(),
        ], [
            'user_email' => auth()->user()->email,
            'user_name' => auth()->user()->name
        ]);

        $task = \App\Models\Task::create([
            ...$attributes,
            'author_id' => auth()->id(),
        ]);

        // Associate the task with the project user
        $task->projectUser()->associate($projectUser)->save();

        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }

    public function show(Task $task)
    {
        if(request()->expectsJson()){
            return response()->json($task);
        }

        return inertia('Tasks/Show')
            ->with([
                'task' => $task
            ]);
    }

    /**
     * Update the status of a task.
     *
     * @param Task $task
     * @param Request $request
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'status' => 'required|string|in:pending,in_progress,completed',
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $task->status,
                'message' => 'Task status updated successfully'
            ]);
        }

        return back()->with([
            'status' => $task->status,
            'message' => 'Task status updated successfully'
        ]);
    }

    /**
     * Update the priority of a task.
     *
     * @param Task $task
     * @param Request $request
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function togglePriority(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'priority' => 'required|string|in:low,medium,high',
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        if ($request->wantsJson()) {
            return response()->json([
                'priority' => $task->priority,
                'message' => 'Task priority updated successfully'
            ]);
        }

        return back()->with([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully'
        ]);
    }
}
