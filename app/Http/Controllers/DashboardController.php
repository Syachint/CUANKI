<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\UserFinancePlan;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\BankData;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function getGreetingUser(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Ensure daily budget exists for today
            $this->ensureDailyBudgetExists($userId);

            // Get today's budget records from database
            $today = Carbon::now()->toDateString();
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->get();

            // Get balance from accounts with type "kebutuhan" for display
            $user->load(['accounts.allocations']);
            $kebutuhanBalance = 0;
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                if ($kebutuhanAllocation) {
                    $kebutuhanBalance += $kebutuhanAllocation->balance_per_type;
                }
            }
            
            // Get current month details - total days in month (30 or 31)
            $daysInMonth = Carbon::now()->daysInMonth;

            // Sum daily_budget from all accounts for today
            $dailyBudget = $todayBudgets->sum('daily_budget');
            $initialDailyBudget = $todayBudgets->sum('initial_daily_budget');
            $budgetDifference = $dailyBudget - $initialDailyBudget;
            $dataSource = 'budget_table';

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
                        'current_amount' => $dailyBudget,
                        'initial_amount' => $initialDailyBudget,
                        'difference' => $budgetDifference,
                        'is_reduced' => $budgetDifference < 0,
                        'formatted' => [
                            'current_amount' => 'Rp ' . number_format($dailyBudget, 0, ',', '.'),
                            'initial_amount' => 'Rp ' . number_format($initialDailyBudget, 0, ',', '.'),
                            'difference' => 'Rp ' . number_format($budgetDifference, 0, ',', '.')
                        ],
                        'kebutuhan_balance' => $kebutuhanBalance,
                        'days_in_month' => $daysInMonth,
                        'source' => $dataSource,
                        'budget_records_count' => $todayBudgets->count()
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

    public function listBanks()
    {
        $banks = BankData::all(['id', 'code_name', 'bank_name']);

        return response()->json([
            'status' => 'success',
            'data'   => $banks
        ]);
    }

    public function addNewAccount(Request $request) {
        try {
            $request->validate([
                'bank_id' => 'required|exists:bank_data,id',
                'type' => 'required|string|in:Kebutuhan,Tabungan,Darurat',
                'balance_per_type' => 'required|numeric|min:0'
            ]);
            
            $user = $request->user();
            $user->load(['accounts.allocations']);
            
            $bankId = $request->bank_id;
            $type = $request->type;
            $balancePerType = $request->balance_per_type;
            
            // Get bank data to generate account name
            $bankData = BankData::find($bankId);
            if (!$bankData) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bank not found'
                ], 404);
            }
            
            // Generate account name from bank name
            $accountName = $bankData->code_name;
            
            // Get current number of accounts
            $currentAccountsCount = $user->accounts->count();
            
            if ($currentAccountsCount == 1) {
                // User has 1 account: Bank A with Kebutuhan, Tabungan, Darurat
                if ($type === 'Tabungan' || $type === 'Darurat') {
                    // Create new account for Bank B
                    $newAccount = Account::create([
                        'user_id' => $user->id,
                        // 'account_name' => $accountName,
                        'bank_id' => $bankId,
                        'current_balance' => 0, // Will be calculated later
                        'initial_balance' => 0
                    ]);
                    
                    // Get existing account (Bank A)
                    $existingAccount = $user->accounts->first();
                    
                    // Remove Tabungan and Darurat from Bank A, keep only Kebutuhan
                    AccountAllocation::where('account_id', $existingAccount->id)
                        ->whereIn('type', ['Tabungan', 'Darurat'])
                        ->delete();
                    
                    // Add requested type to new Bank B
                    AccountAllocation::create([
                        'account_id' => $newAccount->id,
                        'type' => $type,
                        'balance_per_type' => $balancePerType
                    ]);
                    
                    // Add the other type (Tabungan or Darurat) to Bank B with 0 balance
                    $otherType = ($type === 'Tabungan') ? 'Darurat' : 'Tabungan';
                    AccountAllocation::create([
                        'account_id' => $newAccount->id,
                        'type' => $otherType,
                        'balance_per_type' => 0
                    ]);
                    
                    // Update Bank A current_balance (only Kebutuhan)
                    $kebutuhanAllocation = $existingAccount->allocations()->where('type', 'Kebutuhan')->first();
                    $existingAccount->current_balance = $kebutuhanAllocation ? $kebutuhanAllocation->balance_per_type : 0;
                    $existingAccount->initial_balance = $existingAccount->current_balance;
                    $existingAccount->save();
                    
                    // Update Bank B current_balance (Tabungan + Darurat)
                    $newAccount->current_balance = $balancePerType; // Only the requested type has balance
                    $newAccount->initial_balance = $newAccount->current_balance;
                    $newAccount->save();
                    
                    $message = "Account added successfully. Bank A now has Kebutuhan only, Bank B has {$type} and {$otherType}.";
                    $createdAccount = $newAccount;
                    
                } else {
                    // Type is Kebutuhan - not allowed when user has 1 account
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot add Kebutuhan type when you already have 1 account. Choose Tabungan or Darurat.'
                    ], 400);
                }
                
            } elseif ($currentAccountsCount == 2) {
                // User has 2 accounts: Bank A (Kebutuhan), Bank B (Tabungan + Darurat)
                if ($type === 'Darurat') {
                    // Create new account for Bank C
                    $newAccount = Account::create([
                        'user_id' => $user->id,
                        // 'account_name' => $accountName,
                        'bank_id' => $bankId,
                        'current_balance' => $balancePerType,
                        'initial_balance' => $balancePerType
                    ]);
                    
                    // Add Darurat allocation to new Bank C
                    AccountAllocation::create([
                        'account_id' => $newAccount->id,
                        'type' => 'Darurat',
                        'balance_per_type' => $balancePerType
                    ]);
                    
                    // Remove Darurat from Bank B (second account)
                    $secondAccount = $user->accounts->sortBy('id')->skip(1)->first();
                    AccountAllocation::where('account_id', $secondAccount->id)
                        ->where('type', 'Darurat')
                        ->delete();
                    
                    // Update Bank B current_balance (only Tabungan now)
                    $tabunganAllocation = $secondAccount->allocations()->where('type', 'Tabungan')->first();
                    $secondAccount->current_balance = $tabunganAllocation ? $tabunganAllocation->balance_per_type : 0;
                    $secondAccount->initial_balance = $secondAccount->current_balance;
                    $secondAccount->save();
                    
                    $message = "Account added successfully. Darurat moved from Bank B to new Bank C.";
                    $createdAccount = $newAccount;
                    
                } else {
                    // Type is Kebutuhan or Tabungan - not allowed when user has 2 accounts
                    return response()->json([
                        'status' => 'error',
                        'message' => 'When you have 2 accounts, you can only add Darurat type to create a third account.'
                    ], 400);
                }
                
            } else {
                // User has 3+ accounts - can add any type freely
                $newAccount = Account::create([
                    'user_id' => $user->id,
                    // 'account_name' => $accountName,
                    'bank_id' => $bankId,
                    'current_balance' => $balancePerType,
                    'initial_balance' => $balancePerType
                ]);
                
                // Add allocation for the requested type
                AccountAllocation::create([
                    'account_id' => $newAccount->id,
                    'type' => $type,
                    'balance_per_type' => $balancePerType
                ]);
                
                $message = "Account added successfully with {$type} type.";
                $createdAccount = $newAccount;
            }
            
            // Handle budget tracking for "Kebutuhan" type
            $budgetData = null;
            if ($type === 'Kebutuhan') {
                // Reset dan update semua budget bulan ini dengan nilai baru
                $this->resetMonthlyInitialBudget($user->id);
                $this->updateMonthlyDailyBudget($user->id);
                $budgetData = $this->handleBudgetTracking($user->id, $createdAccount->id, $balancePerType);
            }
            
            // Reload user data to get updated accounts
            $user->load(['accounts.allocations']);
            
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'new_account' => [
                        'id' => $createdAccount->id,
                        // 'account_name' => $createdAccount->account_name,
                        'bank_id' => $createdAccount->bank_id,
                        'current_balance' => $createdAccount->current_balance,
                        'type' => $type,
                        'balance_per_type' => $balancePerType,
                        'formatted_balance' => 'Rp ' . number_format($createdAccount->current_balance, 0, ',', '.'),
                        'formatted_balance_per_type' => 'Rp ' . number_format($balancePerType, 0, ',', '.')
                    ],
                    'total_accounts' => $user->accounts->count(),
                    'accounts_summary' => $user->accounts->map(function($account) {
                        return [
                            'id' => $account->id,
                            // 'account_name' => $account->account_name,
                            'current_balance' => $account->current_balance,
                            'allocations' => $account->allocations->map(function($allocation) {
                                return [
                                    'type' => $allocation->type,
                                    'balance_per_type' => $allocation->balance_per_type,
                                    'formatted_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.')
                                ];
                            })
                        ];
                    }),
                    'budget_tracking' => $budgetData
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error adding user account: ' . $e->getMessage()
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
            $account->initial_balance = $newCurrentBalance;
            $account->save();

            // Handle budget tracking for "Kebutuhan" type
            $budgetData = null;
            if ($type === 'Kebutuhan') {
                // Reset dan update semua budget bulan ini dengan nilai baru
                $this->resetMonthlyInitialBudget($user->id);
                $this->updateMonthlyDailyBudget($user->id);
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
            $userId = $user->id;
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Ensure daily budget exists for today
            $this->ensureDailyBudgetExists($userId);

            // Get today's date
            $today = Carbon::now()->toDateString();
            
            // Get today's budget data from budget table
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->get();

            // Sum daily_budget and daily_saving from all accounts for today
            $totalDailyBudget = $todayBudgets->sum('daily_budget');
            $totalInitialBudget = $todayBudgets->sum('initial_daily_budget');
            $totalDailySaving = $todayBudgets->sum('daily_saving');
            $budgetDifference = $totalDailyBudget - $totalInitialBudget;

            // Get today's expenses
            $todayExpenses = Expense::where('user_id', $userId)
                ->whereDate('expense_date', $today)
                ->sum('amount');

            // Calculate remaining budget (based on current daily budget)
            $remainingBudget = max(0, $totalDailyBudget - $todayExpenses);
            
            // Check if over budget (based on current daily budget)
            $isOverBudget = $todayExpenses > $totalDailyBudget;
            $overBudgetAmount = $isOverBudget ? ($todayExpenses - $totalDailyBudget) : 0;

            // Check vs initial budget
            $isOverInitialBudget = $todayExpenses > $totalInitialBudget;
            $overInitialBudgetAmount = $isOverInitialBudget ? ($todayExpenses - $totalInitialBudget) : 0;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'current_daily_budget' => $totalDailyBudget,
                        'initial_daily_budget' => $totalInitialBudget,
                        'budget_difference' => $budgetDifference,
                        'is_budget_reduced' => $budgetDifference < 0
                    ],
                    'expenses' => [
                        'today_expenses' => $todayExpenses,
                        'remaining_budget' => $remainingBudget,
                        'is_over_current_budget' => $isOverBudget,
                        'over_current_budget_amount' => $overBudgetAmount,
                        'is_over_initial_budget' => $isOverInitialBudget,
                        'over_initial_budget_amount' => $overInitialBudgetAmount
                    ],
                    'daily_saving' => $totalDailySaving,
                    'budget_records_count' => $todayBudgets->count(),
                    'formatted' => [
                        'current_daily_budget' => 'Rp ' . number_format($totalDailyBudget, 0, ',', '.'),
                        'initial_daily_budget' => 'Rp ' . number_format($totalInitialBudget, 0, ',', '.'),
                        'budget_difference' => 'Rp ' . number_format($budgetDifference, 0, ',', '.'),
                        'today_expenses' => 'Rp ' . number_format($todayExpenses, 0, ',', '.'),
                        'remaining_budget' => 'Rp ' . number_format($remainingBudget, 0, ',', '.'),
                        'over_current_budget_amount' => 'Rp ' . number_format($overBudgetAmount, 0, ',', '.'),
                        'over_initial_budget_amount' => 'Rp ' . number_format($overInitialBudgetAmount, 0, ',', '.'),
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
            $userId = $user->id;
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Ensure daily budget exists for today
            $this->ensureDailyBudgetExists($userId);

            // Get today's date
            $today = Carbon::now()->toDateString();
            
            // Get today's budget data from budget table
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->get();

            // Sum daily_saving from all accounts for today
            $totalDailySaving = $todayBudgets->sum('daily_saving');
            $totalDailyBudget = $todayBudgets->sum('daily_budget');
            $totalInitialBudget = $todayBudgets->sum('initial_daily_budget');
            $budgetDifference = $totalDailyBudget - $totalInitialBudget;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_saving' => $totalDailySaving,
                    'budget' => [
                        'current_daily_budget' => $totalDailyBudget,
                        'initial_daily_budget' => $totalInitialBudget,
                        'difference' => $budgetDifference,
                        'is_reduced' => $budgetDifference < 0
                    ],
                    'budget_records_count' => $todayBudgets->count(),
                    'formatted' => [
                        'daily_saving' => 'Rp ' . number_format($totalDailySaving, 0, ',', '.'),
                        'current_daily_budget' => 'Rp ' . number_format($totalDailyBudget, 0, ',', '.'),
                        'initial_daily_budget' => 'Rp ' . number_format($totalInitialBudget, 0, ',', '.'),
                        'budget_difference' => 'Rp ' . number_format($budgetDifference, 0, ',', '.')
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

    public function getReceiptToday(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get today's date in multiple formats for debugging
            $today = Carbon::now()->toDateString(); // 2025-10-14
            $todayStart = Carbon::now()->startOfDay(); // 2025-10-14 00:00:00
            $todayEnd = Carbon::now()->endOfDay(); // 2025-10-14 23:59:59
            
            // Debug: Check all expenses for this user first
            $allExpenses = Expense::where('user_id', $userId)->get();
            
            // Try multiple date filter approaches
            $todayExpenses = Expense::where('user_id', $userId)
                ->where(function($query) use ($today, $todayStart, $todayEnd) {
                    $query->whereDate('expense_date', $today)
                          ->orWhereBetween('expense_date', [$todayStart, $todayEnd])
                          ->orWhereDate('created_at', $today);
                })
                ->with(['category'])
                ->orderBy('created_at', 'desc')
                ->get();

            // If still no results, get recent expenses instead
            if ($todayExpenses->isEmpty()) {
                $todayExpenses = Expense::where('user_id', $userId)
                    ->with(['category'])
                    ->orderBy('created_at', 'desc')
                    ->take(10) // Get latest 10 expenses
                    ->get();
            }

            // Calculate total expenses
            $totalExpenses = $todayExpenses->sum('amount');

            // Format expenses list
            $expenses = $todayExpenses->map(function ($expense) {
                return [
                    'id' => $expense->id,
                    'category_name' => $expense->category ? $expense->category->name : 'Tanpa Kategori',
                    'note' => $expense->note ?: 'Tidak ada catatan',
                    'amount' => $expense->amount,
                    'is_income' => false, // Semua adalah expense (negatif)
                    'expense_time' => $expense->created_at->format('H:i'),
                    'expense_date_raw' => $expense->expense_date,
                    'formatted_amount' => '-' . number_format($expense->amount, 0, ',', '.')
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'date' => $today,
                    'total_expenses' => $totalExpenses,
                    'total_transactions' => $todayExpenses->count(),
                    'formatted_date' => Carbon::parse($today)->format('d M Y'),
                    'formatted_total_expenses' => 'Rp ' . number_format($totalExpenses, 0, ',', '.'),
                    'transactions' => $expenses,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving today receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getCalendarStatus(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get month and year from request or use current month
            $month = $request->get('month', Carbon::now()->month);
            $year = $request->get('year', Carbon::now()->year);
            
            // Validate month and year
            if ($month < 1 || $month > 12 || $year < 2020 || $year > 2030) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid month or year parameter'
                ], 400);
            }

            // Create start and end dates for the month
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();
            $daysInMonth = $startDate->daysInMonth;
            
            // Get all budgets for this month
            $monthlyBudgets = Budget::where('user_id', $userId)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->get();

            // Get all expenses for this month
            $monthlyExpenses = Expense::where('user_id', $userId)
                ->whereYear('expense_date', $year)
                ->whereMonth('expense_date', $month)
                ->get();

            // Create calendar status array
            $calendarDates = [];
            
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $currentDate = Carbon::createFromDate($year, $month, $day);
                $dateString = $currentDate->toDateString();
                
                // Get budget for this day
                $dayBudgets = $monthlyBudgets->filter(function ($budget) use ($currentDate) {
                    return Carbon::parse($budget->created_at)->toDateString() === $currentDate->toDateString();
                });
                
                // Get expenses for this day
                $dayExpenses = $monthlyExpenses->filter(function ($expense) use ($currentDate) {
                    return Carbon::parse($expense->expense_date)->toDateString() === $currentDate->toDateString();
                });

                $totalDailyBudget = $dayBudgets->sum('daily_budget');
                $totalDailyExpenses = $dayExpenses->sum('amount');
                $isOverBudget = $totalDailyExpenses > $totalDailyBudget;
                $isToday = $currentDate->isToday();
                $isPast = $currentDate->isPast();
                $isFuture = $currentDate->isFuture();
                
                // Determine status
                $status = 'normal'; // default
                if ($isToday) {
                    $status = $isOverBudget ? 'today-overbudget' : 'today-normal';
                } elseif ($isPast) {
                    if ($totalDailyBudget > 0) {
                        $status = $isOverBudget ? 'overbudget' : 'under-budget';
                    } else {
                        $status = 'no-budget';
                    }
                } else {
                    $status = 'future';
                }

                $calendarDates[] = [
                    'date' => $dateString,
                    'day' => $day,
                    'day_name' => $currentDate->format('D'), // Mon, Tue, etc
                    'is_today' => $isToday,
                    'is_past' => $isPast,
                    'is_future' => $isFuture,
                    'status' => $status,
                    'daily_budget' => $totalDailyBudget,
                    'daily_expenses' => $totalDailyExpenses,
                    'remaining_budget' => max(0, $totalDailyBudget - $totalDailyExpenses),
                    'over_budget_amount' => $isOverBudget ? ($totalDailyExpenses - $totalDailyBudget) : 0,
                    'is_over_budget' => $isOverBudget,
                    'expense_count' => $dayExpenses->count(),
                    'formatted' => [
                        'date' => $currentDate->format('d M'),
                        'daily_budget' => 'Rp ' . number_format($totalDailyBudget, 0, ',', '.'),
                        'daily_expenses' => 'Rp ' . number_format($totalDailyExpenses, 0, ',', '.'),
                        'remaining_budget' => 'Rp ' . number_format(max(0, $totalDailyBudget - $totalDailyExpenses), 0, ',', '.'),
                        'over_budget_amount' => 'Rp ' . number_format($isOverBudget ? ($totalDailyExpenses - $totalDailyBudget) : 0, 0, ',', '.')
                    ]
                ];
            }

            // Calculate month summary
            $totalMonthBudget = $monthlyBudgets->sum('daily_budget');
            $totalMonthExpenses = $monthlyExpenses->sum('amount');
            $overBudgetDaysCount = collect($calendarDates)->where('is_over_budget', true)->count();
            $daysWithExpenses = collect($calendarDates)->where('expense_count', '>', 0)->count();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'month_name' => $startDate->format('F'),
                    'days_in_month' => $daysInMonth,
                    'calendar_dates' => $calendarDates,
                    'summary' => [
                        'total_month_budget' => $totalMonthBudget,
                        'total_month_expenses' => $totalMonthExpenses,
                        'remaining_month_budget' => max(0, $totalMonthBudget - $totalMonthExpenses),
                        'over_budget_days_count' => $overBudgetDaysCount,
                        'days_with_expenses' => $daysWithExpenses,
                        'average_daily_expenses' => $daysWithExpenses > 0 ? round($totalMonthExpenses / $daysWithExpenses, 0) : 0,
                        'formatted' => [
                            'month_year' => $startDate->format('F Y'),
                            'total_month_budget' => 'Rp ' . number_format($totalMonthBudget, 0, ',', '.'),
                            'total_month_expenses' => 'Rp ' . number_format($totalMonthExpenses, 0, ',', '.'),
                            'remaining_month_budget' => 'Rp ' . number_format(max(0, $totalMonthBudget - $totalMonthExpenses), 0, ',', '.'),
                            'over_budget_days' => $overBudgetDaysCount . ' hari',
                            'days_with_expenses' => $daysWithExpenses . ' hari'
                        ]
                    ],
                    'legend' => [
                        'normal' => 'Hari normal (tidak ada data)',
                        'under-budget' => 'Hari past dengan budget cukup',
                        'overbudget' => 'Hari past dengan over budget',
                        'today-normal' => 'Hari ini dengan budget cukup',
                        'today-overbudget' => 'Hari ini dengan over budget',
                        'future' => 'Hari yang akan datang',
                        'no-budget' => 'Hari past tanpa budget'
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving calendar status: ' . $e->getMessage()
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
            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;
            
            // Get or calculate total kebutuhan balance for proportion calculation
            $user = User::with(['accounts.allocations'])->find($userId);
            $totalKebutuhanBalance = 0;
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                if ($kebutuhanAllocation && $kebutuhanAllocation->balance_per_type > 0) {
                    $totalKebutuhanBalance += $kebutuhanAllocation->balance_per_type;
                }
            }
            
            // Calculate proportion for this account
            $accountProportion = $totalKebutuhanBalance > 0 ? ($kebutuhanBalance / $totalKebutuhanBalance) : 0;

            // Get existing budget for today
            $today = Carbon::now()->toDateString();
            $existingBudget = Budget::where('user_id', $userId)
                ->where('account_id', $accountId)
                ->whereDate('created_at', $today)
                ->first();

            // Get monthly initial budget (tetap sepanjang bulan)
            $monthlyInitialBudget = $this->getOrSetMonthlyInitialBudget($userId, $currentYear, $currentMonth);
            $accountInitialBudget = round($monthlyInitialBudget * $accountProportion, 0);

            // Calculate daily saving
            $dailySaving = 0;
            
            if ($existingBudget) {
                // If budget exists for today, keep existing daily_saving 
                // Update daily_budget to follow initial budget (not current balance)
                $dailySaving = $existingBudget->daily_saving;
                $existingBudget->daily_budget = $accountInitialBudget; // Ikuti initial budget
                // Keep initial_daily_budget unchanged if it already exists, or set to monthly initial
                if (!$existingBudget->initial_daily_budget) {
                    $existingBudget->initial_daily_budget = $accountInitialBudget;
                }
                $existingBudget->save();
                $budget = $existingBudget;
            } else {
                // Get yesterday's budget to calculate daily saving
                $yesterday = Carbon::now()->subDay()->toDateString();
                $yesterdayBudget = Budget::where('user_id', $userId)
                    ->where('account_id', $accountId)
                    ->whereDate('created_at', $yesterday)
                    ->first();

                // Transfer yesterday's daily_budget directly to today's daily_saving
                if ($yesterdayBudget) {
                    // Daily saving = yesterday's daily_saving + yesterday's daily_budget (dipindahkan langsung)
                    $dailySaving = $yesterdayBudget->daily_saving + $yesterdayBudget->daily_budget;
                }

                // Create new budget record for today
                $budget = Budget::create([
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'daily_budget' => $accountInitialBudget, // Ikuti initial budget
                    'initial_daily_budget' => $accountInitialBudget, // Initial budget yang tetap
                    'daily_saving' => $dailySaving,
                ]);
            }

            return [
                'budget_id' => $budget->id,
                'daily_budget' => $accountInitialBudget,
                'initial_daily_budget' => $accountInitialBudget,
                'daily_saving' => $dailySaving,
                'kebutuhan_balance' => $kebutuhanBalance,
                'days_in_month' => $daysInMonth,
                'monthly_initial_budget' => $monthlyInitialBudget,
                'calculation' => "monthly_initial_budget ({$monthlyInitialBudget}) * account_proportion",
                'is_new_record' => !$existingBudget,
                'formatted' => [
                    'daily_budget' => 'Rp ' . number_format($accountInitialBudget, 0, ',', '.'),
                    'initial_daily_budget' => 'Rp ' . number_format($accountInitialBudget, 0, ',', '.'),
                    'daily_saving' => 'Rp ' . number_format($dailySaving, 0, ',', '.'),
                    'kebutuhan_balance' => 'Rp ' . number_format($kebutuhanBalance, 0, ',', '.')
                ]
            ];

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to track budget: ' . $e->getMessage(),
                'daily_budget' => 0,
                'initial_daily_budget' => 0,
                'daily_saving' => 0
            ];
        }
    }

    /**
     * Reset monthly initial budget when there's manual change
     */
    private function resetMonthlyInitialBudget($userId)
    {
        try {
            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;
            
            // Delete existing initial budget records for this month to force recalculation
            Budget::where('user_id', $userId)
                ->whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->whereNotNull('initial_daily_budget')
                ->update([
                    'initial_daily_budget' => null,
                    'daily_budget' => null // Reset daily budget juga karena akan dihitung ulang
                ]);

            \Log::info("Reset monthly initial budget for user {$userId}, {$currentYear}-{$currentMonth}");
            
        } catch (\Exception $e) {
            \Log::error('Failed to reset monthly initial budget: ' . $e->getMessage());
        }
    }

    /**
     * Update daily budget untuk semua records bulan ini setelah perubahan manual
     */
    private function updateMonthlyDailyBudget($userId)
    {
        try {
            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;
            $daysInMonth = Carbon::now()->daysInMonth;

            // Get user dengan accounts dan allocations
            $user = User::with(['accounts.allocations'])->find($userId);
            if (!$user) {
                return;
            }

            // Calculate new initial budget dari saldo kebutuhan saat ini
            $totalKebutuhanBalance = 0;
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                if ($kebutuhanAllocation && $kebutuhanAllocation->balance_per_type > 0) {
                    $totalKebutuhanBalance += $kebutuhanAllocation->balance_per_type;
                }
            }

            $newInitialBudget = $daysInMonth > 0 ? round($totalKebutuhanBalance / $daysInMonth, 0) : 0;

            // Update semua budget records bulan ini
            $existingBudgets = Budget::where('user_id', $userId)
                ->whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->get();

            foreach ($existingBudgets as $budget) {
                // Get account proportion
                $account = $user->accounts->where('id', $budget->account_id)->first();
                if ($account) {
                    $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                    $accountBalance = $kebutuhanAllocation ? $kebutuhanAllocation->balance_per_type : 0;
                    $accountProportion = $totalKebutuhanBalance > 0 ? ($accountBalance / $totalKebutuhanBalance) : 0;
                    $accountNewBudget = round($newInitialBudget * $accountProportion, 0);

                    // Update both daily_budget dan initial_daily_budget
                    $budget->daily_budget = $accountNewBudget;
                    $budget->initial_daily_budget = $accountNewBudget;
                    $budget->save();
                }
            }

            \Log::info("Updated monthly daily budget for user {$userId}, new budget: {$newInitialBudget}");
            
        } catch (\Exception $e) {
            \Log::error('Failed to update monthly daily budget: ' . $e->getMessage());
        }
    }

    /**
     * Ensure daily budget exists for today for all user accounts
     */
    private function ensureDailyBudgetExists($userId)
    {
        try {
            $today = Carbon::now()->toDateString();
            
            // Check if today's budget already exists
            $existingBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->count();

            // If no budget exists for today, generate it
            if ($existingBudgets == 0) {
                $this->generateDailyBudget($userId);
            }

        } catch (\Exception $e) {
            \Log::error('Failed to ensure daily budget exists: ' . $e->getMessage());
        }
    }

    /**
     * Generate daily budget for all user accounts for today
     */
    private function generateDailyBudget($userId)
    {
        try {
            // Get user with accounts and allocations
            $user = User::with(['accounts.allocations'])->find($userId);
            if (!$user) {
                throw new \Exception('User not found');
            }

            // Get current month details
            $daysInMonth = Carbon::now()->daysInMonth;
            $today = Carbon::now()->toDateString();
            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;

            // Get or calculate initial daily budget (only set once per month)
            $monthlyInitialBudget = $this->getOrSetMonthlyInitialBudget($userId, $currentYear, $currentMonth);

            // Calculate accumulated daily saving from current month (excluding today)
            $previousBudgets = Budget::where('user_id', $userId)
                ->whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->whereDate('created_at', '<', $today)
                ->get();

            $accumulatedDailySaving = 0;

            // Calculate accumulated daily saving from previous days
            // Daily saving = akumulasi daily_budget dari hari-hari sebelumnya (dipindahkan langsung)
            foreach ($previousBudgets as $previousBudget) {
                // Add daily_budget from previous day directly to daily_saving
                $accumulatedDailySaving += $previousBudget->daily_budget;
            }

            // Calculate total current kebutuhan balance untuk proporsi saja
            $totalKebutuhanBalance = 0;
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                if ($kebutuhanAllocation && $kebutuhanAllocation->balance_per_type > 0) {
                    $totalKebutuhanBalance += $kebutuhanAllocation->balance_per_type;
                }
            }

            // Generate budget for each account that has "Kebutuhan" allocation
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                
                if ($kebutuhanAllocation && $kebutuhanAllocation->balance_per_type > 0) {
                    // Calculate proportion for this account
                    $accountProportion = $totalKebutuhanBalance > 0 ? 
                        ($kebutuhanAllocation->balance_per_type / $totalKebutuhanBalance) : 0;
                    
                    // Both daily_budget and initial_daily_budget use same initial value
                    $accountInitialBudget = round($monthlyInitialBudget * $accountProportion, 0);

                    // Create budget record for today
                    Budget::create([
                        'user_id' => $userId,
                        'account_id' => $account->id,
                        'daily_budget' => $accountInitialBudget, // Ikuti initial budget
                        'initial_daily_budget' => $accountInitialBudget, // Initial budget yang tetap
                        'daily_saving' => $accumulatedDailySaving,
                    ]);

                    \Log::info("Generated daily budget for user {$userId}, account {$account->id}: daily_budget={$accountInitialBudget}, initial={$accountInitialBudget}");
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to generate daily budget: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get or set monthly initial budget (calculated once per month)
     */
    private function getOrSetMonthlyInitialBudget($userId, $year, $month)
    {
        try {
            // Check if we already have initial budget for this month
            $existingInitialBudget = Budget::where('user_id', $userId)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->whereNotNull('initial_daily_budget')
                ->first();

            if ($existingInitialBudget && $existingInitialBudget->initial_daily_budget > 0) {
                // Use existing initial budget for this month
                return $existingInitialBudget->initial_daily_budget;
            }

            // Calculate new initial budget based on current kebutuhan balance
            $user = User::with(['accounts.allocations'])->find($userId);
            if (!$user) {
                return 0;
            }

            $totalKebutuhanBalance = 0;
            foreach ($user->accounts as $account) {
                $kebutuhanAllocation = $account->allocations->where('type', 'Kebutuhan')->first();
                if ($kebutuhanAllocation && $kebutuhanAllocation->balance_per_type > 0) {
                    $totalKebutuhanBalance += $kebutuhanAllocation->balance_per_type;
                }
            }

            // Calculate initial daily budget for this month
            $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
            $initialDailyBudget = $daysInMonth > 0 ? round($totalKebutuhanBalance / $daysInMonth, 0) : 0;

            \Log::info("Set new monthly initial budget for user {$userId}, {$year}-{$month}: {$initialDailyBudget}");
            
            return $initialDailyBudget;

        } catch (\Exception $e) {
            \Log::error('Failed to get/set monthly initial budget: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Public endpoint to manually generate daily budget (for testing)
     */
    public function generateTodayBudget(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Force generate budget for today
            $this->generateDailyBudget($user->id);

            // Get generated budgets
            $today = Carbon::now()->toDateString();
            $todayBudgets = Budget::where('user_id', $user->id)
                ->whereDate('created_at', $today)
                ->with('account')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Daily budget generated successfully',
                'data' => [
                    'date' => $today,
                    'total_daily_budget' => $todayBudgets->sum('daily_budget'),
                    'budgets' => $todayBudgets->map(function($budget) {
                        return [
                            'account_id' => $budget->account_id,
                            'account_name' => $budget->account->account_name ?? 'Unknown',
                            'daily_budget' => $budget->daily_budget,
                            'initial_daily_budget' => $budget->initial_daily_budget,
                            'daily_saving' => $budget->daily_saving,
                            'budget_difference' => $budget->daily_budget - $budget->initial_daily_budget,
                            'formatted' => [
                                'daily_budget' => 'Rp ' . number_format($budget->daily_budget, 0, ',', '.'),
                                'initial_daily_budget' => 'Rp ' . number_format($budget->initial_daily_budget, 0, ',', '.'),
                                'daily_saving' => 'Rp ' . number_format($budget->daily_saving, 0, ',', '.'),
                                'budget_difference' => 'Rp ' . number_format($budget->daily_budget - $budget->initial_daily_budget, 0, ',', '.')
                            ]
                        ];
                    }),
                    'generated_at' => Carbon::now()->toDateTimeString()
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating daily budget: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get budget comparison showing initial vs current daily budget
     */
    public function getBudgetComparison(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Ensure daily budget exists for today
            $this->ensureDailyBudgetExists($userId);

            // Get today's budget records
            $today = Carbon::now()->toDateString();
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->with('account')
                ->get();

            $totalInitialBudget = $todayBudgets->sum('initial_daily_budget');
            $totalCurrentBudget = $todayBudgets->sum('daily_budget');
            $totalDifference = $totalCurrentBudget - $totalInitialBudget;

            return response()->json([
                'status' => 'success',
                'message' => 'Budget comparison retrieved successfully',
                'data' => [
                    'date' => $today,
                    'summary' => [
                        'total_initial_daily_budget' => $totalInitialBudget,
                        'total_current_daily_budget' => $totalCurrentBudget,
                        'total_difference' => $totalDifference,
                        'is_reduced' => $totalDifference < 0,
                        'reduction_percentage' => $totalInitialBudget > 0 ? round(abs($totalDifference / $totalInitialBudget) * 100, 2) : 0,
                        'formatted' => [
                            'total_initial_daily_budget' => 'Rp ' . number_format($totalInitialBudget, 0, ',', '.'),
                            'total_current_daily_budget' => 'Rp ' . number_format($totalCurrentBudget, 0, ',', '.'),
                            'total_difference' => 'Rp ' . number_format($totalDifference, 0, ',', '.'),
                            'reduction_percentage' => abs(round($totalInitialBudget > 0 ? ($totalDifference / $totalInitialBudget) * 100 : 0, 2)) . '%'
                        ]
                    ],
                    'accounts_detail' => $todayBudgets->map(function($budget) {
                        $difference = $budget->daily_budget - $budget->initial_daily_budget;
                        return [
                            'account_id' => $budget->account_id,
                            'account_name' => $budget->account->account_name ?? 'Unknown',
                            'initial_daily_budget' => $budget->initial_daily_budget,
                            'current_daily_budget' => $budget->daily_budget,
                            'difference' => $difference,
                            'is_reduced' => $difference < 0,
                            'daily_saving' => $budget->daily_saving,
                            'formatted' => [
                                'initial_daily_budget' => 'Rp ' . number_format($budget->initial_daily_budget, 0, ',', '.'),
                                'current_daily_budget' => 'Rp ' . number_format($budget->daily_budget, 0, ',', '.'),
                                'difference' => 'Rp ' . number_format($difference, 0, ',', '.'),
                                'daily_saving' => 'Rp ' . number_format($budget->daily_saving, 0, ',', '.')
                            ]
                        ];
                    }),
                    'explanation' => [
                        'initial_daily_budget' => 'Budget harian asli yang dihitung dari saldo kebutuhan / hari dalam bulan',
                        'current_daily_budget' => 'Budget harian saat ini yang bisa berubah sesuai penggunaan',
                        'difference' => 'Selisih antara budget saat ini dengan budget asli (- = berkurang, + = bertambah)',
                        'daily_saving' => 'Akumulasi sisa budget dari hari-hari sebelumnya'
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving budget comparison: ' . $e->getMessage()
            ], 500);
        }
    }
}
