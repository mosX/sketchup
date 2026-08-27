<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PartDefinitionController;
use App\Http\Controllers\PartInstanceController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::apiResource('projects', ProjectController::class);
    Route::scopeBindings()->group(function (): void {
        Route::apiResource('projects.parts', PartDefinitionController::class)
            ->parameters(['parts' => 'partDefinition']);
        Route::post('/projects/{project}/parts/{partDefinition}/instances', [PartInstanceController::class, 'store']);
        Route::patch('/projects/{project}/instances/{partInstance}', [PartInstanceController::class, 'update']);
        Route::delete('/projects/{project}/instances/{partInstance}', [PartInstanceController::class, 'destroy']);
    });
});
