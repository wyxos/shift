<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index()
    {
        return inertia('Projects')
            ->with([
                'filters' => request()->only(['search']),
                'projects' => \App\Models\Project::query()
                    ->where(function ($query) {
                        $query
                            ->whereHas('client.organisation', function ($query) {
                                $query->where('author_id', auth()->user()->id);
                            })
                            ->orWhereHas('organisation', function ($query) {
                                $query->where('author_id', auth()->user()->id);
                            })
                            ->orWhereHas('projectUser', function ($query) {
                                $query->where('user_id', auth()->user()->id);
                            })
                            ->orWhere('author_id', auth()->user()->id);
                    })
                    ->latest()
                    ->when(
                        request('search'),
                        fn($query) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
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
                        fn($query) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                    )
                    ->paginate(10)
                    ->withQueryString(),
                'organisations' => \App\Models\Organisation::query()
                    ->where('author_id', auth()->user()->id)
                    ->latest()
                    ->when(
                        request('search'),
                        fn($query) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                    )
                    ->get(),
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
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'nullable|exists:clients,id',
            'organisation_id' => 'nullable|exists:organisations,id',
        ]);

        $project = \App\Models\Project::create([
            ...$validated,
            'author_id' => auth()->id(),
        ]);

        return redirect()->route('projects.index')->with('success', 'Project created successfully.');
    }

    /**
     * Get users with access to the project.
     */
    public function users(\App\Models\Project $project)
    {
        // Check if the authenticated user has access to the project
        $hasAccess = $project->client?->organisation?->author_id === auth()->user()->id
            || $project->organisation?->author_id === auth()->user()->id
            || $project->author_id === auth()->user()->id
            || $project->projectUser()->where('user_id', auth()->id())->exists();

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
    public function generateApiToken(Project $project)
    {
        $token = $project->generateApiToken();

        if (request()->expectsJson()) {
            return response()->json([
                'token' => $token,
                'project_id' => $project->id,
            ]);
        }

        return redirect()->back()->with('token', $token);
    }
}
