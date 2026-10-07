<?php

use App\Http\Controllers\DownloaderController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')->group(function () {
    Route::post('/inspect', [DownloaderController::class, 'inspect']);
    Route::post('/downloads', [DownloaderController::class, 'download']);
    Route::get('/downloads/{token}', [DownloaderController::class, 'status']);
    Route::get('/downloads/{token}/file', [DownloaderController::class, 'file']);
});
