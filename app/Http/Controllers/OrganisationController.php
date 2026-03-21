<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;

class OrganisationController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        $sortBy = request('sort_by');

        $organisations = Organisation::query()
            ->withCount(['organisationUsers', 'projects'])
            ->where(function (Builder $query) use ($userId) {
                $query->where('author_id', $userId)
                    ->orWhereHas('organisationUsers', function (Builder $query) use ($userId) {
                        $query->where('user_id', $userId);
                    });
            })
            ->when(
                request('search'),
                fn (Builder $query, string $search) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%'.$search.'%'])
            );

        switch ($sortBy) {
            case 'name':
                $organisations->orderBy('name');
                break;
            case 'oldest':
                $organisations->oldest();
                break;
            default:
                $organisations->latest();
                break;
        }

        return inertia('Organisations/Index')->with([
            'filters' => request()->only(['search', 'sort_by']),
            'organisations' => $organisations
                ->paginate(10)
                ->withQueryString(),
        ]);
    }

    public function destroy(Organisation $organisation)
    {
        $organisation->delete();

        return redirect()->route('organisations.index')->with('success', 'Organisation deleted successfully.');
    }

    public function update(Organisation $organisation)
    {
        $organisation->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->route('organisations.index')->with('success', 'Organisation updated successfully.');
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
        ]);

        Organisation::create([
            ...$validated,
            'author_id' => auth()->id(),
        ]);

        return redirect()->route('organisations.index')->with('success', 'Organisation created successfully.');
    }

    public function users(Organisation $organisation)
    {
        if ($organisation->author_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $organisationUsers = $organisation->organisationUsers()
            ->with('user')
            ->get();

        return response()->json($organisationUsers);
    }
}
