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
                    fn ($query)  => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
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
                        fn ($query)  => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
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


            return $task;
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
}
