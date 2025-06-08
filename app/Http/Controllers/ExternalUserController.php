<?php

namespace App\Http\Controllers;

use App\Models\ExternalUser;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExternalUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Get the current user
        $user = auth()->user();

        // Get the IDs of projects that the user owns or has access to
        $projectIds = Project::where('author_id', $user->id)
            ->orWhereHas('projectUser', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->pluck('id');

        $externalUsers = ExternalUser::with('project')
            ->whereIn('project_id', $projectIds)
            ->when(
                request('search'),
                fn($query) => $query->where(function($q) {
                    $search = '%' . request('search') . '%';
                    $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$search])
                      ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$search])
                      ->orWhereRaw('LOWER(environment) LIKE LOWER(?)', [$search]);
                })
            )
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('ExternalUsers/Index', [
            'externalUsers' => $externalUsers,
            'filters' => request()->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        // Get the current user
        $user = auth()->user();

        // Get the IDs of projects that the user owns or has access to
        $projectIds = Project::where('author_id', $user->id)
            ->orWhereHas('projectUser', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->pluck('id');

        $externalUser = ExternalUser::with('project')
            ->whereIn('project_id', $projectIds)
            ->findOrFail($id);

        // Only show projects the user has access to
        $projects = Project::whereIn('id', $projectIds)
            ->get(['id', 'name']);

        return Inertia::render('ExternalUsers/Edit', [
            'externalUser' => $externalUser,
            'projects' => $projects
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // Get the current user
        $user = auth()->user();

        // Get the IDs of projects that the user owns or has access to
        $projectIds = Project::where('author_id', $user->id)
            ->orWhereHas('projectUser', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->pluck('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'project_id' => 'nullable|exists:projects,id|in:' . $projectIds->implode(','),
        ]);

        $externalUser = ExternalUser::whereIn('project_id', $projectIds)
            ->findOrFail($id);

        $externalUser->update($validated);

        return redirect()->route('external-users.index')
            ->with('success', 'External user updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
