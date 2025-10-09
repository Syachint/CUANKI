<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\AdviceController;
use App\Http\Controllers\FormDetailController;

Route::post('/register', [AuthController::class, 'registerUser']);
Route::post('/login', [AuthController::class, 'loginUser']);
Route::post('/refresh', [AuthController::class, 'refreshToken']);

Route::controller(GoogleController::class)->group(function () {
    Route::get('/auth/google', 'googleLogin')->name('auth.google');
    Route::get('/auth/google/callback', 'googleAuthentication')->name('auth.google.callback');
});
// Google Authentication Routes
// Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle']);
// Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);
// Route::post('/auth/google/login', [GoogleController::class, 'loginWithGoogle']); // For mobile/API
Route::get('/listbank', [FormDetailController::class, 'listBanks']);
Route::get('/origins', [FormDetailController::class, 'getOriginsList']);

Route::middleware('auth:sanctum')->group(function() {
    Route::post('/form/user', [FormDetailController::class, 'formDetailUser']);
    Route::post('/form/account', [FormDetailController::class, 'formDetailAccount']);
    Route::post('/form/plan', [FormDetailController::class, 'formDetailPlan']);
    Route::get('/advice', [AdviceController::class, 'getAdvices']);
    Route::get('/user-data', [UserController::class, 'getUserData']);
    Route::get('/greeting-user', [UserController::class, 'getGreetingUser']);
    Route::post('/logout', [UserController::class, 'logout']);
});