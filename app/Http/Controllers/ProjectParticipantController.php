<?php

namespace App\Http\Controllers;

use App\Enums\ExternalIdentityRole;
use App\Models\ExternalIdentity;
use App\Models\Organisation;
use App\Models\Project;
use App\Services\ProjectParticipantService;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProjectParticipantController extends Controller
{
    public function __construct(
        private readonly ShiftPermissionService $permissions,
        private readonly ProjectParticipantService $participants,
    ) {}

    /**
     * @var array<int, Collection<int, ExternalIdentity>>
     */
    private array $projectExternalIdentities = [];

    private function serializeExternalIdentity(ExternalIdentity $externalIdentity): array
    {
        $externalIdentity->loadMissing('project');

        $canManageLinkedAccounts = $externalIdentity->project instanceof Project
            && $this->permissions->canManageExternalRoles($externalIdentity->project, auth()->id());

        return [
            'id' => $externalIdentity->id,
            'name' => $externalIdentity->name,
            'email' => $externalIdentity->email,
            'environment' => $externalIdentity->environment,
            'role' => $externalIdentity->role?->value,
            'role_label' => $externalIdentity->role?->label(),
            'can_manage_role' => $canManageLinkedAccounts,
            'can_edit_profile' => $canManageLinkedAccounts || $externalIdentity->project?->accessOrganisation() !== null,
            'can_update_role' => $externalIdentity->project
                ? $this->permissions->canManageExternalRoles($externalIdentity->project, auth()->id())
                : false,
            'linked_accounts' => $this->linkedAccountsFor($externalIdentity, $canManageLinkedAccounts),
            'linkable_accounts' => $canManageLinkedAccounts ? $this->linkableAccountsFor($externalIdentity) : [],
            'links' => [
                'link_accounts' => $canManageLinkedAccounts ? route('project-participants.linked-accounts.store', $externalIdentity) : null,
            ],
            'project' => $externalIdentity->project ? [
                'id' => $externalIdentity->project->id,
                'name' => $externalIdentity->project->name,
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

    private function visibleExternalIdentity(mixed $id): ExternalIdentity
    {
        $projectIds = $this->visibleProjectsQuery()->pluck('id');

        return ExternalIdentity::with('project')
            ->whereIn('project_id', $projectIds)
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, ExternalIdentity>
     */
    private function externalIdentitiesForProject(int $projectId): Collection
    {
        if (! array_key_exists($projectId, $this->projectExternalIdentities)) {
            $this->projectExternalIdentities[$projectId] = ExternalIdentity::with('project')
                ->where('project_id', $projectId)
                ->orderBy('name')
                ->orderBy('email')
                ->orderBy('id')
                ->get();
        }

        return $this->projectExternalIdentities[$projectId];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedAccountsFor(ExternalIdentity $externalIdentity, bool $canManageLinkedAccounts): array
    {
        if ($externalIdentity->project_id === null || $externalIdentity->project_participant_id === null) {
            return [];
        }

        return $this->externalIdentitiesForProject((int) $externalIdentity->project_id)
            ->filter(fn (ExternalIdentity $account) => $account->id !== $externalIdentity->id
                && $account->project_participant_id === $externalIdentity->project_participant_id)
            ->map(fn (ExternalIdentity $account) => $this->serializeLinkedAccount($externalIdentity, $account, $canManageLinkedAccounts))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkableAccountsFor(ExternalIdentity $externalIdentity): array
    {
        if ($externalIdentity->project_id === null) {
            return [];
        }

        return $this->externalIdentitiesForProject((int) $externalIdentity->project_id)
            ->filter(fn (ExternalIdentity $account) => $account->id !== $externalIdentity->id
                && (
                    $externalIdentity->project_participant_id === null
                    || $account->project_participant_id === null
                    || $account->project_participant_id !== $externalIdentity->project_participant_id
                ))
            ->map(fn (ExternalIdentity $account) => $this->serializeAccountOption($account))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLinkedAccount(ExternalIdentity $externalIdentity, ExternalIdentity $account, bool $canUnlink): array
    {
        return [
            ...$this->serializeAccountOption($account),
            'can_unlink' => $canUnlink,
            'unlink_url' => $canUnlink ? route('project-participants.linked-accounts.destroy', [$externalIdentity, $account]) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccountOption(ExternalIdentity $account): array
    {
        $account->loadMissing('project');

        return [
            'id' => $account->id,
            'label' => $account->name ?: $account->email ?: 'Account '.$account->id,
            'name' => $account->name,
            'email' => $account->email,
            'environment' => $account->environment,
            'url' => $account->url,
            'role' => $account->role?->value,
            'role_label' => $account->role?->label(),
            'project' => $account->project ? [
                'id' => $account->project->id,
                'name' => $account->project->name,
            ] : null,
        ];
    }

    private function assertCanManageLinkedAccounts(ExternalIdentity $externalIdentity, Request $request): void
    {
        abort_unless(
            $externalIdentity->project instanceof Project
                && $this->permissions->canManageExternalRoles($externalIdentity->project, $request->user()?->id),
            403,
        );
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
            ->get(['id', 'name', 'author_id', 'organisation_id', 'client_id']);
        $projectIds = $projects->pluck('id');

        $externalIdentities = ExternalIdentity::with('project')
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
                $externalIdentities->orderBy('name');
                break;
            case 'oldest':
                $externalIdentities->oldest();
                break;
            default:
                $externalIdentities->latest();
                break;
        }

        $externalIdentities = $externalIdentities
            ->paginate(10)
            ->withQueryString();

        $externalIdentities->through(fn (ExternalIdentity $externalIdentity) => $this->serializeExternalIdentity($externalIdentity));

        return Inertia::render('ProjectParticipants/Index', [
            'externalIdentities' => $externalIdentities,
            'filters' => [
                'search' => request('search'),
                'sort_by' => request('sort_by'),
                'project_id' => filled($projectId) ? (int) $projectId : null,
                'organisation_id' => filled($organisationId) ? (int) $organisationId : null,
            ],
            'projects' => $projects
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                ])
                ->values()
                ->all(),
            'roles' => $this->roles(),
            'canManageExternalRoles' => $projects->contains(
                fn (Project $project) => $this->permissions->canManageExternalRoles($project, auth()->id()),
            ),
            'canManageLinkedAccounts' => $projects->contains(
                fn (Project $project) => $this->permissions->canManageExternalRoles($project, auth()->id()),
            ),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $projectIds = $this->visibleProjectsQuery()->pluck('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'role' => ['nullable', Rule::enum(ExternalIdentityRole::class)],
        ]);

        $externalIdentity = ExternalIdentity::with('project')
            ->whereIn('project_id', $projectIds)
            ->findOrFail($id);

        abort_unless(
            $externalIdentity->project?->accessOrganisation() !== null
                || $this->permissions->canManageExternalRoles($externalIdentity->project, $request->user()?->id),
            403,
        );

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
        ];

        if ($request->has('role')) {
            abort_unless(
                $externalIdentity->project instanceof Project
                    && $this->permissions->canManageExternalRoles($externalIdentity->project, $request->user()?->id),
                403,
            );

            $payload['role'] = $validated['role'];
        }

        $externalIdentity->update($payload);

        if ($request->expectsJson()) {
            return response()->json([
                'external_identity' => $this->serializeExternalIdentity($externalIdentity->load('project')),
                'external_user' => $this->serializeExternalIdentity($externalIdentity),
            ]);
        }

        return $this->redirectToExternalIdentitiesList($externalIdentity)
            ->with('success', 'External user updated successfully.');
    }

    private function redirectToExternalIdentitiesList(ExternalIdentity $externalIdentity): RedirectResponse
    {
        $externalIdentity->loadMissing('project.organisation', 'project.client.organisation');

        $organisation = $externalIdentity->project?->accessOrganisation();

        if ($organisation) {
            return redirect()->route('organisation.project-participants', [
                'organisation' => $organisation,
                'project_id' => $externalIdentity->project_id,
            ]);
        }

        return redirect()->route('project-participants.index', ['project_id' => $externalIdentity->project_id]);
    }

    public function linkAccount(Request $request, string $externalIdentity)
    {
        $request->merge(['linked_external_identity_id' => $request->input('linked_external_identity_id', $request->input('linked_external_user_id'))]);
        $validated = $request->validate([
            'linked_external_identity_id' => ['required', 'integer'],
        ]);

        $source = $this->visibleExternalIdentity($externalIdentity);
        $this->assertCanManageLinkedAccounts($source, $request);

        $linked = ExternalIdentity::with('project')
            ->where('project_id', $source->project_id)
            ->whereKey($validated['linked_external_identity_id'])
            ->firstOrFail();

        abort_if($linked->id === $source->id, 422, 'An external account cannot be linked to itself.');

        $this->participants->link($source, $linked, $request->user());

        if ($request->expectsJson()) {
            $this->projectExternalIdentities = [];

            return response()->json([
                'external_identity' => $this->serializeExternalIdentity($source->refresh()->load('project')),
                'external_user' => $this->serializeExternalIdentity($source),
            ]);
        }

        return back()->with('success', 'External account linked successfully.');
    }

    public function unlinkAccount(Request $request, string $externalIdentity, string $linkedExternalIdentity)
    {
        $source = $this->visibleExternalIdentity($externalIdentity);
        $this->assertCanManageLinkedAccounts($source, $request);

        $linked = ExternalIdentity::with('project')
            ->where('project_id', $source->project_id)
            ->whereKey($linkedExternalIdentity)
            ->firstOrFail();

        abort_if($linked->id === $source->id, 422, 'An external account cannot be unlinked from itself.');
        abort_unless(
            $source->project_participant_id !== null
                && $linked->project_participant_id === $source->project_participant_id,
            404,
        );

        $this->participants->unlink($source, $linked, $request->user());

        if ($request->expectsJson()) {
            $this->projectExternalIdentities = [];

            return response()->json([
                'external_identity' => $this->serializeExternalIdentity($source->refresh()->load('project')),
                'external_user' => $this->serializeExternalIdentity($source),
            ]);
        }

        return back()->with('success', 'External account unlinked successfully.');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roles(): array
    {
        return collect(ExternalIdentityRole::cases())
            ->map(fn (ExternalIdentityRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->all();
    }
}
