<?php

namespace App\Http\Controllers;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;

class OrganisationController extends Controller
{
    public function __construct(private readonly ShiftPermissionService $permissions) {}

    public function index(?Organisation $organisation = null, ?string $activePanel = null)
    {
        $userId = auth()->id();
        $sortBy = request('sort_by');
        $routeOrganisationId = $organisation?->id;
        $panel = [
            'create' => request()->boolean('create'),
            'team' => $activePanel === 'team' ? $routeOrganisationId : (request()->integer('team') ?: request()->integer('manage') ?: null),
            'manage' => request()->integer('manage') ?: null,
            'settings' => $activePanel === 'settings' ? $routeOrganisationId : (request()->integer('settings') ?: null),
        ];
        $panelOrganisationId = $panel['team'] ?: $panel['settings'];

        $organisations = Organisation::query()
            ->withCount(['organisationUsers', 'projects'])
            ->visibleToUser($userId)
            ->when(
                request('search'),
                fn (Builder $query, string $search) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%'.$search.'%'])
            );

        switch ($sortBy) {
            case 'name':
                $organisations->orderBy('name');
                break;
            case 'oldest':
                $organisations->oldest();
                break;
            default:
                $organisations->latest();
                break;
        }

        $panelOrganisation = null;
        $panelOrganisationProjects = collect();
        $panelProjectUsers = collect();

        if ($panelOrganisationId) {
            $panelOrganisation = Organisation::query()
                ->with([
                    'author:id,name,email,created_at,email_verified_at,last_login_at',
                    'organisationUsers.user:id,name,email,created_at,email_verified_at,last_login_at',
                ])
                ->find($panelOrganisationId);

            if ($panelOrganisation && ! $this->permissions->canManageOrganisationAccess($panelOrganisation, $userId)) {
                $panelOrganisation = null;
            }

            if ($panelOrganisation) {
                $panelOrganisationProjects = Project::query()
                    ->where(function (Builder $query) use ($panelOrganisation) {
                        $query
                            ->where('organisation_id', $panelOrganisation->id)
                            ->orWhereHas('client', function (Builder $clientQuery) use ($panelOrganisation) {
                                $clientQuery->where('organisation_id', $panelOrganisation->id);
                            });
                    })
                    ->orderBy('name')
                    ->get(['id', 'name']);

                $panelProjectUsers = ProjectUser::query()
                    ->whereIn('project_id', $panelOrganisationProjects->pluck('id'))
                    ->get(['id', 'project_id', 'user_id', 'user_email']);
            }
        }

        return inertia('Organisations/Index')->with([
            'filters' => request()->only(['search', 'sort_by']),
            'organisations' => $organisations
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Organisation $organisation) => [
                    ...$organisation->toArray(),
                    'isOwner' => $organisation->author_id === $userId,
                    ...$this->permissions->organisationCapabilities($organisation, $userId),
                ]),
            'accessUsers' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'panel' => $panel,
            'panelOrganisation' => $panelOrganisation ? [
                'id' => $panelOrganisation->id,
                'name' => $panelOrganisation->name,
                'projects' => $panelOrganisationProjects
                    ->map(fn (Project $project) => [
                        'id' => $project->id,
                        'name' => $project->name,
                    ])
                    ->values()
                    ->all(),
                'roleOptions' => $this->permissions->organisationRoleOptions($panelOrganisation, $userId),
                'canManageTeamRoles' => $this->permissions->canManageOrganisationAccess($panelOrganisation, $userId),
                'teamUsers' => collect([
                    $panelOrganisation->author ? [
                        'id' => 'owner-'.$panelOrganisation->author->id,
                        'name' => $panelOrganisation->author->name,
                        'email' => $panelOrganisation->author->email,
                        'status' => 'owner',
                        'statusLabel' => 'Owner',
                        'role' => OrganisationRole::Administrator->value,
                        'roleLabel' => OrganisationRole::Administrator->label(),
                        'canManageRole' => false,
                        'roleOptions' => [],
                        'projectIds' => $panelOrganisationProjects->pluck('id')->values()->all(),
                        'projectAccessCount' => $panelOrganisationProjects->count(),
                        'createdAt' => $panelOrganisation->author->created_at?->toISOString(),
                        'verifiedAt' => $panelOrganisation->author->email_verified_at?->toISOString(),
                        'lastLoginAt' => $panelOrganisation->author->last_login_at?->toISOString(),
                    ] : null,
                ])
                    ->filter()
                    ->merge(
                        $panelOrganisation->organisationUsers
                            ->reject(fn ($organisationUser) => $organisationUser->user_id === $panelOrganisation->author_id
                                || strcasecmp($organisationUser->user_email, $panelOrganisation->author?->email ?: '') === 0)
                            ->map(function ($organisationUser) use ($panelOrganisation, $panelProjectUsers, $userId) {
                                $projectIds = $panelProjectUsers
                                    ->filter(function (ProjectUser $projectUser) use ($organisationUser) {
                                        if ($organisationUser->user_id) {
                                            return $projectUser->user_id === $organisationUser->user_id
                                                || strcasecmp($projectUser->user_email, $organisationUser->user_email) === 0;
                                        }

                                        return strcasecmp($projectUser->user_email, $organisationUser->user_email) === 0;
                                    })
                                    ->pluck('project_id')
                                    ->unique()
                                    ->values()
                                    ->all();

                                return [
                                    'id' => 'access-'.$organisationUser->id,
                                    'organisationUserId' => $organisationUser->id,
                                    'name' => $organisationUser->user?->name ?: $organisationUser->user_name,
                                    'email' => $organisationUser->user?->email ?: $organisationUser->user_email,
                                    'status' => $organisationUser->user_id ? 'registered' : 'pending',
                                    'statusLabel' => $organisationUser->user_id ? 'Registered' : 'Pending invitation',
                                    'role' => $organisationUser->role?->value,
                                    'roleLabel' => $organisationUser->role?->label(),
                                    'canManageRole' => $this->permissions->canManageOrganisationUserRole($panelOrganisation, $organisationUser, $userId),
                                    'roleOptions' => $this->permissions->organisationRoleOptions($panelOrganisation, $userId, $organisationUser),
                                    'projectIds' => $projectIds,
                                    'projectAccessCount' => count($projectIds),
                                    'createdAt' => ($organisationUser->user?->created_at ?: $organisationUser->created_at)?->toISOString(),
                                    'verifiedAt' => $organisationUser->user?->email_verified_at?->toISOString(),
                                    'lastLoginAt' => $organisationUser->user?->last_login_at?->toISOString(),
                                ];
                            })
                    )
                    ->values()
                    ->all(),
            ] : null,
        ]);
    }

    public function sidebar()
    {
        $userId = auth()->id();
        abort_unless($userId, 401);

        $limit = min(max(request()->integer('limit', 5), 1), 10);
        $offset = max(request()->integer('offset', 0), 0);
        $search = trim((string) request('search', ''));

        $organisations = Organisation::query()
            ->visibleToUser($userId)
            ->when($search !== '', fn (Builder $query) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%'.$search.'%']))
            ->orderBy('name')
            ->skip($offset)
            ->limit($limit + 1)
            ->get(['id', 'name', 'author_id']);

        return response()->json([
            'items' => $organisations
                ->take($limit)
                ->map(fn (Organisation $organisation) => [
                    'id' => $organisation->id,
                    'name' => $organisation->name,
                    'isOwner' => $organisation->author_id === $userId,
                    ...$this->permissions->organisationCapabilities($organisation, $userId),
                ])
                ->values(),
            'hasMore' => $organisations->count() > $limit,
        ]);
    }

    public function team(Organisation $organisation)
    {
        return $this->index($organisation, 'team');
    }

    public function settings(Organisation $organisation)
    {
        return $this->index($organisation, 'settings');
    }

    public function destroy(Organisation $organisation)
    {
        abort_unless($this->permissions->canManageOrganisation($organisation, auth()->id()), 403);

        $organisation->delete();

        return redirect()->route('organisations.index')->with('success', 'Organisation deleted successfully.');
    }

    public function update(Organisation $organisation)
    {
        abort_unless($this->permissions->canManageOrganisation($organisation, auth()->id()), 403);

        $organisation->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->back()->with('success', 'Organisation updated successfully.');
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
        ]);

        $organisation = Organisation::create([
            ...$validated,
            'author_id' => auth()->id(),
        ]);

        $user = auth()->user();
        if ($user) {
            $organisation->organisationUsers()->create([
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_name' => $user->name,
                'role' => OrganisationRole::Administrator->value,
            ]);
        }

        return redirect()->route('organisations.index')->with('success', 'Organisation created successfully.');
    }

    public function users(Organisation $organisation)
    {
        if (! $this->permissions->canManageOrganisationAccess($organisation, auth()->id())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $organisationUsers = $organisation->organisationUsers()
            ->with('user')
            ->get();

        return response()->json($organisationUsers);
    }
}
