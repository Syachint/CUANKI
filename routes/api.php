<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\AdviceController;
use App\Http\Controllers\FormDetailController;
use App\Http\Controllers\TransactionController;

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
Route::get('/origins', [FormDetailController::class, 'getOrigins']);
Route::get('/expense-categories', [TransactionController::class, 'getExpenseCategories']);

Route::middleware('auth:sanctum')->group(function() {
    // Form endpoints
    Route::post('/form/user', [FormDetailController::class, 'formDetailUser']);
    Route::post('/form/account', [FormDetailController::class, 'formDetailAccount']);
    Route::post('/form/plan', [FormDetailController::class, 'formDetailPlan']);
    
    // Transaction endpoints
    Route::get('/user-accounts', [TransactionController::class, 'getUserAccounts']);
    Route::post('/add-income', [TransactionController::class, 'addIncome']);
    Route::post('/add-expense', [TransactionController::class, 'addExpense']);
    
    // Other endpoints
    Route::get('/advice', [AdviceController::class, 'getAdvices']);

    // User management endpoints
    Route::get('/user-data', [UserController::class, 'getUserData']);
    Route::post('/logout', [UserController::class, 'logout']);
    
    // Dashboard endpoints
    Route::post('/add-new-account', [DashboardController::class, 'addNewAccount']);
    Route::get('/greeting-user', [DashboardController::class, 'getGreetingUser']);
    Route::get('/goals-progress', [DashboardController::class, 'getGoalsProgress']);
    Route::get('/today-expenses', [DashboardController::class, 'getTodayExpenses']);
    Route::get('/daily-saving', [DashboardController::class, 'getDailySaving']);
    Route::get('/receipt-today', [DashboardController::class, 'getReceiptToday']);
    Route::get('/budget-comparison', [DashboardController::class, 'getBudgetComparison']);
    Route::put('/update-account-balance', [DashboardController::class, 'updateAccountBalance']);
    Route::post('/generate-today-budget', [DashboardController::class, 'generateTodayBudget']);
    
    // Advice endpoints
    Route::get('/advice', [AdviceController::class, 'getAdvices']);
});