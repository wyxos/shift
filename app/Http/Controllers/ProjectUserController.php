<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class ProjectUserController extends Controller
{
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

        // Check if the authenticated user is the project owner
        $isOwner = $project->client?->organisation?->author_id === Auth::id() ||
                   $project->organisation?->author_id === Auth::id() ||
                   $project->author_id === Auth::id();

        if (!$isOwner) {
            return response()->json(['message' => 'Unauthorized. Only project owners can grant access.'], 403);
        }

        // Check if the user is already a member of the project
        $existingUser = ProjectUser::where('project_id', $project->id)
            ->where('user_email', $validated['email'])
            ->first();

        if ($existingUser) {
            return response()->json(['message' => 'User already has access to this project'], 422);
        }

        // Find the user by email if they exist
        $user = User::where('email', $validated['email'])->first();

        // Create the project user
        $projectUser = ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $user ? $user->id : null, // Use null when the user doesn't exist
            'user_email' => $validated['email'],
            'user_name' => $validated['name'],
            'registration_status' => $user ? 'registered' : 'pending', // Set status based on user existence
        ]);

        // If the user doesn't exist, send an invitation email
        if (!$user) {
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
        // Check if the authenticated user is the project owner
        $isOwner = $project->client?->organisation?->author_id === Auth::id() ||
                   $project->organisation?->author_id === Auth::id() ||
                   $project->author_id === Auth::id();

        if (!$isOwner) {
            return response()->json(['message' => 'Unauthorized. Only project owners can remove access.'], 403);
        }

        // Check if the projectUser belongs to the project
        if ($projectUser->project_id !== $project->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
     * @param User $user The newly registered user
     * @return void
     */
    public static function updateUserRegistration(User $user)
    {
        // Find all project users with matching email and pending status
        $pendingProjectUsers = ProjectUser::where('user_email', $user->email)
            ->where('registration_status', 'pending')
            ->get();

        // Update each project user with the new user_id and set status to registered
        foreach ($pendingProjectUsers as $projectUser) {
            $projectUser->update([
                'user_id' => $user->id,
                'registration_status' => 'registered',
            ]);
        }
    }
}
