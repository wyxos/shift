<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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

        // Check if the user is already a member of the organisation
        $existingUser = OrganisationUser::where('organisation_id', $organisation->id)
            ->where('user_email', $validated['email'])
            ->first();

        if ($existingUser) {
            return response()->json(['message' => 'User is already a member of this organisation'], 422);
        }

        // Find the user by email if they exist
        $user = User::where('email', $validated['email'])->first();

        // Create the organisation user
        $organisationUser = OrganisationUser::create([
            'organisation_id' => $organisation->id,
            'user_id' => $user ? $user->id : null, // Use null when the user doesn't exist
            'user_email' => $validated['email'],
            'user_name' => $validated['name'],
        ]);

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
        // Check if the authenticated user is the author of the organisation
        if ($organisation->author_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if the organisationUser belongs to the organisation
        if ($organisationUser->organisation_id !== $organisation->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $organisationUser->delete();

        if (request()->expectsJson()) {
            return response()->json(['message' => 'User removed from organisation successfully.']);
        }

        return redirect()->back()->with('success', 'User removed from organisation successfully.');
    }
}
