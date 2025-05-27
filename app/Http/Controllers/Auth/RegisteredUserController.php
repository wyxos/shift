<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Notifications\ProjectUserRegisteredNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Register', [
            'email' => $request->email,
            'name' => $request->name,
            'project_id' => $request->project_id,
            'organisation_id' => $request->organisation_id,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'project_id' => 'nullable|exists:projects,id',
            'organisation_id' => 'nullable|exists:organisations,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        // If the user was invited to a project, update the project_user record and redirect to the project
        if ($request->project_id) {
            // Update the project_user record with the new user_id and set registration_status to registered
            ProjectUser::where('project_id', $request->project_id)
                ->where('user_email', $request->email)
                ->update([
                    'user_id' => $user->id,
                    'registration_status' => 'registered'
                ]);

            // Notify the project owner that a user has completed registration
            $project = Project::find($request->project_id);
            if ($project && $project->author) {
                $project->author->notify(new ProjectUserRegisteredNotification($user, $project));
            }

            return to_route('projects.index', ['highlight' => $request->project_id]);
        }

        // If the user was invited to an organisation, update the organisation_user record and redirect to the organisation
        if ($request->organisation_id) {
            // Update the organisation_user record with the new user_id
            OrganisationUser::where('organisation_id', $request->organisation_id)
                ->where('user_email', $request->email)
                ->update(['user_id' => $user->id]);

            return to_route('organisations.index', ['highlight' => $request->organisation_id]);
        }

        return to_route('dashboard');
    }
}
