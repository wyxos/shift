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
            // return tasks for the project
            $tasks = Task::where('project_id', $project)
                ->where('project_user_id', auth()->user()->id)
                ->latest()
                ->when(
                    request('search'),
                    fn ($query)  => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
                )
                ->paginate(10)
                ->withQueryString();

            return response()->json($tasks);
        }

        return inertia('Tasks/Index')
            ->with([
                'filters' => request()->only(['search']),
                'tasks' => \App\Models\Task::query()
                    ->latest()
                    ->when(
                        request('search'),
                        fn ($query)  => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
                    )
                    ->paginate(10)
                    ->withQueryString(),
            ]);
    }

    // create task
    public function create()
    {
        $projects = Project::whereHas('client.organisation', function ($query) {
            $query->where('author_id', auth()->user()->id);
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
        $task->update(request()->validate([
            'title' => 'required|string|max:255',
        ]));
        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    // create task
    public function store()
    {
        // if expects json
        if (request()->expectsJson()) {
            $projectUser = ProjectUser::updateOrCreate([
                ...request()->validate([
                    'project_id' => 'required|exists:projects,id',
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
                ]),
                'author_id' => auth()->id(),
            ];

            $task = \App\Models\Task::create($taskAttributes);

            $task->projectUser()->associate($projectUser)->save();

            return response()->json($task, 201);
        }

        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
        ]);

        $task = \App\Models\Task::create([
            ...$attributes,
            'author_id' => auth()->id(),
        ]);

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
