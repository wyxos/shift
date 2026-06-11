<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;

class ProjectController extends Controller
{
    public function __construct(private readonly ShiftPermissionService $permissions) {}

    public function index(?Organisation $organisation = null)
    {
        $sortBy = request('sort_by');
        $organisationId = $organisation?->id ?? request('organisation_id');

        $projects = Project::query()
            ->with([
                'client:id,name,organisation_id',
                'client.organisation:id,name,author_id',
                'organisation:id,name,author_id',
            ])
            ->visibleTo(auth()->id())
            ->when(filled($organisationId), function (Builder $query) use ($organisationId) {
                $query->where(function (Builder $subQuery) use ($organisationId) {
                    $subQuery
                        ->where('organisation_id', $organisationId)
                        ->orWhereHas('client', function (Builder $clientQuery) use ($organisationId) {
                            $clientQuery->where('organisation_id', $organisationId);
                        });
                });
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
                'filters' => [
                    ...request()->only(['search', 'sort_by']),
                    'organisation_id' => filled($organisationId) ? (int) $organisationId : null,
                ],
                'projects' => $projects
                    ->paginate(10)
                    ->withQueryString()
                    ->through(fn (Project $project) => [
                        ...$project->toArray(),
                        'client_name' => $project->client?->name,
                        'organisation_name' => $project->organisation?->name ?? $project->client?->organisation?->name,
                        'isOwner' => $project->isManagedByUser(auth()->id()),
                        ...$this->permissions->projectCapabilities($project, auth()->id()),
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
                'accessUsers' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']),
            ]);
    }

    public function destroy(Project $project)
    {
        abort_unless($this->permissions->canManageProject($project, auth()->id()), 403);

        $project->delete();

        return redirect()->route('projects.index')->with('success', 'Project deleted successfully.');
    }

    public function update(Project $project)
    {
        abort_unless($this->permissions->canManageProject($project, auth()->id()), 403);

        $project->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->route('projects.index')->with('success', 'Project updated successfully.');
    }

    public function updateWidgetSettings(Project $project)
    {
        abort_unless(
            $this->permissions->canManageTechnicalSettings($project, auth()->id()),
            403,
        );

        $attributes = request()->validate([
            'external_widget_enabled' => 'required|boolean',
            'external_widget_guest_submissions_enabled' => 'required|boolean',
        ]);

        $project->update($attributes);

        if (request()->expectsJson()) {
            return response()->json([
                'project_id' => $project->id,
                'external_widget_enabled' => $project->external_widget_enabled,
                'external_widget_guest_submissions_enabled' => $project->external_widget_guest_submissions_enabled,
            ]);
        }

        return redirect()->route('projects.index')->with('success', 'Widget settings updated successfully.');
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'nullable|exists:clients,id',
            'organisation_id' => 'nullable|exists:organisations,id',
        ]);

        $organisation = null;
        if (! empty($validated['organisation_id'])) {
            $organisation = Organisation::query()->findOrFail($validated['organisation_id']);
        } elseif (! empty($validated['client_id'])) {
            $organisation = Client::query()->findOrFail($validated['client_id'])->organisation;
        }

        if ($organisation) {
            abort_unless($this->permissions->canManageOrganisation($organisation, auth()->id()), 403);
        }

        Project::create([
            ...$validated,
            'author_id' => auth()->id(),
        ]);

        return redirect()->route('projects.index')->with('success', 'Project created successfully.');
    }

    public function users(Project $project)
    {
        if (! $this->permissions->canManageProjectAccess($project, auth()->id())) {
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
            $this->permissions->canManageTechnicalSettings($project, auth()->id()),
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
