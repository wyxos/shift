<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('sdk/install')->name('api.sdk-install.')->group(function () {
    Route::post('/sessions', [\App\Http\Controllers\Api\SdkInstallController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::post('/sessions/poll', [\App\Http\Controllers\Api\SdkInstallController::class, 'poll'])->middleware('throttle:120,1')->name('poll');
    Route::post('/sessions/projects', [\App\Http\Controllers\Api\SdkInstallController::class, 'projects'])->middleware('throttle:60,1')->name('projects');
    Route::post('/sessions/finalize', [\App\Http\Controllers\Api\SdkInstallController::class, 'finalize'])->middleware('throttle:20,1')->name('finalize');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    // Prefix API route names to avoid collisions with web/shift routes
    Route::name('api.')->group(function () {
        // tasks
        Route::get('/collaborators/internal', [\App\Http\Controllers\Api\ExternalTaskController::class, 'internalCollaborators'])->name('collaborators.internal');
        Route::get('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'show'])->name('tasks.show');
        Route::post('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'store'])->name('tasks.store');
        Route::put('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'update'])->name('tasks.update');
        Route::patch('/tasks/{task}/collaborators', [\App\Http\Controllers\Api\ExternalTaskController::class, 'updateCollaborators'])->name('tasks.collaborators.update');
        Route::delete('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'destroy'])->name('tasks.destroy');
        Route::patch('/tasks/{task}/toggle-status', [\App\Http\Controllers\Api\ExternalTaskController::class, 'toggleStatus'])->name('tasks.toggle-status');
        Route::patch('/tasks/{task}/toggle-priority', [\App\Http\Controllers\Api\ExternalTaskController::class, 'togglePriority'])->name('tasks.toggle-priority');
        Route::post('/ai/improve', [\App\Http\Controllers\Api\ExternalAiController::class, 'improve'])->name('ai.improve');
        Route::post('/project-environments/register', [\App\Http\Controllers\Api\ProjectEnvironmentController::class, 'register'])->name('project-environments.register');

        // task threads
        Route::get('/tasks/{task}/threads', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'index'])->name('task-threads.index');
        Route::post('/tasks/{task}/threads', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'store'])->name('task-threads.store');
        Route::get('/tasks/{task}/threads/{threadId}', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'show'])->name('task-threads.show');
        Route::put('/tasks/{task}/threads/{threadId}', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'update'])->name('task-threads.update');
        Route::delete('/tasks/{task}/threads/{threadId}', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'destroy'])->name('task-threads.destroy');
    });

    // attachments (already use api.attachments.* names)
    Route::post('/attachments/upload', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'upload'])->name('api.attachments.upload');
    Route::post('/attachments/upload-init', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'uploadInit'])->name('api.attachments.upload-init');
    Route::get('/attachments/upload-status', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'uploadStatus'])->name('api.attachments.upload-status');
    Route::post('/attachments/upload-chunk', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'uploadChunk'])->name('api.attachments.upload-chunk');
    Route::post('/attachments/upload-complete', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'uploadComplete'])->name('api.attachments.upload-complete');
    Route::post('/attachments/upload-multiple', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'uploadMultiple'])->name('api.attachments.upload-multiple');
    Route::delete('/attachments/remove-temp', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'removeTemp'])->name('api.attachments.remove-temp');
    Route::get('/attachments/list-temp', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'listTemp'])->name('api.attachments.list-temp');
    Route::get('/attachments/temp/{temp}/{filename}', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'showTemp'])
        ->where('filename', '.*')
        ->name('api.attachments.temp');
});

Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\Api\ExternalAttachmentController::class, 'download'])->name('api.attachments.download');
