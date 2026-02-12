<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    // Prefix API route names to avoid collisions with web/shift routes
    Route::name('api.')->group(function () {
        // tasks
        Route::get('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'show'])->name('tasks.show');
        Route::post('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'store'])->name('tasks.store');
        Route::put('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'destroy'])->name('tasks.destroy');
        Route::patch('/tasks/{task}/toggle-status', [\App\Http\Controllers\Api\ExternalTaskController::class, 'toggleStatus'])->name('tasks.toggle-status');
        Route::patch('/tasks/{task}/toggle-priority', [\App\Http\Controllers\Api\ExternalTaskController::class, 'togglePriority'])->name('tasks.toggle-priority');

        // task threads
        Route::get('/tasks/{task}/threads', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'index'])->name('task-threads.index');
        Route::post('/tasks/{task}/threads', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'store'])->name('task-threads.store');
        Route::get('/tasks/{task}/threads/{threadId}', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'show'])->name('task-threads.show');
        Route::put('/tasks/{task}/threads/{threadId}', [\App\Http\Controllers\Api\ExternalTaskThreadController::class, 'update'])->name('task-threads.update');
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
