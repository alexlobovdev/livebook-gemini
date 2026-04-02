<?php

use App\Http\Controllers\Api\GeminiJobController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('service.token')->group(function (): void {
    Route::post('/jobs', [GeminiJobController::class, 'store']);
    Route::get('/jobs/{jobId}', [GeminiJobController::class, 'show']);
    Route::post('/jobs/{jobId}/retry', [GeminiJobController::class, 'retry']);
    Route::get('/health', [GeminiJobController::class, 'health']);
});
