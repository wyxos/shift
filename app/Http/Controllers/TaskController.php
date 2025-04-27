<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index()
    {
        return inertia('Tasks')
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
        $task = \App\Models\Task::create(request()->validate([
            'name' => 'required|string|max:255',
        ]));
        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }
}
