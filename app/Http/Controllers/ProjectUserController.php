<?php

namespace App\Http\Controllers;

use App\Enums\OrganisationRole;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use App\Services\ProjectAppErrorNotificationService;
use App\Services\ShiftPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class ProjectUserController extends Controller
{
    public function __construct(
        private readonly ShiftPermissionService $permissions,
        private readonly ProjectAppErrorNotificationService $appErrorNotifications,
    ) {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project)
    {
        // Validate the request
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
        ]);

        if (! $this->permissions->canManageProjectAccess($project, Auth::id())) {
            return response()->json(['message' => 'Unauthorized. You cannot grant access to this project.'], 403);
        }

        $organisation = $project->accessOrganisation();

        if (! $organisation) {
            return response()->json(['message' => 'Project must belong to an organisation before access can be granted.'], 422);
        }

        // Find the user by email if they exist
        $user = User::whereRaw('LOWER(email) = LOWER(?)', [$validated['email']])->first();

        // Check if the user is already a member of the project
        $existingUser = ProjectUser::where('project_id', $project->id)
            ->where(function ($query) use ($validated, $user) {
                $query->whereRaw('LOWER(user_email) = LOWER(?)', [$validated['email']]);

                if ($user) {
                    $query->orWhere('user_id', $user->id);
                }
            })
            ->first();

        if ($existingUser) {
            OrganisationUser::ensureForIdentity($organisation, $user, $validated['email'], $validated['name']);

            return response()->json(['message' => 'User already has access to this project'], 422);
        }

        // Create the project user
        $projectUser = ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $user ? $user->id : null, // Use null when the user doesn't exist
            'user_email' => $validated['email'],
            'user_name' => $validated['name'],
            'registration_status' => $user ? 'registered' : 'pending', // Set status based on user existence
        ]);

        $organisationUser = OrganisationUser::ensureForIdentity($organisation, $user, $validated['email'], $validated['name']);
        if ($organisationUser->role === null) {
            $organisationUser->forceFill(['role' => OrganisationRole::Developer])->save();
        }

        // If the user doesn't exist, send an invitation email
        if (! $user) {
            Notification::route('mail', [
                $validated['email'] => $validated['name'],
            ])->notify(new ProjectInvitationNotification($projectUser, $project));
        }

        if ($request->expectsJson()) {
            return response()->json($projectUser, 201);
        }

        return redirect()->back()->with('success', 'User granted access to project successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, ProjectUser $projectUser)
    {
        if (! $this->permissions->canManageProjectAccess($project, Auth::id())) {
            return response()->json(['message' => 'Unauthorized. You cannot remove access from this project.'], 403);
        }

        // Check if the projectUser belongs to the project
        if ($projectUser->project_id !== $project->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->appErrorNotifications->removeProjectUserRecipients([$projectUser]);
        $projectUser->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'User access to project revoked successfully.']);
        }

        return redirect()->back()->with('success', 'User access to project revoked successfully.');
    }

    /**
     * Update the user_id and registration_status when a user registers.
     * This method should be called from the registration process.
     *
     * @param  User  $user  The newly registered user
     * @return void
     */
    public static function updateUserRegistration(User $user)
    {
        // Find all project users with matching email and pending status
        $pendingProjectUsers = ProjectUser::whereRaw('LOWER(user_email) = LOWER(?)', [$user->email])
            ->where('registration_status', 'pending')
            ->get();

        // Update each project user with the new user_id and set status to registered
        foreach ($pendingProjectUsers as $projectUser) {
            $projectUser->update([
                'user_id' => $user->id,
                'registration_status' => 'registered',
            ]);

            if ($organisation = $projectUser->project?->accessOrganisation()) {
                OrganisationUser::ensureForIdentity($organisation, $user, $projectUser->user_email, $projectUser->user_name);
            }
        }
    }
}
