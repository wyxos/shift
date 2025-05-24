<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = \App\Models\Task::query()
            ->with(['submitter', 'metadata', 'project'])
            ->latest()
            ->when(
                request('search'),
                fn ($query)  => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->paginate(10)
            ->withQueryString();

        // Only set the is_external flag, keeping the submitter object as is
        // Note: The UI will reference submitter directly instead of using submitter_info
        // This change was made to simplify the code and make it more maintainable
        // The frontend has been updated to reference the submitter object directly
        $tasks->through(function (Task $task) {
            $task->is_external = $task->isExternallySubmitted();
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
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
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

        return back()->with([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully'
        ]);
    }
}
