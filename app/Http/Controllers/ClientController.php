<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index()
    {
        return inertia('Clients')
            ->with([
                'filters' => request()->only(['search']),
                'clients' => \App\Models\Client::query()
                    ->whereHas('organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->latest()
                    ->when(
                        request('search'),
                        fn ($query)  => $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%' . request('search') . '%'])
                    )
                    ->paginate(10)
                    ->withQueryString(),
                'organisations' => \App\Models\Organisation::query()
                    ->where('author_id', auth()->user()->id)
                    ->get(),
            ]);
    }

    // delete route
    public function destroy(\App\Models\Client $client)
    {
        $client->delete();
        return redirect()->route('clients.index')->with('success', 'Client deleted successfully.');
    }

    // put client
    public function update(\App\Models\Client $client)
    {
        $client->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));
        return redirect()->route('clients.index')->with('success', 'Client updated successfully.');
    }

    // create client
    public function store()
    {
        $client = \App\Models\Client::create(request()->validate([
            'name' => 'required|string|max:255',
            'organisation_id' => 'required|exists:organisations,id',
        ]));
        return redirect()->route('clients.index')->with('success', 'Client created successfully.');
    }
}
