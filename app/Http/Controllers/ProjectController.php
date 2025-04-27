<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index()
    {
        return inertia('Projects')
            ->with([
                'filters' => request()->only(['search']),
                'projects' => \App\Models\Project::query()
                    ->whereHas('client.organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->latest()
                    ->when(
                        request('search'),
                        fn ($query)  => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                    )
                    ->paginate(10)
                    ->withQueryString(),
                'clients' => \App\Models\Client::query()
                    ->whereHas('organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
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
    public function destroy(\App\Models\Project $project)
    {
        $project->delete();
        return redirect()->route('projects.index')->with('success', 'Project deleted successfully.');
    }

    // put project
    public function update(\App\Models\Project $project)
    {
        $project->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));
        return redirect()->route('projects.index')->with('success', 'Project updated successfully.');
    }

    // create project
    public function store()
    {
        $project = \App\Models\Project::create(request()->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
        ]));
        return redirect()->route('projects.index')->with('success', 'Project created successfully.');
    }
}
