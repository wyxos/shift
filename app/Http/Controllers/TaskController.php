<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index()
    {
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
        $projects = Project::whereHas('client.organisation', function ($query) {
            $query->where('author_id', auth()->user()->id);
        })->get();

        return inertia('Tasks/Edit')
            ->with([
                'task' => $task,
                'projects' => $projects
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
            'name' => 'required|string|max:255',
        ]));
        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    // create task
    public function store()
    {
        $attributes = request()->validate([
            'name' => 'required|string|max:255',
            'project_id' => 'required|exists:projects,id',
        ]);
        $task = \App\Models\Task::create([
            ...$attributes,
            'author_id' => auth()->id(),
        ]);
        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }
}
