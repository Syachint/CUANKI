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
use App\Http\Controllers\AssetController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\MonthlyExpenseController;

// Auth endpoints
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

// Dropdown endpoints
Route::get('/listbank', [FormDetailController::class, 'listBanks']);
Route::get('/origins', [FormDetailController::class, 'getOrigins']);
Route::get('/expense-categories', [TransactionController::class, 'getExpenseCategories']);

Route::middleware('auth:sanctum')->group(function() {
    // Form endpoints
    Route::post('/form/user', [FormDetailController::class, 'formDetailUser']);
    Route::post('/form/account', [FormDetailController::class, 'formDetailAccount']);
    Route::post('/form/plan', [FormDetailController::class, 'formDetailPlan']);
    
    // Dashboard endpoints
    Route::get('/greeting-user', [DashboardController::class, 'getGreetingUser']);
    Route::get('/goals-progress', [DashboardController::class, 'getGoalsProgress']);
    Route::get('/today-expenses', [DashboardController::class, 'getTodayExpenses']);
    Route::get('/daily-saving', [DashboardController::class, 'getDailySaving']);
    Route::get('/receipt-today', [DashboardController::class, 'getReceiptToday']);
    Route::get('/calendar-status', [DashboardController::class, 'getCalendarStatus']);
    Route::get('/timezone-test', [DashboardController::class, 'timezoneTest']);
    Route::get('/budget-comparison', [DashboardController::class, 'getBudgetComparison']);
    Route::post('/generate-today-budget', [DashboardController::class, 'generateTodayBudget']);
    
    // Transaction endpoints
    Route::post('/add-income', [TransactionController::class, 'addIncome']);
    Route::post('/add-expense', [TransactionController::class, 'addExpense']);
    Route::get('/detail-receipt-expense', [TransactionController::class, 'getDetailReceiptExpense']);
    Route::get('/detail-receipt-incomes', [TransactionController::class, 'getDetailReceiptIncomes']);
    
    // Asset/Account endpoints
    Route::post('/add-new-account', [AssetController::class, 'addNewAccount']);
    Route::get('/user-accounts', [AssetController::class, 'getUserAccounts']);
    // Route::put('/update-account-balance', [AssetController::class, 'updateAccountBalance']);
    Route::put('/update-account-allocation', [AssetController::class, 'updateAccountAllocation']);
    Route::delete('/account/{id}', [AssetController::class, 'deleteAccount']);
    Route::get('/usage-bar-allocation', [AssetController::class, 'getUsageBarAllocation']);
    
    // Goal endpoints
    Route::get('/goal-graphic-rate', [GoalController::class, 'getGoalGraphicRate']);
    Route::get('/main-goal-progress', [GoalController::class, 'getMainGoalProgress']);
    // Goals CRUD
    Route::get('/goals/available-allocations', [GoalController::class, 'getAvailableAllocations']);
    Route::get('/goals', [GoalController::class, 'getAllGoals']);
    Route::post('/goals', [GoalController::class, 'createGoal']);
    Route::get('/goals/{id}', [GoalController::class, 'getGoal']);
    Route::put('/goals/{id}', [GoalController::class, 'updateGoal']);
    Route::delete('/goals/{id}', [GoalController::class, 'deleteGoal']);
    
    // Monthly Expenses CRUD
    Route::get('/monthly-expenses', [MonthlyExpenseController::class, 'index']);
    Route::post('/monthly-expenses', [MonthlyExpenseController::class, 'store']);
    Route::put('/monthly-expenses/{id}', [MonthlyExpenseController::class, 'update']);
    Route::delete('/monthly-expenses/{id}', [MonthlyExpenseController::class, 'destroy']);
    Route::post('/monthly-expenses/{id}/add-expense', [MonthlyExpenseController::class, 'addExpenseAmount']);
    Route::get('/monthly-expenses/categories', [MonthlyExpenseController::class, 'getCategories']);
    
    // User Profile CRUD endpoints
    Route::get('/profile', [UserController::class, 'getUserProfile']);
    Route::put('/profile', [UserController::class, 'updateUserProfile']);
    Route::post('/profile/picture', [UserController::class, 'updateProfilePicture']);
    Route::delete('/profile', [UserController::class, 'deleteUserAccount']);
    
    // User management endpoints (legacy)
    Route::get('/user-data', [UserController::class, 'getUserData']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Other endpoints
    Route::get('/advice', [AdviceController::class, 'getAdvices']);
});