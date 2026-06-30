<?php

use App\Http\Controllers\AiRewriteController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ExternalUserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\OrganisationUserController;
use App\Http\Controllers\ProjectAppErrorNotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectUserController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskErrorOccurrenceController;
use App\Support\LaravelIssueReportingDemo;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');

Route::get('docs/laravel-issue-reporting-demo/{screen}', function (string $screen) {
    abort_unless(app()->environment('local', 'testing'), 404);

    $demo = LaravelIssueReportingDemo::screen($screen);

    abort_unless($demo !== null, 404);

    return view('docs.laravel-issue-reporting-demo', [
        'demo' => $demo,
        'screens' => LaravelIssueReportingDemo::screens(),
    ]);
})->name('docs.laravel-issue-reporting-demo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('sdk/install', [\App\Http\Controllers\SdkInstallController::class, 'show'])->name('sdk-install.verify');
    Route::post('sdk/install/approve', [\App\Http\Controllers\SdkInstallController::class, 'approve'])->name('sdk-install.approve');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('organisation/{organisation}')->name('organisation.')->group(function () {
        Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
        Route::get('tasks', [TaskController::class, 'indexV2'])->name('tasks');
        Route::get('requirements', [TaskController::class, 'requirementsV2'])->name('requirements');
        Route::get('clients', [ClientController::class, 'index'])->name('clients');
        Route::get('projects', [ProjectController::class, 'index'])->name('projects');
        Route::get('external-users', [ExternalUserController::class, 'index'])->name('external-users');
        Route::get('team', [OrganisationController::class, 'team'])->name('team');
        Route::get('settings', [OrganisationController::class, 'settings'])->name('settings');
    });

    // organisations
    Route::get('organisations', [OrganisationController::class, 'index'])->name('organisations.index');
    Route::get('organisations/sidebar', [OrganisationController::class, 'sidebar'])->name('organisations.sidebar');
    Route::post('organisations', [OrganisationController::class, 'store'])->name('organisations.store');
    Route::put('organisations/{organisation}', [OrganisationController::class, 'update'])->name('organisations.update');
    Route::delete('organisations/{organisation}', [OrganisationController::class, 'destroy'])->name('organisations.destroy');
    Route::get('organisations/{organisation}/users', [OrganisationController::class, 'users'])->name('organisations.users');

    // organisation users (invitations)
    Route::post('organisations/{organisation}/users', [OrganisationUserController::class, 'store'])->name('organisation-users.store');
    Route::patch('organisations/{organisation}/users/{organisationUser}/projects', [OrganisationUserController::class, 'syncProjects'])->name('organisation-users.projects.sync');
    Route::delete('organisations/{organisation}/users/{organisationUser}', [OrganisationUserController::class, 'destroy'])->name('organisation-users.destroy');

    // clients
    Route::get('clients', fn () => abort(404));
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

    // projects
    Route::get('projects', fn () => abort(404));
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::get('projects/{project}/users', [ProjectController::class, 'users'])->name('projects.users');
    Route::post('projects/{project}/api-token', [ProjectController::class, 'generateApiToken'])->name('projects.api-token');
    Route::patch('projects/{project}/widget-settings', [ProjectController::class, 'updateWidgetSettings'])->name('projects.widget-settings');
    Route::patch('projects/{project}/mcp-settings', [ProjectController::class, 'updateMcpSettings'])->name('projects.mcp-settings');
    Route::get('projects/{project}/app-error-notifications', [ProjectAppErrorNotificationController::class, 'show'])->name('projects.app-error-notifications.show');
    Route::put('projects/{project}/app-error-notifications', [ProjectAppErrorNotificationController::class, 'update'])->name('projects.app-error-notifications.update');

    // project users (access control)
    Route::post('projects/{project}/users', [ProjectUserController::class, 'store'])->name('project-users.store');
    Route::delete('projects/{project}/users/{projectUser}', [ProjectUserController::class, 'destroy'])->name('project-users.destroy');

    // tasks
    Route::get('tasks', [TaskController::class, 'indexV2'])->name('tasks.index');
    Route::get('requirements', [TaskController::class, 'requirementsV2'])->name('requirements.index');
    Route::get('tasks-v2', fn () => redirect()->route('tasks.index', request()->query()))->name('tasks.v2');
    Route::get('tasks-v2/tasks/{task}', [TaskController::class, 'showV2'])
        ->missing(fn () => response()->json(['message' => TaskController::TASK_NOT_FOUND_MESSAGE], 404))
        ->name('tasks.v2.show');
    Route::get('tasks-v2/projects/{project}/collaborators', [TaskController::class, 'collaborators'])->name('tasks.v2.collaborators');
    Route::post('tasks-v2/tasks', [TaskController::class, 'storeV2'])->name('tasks.v2.store');
    Route::put('tasks-v2/tasks/{task}', [TaskController::class, 'updateV2'])->name('tasks.v2.update');
    Route::patch('tasks-v2/tasks/{task}/collaborators', [TaskController::class, 'updateCollaboratorsV2'])->name('tasks.v2.collaborators.update');
    Route::patch('tasks-v2/tasks/{task}/requirements/finalize', [TaskController::class, 'finalizeRequirementV2'])->name('requirements.v2.finalize');
    Route::patch('requirements/batches/{requirementBatch}/finalize', [TaskController::class, 'finalizeRequirementBatchV2'])->name('requirements.v2.batches.finalize');
    Route::delete('tasks-v2/tasks/{task}', [TaskController::class, 'destroyV2'])->name('tasks.v2.destroy');
    Route::get('tasks/{task}/error-occurrences', [TaskErrorOccurrenceController::class, 'index'])->name('task-error-occurrences.index');
    Route::get('tasks/create', fn () => redirect()->route('tasks.index'))->name('tasks.create');
    Route::get('tasks/{task}/edit', fn ($task) => redirect()->route('tasks.index', ['task' => $task]))->name('tasks.edit');

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
    Route::post('ai/improve', [AiRewriteController::class, 'improve'])->name('ai.improve');

    // External Users
    Route::get('external-users', fn () => abort(404));
    Route::put('external-users/{externalUser}', [ExternalUserController::class, 'update'])->name('external-users.update');
    Route::post('external-users/{externalUser}/linked-accounts', [ExternalUserController::class, 'linkAccount'])->name('external-users.linked-accounts.store');
    Route::delete('external-users/{externalUser}/linked-accounts/{linkedExternalUser}', [ExternalUserController::class, 'unlinkAccount'])->name('external-users.linked-accounts.destroy');

    // Users
    Route::get('users', fn () => abort(404));

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
