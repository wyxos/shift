<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    // tasks
    Route::get('/tasks', [\App\Http\Controllers\Api\TaskController::class, 'index']);
    Route::get('/tasks/{task}', [\App\Http\Controllers\Api\TaskController::class, 'show']);
    Route::post('/tasks', [\App\Http\Controllers\Api\TaskController::class, 'store']);
    Route::put('/tasks/{task}', [\App\Http\Controllers\Api\TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [\App\Http\Controllers\Api\TaskController::class, 'destroy']);
    Route::patch('/tasks/{task}/toggle-status', [\App\Http\Controllers\Api\TaskController::class, 'toggleStatus']);
    Route::patch('/tasks/{task}/toggle-priority', [\App\Http\Controllers\Api\TaskController::class, 'togglePriority']);
});
