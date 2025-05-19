<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OrganisationController extends Controller
{
    public function index()
    {
        return inertia('Organisations/Index')
            ->with([
                'filters' => request()->only(['search']),
                'organisations' => \App\Models\Organisation::query()
                    ->latest()
                    ->when(
                        request('search'),
                        fn ($query)  => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                    )
                    ->where(function($query) {
                        $query->where('author_id', auth()->user()->id)
                            ->orWhereHas('organisationUsers', function($query) {
                                $query->where('user_id', auth()->user()->id);
                            });
                    })
                    ->paginate(10)
                    ->withQueryString(),
            ]);
    }

    // delete route
    public function destroy(\App\Models\Organisation $organisation)
    {
        $organisation->delete();
        return redirect()->route('organisations.index')->with('success', 'Organisation deleted successfully.');
    }

    // put organisation
    public function update(\App\Models\Organisation $organisation)
    {
        $organisation->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));
        return redirect()->route('organisations.index')->with('success', 'Organisation updated successfully.');
    }

    // create organisation
    public function store()
    {
        $validate = request()->validate([
            'name' => 'required|string|max:255',
        ]);

        $organisation = \App\Models\Organisation::create([
            ...$validate,
            'author_id' => auth()->id(),
        ]);

        return redirect()->route('organisations.index')->with('success', 'Organisation created successfully.');
    }

    /**
     * Get users with access to the organisation.
     */
    public function users(\App\Models\Organisation $organisation)
    {
        // Check if the authenticated user is the author of the organisation
        if ($organisation->author_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $organisationUsers = $organisation->organisationUsers()
            ->with('user')
            ->get();

        return response()->json($organisationUsers);
    }
}
