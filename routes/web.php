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
        return Inertia::render('Clients');
    })->name('clients.index');

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
