<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GoogleController;

Route::post('/register/1', [UserController::class, 'registerUser1']);
Route::post('/register/2', [UserController::class, 'registerUser2']);
Route::post('/register/3', [UserController::class, 'registerUser3']);
Route::post('/login', [UserController::class, 'loginUser']);
Route::post('/refresh', [UserController::class, 'refreshToken']);

Route::controller(GoogleController::class)->group(function () {
    Route::get('/auth/google', 'googleLogin')->name('auth.google');
    Route::get('/auth/google/callback', 'googleAuthentication')->name('auth.google.callback');
});
// Google Authentication Routes
// Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle']);
// Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
// Route::post('/auth/google/login', [GoogleController::class, 'loginWithGoogle']); // For mobile/API

Route::middleware('auth:sanctum')->group(function() {
    Route::post('/logout', [UserController::class, 'logout']);
});