<?php

use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');



Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');

    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');

    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');

    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

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
