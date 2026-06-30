<?php

namespace App\Http\Controllers;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\OrganisationAccessNotification;
use App\Notifications\OrganisationInvitationNotification;
use App\Services\ProjectAppErrorNotificationService;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class OrganisationUserController extends Controller
{
    public function __construct(
        private readonly ShiftPermissionService $permissions,
        private readonly ProjectAppErrorNotificationService $appErrorNotifications,
    ) {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Organisation $organisation)
    {
        // Validate the request
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'role' => ['nullable', Rule::enum(OrganisationRole::class)],
        ]);

        if (! $this->permissions->canManageOrganisationAccess($organisation, Auth::id())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $role = OrganisationRole::tryFrom($validated['role'] ?? '') ?? OrganisationRole::Developer;
        if (! $this->permissions->canAssignOrganisationRole($organisation, Auth::id(), $role)) {
            return response()->json(['message' => 'You cannot assign that organisation role.'], 403);
        }

        // Find the user by email if they exist
        $user = User::whereRaw('LOWER(email) = LOWER(?)', [$validated['email']])->first();

        // Check if the user is already a member of the organisation
        $existingUser = OrganisationUser::matchingIdentity($organisation, $user, $validated['email'])->first();

        if ($existingUser) {
            return response()->json(['message' => 'User is already a member of this organisation'], 422);
        }

        // Create the organisation user
        $organisationUser = OrganisationUser::create([
            'organisation_id' => $organisation->id,
            'user_id' => $user ? $user->id : null, // Use null when the user doesn't exist
            'user_email' => $validated['email'],
            'user_name' => $validated['name'],
            'role' => $role->value,
        ]);

        // Send appropriate notification based on whether the user exists or not
        if (! $user) {
            // For new users, send an invitation email with registration link
            Notification::route('mail', [
                $validated['email'] => $validated['name'],
            ])->notify(new OrganisationInvitationNotification($organisationUser, $organisation));
        } else {
            // For existing users, send an access notification
            $user->notify(new OrganisationAccessNotification($organisationUser, $organisation));
        }

        if ($request->expectsJson()) {
            return response()->json($organisationUser, 201);
        }

        return redirect()->back()->with('success', 'User invited to organisation successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Organisation $organisation, OrganisationUser $organisationUser)
    {
        if (
            $organisationUser->organisation_id !== $organisation->id
            || ! $this->permissions->canManageOrganisation($organisation, Auth::id())
        ) {
            abort(403);
        }

        $projectIds = $this->organisationProjectIds($organisation);
        $projectUsers = $this->projectUserQuery($organisationUser, $projectIds)->get();
        $this->appErrorNotifications->removeProjectUserRecipients($projectUsers);
        ProjectUser::query()->whereKey($projectUsers->pluck('id'))->delete();
        $organisationUser->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'User removed from organisation successfully.']);
        }

        return redirect()->back()->with('success', 'User removed from organisation successfully.');
    }

    public function syncProjects(Request $request, Organisation $organisation, OrganisationUser $organisationUser)
    {
        $this->authorizeOrganisationUser($organisation, $organisationUser);

        $validated = $request->validate([
            'project_ids' => 'array',
            'project_ids.*' => 'integer',
            'role' => ['nullable', Rule::enum(OrganisationRole::class)],
        ]);

        $role = OrganisationRole::tryFrom($validated['role'] ?? '');
        if ($role instanceof OrganisationRole) {
            if (! $this->permissions->canSetOrganisationUserRole($organisation, $organisationUser, Auth::id(), $role)) {
                abort(403);
            }

            $organisationUser->forceFill([
                'role' => $role->value,
            ])->save();
        }

        $projectIds = $this->manageableOrganisationProjectIds($organisation);
        $selectedProjectIds = collect($validated['project_ids'] ?? [])
            ->map(fn ($projectId) => (int) $projectId)
            ->unique()
            ->values();

        if ($selectedProjectIds->diff($projectIds)->isNotEmpty()) {
            return response()->json(['message' => 'One or more projects are not available for this organisation.'], 422);
        }

        $existingProjectUsers = $this->projectUserQuery($organisationUser, $projectIds)->get();
        $existingSelectedProjectUsers = $existingProjectUsers->whereIn('project_id', $selectedProjectIds);
        $existingSelectedProjectIds = $existingSelectedProjectUsers->pluck('project_id')->unique()->values();
        $deletedProjectUsers = $existingProjectUsers->whereNotIn('project_id', $selectedProjectIds);
        $deleteIds = $deletedProjectUsers->pluck('id');

        if ($deleteIds->isNotEmpty()) {
            $this->appErrorNotifications->removeProjectUserRecipients($deletedProjectUsers);
            ProjectUser::query()->whereKey($deleteIds)->delete();
        }

        foreach ($existingSelectedProjectUsers as $projectUser) {
            $projectUser->update($this->projectUserPayload($organisationUser));
        }

        $selectedProjectIds
            ->diff($existingSelectedProjectIds)
            ->each(function (int $projectId) use ($organisationUser) {
                ProjectUser::create([
                    'project_id' => $projectId,
                    ...$this->projectUserPayload($organisationUser),
                ]);
            });

        return response()->json([
            'project_ids' => $selectedProjectIds->all(),
            'organisation_user' => [
                'id' => $organisationUser->id,
                'role' => $organisationUser->role?->value,
                'role_label' => $organisationUser->role?->label(),
            ],
        ]);
    }

    private function authorizeOrganisationUser(Organisation $organisation, OrganisationUser $organisationUser): void
    {
        if ($organisationUser->organisation_id !== $organisation->id) {
            abort(403);
        }

        if ($this->permissions->canManageOrganisation($organisation, Auth::id())) {
            return;
        }

        if ($this->permissions->canManageOrganisationUserRole($organisation, $organisationUser, Auth::id())) {
            return;
        }

        abort(403);
    }

    /**
     * @return Collection<int, int>
     */
    private function organisationProjectIds(Organisation $organisation): Collection
    {
        return Project::query()
            ->where(function (Builder $query) use ($organisation) {
                $query
                    ->where('organisation_id', $organisation->id)
                    ->orWhereHas('client', function (Builder $clientQuery) use ($organisation) {
                        $clientQuery->where('organisation_id', $organisation->id);
                    });
            })
            ->pluck('id');
    }

    /**
     * @return Collection<int, int>
     */
    private function manageableOrganisationProjectIds(Organisation $organisation): Collection
    {
        $projectIds = $this->organisationProjectIds($organisation);

        if ($this->permissions->canManageOrganisation($organisation, Auth::id())) {
            return $projectIds;
        }

        if ($projectIds->isEmpty()) {
            return $projectIds;
        }

        return Project::query()
            ->whereIn('id', $projectIds)
            ->get()
            ->filter(fn (Project $project) => $this->permissions->canManageProjectAccess($project, Auth::id()))
            ->pluck('id')
            ->values();
    }

    /**
     * @param  Collection<int, int>  $projectIds
     */
    private function projectUserQuery(OrganisationUser $organisationUser, Collection $projectIds): Builder
    {
        return ProjectUser::query()
            ->whereIn('project_id', $projectIds)
            ->where(function (Builder $query) use ($organisationUser) {
                if ($organisationUser->user_id) {
                    $query->where('user_id', $organisationUser->user_id)
                        ->orWhereRaw('LOWER(user_email) = LOWER(?)', [$organisationUser->user_email]);

                    return;
                }

                $query->whereRaw('LOWER(user_email) = LOWER(?)', [$organisationUser->user_email]);
            });
    }

    /**
     * @return array{user_id: int|null, user_email: string, user_name: string, registration_status: string}
     */
    private function projectUserPayload(OrganisationUser $organisationUser): array
    {
        return [
            'user_id' => $organisationUser->user_id,
            'user_email' => $organisationUser->user_email,
            'user_name' => $organisationUser->user_name,
            'registration_status' => $organisationUser->user_id ? 'registered' : 'pending',
        ];
    }
}
