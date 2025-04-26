<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');



Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // clients
    Route::get('clients', function () {
        return Inertia::render('Clients')
            ->with([
                'clients' => App\Models\Client::query()->paginate(10)->withQueryString(),
            ]);
    })->name('clients.index');

    // delete route
    Route::delete('clients/{client}', function (App\Models\Client $client) {
        $client->delete();
        return redirect()->route('clients.index')->with('success', 'Client deleted successfully.');
    })->name('clients.destroy');

    // put client
    Route::put('clients/{client}', function (App\Models\Client $client) {
        $client->update(request()->validate([
            'name' => 'required|string|max:255',
        ]));
        return redirect()->route('clients.index')->with('success', 'Client updated successfully.');
    })->name('clients.update');

    // projects
    Route::get('projects', function () {
        return Inertia::render('Projects');
    })->name('projects.index');

    // tasks
    Route::get('tasks', function () {
        return Inertia::render('Tasks');
    })->name('tasks.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
