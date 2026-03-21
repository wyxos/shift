<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

class ProjectController extends Controller
{
    public function index()
    {
        $sortBy = request('sort_by');

        $projects = Project::query()
            ->with([
                'client:id,name,organisation_id',
                'client.organisation:id,author_id',
                'organisation:id,name,author_id',
            ])
            ->where(function (Builder $query) {
                $query
                    ->whereHas('client.organisation', function (Builder $subQuery) {
                        $subQuery->where('author_id', auth()->id());
                    })
                    ->orWhereHas('organisation', function (Builder $subQuery) {
                        $subQuery->where('author_id', auth()->id());
                    })
                    ->orWhereHas('projectUser', function (Builder $subQuery) {
                        $subQuery->where('user_id', auth()->id());
                    })
                    ->orWhere('author_id', auth()->id());
            })
            ->when(
                request('search'),
                fn (Builder $query, string $search) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%'.$search.'%'])
            );

        switch ($sortBy) {
            case 'name':
                $projects->orderBy('name');
                break;
            case 'oldest':
                $projects->oldest();
                break;
            default:
                $projects->latest();
                break;
        }

        return inertia('Projects')
            ->with([
                'filters' => request()->only(['search', 'sort_by']),
                'projects' => $projects
                    ->paginate(10)
                    ->withQueryString()
                    ->through(fn (Project $project) => [
                        ...$project->toArray(),
                        'client_name' => $project->client?->name,
                        'organisation_name' => $project->organisation?->name,
                        'isOwner' => $project->isManagedByUser(auth()->id()),
                    ]),
                'clients' => Client::query()
                    ->whereHas('organisation', function (Builder $query) {
                        $query->where('author_id', auth()->id());
                    })
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'organisations' => Organisation::query()
                    ->where('author_id', auth()->id())
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ]);
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'Project deleted successfully.');
    }

    public function update(Project $project)
    {
        $project->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->route('projects.index')->with('success', 'Project updated successfully.');
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'nullable|exists:clients,id',
            'organisation_id' => 'nullable|exists:organisations,id',
        ]);

        Project::create([
            ...$validated,
            'author_id' => auth()->id(),
        ]);

        return redirect()->route('projects.index')->with('success', 'Project created successfully.');
    }

    public function users(Project $project)
    {
        $hasAccess = $project->client?->organisation?->author_id === auth()->user()->id
            || $project->organisation?->author_id === auth()->user()->id
            || $project->author_id === auth()->user()->id
            || $project->projectUser()->where('user_id', auth()->id())->exists();

        if (! $hasAccess) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(
            $project->projectUser()
                ->with('user')
                ->get()
        );
    }

    public function generateApiToken(Project $project)
    {
        abort_unless(
            $project->isManagedByUser(auth()->id()),
            403,
        );

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
