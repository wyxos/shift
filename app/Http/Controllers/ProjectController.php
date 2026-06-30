<?php

namespace App\Http\Controllers;

use App\Enums\OrganisationRole;
use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectEnvironmentService;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ShiftPermissionService $permissions,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    public function index(?Organisation $organisation = null)
    {
        if ($organisation && ! $organisation->isVisibleToUser(auth()->id())) {
            abort(404);
        }

        $sortBy = request('sort_by');
        $organisationId = $organisation?->id ?? request('organisation_id');
        $canCreateProject = $organisation instanceof Organisation
            && $this->permissions->canManageOrganisation($organisation, auth()->id());

        $projects = Project::query()
            ->with([
                'client:id,name,organisation_id',
                'client.organisation:id,name,author_id',
                'environments:id,project_id,environment,url,external_widget_enabled,external_widget_guest_submissions_enabled',
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
                        'environments' => $project->environments
                            ->sortBy('environment')
                            ->map(fn ($environment) => $this->environmentPayload($environment))
                            ->values(),
                        'isOwner' => $project->isManagedByUser(auth()->id()),
                        ...$this->permissions->projectCapabilities($project, auth()->id()),
                    ]),
                'clients' => $canCreateProject
                    ? Client::query()
                        ->where('organisation_id', $organisation->id)
                        ->orderBy('name')
                        ->get(['id', 'name'])
                    : collect(),
                'organisations' => $canCreateProject
                    ? collect([[
                        'id' => $organisation->id,
                        'name' => $organisation->name,
                    ]])
                    : collect(),
                'currentOrganisation' => $organisation ? [
                    'id' => $organisation->id,
                    'name' => $organisation->name,
                ] : null,
                'canCreateProject' => $canCreateProject,
                'accessUsers' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']),
            ]);
    }

    public function destroy(Project $project)
    {
        abort_unless($this->permissions->canManageProject($project, auth()->id()), 403);

        $redirect = $this->redirectToProjectList($project);

        $project->delete();

        return $redirect->with('success', 'Project deleted successfully.');
    }

    public function update(Project $project)
    {
        abort_unless($this->permissions->canManageProject($project, auth()->id()), 403);

        $project->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return $this->redirectToProjectList($project)->with('success', 'Project updated successfully.');
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
            'environments' => 'sometimes|array',
            'environments.*.id' => 'required|integer',
            'environments.*.external_widget_enabled' => 'required|boolean',
            'environments.*.external_widget_guest_submissions_enabled' => 'required|boolean',
        ]);

        $project->update([
            'external_widget_enabled' => $attributes['external_widget_enabled'],
            'external_widget_guest_submissions_enabled' => $attributes['external_widget_enabled']
                ? $attributes['external_widget_guest_submissions_enabled']
                : false,
        ]);

        $this->updateEnvironmentWidgetSettings($project, $attributes['environments'] ?? []);
        $project->load('environments');

        if (request()->expectsJson()) {
            return response()->json([
                'project_id' => $project->id,
                'external_widget_enabled' => $project->external_widget_enabled,
                'external_widget_guest_submissions_enabled' => $project->external_widget_guest_submissions_enabled,
                'environments' => $project->environments
                    ->sortBy('environment')
                    ->map(fn ($environment) => $this->environmentPayload($environment))
                    ->values(),
            ]);
        }

        return $this->redirectToProjectList($project)->with('success', 'Widget settings updated successfully.');
    }

    public function updateMcpSettings(Project $project)
    {
        abort_unless(
            $this->permissions->canManageTechnicalSettings($project, auth()->id()),
            403,
        );

        $attributes = request()->validate([
            'mcp_enabled' => 'required|boolean',
        ]);

        $project->update($attributes);

        if (request()->expectsJson()) {
            return response()->json([
                'project_id' => $project->id,
                'mcp_enabled' => $project->mcp_enabled,
            ]);
        }

        return $this->redirectToProjectList($project)->with('success', 'MCP settings updated successfully.');
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'client_id' => [
                'nullable',
                Rule::exists('clients', 'id')
                    ->where(fn ($query) => $query->whereIn('organisation_id', $this->manageableOrganisationsQuery()->select('id'))),
            ],
            'organisation_id' => [
                'nullable',
                Rule::exists('organisations', 'id')
                    ->where(fn ($query) => $query->whereIn('id', $this->manageableOrganisationsQuery()->select('id'))),
            ],
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

        $project = Project::create([
            ...$validated,
            'author_id' => auth()->id(),
        ]);

        return $this->redirectToProjectList($project)->with('success', 'Project created successfully.');
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

    private function updateEnvironmentWidgetSettings(Project $project, array $environments): void
    {
        if ($environments === []) {
            return;
        }

        $registrations = $project->environments()
            ->whereIn('id', collect($environments)->pluck('id')->all())
            ->get()
            ->keyBy('id');

        abort_unless($registrations->count() === count($environments), 404);

        foreach ($environments as $environment) {
            $registration = $registrations->get($environment['id']);
            $registration->update([
                'external_widget_enabled' => $environment['external_widget_enabled'],
                'external_widget_guest_submissions_enabled' => $environment['external_widget_enabled']
                    ? $environment['external_widget_guest_submissions_enabled']
                    : false,
            ]);
        }
    }

    private function redirectToProjectList(Project $project): RedirectResponse
    {
        $project->loadMissing('organisation:id,name', 'client.organisation:id,name');

        $organisation = $project->accessOrganisation();

        if ($organisation) {
            return redirect()->route('organisation.projects', $organisation);
        }

        return redirect()->route('dashboard');
    }

    private function manageableOrganisationsQuery(): Builder
    {
        return Organisation::query()
            ->where(function (Builder $query) {
                $query
                    ->where('author_id', auth()->id())
                    ->orWhereHas('organisationUsers', function (Builder $membershipQuery) {
                        $membershipQuery
                            ->where('user_id', auth()->id())
                            ->where('role', OrganisationRole::Administrator->value);
                    });
            });
    }

    private function environmentPayload($environment): array
    {
        return [
            'id' => $environment->id,
            'key' => $environment->environment,
            'label' => $this->projectEnvironmentService->label($environment->environment),
            'url' => $environment->url,
            'external_widget_enabled' => $environment->external_widget_enabled,
            'external_widget_guest_submissions_enabled' => $environment->external_widget_guest_submissions_enabled,
        ];
    }
}
