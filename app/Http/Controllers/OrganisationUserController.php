<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\OrganisationAccessNotification;
use App\Notifications\OrganisationInvitationNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class OrganisationUserController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Organisation $organisation)
    {
        // Validate the request
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
        ]);

        // Check if the authenticated user is the author of the organisation
        if ($organisation->author_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Find the user by email if they exist
        $user = User::where('email', $validated['email'])->first();

        // Check if the user is already a member of the organisation
        $existingUser = OrganisationUser::where('organisation_id', $organisation->id)
            ->where(function ($query) use ($validated, $user) {
                $query->where('user_email', $validated['email']);
                if ($user) {
                    $query->orWhere('user_id', $user->id);
                }
            })
            ->first();

        if ($existingUser) {
            return response()->json(['message' => 'User is already a member of this organisation'], 422);
        }

        // Create the organisation user
        $organisationUser = OrganisationUser::create([
            'organisation_id' => $organisation->id,
            'user_id' => $user ? $user->id : null, // Use null when the user doesn't exist
            'user_email' => $validated['email'],
            'user_name' => $validated['name'],
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
        $this->authorizeOrganisationUser($organisation, $organisationUser);

        $projectIds = $this->organisationProjectIds($organisation);
        $this->projectUserQuery($organisationUser, $projectIds)->delete();
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
        ]);

        $projectIds = $this->organisationProjectIds($organisation);
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
        $deleteIds = $existingProjectUsers
            ->whereNotIn('project_id', $selectedProjectIds)
            ->pluck('id');

        if ($deleteIds->isNotEmpty()) {
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
        ]);
    }

    private function authorizeOrganisationUser(Organisation $organisation, OrganisationUser $organisationUser): void
    {
        if ($organisation->author_id !== Auth::id() || $organisationUser->organisation_id !== $organisation->id) {
            abort(403);
        }
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
     * @param  Collection<int, int>  $projectIds
     */
    private function projectUserQuery(OrganisationUser $organisationUser, Collection $projectIds): Builder
    {
        return ProjectUser::query()
            ->whereIn('project_id', $projectIds)
            ->where(function (Builder $query) use ($organisationUser) {
                if ($organisationUser->user_id) {
                    $query->where('user_id', $organisationUser->user_id)
                        ->orWhere('user_email', $organisationUser->user_email);

                    return;
                }

                $query->where('user_email', $organisationUser->user_email);
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
