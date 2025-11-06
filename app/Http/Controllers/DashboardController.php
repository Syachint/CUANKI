<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\UserFinancePlan;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\Income;
use App\Models\BankData;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Helper function to check and award badges
     */
    private function checkBadges($user)
    {
        try {
            $achievementController = new \App\Http\Controllers\AchievementController();
            $request = new \Illuminate\Http\Request();
            $request->setUserResolver(function() use ($user) {
                return $user;
            });
            $achievementController->checkAndAwardBadges($request);
        } catch (\Exception $e) {
            // Badge checking should not affect main operation
            \Log::warning('Badge checking failed: ' . $e->getMessage());
        }
    }
    /**
     * Get current datetime with proper timezone
     */
    private function now()
    {
        return Carbon::now(config('app.timezone', 'Asia/Jakarta'));
    }

    /**
     * Create Carbon instance from date with proper timezone
     */
    private function carbonFromDate($year, $month, $day)
    {
        return Carbon::createFromDate($year, $month, $day, config('app.timezone', 'Asia/Jakarta'));
    }

    /**
     * Round currency amounts to 2 decimal places for cleaner display
     */
    private function roundCurrency($amount)
    {
        return round((float)$amount, 2);
    }

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
            $today = $this->now()->toDateString();
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
            $daysInMonth = $this->now()->daysInMonth;

            // Sum daily_budget from all accounts for today
            // Round to 2 decimal places untuk pembacaan yang lebih rapi
            $dailyBudget = $this->roundCurrency($todayBudgets->sum('daily_budget'));
            $dailySaving = $this->roundCurrency($todayBudgets->sum('daily_saving'));
            $initialDailyBudget = $this->roundCurrency($todayBudgets->sum('initial_daily_budget'));
            $budgetDifference = $this->roundCurrency($dailyBudget - $initialDailyBudget);
            
            // Ensure daily budget is never negative (minimum Rp 0)
            $displayDailyBudget = max(0, $dailyBudget);
            $totalAvailable = $displayDailyBudget + $dailySaving;
            $dataSource = 'budget_table';

            // Create greeting message with total available funds
            $greetingMessage = "Hai, " . ($user->username ?: $user->name) . "! ini uang kamu hari ini Rp " . number_format($dailyBudget, 0, ',', '.');

            return response()->json([
                'status' => 'success',
                'message' => $greetingMessage,
                'data' => [
                    'user' => [
                        'name' => $user->name,
                        'username' => $user->username,
                        'daily_budget' => 'Rp ' . number_format($displayDailyBudget, 0, ',', '.'),
                    ],
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
                $targetDate = $this->now()->addMonths($savingTargetDurationMonths);
                $daysRemaining = $this->now()->diffInDays($targetDate, false);
                
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

            $this->ensureDailyBudgetExists($userId);

            $today = $this->now()->toDateString();
            
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->get();

            $totalDailyBudget = $this->roundCurrency($todayBudgets->sum('daily_budget'));
            $totalInitialBudget = $this->roundCurrency($todayBudgets->sum('initial_daily_budget'));
            $totalDailySaving = $this->roundCurrency($todayBudgets->sum('daily_saving'));
            $budgetDifference = $this->roundCurrency($totalDailyBudget - $totalInitialBudget);

            $todayExpenses = Expense::where('user_id', $userId)
                ->whereDate('expense_date', $today)
                ->sum('amount');

            $remainingBudget = max(0, $totalDailyBudget - $todayExpenses);
            
            $isOverBudget = $todayExpenses > $totalDailyBudget;
            $overBudgetAmount = $isOverBudget ? ($todayExpenses - $totalDailyBudget) : 0;

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
            $today = $this->now()->toDateString();
            
            // Get today's budget data from budget table
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->get();

            // Sum daily_saving from all accounts for today (dengan pembulatan)
            $totalDailySaving = $this->roundCurrency($todayBudgets->sum('daily_saving'));
            $totalDailyBudget = $this->roundCurrency($todayBudgets->sum('daily_budget'));
            $totalInitialBudget = $this->roundCurrency($todayBudgets->sum('initial_daily_budget'));
            $budgetDifference = $this->roundCurrency($totalDailyBudget - $totalInitialBudget);
            
            // Ensure daily budget display is never negative
            $displayDailyBudget = max(0, $totalDailyBudget);
            $totalAvailable = $displayDailyBudget + $totalDailySaving;
            $isOverBudget = $totalDailyBudget < 0;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_saving' => $totalDailySaving,
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

            $today = $this->now()->toDateString();
            $todayStart = $this->now()->startOfDay();
            $todayEnd = $this->now()->endOfDay();
            
            $todayExpenses = Expense::where('user_id', $userId)
                ->whereDate('expense_date', $today)
                ->with(['category', 'account.bank'])
                ->orderBy('created_at', 'desc')
                ->get();

            $todayIncomes = Income::where('user_id', $userId)
                ->whereDate('received_date', $today)
                ->with(['account.bank'])
                ->orderBy('created_at', 'desc')
                ->get();

            $totalExpenses = $todayExpenses->sum('amount');
            $totalIncomes = $todayIncomes->sum('amount');

            $expenseTransactions = $todayExpenses->map(function ($expense) {
                $isMonthlyExpense = $expense->frequency === 'Bulanan';
                return [
                    'id' => $expense->id,
                    'category_name' => $expense->category ? $expense->category->name : 'Tanpa Kategori',
                    'note' => $expense->note ?: 'Tidak ada catatan',
                    'amount' => $expense->amount,
                    'is_income' => false,
                    'expense_time' => $expense->created_at->format('H:i'),
                    'expense_date_raw' => Carbon::parse($expense->expense_date)->format('d M Y'),
                    'formatted_amount' => '-' . number_format($expense->amount, 0, ',', '.'),
                    'is_monthly_expense' => $isMonthlyExpense,
                    'expense_type' => $isMonthlyExpense ? 'Monthly Budget' : 'Regular',
                    'created_at_timestamp' => $expense->created_at->timestamp
                ];
            });

            $incomeTransactions = $todayIncomes->map(function ($income) {
                return [
                    'id' => $income->id,
                    'category_name' => $income->income_source ?: 'Pemasukan',
                    'note' => $income->note ?: 'Tidak ada catatan',
                    'amount' => $income->amount,
                    'is_income' => true,
                    'expense_time' => $income->created_at->format('H:i'),
                    'expense_date_raw' => Carbon::parse($income->received_date)->format('d M Y'),
                    'formatted_amount' => '+' . number_format($income->amount, 0, ',', '.'),
                    'created_at_timestamp' => $income->created_at->timestamp
                ];
            });

            $allTransactions = $expenseTransactions->concat($incomeTransactions)
                ->sortByDesc('created_at_timestamp')
                ->map(function($transaction) {
                    unset($transaction['created_at_timestamp']);
                    return $transaction;
                })
                ->values();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'date' => $today,
                    'total_expenses' => $totalExpenses,
                    'total_transactions' => $todayIncomes->count() + $todayExpenses->count(),
                    'formatted_date' => Carbon::parse($today)->format('d M Y'),
                    'formatted_total_expenses' => 'Rp ' . number_format($totalExpenses, 0, ',', '.'),
                    'transactions' => $allTransactions,
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
            $month = $request->get('month', $this->now()->month);
            $year = $request->get('year', $this->now()->year);
            
            // Validate month and year
            if ($month < 1 || $month > 12 || $year < 2020 || $year > 2030) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid month or year parameter'
                ], 400);
            }

            // Create start and end dates for the month
            $startDate = $this->carbonFromDate($year, $month, 1)->startOfMonth();
            $endDate = $this->carbonFromDate($year, $month, 1)->endOfMonth();
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

            // Get current date for comparison (ensure correct timezone)
            $today = Carbon::now('Asia/Jakarta'); // Adjust timezone as needed
            $todayString = $today->toDateString();
            
            // Create calendar status array
            $calendarDates = [];
            
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $currentDate = $this->carbonFromDate($year, $month, $day);
                $dateString = $currentDate->toDateString();
                
                // Get budget for this day
                $dayBudgets = $monthlyBudgets->filter(function ($budget) use ($currentDate) {
                    return Carbon::parse($budget->created_at)->toDateString() === $currentDate->toDateString();
                });
                
                // Get expenses for this day
                $dayExpenses = $monthlyExpenses->filter(function ($expense) use ($currentDate) {
                    return Carbon::parse($expense->expense_date)->toDateString() === $currentDate->toDateString();
                });

                // Calculate budget values correctly
                $totalInitialDailyBudget = $dayBudgets->sum('initial_daily_budget'); // Budget asli untuk ditampilkan
                $totalCurrentDailyBudget = $dayBudgets->sum('daily_budget'); // Budget saat ini untuk remaining calculation
                $totalDailyExpenses = $dayExpenses->sum('amount');
                
                // Over budget check menggunakan initial budget
                $isOverBudget = $totalDailyExpenses > $totalInitialDailyBudget;
                
                // Remaining budget menggunakan current daily budget
                $remainingBudget = max(0, $totalInitialDailyBudget - $totalDailyExpenses);
                
                // Fix date comparison - use string comparison for better accuracy
                $isToday = $dateString === $todayString;
                $isPast = $currentDate->lt($today->startOfDay());
                $isFuture = $currentDate->gt($today->endOfDay());
                
                // Determine status
                $status = 'normal'; // default
                if ($isToday) {
                    $status = $isOverBudget ? 'today-overbudget' : 'today-normal';
                } elseif ($isPast) {
                    if ($totalInitialDailyBudget > 0) {
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
                    'daily_budget' => $totalInitialDailyBudget, // Menggunakan initial budget untuk ditampilkan
                    'daily_expenses' => $totalDailyExpenses,
                    'remaining_budget' => $remainingBudget, // Menggunakan current budget dikurangi expenses
                    'over_budget_amount' => $isOverBudget ? ($totalDailyExpenses - $totalInitialDailyBudget) : 0,
                    'is_over_budget' => $isOverBudget,
                    'expense_count' => $dayExpenses->count(),
                    'formatted' => [
                        'date' => $currentDate->format('d M'),
                        'daily_budget' => 'Rp ' . number_format($totalInitialDailyBudget, 0, ',', '.'), // Display initial budget
                        'daily_expenses' => 'Rp ' . number_format($totalDailyExpenses, 0, ',', '.'),
                        'remaining_budget' => 'Rp ' . number_format($remainingBudget, 0, ',', '.'), // Current budget - expenses
                        'over_budget_amount' => 'Rp ' . number_format($isOverBudget ? ($totalDailyExpenses - $totalInitialDailyBudget) : 0, 0, ',', '.')
                    ],
                    // Debug info (will be removed after fixing)
                    'debug' => [
                        'current_date_string' => $dateString,
                        'today_string' => $todayString,
                        'is_same_date' => $dateString === $todayString
                    ]
                ];
            }

            // Calculate month summary
            $totalMonthInitialBudget = $monthlyBudgets->sum('initial_daily_budget'); // Total initial budget untuk bulan
            $totalMonthCurrentBudget = $monthlyBudgets->sum('daily_budget'); // Total current budget untuk bulan
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
                        'total_month_initial_budget' => $totalMonthInitialBudget, // Total initial budget untuk display
                        'total_month_current_budget' => $totalMonthCurrentBudget, // Total current budget untuk calculation
                        'total_month_expenses' => $totalMonthExpenses,
                        'remaining_month_budget' => max(0, $totalMonthCurrentBudget - $totalMonthExpenses), // Remaining dari current budget
                        'over_budget_days_count' => $overBudgetDaysCount,
                        'days_with_expenses' => $daysWithExpenses,
                        'average_daily_expenses' => $daysWithExpenses > 0 ? round($totalMonthExpenses / $daysWithExpenses, 0) : 0,
                        'formatted' => [
                            'month_year' => $startDate->format('F Y'),
                            'total_month_initial_budget' => 'Rp ' . number_format($totalMonthInitialBudget, 0, ',', '.'), // Display initial
                            'total_month_current_budget' => 'Rp ' . number_format($totalMonthCurrentBudget, 0, ',', '.'), // Display current
                            'total_month_expenses' => 'Rp ' . number_format($totalMonthExpenses, 0, ',', '.'),
                            'remaining_month_budget' => 'Rp ' . number_format(max(0, $totalMonthCurrentBudget - $totalMonthExpenses), 0, ',', '.'), // Remaining dari current
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
                    ],
                    'debug_info' => [
                        'server_today' => $todayString,
                        'server_now' => $today->toDateTimeString(),
                        'requested_month' => $month,
                        'requested_year' => $year,
                        'timezone' => config('app.timezone', 'UTC')
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
            $daysInMonth = $this->now()->daysInMonth;
            $currentYear = $this->now()->year;
            $currentMonth = $this->now()->month;
            
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
            $today = $this->now()->toDateString();
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
                $yesterday = $this->now()->subDay()->toDateString();
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
            $currentYear = $this->now()->year;
            $currentMonth = $this->now()->month;
            
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
            $currentYear = $this->now()->year;
            $currentMonth = $this->now()->month;
            $daysInMonth = $this->now()->daysInMonth;

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
            $today = $this->now()->toDateString();
            
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
            $now = $this->now();
            $daysInMonth = $now->daysInMonth;
            $today = $now->toDateString();
            $currentYear = $now->year;
            $currentMonth = $now->month;

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

            // Calculate initial daily budget for remaining days in this month (not total days)
            $today = $this->now();
            $endOfMonth = $this->carbonFromDate($year, $month, 1)->endOfMonth();
            $remainingDaysInMonth = $today->diffInDays($endOfMonth); // +1 include today
            
            // For user with zero balance, set budget to 0
            if ($totalKebutuhanBalance == 0) {
                $initialDailyBudget = 0;
                \Log::info("User {$userId} has zero balance, setting daily budget to 0");
            } else {
                $initialDailyBudget = $remainingDaysInMonth > 0 ? round($totalKebutuhanBalance / $remainingDaysInMonth, 0) : 0;
            }

            \Log::info("Set new monthly initial budget for user {$userId}, {$year}-{$month}: {$initialDailyBudget} (Balance: {$totalKebutuhanBalance}, Remaining days: {$remainingDaysInMonth})");
            
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
            $today = $this->now()->toDateString();
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
                    'generated_at' => $this->now()->toDateTimeString()
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
            $today = $this->now()->toDateString();
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

    public function timezoneTest(Request $request)
    {
        $now = $this->now();
        $utcNow = \Carbon\Carbon::now('UTC');
        $configTimezone = config('app.timezone');

        return response()->json([
            'status' => 'success',
            'message' => 'Timezone test data',
            'data' => [
                'config_timezone' => $configTimezone,
                'jakarta_time' => [
                    'datetime' => $now->format('Y-m-d H:i:s T'),
                    'date' => $now->toDateString(),
                    'timestamp' => $now->timestamp,
                    'timezone' => $now->timezone->getName()
                ],
                'utc_time' => [
                    'datetime' => $utcNow->format('Y-m-d H:i:s T'),
                    'date' => $utcNow->toDateString(),
                    'timestamp' => $utcNow->timestamp,
                    'timezone' => $utcNow->timezone->getName()
                ],
                'difference_hours' => $now->diffInHours($utcNow),
                'is_october_15' => $now->toDateString() === '2025-10-15',
                'current_date_check' => $now->format('Y-m-d') === '2025-10-15' ? 'Correct! Today is October 15' : 'Wrong! Should be October 15',
                'debug_info' => [
                    'day' => $now->day,
                    'month' => $now->month,
                    'year' => $now->year,
                    'day_name' => $now->dayName,
                    'hour' => $now->hour
                ]
            ]
        ]);
    }
}
