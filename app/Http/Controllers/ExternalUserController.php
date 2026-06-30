<?php

namespace App\Http\Controllers;

use App\Enums\ExternalUserRole;
use App\Models\ExternalContact;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\Project;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExternalUserController extends Controller
{
    public function __construct(private readonly ShiftPermissionService $permissions) {}

    /**
     * @var array<int, Collection<int, ExternalUser>>
     */
    private array $projectExternalUsers = [];

    private function serializeExternalUser(ExternalUser $externalUser): array
    {
        $externalUser->loadMissing('project');

        $canManageLinkedAccounts = $externalUser->project instanceof Project
            && $this->permissions->canManageExternalRoles($externalUser->project, auth()->id());

        return [
            'id' => $externalUser->id,
            'name' => $externalUser->name,
            'email' => $externalUser->email,
            'environment' => $externalUser->environment,
            'role' => $externalUser->role?->value,
            'role_label' => $externalUser->role?->label(),
            'can_manage_role' => $canManageLinkedAccounts,
            'can_update_role' => $externalUser->project
                ? $this->permissions->canManageExternalRoles($externalUser->project, auth()->id())
                : false,
            'linked_accounts' => $this->linkedAccountsFor($externalUser, $canManageLinkedAccounts),
            'linkable_accounts' => $canManageLinkedAccounts ? $this->linkableAccountsFor($externalUser) : [],
            'links' => [
                'link_accounts' => $canManageLinkedAccounts ? route('external-users.linked-accounts.store', $externalUser) : null,
            ],
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

    private function visibleExternalUser(mixed $id): ExternalUser
    {
        $projectIds = $this->visibleProjectsQuery()->pluck('id');

        return ExternalUser::with('project')
            ->whereIn('project_id', $projectIds)
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, ExternalUser>
     */
    private function externalUsersForProject(int $projectId): Collection
    {
        if (! array_key_exists($projectId, $this->projectExternalUsers)) {
            $this->projectExternalUsers[$projectId] = ExternalUser::with('project')
                ->where('project_id', $projectId)
                ->orderBy('name')
                ->orderBy('email')
                ->orderBy('id')
                ->get();
        }

        return $this->projectExternalUsers[$projectId];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedAccountsFor(ExternalUser $externalUser, bool $canManageLinkedAccounts): array
    {
        if ($externalUser->project_id === null || $externalUser->external_contact_id === null) {
            return [];
        }

        return $this->externalUsersForProject((int) $externalUser->project_id)
            ->filter(fn (ExternalUser $account) => $account->id !== $externalUser->id
                && $account->external_contact_id === $externalUser->external_contact_id)
            ->map(fn (ExternalUser $account) => $this->serializeLinkedAccount($externalUser, $account, $canManageLinkedAccounts))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkableAccountsFor(ExternalUser $externalUser): array
    {
        if ($externalUser->project_id === null) {
            return [];
        }

        return $this->externalUsersForProject((int) $externalUser->project_id)
            ->filter(fn (ExternalUser $account) => $account->id !== $externalUser->id
                && (
                    $externalUser->external_contact_id === null
                    || $account->external_contact_id === null
                    || $account->external_contact_id !== $externalUser->external_contact_id
                ))
            ->map(fn (ExternalUser $account) => $this->serializeAccountOption($account))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLinkedAccount(ExternalUser $externalUser, ExternalUser $account, bool $canUnlink): array
    {
        return [
            ...$this->serializeAccountOption($account),
            'can_unlink' => $canUnlink,
            'unlink_url' => $canUnlink ? route('external-users.linked-accounts.destroy', [$externalUser, $account]) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccountOption(ExternalUser $account): array
    {
        $account->loadMissing('project');

        return [
            'id' => $account->id,
            'label' => $account->name ?: $account->email ?: 'Account '.$account->id,
            'name' => $account->name,
            'email' => $account->email,
            'environment' => $account->environment,
            'role' => $account->role?->value,
            'role_label' => $account->role?->label(),
            'project' => $account->project ? [
                'id' => $account->project->id,
                'name' => $account->project->name,
            ] : null,
        ];
    }

    private function ensureContact(ExternalUser $externalUser): ExternalContact
    {
        $externalUser->loadMissing('contact');

        if ($externalUser->contact instanceof ExternalContact) {
            return $externalUser->contact;
        }

        $contact = ExternalContact::create([
            'project_id' => $externalUser->project_id,
        ]);

        $externalUser->forceFill([
            'external_contact_id' => $contact->id,
        ])->save();
        $externalUser->setRelation('contact', $contact);

        return $contact;
    }

    private function assertCanManageLinkedAccounts(ExternalUser $externalUser, Request $request): void
    {
        abort_unless(
            $externalUser->project instanceof Project
                && $this->permissions->canManageExternalRoles($externalUser->project, $request->user()?->id),
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

        $externalUsers = ExternalUser::with('project')
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
            'role' => ['nullable', Rule::enum(ExternalUserRole::class)],
        ]);

        $externalUser = ExternalUser::with('project')
            ->whereIn('project_id', $projectIds)
            ->findOrFail($id);

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
        ];

        if ($request->has('role')) {
            abort_unless(
                $externalUser->project instanceof Project
                    && $this->permissions->canManageExternalRoles($externalUser->project, $request->user()?->id),
                403,
            );

            $payload['role'] = $validated['role'];
        }

        $externalUser->update($payload);

        if ($request->expectsJson()) {
            return response()->json([
                'external_user' => $this->serializeExternalUser($externalUser->load('project')),
            ]);
        }

        return $this->redirectToExternalUsersList($externalUser)
            ->with('success', 'External user updated successfully.');
    }

    private function redirectToExternalUsersList(ExternalUser $externalUser): RedirectResponse
    {
        $externalUser->loadMissing('project.organisation', 'project.client.organisation');

        $organisation = $externalUser->project?->accessOrganisation();

        if ($organisation) {
            return redirect()->route('organisation.external-users', [
                'organisation' => $organisation,
                'project_id' => $externalUser->project_id,
            ]);
        }

        return redirect()->route('dashboard');
    }

    public function linkAccount(Request $request, string $externalUser)
    {
        $validated = $request->validate([
            'linked_external_user_id' => ['required', 'integer'],
        ]);

        $source = $this->visibleExternalUser($externalUser);
        $this->assertCanManageLinkedAccounts($source, $request);

        $linked = ExternalUser::with('project')
            ->where('project_id', $source->project_id)
            ->whereKey($validated['linked_external_user_id'])
            ->firstOrFail();

        abort_if($linked->id === $source->id, 422, 'An external account cannot be linked to itself.');

        DB::transaction(function () use ($source, $linked) {
            $contact = $this->ensureContact($source);

            if ($linked->external_contact_id === $contact->id) {
                return;
            }

            if ($linked->external_contact_id !== null) {
                ExternalUser::query()
                    ->where('project_id', $source->project_id)
                    ->where('external_contact_id', $linked->external_contact_id)
                    ->update(['external_contact_id' => $contact->id]);

                return;
            }

            $linked->forceFill([
                'external_contact_id' => $contact->id,
            ])->save();
        });

        if ($request->expectsJson()) {
            $this->projectExternalUsers = [];

            return response()->json([
                'external_user' => $this->serializeExternalUser($source->refresh()->load('project')),
            ]);
        }

        return back()->with('success', 'External account linked successfully.');
    }

    public function unlinkAccount(Request $request, string $externalUser, string $linkedExternalUser)
    {
        $source = $this->visibleExternalUser($externalUser);
        $this->assertCanManageLinkedAccounts($source, $request);

        $linked = ExternalUser::with('project')
            ->where('project_id', $source->project_id)
            ->whereKey($linkedExternalUser)
            ->firstOrFail();

        abort_if($linked->id === $source->id, 422, 'An external account cannot be unlinked from itself.');
        abort_unless(
            $source->external_contact_id !== null
                && $linked->external_contact_id === $source->external_contact_id,
            404,
        );

        DB::transaction(function () use ($linked) {
            $contact = ExternalContact::create([
                'project_id' => $linked->project_id,
            ]);

            $linked->forceFill([
                'external_contact_id' => $contact->id,
            ])->save();
        });

        if ($request->expectsJson()) {
            $this->projectExternalUsers = [];

            return response()->json([
                'external_user' => $this->serializeExternalUser($source->refresh()->load('project')),
            ]);
        }

        return back()->with('success', 'External account unlinked successfully.');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roles(): array
    {
        return collect(ExternalUserRole::cases())
            ->map(fn (ExternalUserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->all();
    }
}
