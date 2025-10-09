<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GoogleController;

Route::get('/', function () {
    return view('welcome');
});

Route::controller(GoogleController::class)->group(function () {
    Route::get('/auth/google', 'googleLogin')->name('auth.google.aasasassas');
    Routae::get('/auth/google/callback', 'googleAuthentication')->name('auth.google.callmeback');
});
