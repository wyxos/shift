<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index()
    {
        if(request()->expectsJson()) {
            return \App\Models\Project::query()
                ->select('id', 'name', 'client_id', 'token', 'created_at', 'updated_at')
                ->where(function($query) {
                    $query->whereHas('client.organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->orWhereHas('projectUser', function($query) {
                        $query->where('user_id', auth()->user()->id);
                    });
                })
                ->latest()
                ->when(
                    request('search'),
                    fn ($query)  => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                )
                ->paginate(10)
                ->withQueryString();
        }

        return inertia('Projects')
            ->with([
                'filters' => request()->only(['search']),
                'projects' => \App\Models\Project::query()
                    ->where(function($query) {
                        $query->whereHas('client.organisation', function ($query) {
                            $query->where('author_id', auth()->user()->id);
                        })
                        ->orWhereHas('projectUser', function($query) {
                            $query->where('user_id', auth()->user()->id);
                        });
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

        if(request()->expectsJson()) {
            return response()->json(['message' => 'Project deleted successfully.']);
        }

        return redirect()->route('projects.index')->with('success', 'Project deleted successfully.');
    }

    // put project
    public function update(\App\Models\Project $project)
    {
        $project->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        if(request()->expectsJson()) {
            return response()->json($project);
        }

        return redirect()->route('projects.index')->with('success', 'Project updated successfully.');
    }

    // create project
    public function store()
    {
        $project = \App\Models\Project::create(request()->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
        ]));

        if(request()->expectsJson()) {
            return response()->json($project, 201);
        }

        return redirect()->route('projects.index')->with('success', 'Project created successfully.');
    }

    /**
     * Get users with access to the project.
     */
    public function users(\App\Models\Project $project)
    {
        // Check if the authenticated user has access to the project
        $hasAccess = $project->client->organisation->author_id === auth()->id();

        if (!$hasAccess) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $projectUsers = $project->projectUser()
            ->with('user')
            ->get();

        return response()->json($projectUsers);
    }

    /**
     * Generate a new API token for the project.
     */
    public function generateApiToken(\App\Models\Project $project)
    {
        // Check if the authenticated user has access to the project
        $hasAccess = $project->client->organisation->author_id === auth()->id();

        if (!$hasAccess) {
            if(request()->expectsJson()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            return redirect()->route('projects.index')->with('error', 'Unauthorized');
        }

        $token = $project->generateApiToken();

        if(request()->expectsJson()) {
            return response()->json(['token' => $token]);
        }

        return redirect()->back()->with('success', 'API token generated successfully')->with('token', $token);
    }
}
