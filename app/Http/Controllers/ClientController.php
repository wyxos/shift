<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organisation;
use App\Services\ShiftPermissionService;
use Illuminate\Database\Eloquent\Builder;

class ClientController extends Controller
{
    public function __construct(private readonly ShiftPermissionService $permissions) {}

    public function index(?Organisation $organisation = null)
    {
        if ($organisation && ! $organisation->isVisibleToUser(auth()->id())) {
            abort(404);
        }

        $sortBy = request('sort_by');
        $organisationId = $organisation?->id ?? request('organisation_id');

        $clients = Client::query()
            ->with('organisation:id,name')
            ->whereHas('organisation', function (Builder $query) {
                $query
                    ->where('author_id', auth()->id())
                    ->orWhereHas('organisationUsers', function (Builder $memberQuery) {
                        $memberQuery
                            ->where('user_id', auth()->id())
                            ->where('role', \App\Enums\OrganisationRole::Administrator->value);
                    });
            })
            ->when(
                filled($organisationId),
                fn (Builder $query) => $query->where('organisation_id', $organisationId)
            )
            ->when(
                request('search'),
                fn (Builder $query, string $search) => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%'.$search.'%'])
            );

        switch ($sortBy) {
            case 'name':
                $clients->orderBy('name');
                break;
            case 'oldest':
                $clients->oldest();
                break;
            default:
                $clients->latest();
                break;
        }

        return inertia('Clients')
            ->with([
                'filters' => [
                    ...request()->only(['search', 'sort_by']),
                    'organisation_id' => filled($organisationId) ? (int) $organisationId : null,
                ],
                'clients' => $clients
                    ->paginate(10)
                    ->withQueryString()
                    ->through(fn (Client $client) => [
                        ...$client->toArray(),
                        'organisation_name' => $client->organisation?->name,
                    ]),
                'organisations' => Organisation::query()
                    ->where('author_id', auth()->id())
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ]);
    }

    public function destroy(Client $client)
    {
        abort_unless($client->organisation && $this->permissions->canManageOrganisation($client->organisation, auth()->id()), 403);

        $client->delete();

        return redirect()->route('clients.index')->with('success', 'Client deleted successfully.');
    }

    public function update(Client $client)
    {
        abort_unless($client->organisation && $this->permissions->canManageOrganisation($client->organisation, auth()->id()), 403);

        $client->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->route('clients.index')->with('success', 'Client updated successfully.');
    }

    public function store()
    {
        $attributes = request()->validate([
            'name' => 'required|string|max:255',
            'organisation_id' => 'required|exists:organisations,id',
        ]);

        $organisation = Organisation::query()->findOrFail($attributes['organisation_id']);
        abort_unless($this->permissions->canManageOrganisation($organisation, auth()->id()), 403);

        Client::create($attributes);

        return redirect()->route('clients.index')->with('success', 'Client created successfully.');
    }
}
