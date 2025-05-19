<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ProjectUser;
use App\Models\User;
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
            // Update the project_user record with the new user_id
            ProjectUser::where('project_id', $request->project_id)
                ->where('user_email', $request->email)
                ->update(['user_id' => $user->id]);

            return to_route('projects.index', ['highlight' => $request->project_id]);
        }

        return to_route('dashboard');
    }
}
