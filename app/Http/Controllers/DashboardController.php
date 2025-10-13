<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\UserFinancePlan;
use App\Models\Budget;
use App\Models\Expense;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function getGreetingUser(Request $request)
    {
        try {
            $user = $request->user();
            $user->load(['accounts.allocations']);
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get balance from accounts with type "kebutuhan" only
            $kebutuhanBalance = 0;
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                if ($kebutuhanAllocation) {
                    $kebutuhanBalance += $kebutuhanAllocation->balance_per_type;
                }
            }
            
            // Get current month details - total days in month (30 or 31)
            $daysInMonth = Carbon::now()->daysInMonth;
            
            // Calculate daily budget from kebutuhan balance only
            $dailyBudget = $daysInMonth > 0 ? round($kebutuhanBalance / $daysInMonth, 0) : 0;

            // Create greeting message
            $greetingMessage = "Hai, " . ($user->username ?: $user->name) . "! ini uang kamu hari ini Rp " . number_format($dailyBudget, 0, ',', '.');

            return response()->json([
                'status' => 'success',
                'message' => $greetingMessage,
                'data' => [
                    'user' => [
                        'name' => $user->name,
                        'username' => $user->username
                    ],
                    'daily_budget' => [
                        'amount' => $dailyBudget,
                        'formatted' => 'Rp ' . number_format($dailyBudget, 0, ',', '.'),
                        'kebutuhan_balance' => $kebutuhanBalance,
                        'days_in_month' => $daysInMonth
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving greeting: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getGoalsProgress(Request $request)
    {
        try {
            $user = $request->user();
            $user->load(['accounts.allocations']);
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get user finance plan
            $userFinancePlan = UserFinancePlan::where('user_id', $user->id)->first();
            
            if (!$userFinancePlan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User finance plan not found. Please complete your financial planning first.'
                ], 404);
            }

            // Get main saving target from UserFinancePlan
            $monthlySavingTarget = $userFinancePlan->saving_target_amount; // Monthly target
            $savingTargetDurationYears = $userFinancePlan->saving_target_duration; // in years
            $emergencyTargetAmount = $userFinancePlan->emergency_target_amount;
            
            // Convert years to months
            $savingTargetDurationMonths = $savingTargetDurationYears * 12;
            
            // Calculate total saving target: monthly target × duration in months
            $savingTargetAmount = $monthlySavingTarget * $savingTargetDurationMonths;
            
            // Get current saving amount from accounts with type "Tabungan"
            $currentSavingAmount = 0;
            foreach ($user->accounts as $account) {
                $tabunganAllocation = $account->allocations->where('type', 'Tabungan')->first();
                if ($tabunganAllocation) {
                    $currentSavingAmount += $tabunganAllocation->balance_per_type;
                }
            }

            // Get current emergency amount from accounts with type "Darurat"
            $currentEmergencyAmount = 0;
            foreach ($user->accounts as $account) {
                $daruratAllocation = $account->allocations->where('type', 'Darurat')->first();
                if ($daruratAllocation) {
                    $currentEmergencyAmount += $daruratAllocation->balance_per_type;
                }
            }

            // Calculate progress percentages
            $savingProgress = $savingTargetAmount > 0 
                ? round(($currentSavingAmount / $savingTargetAmount) * 100, 2) 
                : 0;
                
            $emergencyProgress = $emergencyTargetAmount > 0 
                ? round(($currentEmergencyAmount / $emergencyTargetAmount) * 100, 2) 
                : 0;

            // Calculate remaining amounts
            $savingRemaining = max(0, $savingTargetAmount - $currentSavingAmount);
            $emergencyRemaining = max(0, $emergencyTargetAmount - $currentEmergencyAmount);

            // Calculate target date based on duration
            $targetDate = null;
            $daysRemaining = null;
            $monthlySavingNeeded = 0;
            
            if ($savingTargetDurationMonths && $savingTargetDurationMonths > 0) {
                $targetDate = Carbon::now()->addMonths($savingTargetDurationMonths);
                $daysRemaining = Carbon::now()->diffInDays($targetDate, false);
                
                // Calculate monthly saving needed
                $monthlySavingNeeded = $savingTargetDurationMonths > 0 && $savingRemaining > 0 
                    ? round($savingRemaining / $savingTargetDurationMonths, 0) 
                    : 0;
            }

            // Create main goals array
            $mainGoals = [
                [
                    'type' => 'saving',
                    'goal_name' => 'Target Tabungan Utama',
                    'target_amount' => $savingTargetAmount,
                    'current_amount' => $currentSavingAmount,
                    'remaining_amount' => $savingRemaining,
                    'progress_percentage' => $savingProgress,
                    'target_duration_years' => $savingTargetDurationYears,
                    'target_duration_months' => $savingTargetDurationMonths,
                    'target_date' => $targetDate ? $targetDate->format('Y-m-d') : null,
                    'days_remaining' => $daysRemaining,
                    'monthly_saving_needed' => $monthlySavingNeeded,
                    'is_completed' => $currentSavingAmount >= $savingTargetAmount,
                    'formatted' => [
                        'target_amount' => 'Rp ' . number_format($savingTargetAmount, 0, ',', '.'),
                        'current_amount' => 'Rp ' . number_format($currentSavingAmount, 0, ',', '.'),
                        'remaining_amount' => 'Rp ' . number_format($savingRemaining, 0, ',', '.'),
                        'progress_percentage' => $savingProgress . '%',
                        'monthly_saving_needed' => 'Rp ' . number_format($monthlySavingNeeded, 0, ',', '.'),
                        'target_date' => $targetDate ? $targetDate->format('d M Y') : 'Tidak ditentukan',
                        'days_remaining' => $daysRemaining ? $daysRemaining . ' hari lagi' : 'Tidak ditentukan'
                    ]
                ],
                [
                    'type' => 'emergency',
                    'goal_name' => 'Dana Darurat',
                    'target_amount' => $emergencyTargetAmount,
                    'current_amount' => $currentEmergencyAmount,
                    'remaining_amount' => $emergencyRemaining,
                    'progress_percentage' => $emergencyProgress,
                    'is_completed' => $currentEmergencyAmount >= $emergencyTargetAmount,
                    'formatted' => [
                        'target_amount' => 'Rp ' . number_format($emergencyTargetAmount, 0, ',', '.'),
                        'current_amount' => 'Rp ' . number_format($currentEmergencyAmount, 0, ',', '.'),
                        'remaining_amount' => 'Rp ' . number_format($emergencyRemaining, 0, ',', '.'),
                        'progress_percentage' => $emergencyProgress . '%'
                    ]
                ]
            ];

            // Calculate overall progress
            $totalTargetAmount = $savingTargetAmount + $emergencyTargetAmount;
            $totalCurrentAmount = $currentSavingAmount + $currentEmergencyAmount;
            $overallProgress = $totalTargetAmount > 0 
                ? round(($totalCurrentAmount / $totalTargetAmount) * 100, 2) 
                : 0;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'main_saving_target' => [
                        'amount' => $savingTargetAmount,
                        'current_amount' => $currentSavingAmount,
                        'progress_percentage' => $savingProgress,
                        'formatted_amount' => 'Rp ' . number_format($savingTargetAmount, 0, ',', '.'),
                        'formatted_current' => 'Rp ' . number_format($currentSavingAmount, 0, ',', '.'),
                    ],
                    'finance_plan' => [
                        'monthly_income' => $userFinancePlan->monthly_income,
                        'income_date' => $userFinancePlan->income_date,
                        'monthly_saving_target' => $monthlySavingTarget,
                        'saving_target_duration_years' => $savingTargetDurationYears,
                        'saving_target_duration_months' => $savingTargetDurationMonths,
                        'total_saving_target' => $savingTargetAmount,
                        'emergency_target_amount' => $emergencyTargetAmount,
                        'formatted' => [
                            'monthly_income' => 'Rp ' . number_format($userFinancePlan->monthly_income, 0, ',', '.'),
                            'monthly_saving_target' => 'Rp ' . number_format($monthlySavingTarget, 0, ',', '.'),
                            'saving_target_duration' => $savingTargetDurationYears . ' tahun (' . $savingTargetDurationMonths . ' bulan)',
                            'total_saving_target' => 'Rp ' . number_format($savingTargetAmount, 0, ',', '.'),
                            'emergency_target_amount' => 'Rp ' . number_format($emergencyTargetAmount, 0, ',', '.')
                        ]
                    ],
                    'goals' => $mainGoals,
                    'summary' => [
                        'total_goals' => count($mainGoals),
                        'completed_goals' => count(array_filter($mainGoals, fn($goal) => $goal['is_completed'])),
                        'total_target_amount' => $totalTargetAmount,
                        'total_current_amount' => $totalCurrentAmount,
                        'overall_progress' => $overallProgress,
                        'formatted' => [
                            'total_target_amount' => 'Rp ' . number_format($totalTargetAmount, 0, ',', '.'),
                            'total_current_amount' => 'Rp ' . number_format($totalCurrentAmount, 0, ',', '.'),
                            'overall_progress' => $overallProgress . '%'
                        ]
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving goals progress: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateAccountBalance(Request $request)
    {
        try {
            $request->validate([
                'account_id' => 'required|exists:accounts,id',
                'type' => 'required|string|in:Kebutuhan,Tabungan,Darurat',
                'balance_per_type' => 'required|numeric|min:0'
            ]);

            $user = $request->user();
            $user->load('accounts.allocations');
            
            $accountId = $request->account_id;
            $type = $request->type;
            $newBalancePerType = $request->balance_per_type;

            // Verify account belongs to user
            $account = $user->accounts()->find($accountId);
            if (!$account) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account not found or not accessible'
                ], 403);
            }

            // Get total number of banks user has
            $totalBanks = $user->accounts->count();

            // Update or create allocation for this type
            $allocation = $account->allocations()->where('type', $type)->first();
            $oldBalancePerType = $allocation ? $allocation->balance_per_type : 0;

            if ($allocation) {
                $allocation->balance_per_type = $newBalancePerType;
                $allocation->save();
            } else {
                AccountAllocation::create([
                    'account_id' => $accountId,
                    'type' => $type,
                    'balance_per_type' => $newBalancePerType
                ]);
            }

            // Calculate current_balance based on number of banks
            $newCurrentBalance = 0;
            
            if ($totalBanks == 1) {
                // 1 Bank: current_balance = kebutuhan + tabungan
                $kebutuhanBalance = $account->allocations()->where('type', 'Kebutuhan')->first()->balance_per_type ?? 0;
                $tabunganBalance = $account->allocations()->where('type', 'Tabungan')->first()->balance_per_type ?? 0;
                $newCurrentBalance = $kebutuhanBalance + $tabunganBalance;
                
            } elseif ($totalBanks == 2) {
                // 2 Banks: Bank1(kebutuhan) + Bank2(tabungan + darurat)
                $accounts = $user->accounts->sortBy('id'); // Sort to ensure consistent order
                
                if ($account->id == $accounts->first()->id) {
                    // This is Bank 1 - only kebutuhan
                    $newCurrentBalance = $account->allocations()->where('type', 'Kebutuhan')->first()->balance_per_type ?? 0;
                } else {
                    // This is Bank 2 - tabungan + darurat
                    $tabunganBalance = $account->allocations()->where('type', 'Tabungan')->first()->balance_per_type ?? 0;
                    $daruratBalance = $account->allocations()->where('type', 'Darurat')->first()->balance_per_type ?? 0;
                    $newCurrentBalance = $tabunganBalance + $daruratBalance;
                }
                
            } else {
                // 3+ Banks: sum all allocations for this account
                $newCurrentBalance = $account->allocations()->sum('balance_per_type');
            }

            // Update account current_balance
            $oldCurrentBalance = $account->current_balance;
            $account->current_balance = $newCurrentBalance;
            $account->save();

            // Handle budget tracking for "Kebutuhan" type
            $budgetData = null;
            if ($type === 'Kebutuhan') {
                $budgetData = $this->handleBudgetTracking($user->id, $accountId, $newBalancePerType);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Account balance updated successfully',
                'data' => [
                    'account_id' => $accountId,
                    'account_name' => $account->account_name,
                    'type' => $type,
                    'total_banks' => $totalBanks,
                    'allocation_update' => [
                        'old_balance_per_type' => $oldBalancePerType,
                        'new_balance_per_type' => $newBalancePerType,
                        'balance_change' => $newBalancePerType - $oldBalancePerType
                    ],
                    'account_balance' => [
                        'old_current_balance' => $oldCurrentBalance,
                        'new_current_balance' => $newCurrentBalance,
                        'current_balance_change' => $newCurrentBalance - $oldCurrentBalance
                    ],
                    'calculation_method' => $this->getCalculationMethod($totalBanks, $account->id, $user->accounts->sortBy('id')),
                    'budget_tracking' => $budgetData,
                    'formatted' => [
                        'type' => ucfirst($type),
                        'old_balance_per_type' => 'Rp ' . number_format($oldBalancePerType, 0, ',', '.'),
                        'new_balance_per_type' => 'Rp ' . number_format($newBalancePerType, 0, ',', '.'),
                        'new_current_balance' => 'Rp ' . number_format($newCurrentBalance, 0, ',', '.')
                    ]
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating account balance: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getTodayExpenses(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get today's date
            $today = Carbon::now()->toDateString();
            
            // Get today's budget data from budget table
            $todayBudgets = Budget::where('user_id', $user->id)
                ->whereDate('created_at', $today)
                ->get();

            if ($todayBudgets->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No budget data found for today. Please update your account balance first.'
                ], 404);
            }

            // Sum daily_budget and daily_saving from all accounts for today
            $totalDailyBudget = $todayBudgets->sum('daily_budget');
            $totalDailySaving = $todayBudgets->sum('daily_saving');

            // TODO: Today expenses will be implemented later via expenses function
            $todayExpenses = 0; // Placeholder - will be replaced with actual expenses calculation

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_budget' => $totalDailyBudget,
                    'today_expenses' => $todayExpenses,
                    'daily_saving' => $totalDailySaving,
                    'budget_records_count' => $todayBudgets->count(),
                    'formatted' => [
                        'daily_budget' => 'Rp ' . number_format($totalDailyBudget, 0, ',', '.'),
                        'today_expenses' => 'Rp ' . number_format($todayExpenses, 0, ',', '.'),
                        'daily_saving' => 'Rp ' . number_format($totalDailySaving, 0, ',', '.')
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving today expenses: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDailySaving(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get today's date
            $today = Carbon::now()->toDateString();
            
            // Get today's budget data from budget table
            $todayBudgets = Budget::where('user_id', $user->id)
                ->whereDate('created_at', $today)
                ->get();

            if ($todayBudgets->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No budget data found for today. Please update your account balance first.'
                ], 404);
            }

            // Sum daily_saving from all accounts for today
            $totalDailySaving = $todayBudgets->sum('daily_saving');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_saving' => $totalDailySaving,
                    'budget_records_count' => $todayBudgets->count(),
                    'formatted' => [
                        'daily_saving' => 'Rp ' . number_format($totalDailySaving, 0, ',', '.')
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving daily saving: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getCalculationMethod($totalBanks, $accountId, $sortedAccounts)
    {
        if ($totalBanks == 1) {
            return 'Single bank: kebutuhan + tabungan';
        } elseif ($totalBanks == 2) {
            $isFirstBank = $accountId == $sortedAccounts->first()->id;
            return $isFirstBank ? 'Bank 1: kebutuhan only' : 'Bank 2: tabungan + darurat';
        } else {
            return 'Multiple banks: sum all allocations for this bank';
        }
    }

    private function handleBudgetTracking($userId, $accountId, $kebutuhanBalance)
    {
        try {
            // Get current month details - total days in month (30 or 31)
            $daysInMonth = Carbon::now()->daysInMonth;
            
            // Calculate daily budget from kebutuhan balance divided by days in month
            $dailyBudget = $daysInMonth > 0 ? round($kebutuhanBalance / $daysInMonth, 0) : 0;

            // Get existing budget for today
            $today = Carbon::now()->toDateString();
            $existingBudget = Budget::where('user_id', $userId)
                ->where('account_id', $accountId)
                ->whereDate('created_at', $today)
                ->first();

            // Calculate daily saving
            $dailySaving = 0;
            
            if ($existingBudget) {
                // If budget exists for today, keep existing daily_saving and update daily_budget
                $dailySaving = $existingBudget->daily_saving;
                $existingBudget->daily_budget = $dailyBudget;
                $existingBudget->save();
                $budget = $existingBudget;
            } else {
                // Get yesterday's budget to calculate daily saving
                $yesterday = Carbon::now()->subDay()->toDateString();
                $yesterdayBudget = Budget::where('user_id', $userId)
                    ->where('account_id', $accountId)
                    ->whereDate('created_at', $yesterday)
                    ->first();

                // If yesterday had leftover money, add it to today's daily saving
                if ($yesterdayBudget) {
                    // Get yesterday's expenses from expense table
                    $yesterdayExpenses = Expense::where('user_id', $userId)
                        ->where('account_id', $accountId)
                        ->whereDate('created_at', $yesterday)
                        ->sum('amount');

                    // Calculate yesterday's leftover (daily_budget - actual_expenses)
                    $yesterdayLeftover = max(0, $yesterdayBudget->daily_budget - $yesterdayExpenses);
                    
                    // Add yesterday's leftover to previous daily_saving
                    $dailySaving = $yesterdayBudget->daily_saving + $yesterdayLeftover;
                }

                // Create new budget record for today
                $budget = Budget::create([
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'daily_budget' => $dailyBudget,
                    'daily_saving' => $dailySaving,
                ]);
            }

            return [
                'budget_id' => $budget->id,
                'daily_budget' => $dailyBudget,
                'daily_saving' => $dailySaving,
                'kebutuhan_balance' => $kebutuhanBalance,
                'days_in_month' => $daysInMonth,
                'calculation' => "kebutuhan_balance ({$kebutuhanBalance}) / days_in_month ({$daysInMonth})",
                'is_new_record' => !$existingBudget,
                'formatted' => [
                    'daily_budget' => 'Rp ' . number_format($dailyBudget, 0, ',', '.'),
                    'daily_saving' => 'Rp ' . number_format($dailySaving, 0, ',', '.'),
                    'kebutuhan_balance' => 'Rp ' . number_format($kebutuhanBalance, 0, ',', '.')
                ]
            ];

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to track budget: ' . $e->getMessage(),
                'daily_budget' => 0,
                'daily_saving' => 0
            ];
        }
    }
}
