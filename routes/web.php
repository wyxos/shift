<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\OrganisationUserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectUserController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');



Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // organisations
    Route::get('organisations', [OrganisationController::class, 'index'])->name('organisations.index');
    Route::post('organisations', [OrganisationController::class, 'store'])->name('organisations.store');
    Route::put('organisations/{organisation}', [OrganisationController::class, 'update'])->name('organisations.update');
    Route::delete('organisations/{organisation}', [OrganisationController::class, 'destroy'])->name('organisations.destroy');
    Route::get('organisations/{organisation}/users', [OrganisationController::class, 'users'])->name('organisations.users');

    // organisation users (invitations)
    Route::post('organisations/{organisation}/users', [OrganisationUserController::class, 'store'])->name('organisation-users.store');
    Route::delete('organisations/{organisation}/users/{organisationUser}', [OrganisationUserController::class, 'destroy'])->name('organisation-users.destroy');

    // clients
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

    // projects
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::get('projects/{project}/users', [ProjectController::class, 'users'])->name('projects.users');
    Route::post('projects/{project}/api-token', [ProjectController::class, 'generateApiToken'])->name('projects.api-token');

    // project users (access control)
    Route::post('projects/{project}/users', [ProjectUserController::class, 'store'])->name('project-users.store');
    Route::delete('projects/{project}/users/{projectUser}', [ProjectUserController::class, 'destroy'])->name('project-users.destroy');

    // tasks
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::patch('tasks/{task}/toggle-status', [TaskController::class, 'toggleStatus'])->name('tasks.toggle-status');
    Route::patch('tasks/{task}/toggle-priority', [TaskController::class, 'togglePriority'])->name('tasks.toggle-priority');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
