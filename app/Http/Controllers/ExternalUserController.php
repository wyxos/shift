<?php

namespace App\Http\Controllers;

use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExternalUserController extends Controller
{
    private function serializeExternalUser(ExternalUser $externalUser): array
    {
        return [
            'id' => $externalUser->id,
            'name' => $externalUser->name,
            'email' => $externalUser->email,
            'environment' => $externalUser->environment,
            'role' => $externalUser->role?->value,
            'role_label' => $externalUser->role?->label(),
            'project' => $externalUser->project ? [
                'id' => $externalUser->project->id,
                'name' => $externalUser->project->name,
            ] : null,
        ];
    }

    private function visibleProjectsQuery(mixed $organisationId = null): Builder
    {
        return Project::query()
            ->visibleTo(auth()->id())
            ->when(filled($organisationId), function (Builder $query) use ($organisationId) {
                $query->where(function (Builder $subQuery) use ($organisationId) {
                    $subQuery
                        ->where('organisation_id', $organisationId)
                        ->orWhereHas('client', function (Builder $clientQuery) use ($organisationId) {
                            $clientQuery->where('organisation_id', $organisationId);
                        });
                });
            });
    }

    /**
     * Display a listing of the resource.
     */
    public function index(?Organisation $organisation = null)
    {
        $sortBy = request('sort_by');
        $projectId = request('project_id');
        $organisationId = $organisation?->id ?? request('organisation_id');

        $projects = $this->visibleProjectsQuery($organisationId)
            ->orderBy('name')
            ->get(['id', 'name']);
        $projectIds = $projects->pluck('id');

        $externalUsers = ExternalUser::with('project:id,name')
            ->whereIn('project_id', $projectIds)
            ->when(filled($projectId), fn ($query) => $query->where('project_id', $projectId))
            ->when(
                request('search'),
                fn ($query, string $search) => $query->where(function ($query) use ($search) {
                    $term = '%'.$search.'%';

                    $query->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                        ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term])
                        ->orWhereRaw('LOWER(environment) LIKE LOWER(?)', [$term]);
                })
            );

        switch ($sortBy) {
            case 'name':
                $externalUsers->orderBy('name');
                break;
            case 'oldest':
                $externalUsers->oldest();
                break;
            default:
                $externalUsers->latest();
                break;
        }

        $externalUsers = $externalUsers
            ->paginate(10)
            ->withQueryString();

        $externalUsers->through(fn (ExternalUser $externalUser) => $this->serializeExternalUser($externalUser));

        return Inertia::render('ExternalUsers/Index', [
            'externalUsers' => $externalUsers,
            'filters' => [
                'search' => request('search'),
                'sort_by' => request('sort_by'),
                'project_id' => filled($projectId) ? (int) $projectId : null,
                'organisation_id' => filled($organisationId) ? (int) $organisationId : null,
            ],
            'projects' => $projects->values()->all(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $projectIds = $this->visibleProjectsQuery()->pluck('id');

        $externalUser = ExternalUser::with('project:id,name')
            ->whereIn('project_id', $projectIds)
            ->findOrFail($id);

        $projects = $this->visibleProjectsQuery()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('ExternalUsers/Edit', [
            'externalUser' => $externalUser,
            'projects' => $projects,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $projectIds = $this->visibleProjectsQuery()->pluck('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'project_id' => ['nullable', 'exists:projects,id', Rule::in($projectIds->all())],
        ]);

        $externalUser = ExternalUser::whereIn('project_id', $projectIds)
            ->findOrFail($id);

        $externalUser->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'external_user' => $this->serializeExternalUser($externalUser->load('project:id,name')),
            ]);
        }

        return redirect()->route('external-users.index')
            ->with('success', 'External user updated successfully.');
    }
}
