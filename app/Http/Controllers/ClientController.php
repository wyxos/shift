<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    private function ensureClientManageable(Client $client): void
    {
        abort_unless(
            $client->organisation()->where('author_id', auth()->id())->exists(),
            403,
        );
    }

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
                $query->where('author_id', auth()->id());
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
        $this->ensureClientManageable($client);

        $client->delete();

        return redirect()->route('clients.index')->with('success', 'Client deleted successfully.');
    }

    public function update(Client $client)
    {
        $this->ensureClientManageable($client);

        $client->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));

        return redirect()->route('clients.index')->with('success', 'Client updated successfully.');
    }

    public function store()
    {
        Client::create(request()->validate([
            'name' => 'required|string|max:255',
            'organisation_id' => [
                'required',
                Rule::exists('organisations', 'id')
                    ->where(fn ($query) => $query->where('author_id', auth()->id())),
            ],
        ]));

        return redirect()->route('clients.index')->with('success', 'Client created successfully.');
    }
}
