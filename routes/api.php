<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    // tasks
    Route::get('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'show'])->name('tasks.show');
    Route::post('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'store'])->name('tasks.store');
    Route::put('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'destroy'])->name('tasks.destroy');
    Route::patch('/tasks/{task}/toggle-status', [\App\Http\Controllers\Api\ExternalTaskController::class, 'toggleStatus'])->name('tasks.toggle-status');
    Route::patch('/tasks/{task}/toggle-priority', [\App\Http\Controllers\Api\ExternalTaskController::class, 'togglePriority'])->name('tasks.toggle-priority');
});
