<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OrganisationController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        $sortBy = request('sort_by');
        $panel = [
            'create' => request()->boolean('create'),
            'team' => request()->integer('team') ?: request()->integer('manage') ?: null,
            'manage' => request()->integer('manage') ?: null,
            'settings' => request()->integer('settings') ?: null,
        ];
        $panelOrganisationId = $panel['team'] ?: $panel['settings'];

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

        $panelOrganisation = null;

        if ($panelOrganisationId) {
            $panelOrganisation = Organisation::query()
                ->where('author_id', $userId)
                ->with(['author:id,name,email', 'organisationUsers.user:id,name,email'])
                ->find($panelOrganisationId);
        }

        return inertia('Organisations/Index')->with([
            'filters' => request()->only(['search', 'sort_by']),
            'organisations' => $organisations
                ->paginate(10)
                ->withQueryString(),
            'accessUsers' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'panel' => $panel,
            'panelOrganisation' => $panelOrganisation ? [
                'id' => $panelOrganisation->id,
                'name' => $panelOrganisation->name,
                'teamUsers' => collect([
                    $panelOrganisation->author ? [
                        'id' => 'owner-'.$panelOrganisation->author->id,
                        'name' => $panelOrganisation->author->name,
                        'email' => $panelOrganisation->author->email,
                        'status' => 'owner',
                        'statusLabel' => 'Owner',
                    ] : null,
                ])
                    ->filter()
                    ->merge(
                        $panelOrganisation->organisationUsers->map(fn ($organisationUser) => [
                            'id' => 'access-'.$organisationUser->id,
                            'organisationUserId' => $organisationUser->id,
                            'name' => $organisationUser->user?->name ?: $organisationUser->user_name,
                            'email' => $organisationUser->user?->email ?: $organisationUser->user_email,
                            'status' => $organisationUser->user_id ? 'registered' : 'pending',
                            'statusLabel' => $organisationUser->user_id ? 'Registered' : 'Pending invitation',
                        ])
                    )
                    ->values()
                    ->all(),
            ] : null,
        ]);
    }

    public function destroy(Organisation $organisation)
    {
        abort_if($organisation->author_id !== auth()->id(), 403);

        $organisation->delete();

        return redirect()->route('organisations.index')->with('success', 'Organisation deleted successfully.');
    }

    public function update(Organisation $organisation)
    {
        abort_if($organisation->author_id !== auth()->id(), 403);

        $organisation->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->back()->with('success', 'Organisation updated successfully.');
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
