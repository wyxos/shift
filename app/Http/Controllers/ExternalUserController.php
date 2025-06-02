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
        $externalUsers = ExternalUser::with('project')->paginate(10);

        return Inertia::render('ExternalUsers/Index', [
            'externalUsers' => $externalUsers
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
        $externalUser = ExternalUser::with('project')->findOrFail($id);
        $projects = Project::all(['id', 'name']);

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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $externalUser = ExternalUser::findOrFail($id);
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
