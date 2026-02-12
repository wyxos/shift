<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ExternalUserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\OrganisationUserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectUserController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

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
    Route::get('tasks-v2', [TaskController::class, 'indexV2'])->name('tasks.v2');
    Route::get('tasks-v2/tasks/{task}', [TaskController::class, 'showV2'])->name('tasks.v2.show');
    Route::put('tasks-v2/tasks/{task}', [TaskController::class, 'updateV2'])->name('tasks.v2.update');
    Route::delete('tasks-v2/tasks/{task}', [TaskController::class, 'destroyV2'])->name('tasks.v2.destroy');
    Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');

    // Use Api\TaskController for API requests
    Route::post('tasks', [\App\Http\Controllers\TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [\App\Http\Controllers\TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [\App\Http\Controllers\TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::patch('tasks/{task}/toggle-status', [\App\Http\Controllers\TaskController::class, 'toggleStatus'])->name('tasks.toggle-status');
    Route::patch('tasks/{task}/toggle-priority', [\App\Http\Controllers\TaskController::class, 'togglePriority'])->name('tasks.toggle-priority');

    // Task Threads
    Route::get('tasks/{task}/threads', [\App\Http\Controllers\TaskThreadController::class, 'index'])->name('task-threads.index');
    Route::post('tasks/{task}/threads', [\App\Http\Controllers\TaskThreadController::class, 'store'])->name('task-threads.store');
    Route::get('tasks/{task}/threads/{thread}', [\App\Http\Controllers\TaskThreadController::class, 'show'])->name('task-threads.show');
    Route::put('tasks/{task}/threads/{thread}', [\App\Http\Controllers\TaskThreadController::class, 'update'])->name('task-threads.update');
    Route::delete('tasks/{task}/threads/{thread}', [\App\Http\Controllers\TaskThreadController::class, 'destroy'])->name('task-threads.destroy');

    // Attachments
    Route::post('attachments/upload', [AttachmentController::class, 'upload'])->name('attachments.upload');
    Route::post('attachments/upload-init', [AttachmentController::class, 'uploadInit'])->name('attachments.upload-init');
    Route::get('attachments/upload-status', [AttachmentController::class, 'uploadStatus'])->name('attachments.upload-status');
    Route::post('attachments/upload-chunk', [AttachmentController::class, 'uploadChunk'])->name('attachments.upload-chunk');
    Route::post('attachments/upload-complete', [AttachmentController::class, 'uploadComplete'])->name('attachments.upload-complete');
    Route::get('attachments/list-temp', [AttachmentController::class, 'listTempFiles'])->name('attachments.list-temp');
    Route::delete('attachments/remove-temp', [AttachmentController::class, 'removeTempFile'])->name('attachments.remove-temp');
    Route::get('tasks/{task}/attachments', [AttachmentController::class, 'listTaskAttachments'])->name('attachments.list-task');
    Route::get('{type}/{id}/attachments', [AttachmentController::class, 'listAttachments'])->name('attachments.list');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'deleteAttachment'])->name('attachments.delete');
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'downloadAttachment'])->name('attachments.download');
    Route::get('attachments/temp/{temp}/{filename}', [AttachmentController::class, 'showTemp'])->where('filename', '.*')->name('attachments.temp');

    // External Users
    Route::get('external-users', [ExternalUserController::class, 'index'])->name('external-users.index');
    Route::get('external-users/{externalUser}/edit', [ExternalUserController::class, 'edit'])->name('external-users.edit');
    Route::put('external-users/{externalUser}', [ExternalUserController::class, 'update'])->name('external-users.update');

    // Users
    Route::get('users', [UserController::class, 'index'])->name('users.index');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread', [NotificationController::class, 'getUnread'])->name('notifications.unread');
    Route::post('notifications/{id}/mark-as-read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('notifications/{id}/mark-as-unread', [NotificationController::class, 'markAsUnread'])->name('notifications.mark-as-unread');
    Route::post('notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');

    Route::inertia('/components', 'Components');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
