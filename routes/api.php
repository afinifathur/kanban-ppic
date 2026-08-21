<?php

use Illuminate\Support\Facades\Route;

Route::prefix('print-jobs')->middleware(\App\Http\Middleware\VerifyPrintAgentToken::class)->group(function () {
    Route::post('/recover', [\App\Http\Controllers\Api\PrintJobApiController::class, 'recover']);
    Route::post('/claim', [\App\Http\Controllers\Api\PrintJobApiController::class, 'claim']);
    Route::post('/{id}/complete', [\App\Http\Controllers\Api\PrintJobApiController::class, 'complete']);
    Route::post('/{id}/failed', [\App\Http\Controllers\Api\PrintJobApiController::class, 'failed']);
});
