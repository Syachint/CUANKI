<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\FinancePlanController;
use App\Http\Controllers\AdviceController;

Route::post('/register', [UserController::class, 'registerUser']);
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
Route::get('/listbank', [AccountController::class, 'listBanks']);

Route::middleware('auth:sanctum')->group(function() {
    Route::post('/form/user', [UserController::class, 'formDetailUser']);
    Route::post('/form/account', [AccountController::class, 'formDetailAccount']);
    Route::post('/form/plan', [FinancePlanController::class, 'formDetailPlan']);
    Route::get('/advice', [AdviceController::class, 'getAdvices']);
    Route::post('/logout', [UserController::class, 'logout']);
});