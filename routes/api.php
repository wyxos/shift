<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {


    // projects
    Route::get('/projects', [\App\Http\Controllers\ProjectController::class, 'index']);
    Route::post('/projects', [\App\Http\Controllers\ProjectController::class, 'store']);
    Route::put('/projects/{project}', [\App\Http\Controllers\ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [\App\Http\Controllers\ProjectController::class, 'destroy']);

    // tasks
    Route::get('/tasks', [\App\Http\Controllers\TaskController::class, 'index']);
    Route::post('/tasks', [\App\Http\Controllers\TaskController::class, 'store']);
    Route::put('/tasks/{task}', [\App\Http\Controllers\TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [\App\Http\Controllers\TaskController::class, 'destroy']);
});
