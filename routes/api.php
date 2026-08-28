<?php

use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\CapabilityController;
use App\Http\Controllers\Api\V1\ProjectCommandController;
use App\Http\Controllers\Api\V1\ProjectSnapshotController;
use App\Http\Controllers\Api\V1\ProjectValidationController;
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
    Route::apiResource('projects', ProjectController::class)->only('index');
    Route::apiResource('projects', ProjectController::class)->only('store')->middleware('token.project');
    Route::apiResource('projects', ProjectController::class)->only(['show', 'update', 'destroy'])->middleware('token.project');
    Route::scopeBindings()->middleware('token.project')->group(function (): void {
        Route::apiResource('projects.parts', PartDefinitionController::class)
            ->parameters(['parts' => 'partDefinition']);
        Route::post('/projects/{project}/parts/{partDefinition}/instances', [PartInstanceController::class, 'store']);
        Route::patch('/projects/{project}/instances/{partInstance}', [PartInstanceController::class, 'update']);
        Route::delete('/projects/{project}/instances/{partInstance}', [PartInstanceController::class, 'destroy']);
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('/api-tokens', [ApiTokenController::class, 'index']);
    Route::post('/api-tokens', [ApiTokenController::class, 'store']);
    Route::delete('/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy']);

    Route::get('/capabilities', CapabilityController::class)
        ->middleware('ability:projects:read,projects:write');

    Route::get('/projects', [ProjectController::class, 'index'])
        ->middleware('ability:projects:read,projects:write');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware(['abilities:projects:write', 'token.project']);

    Route::scopeBindings()->middleware('token.project')->group(function (): void {
        Route::get('/projects/{project}', ProjectSnapshotController::class)
            ->middleware('ability:projects:read,projects:write');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])
            ->middleware('abilities:projects:write');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->middleware('abilities:projects:write');

        Route::get('/projects/{project}/parts', [PartDefinitionController::class, 'index'])
            ->middleware('ability:projects:read,projects:write');
        Route::post('/projects/{project}/parts', [PartDefinitionController::class, 'store'])
            ->middleware('abilities:projects:write');
        Route::get('/projects/{project}/parts/{partDefinition}', [PartDefinitionController::class, 'show'])
            ->middleware('ability:projects:read,projects:write');
        Route::patch('/projects/{project}/parts/{partDefinition}', [PartDefinitionController::class, 'update'])
            ->middleware('abilities:projects:write');
        Route::delete('/projects/{project}/parts/{partDefinition}', [PartDefinitionController::class, 'destroy'])
            ->middleware('abilities:projects:write');

        Route::post('/projects/{project}/parts/{partDefinition}/instances', [PartInstanceController::class, 'store'])
            ->middleware('abilities:projects:write');
        Route::patch('/projects/{project}/instances/{partInstance}', [PartInstanceController::class, 'update'])
            ->middleware('abilities:projects:write');
        Route::delete('/projects/{project}/instances/{partInstance}', [PartInstanceController::class, 'destroy'])
            ->middleware('abilities:projects:write');

        Route::post('/projects/{project}/commands', ProjectCommandController::class)
            ->middleware('abilities:projects:write');
        Route::post('/projects/{project}/validate', ProjectValidationController::class)
            ->middleware('ability:projects:read,projects:write');
    });
});
