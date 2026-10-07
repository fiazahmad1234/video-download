<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\AuthController;
Route::get('/csrf-token', function () {
    return response()->json([
        'token' => csrf_token(),
    ]);
});

Route::post('/register', [AuthController::class, 'register']);