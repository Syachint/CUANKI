<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Controllers\UserController;
use App\Models\User;
use App\Models\Income;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\ExpenseCategories;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class TransactionController extends Controller
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
            // Log error but don't return error to user
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

    public function addIncome(Request $request)
    {
        try {
            $request->validate([
                'tanggal' => 'required|date',
                'total' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:255',
                'bank_allocation_id' => 'required|exists:accounts_allocation,id',
            ]);

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Find the allocation and verify user ownership
            $allocation = AccountAllocation::with(['account.bank'])
                ->where('id', $request->bank_allocation_id)
                ->whereHas('account', function($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();

            if (!$allocation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bank allocation not found or access denied'
                ], 404);
            }

            $account = $allocation->account;
            $allocationType = $allocation->type;

            // Create income record
            $income = Income::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'amount' => $request->total,
                'actual_amount' => null, // Will be set when confirmed
                'note' => $request->notes,
                'received_date' => Carbon::parse($request->tanggal)->format('Y-m-d'),
                'is_manual' => true,
                'frequency' => 'Sekali', // Default for manual entry
                'income_source' => 'Lainnya', // Default source
                'confirmation_status' => 'Pending'
            ]);

            // Update account allocation balance (add income to the specific allocation)
            $oldAllocationBalance = $allocation->balance_per_type;
            $allocation->balance_per_type += $request->total;
            $allocation->save();

            // Update account current_balance (sum of all allocations)
            $oldCurrentBalance = $account->current_balance;
            $account->refresh();
            $newCurrentBalance = $account->allocations()->sum('balance_per_type');
            $account->update([
                'current_balance' => $newCurrentBalance,
                'initial_balance' => max($account->initial_balance, $newCurrentBalance)
            ]);

            // Update or create budget if allocation type is "Kebutuhan"
            $budgetData = null;
            if ($allocationType === 'Kebutuhan') {
                $budgetData = $this->updateBudgetFromIncome($user->id, $account->id, $allocation->balance_per_type);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Income added successfully',
                'data' => [
                    'income_id' => $income->id,
                    'user_id' => $income->user_id,
                    'account_id' => $income->account_id,
                    'account_name' => $account->bank ? $account->bank->code_name : 'Unknown Bank',
                    'amount' => $income->amount,
                    'note' => $income->note,
                    'received_date' => $income->received_date,
                    'confirmation_status' => $income->confirmation_status,
                    'created_at' => $income->created_at->format('Y-m-d H:i:s'),
                    'allocation_update' => [
                        'allocation_id' => $allocation->id,
                        'allocation_type' => $allocationType,
                        'old_balance' => $oldAllocationBalance,
                        'new_balance' => $allocation->balance_per_type,
                        'balance_increase' => $request->total
                    ],
                    'account_update' => [
                        'old_current_balance' => $oldCurrentBalance,
                        'new_current_balance' => $newCurrentBalance,
                        'balance_increase' => $request->total
                    ],
                    'budget_update' => $budgetData,
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($income->amount, 0, ',', '.'),
                        'received_date' => Carbon::parse($income->received_date)->format('d M Y'),
                        'bank_allocation' => ($account->bank->code_name ?? 'Unknown Bank') . ' - ' . $allocationType,
                        'old_allocation_balance' => 'Rp ' . number_format($oldAllocationBalance, 0, ',', '.'),
                        'new_allocation_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.'),
                        'old_current_balance' => 'Rp ' . number_format($oldCurrentBalance, 0, ',', '.'),
                        'new_current_balance' => 'Rp ' . number_format($newCurrentBalance, 0, ',', '.')
                    ]
                ]
            ], 201);

            // Check and award badges after successful income creation
            $this->checkBadges($user);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error adding income: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getExpenseCategories(Request $request)
    {
        $expenseCategories = ExpenseCategories::all();
        return response()->json($expenseCategories);
    }

    public function addExpense(Request $request)
    {
        try {
            $request->validate([
                'tanggal' => 'required|date',
                'total' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:255',
                'kategori' => 'required|integer|exists:expense_categories,id',
                'bank_allocation_id' => 'required|exists:accounts_allocation,id',
            ]);

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Find the allocation and verify user ownership
            $allocation = AccountAllocation::with(['account.bank'])
                ->where('id', $request->bank_allocation_id)
                ->whereHas('account', function($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();

            if (!$allocation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bank allocation not found or access denied'
                ], 404);
            }

            $account = $allocation->account;
            $allocationType = $allocation->type;

            // Verify the category exists
            $category = ExpenseCategories::where('id', $request->kategori)->first();

            if (!$category) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Category not found'
                ], 404);
            }

            // Check if user has enough balance in the allocation
            if ($allocation->balance_per_type < $request->total) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient balance in ' . $allocationType . ' allocation. Available: Rp ' . number_format($allocation->balance_per_type, 0, ',', '.')
                ], 400);
            }

            // Create expense record
            $expense = Expense::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'expense_category_id' => $request->kategori,
                'amount' => $request->total,
                'note' => $request->notes,
                'expense_date' => Carbon::parse($request->tanggal)->format('Y-m-d'),
                'is_manual' => true,
                'frequency' => 'Sekali', // Default for manual entry
            ]);

            // Update account allocation balance (subtract expense from the specific allocation)
            $oldAllocationBalance = $allocation->balance_per_type;
            $allocation->balance_per_type -= $request->total;
            $allocation->save();

            // Update account current_balance (sum of all allocations)
            $oldCurrentBalance = $account->current_balance;
            $account->refresh();
            $newCurrentBalance = $account->allocations()->sum('balance_per_type');
            $account->update([
                'current_balance' => $newCurrentBalance,
                'initial_balance' => max($account->initial_balance, $newCurrentBalance)
            ]);

            // Update budget if allocation type is "Kebutuhan"
            $budgetData = null;
            if ($allocationType === 'Kebutuhan') {
                $budgetData = $this->updateBudgetFromExpense($user->id, $account->id, $request->total, $allocation->balance_per_type);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Expense added successfully',
                'data' => [
                    'expense_id' => $expense->id,
                    'user_id' => $expense->user_id,
                    'account_id' => $expense->account_id,
                    'account_name' => $account->bank ? $account->bank->code_name : 'Unknown Bank',
                    'category_id' => $expense->expense_category_id,
                    'category_name' => $category->name,
                    'amount' => $expense->amount,
                    'note' => $expense->note,
                    'expense_date' => $expense->expense_date,
                    'created_at' => $expense->created_at->format('Y-m-d H:i:s'),
                    'allocation_update' => [
                        'allocation_id' => $allocation->id,
                        'allocation_type' => $allocationType,
                        'old_balance' => $oldAllocationBalance,
                        'new_balance' => $allocation->balance_per_type,
                        'balance_decrease' => $request->total
                    ],
                    'account_update' => [
                        'old_current_balance' => $oldCurrentBalance,
                        'new_current_balance' => $newCurrentBalance,
                        'balance_decrease' => $request->total
                    ],
                    'budget_update' => $budgetData,
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($expense->amount, 0, ',', '.'),
                        'expense_date' => Carbon::parse($expense->expense_date)->format('d M Y'),
                        'bank_allocation' => ($account->bank->code_name ?? 'Unknown Bank') . ' - ' . $allocationType,
                        'kategori' => $category->name,
                        'old_allocation_balance' => 'Rp ' . number_format($oldAllocationBalance, 0, ',', '.'),
                        'new_allocation_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.'),
                        'old_current_balance' => 'Rp ' . number_format($oldCurrentBalance, 0, ',', '.'),
                        'new_current_balance' => 'Rp ' . number_format($newCurrentBalance, 0, ',', '.')
                    ]
                ]
            ], 201);

            // Check and award badges after successful expense creation
            $this->checkBadges($user);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error adding expense: ' . $e->getMessage()
            ], 500);
        }
    }

    private function updateBudgetFromIncome($userId, $accountId, $newKebutuhanBalance)
    {
        try {
            // Get remaining days from today until end of month for fair daily budget calculation
            $currentDate = $this->now();
            $endOfMonth = $currentDate->copy()->endOfMonth();
            $remainingDaysInMonth = $currentDate->diffInDays($endOfMonth); // +1 to include today
            
            // Calculate new daily budget from updated kebutuhan balance using remaining days
            // This ensures fair distribution for users registering mid-month or adding income later
            $newDailyBudget = 0;
            if ($remainingDaysInMonth > 0 && $newKebutuhanBalance > 0) {
                $newDailyBudget = round($newKebutuhanBalance / $remainingDaysInMonth, 0);
            }

            // Get today's date
            $today = $currentDate->toDateString();
            
            // Check if budget exists for today
            $existingBudget = Budget::where('user_id', $userId)
                ->where('account_id', $accountId)
                ->whereDate('created_at', $today)
                ->first();

            if ($existingBudget) {
                // Update existing budget with new daily_budget
                $oldDailyBudget = $existingBudget->daily_budget;
                $existingBudget->daily_budget = $newDailyBudget;
                $existingBudget->save();
                
                return [
                    'budget_id' => $existingBudget->id,
                    'action' => 'updated',
                    'old_daily_budget' => $oldDailyBudget,
                    'new_daily_budget' => $newDailyBudget,
                    'daily_budget_increase' => $newDailyBudget - $oldDailyBudget,
                    'daily_saving' => $existingBudget->daily_saving,
                    'kebutuhan_balance' => $newKebutuhanBalance,
                    'remaining_days_in_month' => $remainingDaysInMonth,
                    'formatted' => [
                        'old_daily_budget' => 'Rp ' . number_format($oldDailyBudget, 0, ',', '.'),
                        'new_daily_budget' => 'Rp ' . number_format($newDailyBudget, 0, ',', '.'),
                        'daily_budget_increase' => 'Rp ' . number_format($newDailyBudget - $oldDailyBudget, 0, ',', '.'),
                        'daily_saving' => 'Rp ' . number_format($existingBudget->daily_saving, 0, ',', '.'),
                        'kebutuhan_balance' => 'Rp ' . number_format($newKebutuhanBalance, 0, ',', '.')
                    ]
                ];
            } else {
                // Create new budget record
                $budget = Budget::create([
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'daily_budget' => $newDailyBudget,
                    'daily_saving' => 0, // Start with 0 for new budget
                ]);
                
                return [
                    'budget_id' => $budget->id,
                    'action' => 'created',
                    'old_daily_budget' => 0,
                    'new_daily_budget' => $newDailyBudget,
                    'daily_budget_increase' => $newDailyBudget,
                    'daily_saving' => 0,
                    'kebutuhan_balance' => $newKebutuhanBalance,
                    'remaining_days_in_month' => $remainingDaysInMonth,
                    'formatted' => [
                        'old_daily_budget' => 'Rp 0',
                        'new_daily_budget' => 'Rp ' . number_format($newDailyBudget, 0, ',', '.'),
                        'daily_budget_increase' => 'Rp ' . number_format($newDailyBudget, 0, ',', '.'),
                        'daily_saving' => 'Rp 0',
                        'kebutuhan_balance' => 'Rp ' . number_format($newKebutuhanBalance, 0, ',', '.')
                    ]
                ];
            }

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to update budget: ' . $e->getMessage(),
                'action' => 'failed',
                'new_daily_budget' => 0,
                'daily_saving' => 0
            ];
        }
    }

    private function updateBudgetFromExpense($userId, $accountId, $expenseAmount, $newKebutuhanBalance)
    {
        try {
            // Get remaining days from today until end of month for consistency with income calculation
            $currentDate = $this->now();
            $endOfMonth = $currentDate->copy()->endOfMonth();
            $remainingDaysInMonth = $currentDate->diffInDays($endOfMonth); // +1 to include today

            // Get today's date
            $today = $currentDate->toDateString();
            
            // Check if budget exists for today
            $existingBudget = Budget::where('user_id', $userId)
                ->where('account_id', $accountId)
                ->whereDate('created_at', $today)
                ->first();

            if ($existingBudget) {
                // Smart budget deduction: daily_budget first, then daily_saving if needed
                $oldDailyBudget = $existingBudget->daily_budget;
                $oldDailySaving = $existingBudget->daily_saving;
                
                if ($oldDailyBudget >= $expenseAmount) {
                    // Normal case: expense covered by daily budget
                    $newDailyBudget = $oldDailyBudget - $expenseAmount;
                    $newDailySaving = $oldDailySaving;
                    $usedFromSaving = 0;
                } else {
                    // Over budget case: use daily_budget + daily_saving
                    $remainingExpense = $expenseAmount - $oldDailyBudget;
                    $newDailyBudget = 0; // Daily budget becomes 0
                    
                    if ($oldDailySaving >= $remainingExpense) {
                        // Daily saving can cover the remaining expense
                        $newDailySaving = $oldDailySaving - $remainingExpense;
                        $usedFromSaving = $remainingExpense;
                    } else {
                        // Not enough daily saving, use all available
                        $newDailySaving = 0;
                        $usedFromSaving = $oldDailySaving;
                    }
                }
                
                $existingBudget->daily_budget = $newDailyBudget;
                $existingBudget->daily_saving = $newDailySaving;
                $existingBudget->save();
                
                return [
                    'budget_id' => $existingBudget->id,
                    'action' => 'updated_after_expense',
                    'old_daily_budget' => $oldDailyBudget,
                    'new_daily_budget' => $newDailyBudget,
                    'old_daily_saving' => $oldDailySaving,
                    'new_daily_saving' => $newDailySaving,
                    'daily_budget_decrease' => min($expenseAmount, $oldDailyBudget),
                    'daily_saving_decrease' => $usedFromSaving,
                    'expense_amount' => $expenseAmount,
                    'is_over_budget' => $expenseAmount > $oldDailyBudget,
                    'total_coverage' => ($oldDailyBudget + $oldDailySaving),
                    'kebutuhan_balance' => $newKebutuhanBalance,
                    'remaining_days_in_month' => $remainingDaysInMonth,
                    'formatted' => [
                        'old_daily_budget' => 'Rp ' . number_format($oldDailyBudget, 0, ',', '.'),
                        'new_daily_budget' => 'Rp ' . number_format($newDailyBudget, 0, ',', '.'),
                        'old_daily_saving' => 'Rp ' . number_format($oldDailySaving, 0, ',', '.'),
                        'new_daily_saving' => 'Rp ' . number_format($newDailySaving, 0, ',', '.'),
                        'daily_budget_decrease' => 'Rp ' . number_format(min($expenseAmount, $oldDailyBudget), 0, ',', '.'),
                        'daily_saving_decrease' => 'Rp ' . number_format($usedFromSaving, 0, ',', '.'),
                        'expense_amount' => 'Rp ' . number_format($expenseAmount, 0, ',', '.'),
                        'total_coverage' => 'Rp ' . number_format(($oldDailyBudget + $oldDailySaving), 0, ',', '.'),
                        'kebutuhan_balance' => 'Rp ' . number_format($newKebutuhanBalance, 0, ',', '.')
                    ]
                ];
            } else {
                // This shouldn't happen for expenses, but handle it just in case
                // Calculate what the daily budget would be based on current balance
                $calculatedDailyBudget = $remainingDaysInMonth > 0 && $newKebutuhanBalance > 0 
                    ? round($newKebutuhanBalance / $remainingDaysInMonth, 0) 
                    : 0;
                    
                return [
                    'action' => 'no_budget_found',
                    'message' => 'No budget found for today. Please update account balance first.',
                    'new_daily_budget' => $calculatedDailyBudget,
                    'kebutuhan_balance' => $newKebutuhanBalance,
                    'remaining_days_in_month' => $remainingDaysInMonth
                ];
            }

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to update budget: ' . $e->getMessage(),
                'action' => 'failed',
                'new_daily_budget' => 0,
                'daily_saving' => 0
            ];
        }
    }

    public function getDetailReceiptExpense(Request $request)
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

            // Get date parameter from request, default to today
            $requestDate = $request->get('date', $this->now()->toDateString());
            
            // Validate and parse the date
            try {
                $targetDate = Carbon::createFromFormat('Y-m-d', $requestDate, 'Asia/Jakarta');
                $targetDateString = $targetDate->toDateString();
                $targetDateStart = $targetDate->startOfDay();
                $targetDateEnd = $targetDate->endOfDay();
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid date format. Please use YYYY-MM-DD format.'
                ], 400);
            }

            // Get expenses for the target date with detailed information (include monthly expenses)
            $expenses = Expense::where('user_id', $userId)
                ->where(function($query) use ($targetDateString, $targetDateStart, $targetDateEnd) {
                    $query->whereDate('expense_date', $targetDateString)
                          ->orWhereBetween('created_at', [$targetDateStart, $targetDateEnd]);
                })
                ->with([
                    'category:id,name',
                    'account.bank:id,code_name,bank_name'
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Calculate total expenses
            $totalExpenses = $expenses->sum('amount');
            $expenseCount = $expenses->count();

            // Format detailed expenses list
            $detailExpenses = $expenses->map(function ($expense) {
                $createdAt = Carbon::parse($expense->created_at)->setTimezone('Asia/Jakarta');
                $expenseDate = Carbon::parse($expense->expense_date)->setTimezone('Asia/Jakarta');
                $isMonthlyExpense = $expense->frequency === 'monthly';
                
                return [
                    'expense_id' => $expense->id,
                    'category' => [
                        'id' => $expense->category->id ?? null,
                        'name' => $expense->category->name ?? 'Unknown Category',
                        'note' => $expense->category->note ?? null
                    ],
                    'note' => $expense->note ?? '',
                    'amount' => $expense->amount,
                    'expense_date' => $expenseDate->toDateString(),
                    'expense_time' => $createdAt->format('H:i:s'),
                    'expense_type' => $isMonthlyExpense ? 'Monthly Budget' : 'Regular',
                    'is_monthly_expense' => $isMonthlyExpense,
                    'frequency' => $expense->frequency,
                    'from_bank' => [
                        'code_name' => $expense->account->bank->code_name ?? 'Unknown Bank',
                        'bank_name' => $expense->account->bank->bank_name ?? 'Unknown Bank',
                        'account_id' => $expense->account_id
                    ],
                    'timestamps' => [
                        'created_at' => $createdAt->format('Y-m-d H:i:s'),
                        'expense_date_full' => $expenseDate->format('Y-m-d H:i:s')
                    ],
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($expense->amount, 0, ',', '.'),
                        'expense_date' => $expenseDate->format('d M Y'),
                        'expense_time' => $createdAt->format('H:i'),
                        'expense_datetime' => $expenseDate->format('d M Y') . ' ' . $createdAt->format('H:i'),
                        'expense_type' => $isMonthlyExpense ? 'Monthly Budget' : 'Regular Expense'
                    ]
                ];
            });

            // Get date navigation info (previous/next dates with expenses)
            $previousExpenseDate = Expense::where('user_id', $userId)
                ->whereDate('expense_date', '<', $targetDateString)
                ->orderBy('expense_date', 'desc')
                ->first();
                
            $nextExpenseDate = Expense::where('user_id', $userId)
                ->whereDate('expense_date', '>', $targetDateString)
                ->orderBy('expense_date', 'asc')
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Detail receipt expenses retrieved successfully',
                'data' => [
                    'target_date' => $targetDateString,
                    'date_info' => [
                        'date' => $targetDateString,
                        'day_name' => $targetDate->format('l'),
                        'formatted_date' => $targetDate->format('d M Y'),
                        'is_today' => $targetDateString === $this->now()->toDateString(),
                        'is_past' => $targetDate->lt($this->now()->startOfDay()),
                        'is_future' => $targetDate->gt($this->now()->endOfDay())
                    ],
                    'summary' => [
                        'total_expenses' => $totalExpenses,
                        'expense_count' => $expenseCount,
                        'average_per_expense' => $expenseCount > 0 ? round($totalExpenses / $expenseCount, 0) : 0,
                        'formatted' => [
                            'total_expenses' => 'Rp ' . number_format($totalExpenses, 0, ',', '.'),
                            'expense_count' => $expenseCount . ' transaksi',
                            'average_per_expense' => 'Rp ' . number_format($expenseCount > 0 ? round($totalExpenses / $expenseCount, 0) : 0, 0, ',', '.')
                        ]
                    ],
                    'expenses' => $detailExpenses,
                    'navigation' => [
                        'previous_date' => $previousExpenseDate ? Carbon::parse($previousExpenseDate->expense_date)->toDateString() : null,
                        'next_date' => $nextExpenseDate ? Carbon::parse($nextExpenseDate->expense_date)->toDateString() : null,
                        'has_previous' => $previousExpenseDate !== null,
                        'has_next' => $nextExpenseDate !== null,
                        'formatted' => [
                            'previous_date' => $previousExpenseDate ? Carbon::parse($previousExpenseDate->expense_date)->format('d M Y') : null,
                            'next_date' => $nextExpenseDate ? Carbon::parse($nextExpenseDate->expense_date)->format('d M Y') : null
                        ]
                    ],
                    'debug_info' => [
                        'requested_date' => $requestDate,
                        'target_date' => $targetDateString,
                        'query_date_start' => $targetDateStart->format('Y-m-d H:i:s'),
                        'query_date_end' => $targetDateEnd->format('Y-m-d H:i:s'),
                        'timezone' => 'Asia/Jakarta'
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving detail receipt expenses: ' . $e->getMessage(),
                'debug' => [
                    'requested_date' => $request->get('date', 'today'),
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    public function getUsageBarToday(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }

            // Get today's date
            $today = Carbon::now('Asia/Jakarta')->format('Y-m-d');
            
            // Get user's budget (from budgets table)
            $dailyBudget = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->first();

            // Calculate daily limit
            $dailyLimit = 0;
            if ($dailyBudget && $dailyBudget->initial_daily_budget > 0) {
                $dailyLimit = $dailyBudget->initial_daily_budget;
            } if ($dailyLimit < $dailyBudget->daily_budget) {
                $dailyLimit = $dailyBudget->daily_budget;
            }

            // Get today's total expenses
            $todayExpenses = Expense::where('user_id', $userId)
                ->whereDate('expense_date', $today)
                ->sum('amount');

            // Calculate percentage (100% to 0% - remaining budget percentage)
            $remainingBudget = max(0, $dailyBudget->daily_budget);
            $percentage = $dailyLimit > 0 ? ($remainingBudget / $dailyLimit) * 100 : 100;
            $percentage = max(0, min(100, $percentage)); // Cap between 0% and 100%

            return response()->json([
                'success' => true,
                'data' => [
                    'current_daily_budget' => number_format($dailyBudget->daily_budget),
                    'daily_limit' => number_format($dailyLimit),
                    'percentage' => round($percentage, 1),
                    'formatted_text' => 'Transaksi anda hari ini: Rp ' . number_format($dailyBudget->daily_budget) . '/Rp ' . number_format($dailyLimit)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error getting usage bar: ' . $e->getMessage()], 500);
        }
    }

    public function getDetailReceiptIncomes(Request $request)
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

            // Get date parameter from request, default to today
            $requestDate = $request->get('date', $this->now()->toDateString());
            
            // Validate and parse the date
            try {
                $targetDate = Carbon::createFromFormat('Y-m-d', $requestDate, 'Asia/Jakarta');
                $targetDateString = $targetDate->toDateString();
                $targetDateStart = $targetDate->startOfDay();
                $targetDateEnd = $targetDate->endOfDay();
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid date format. Please use YYYY-MM-DD format.'
                ], 400);
            }

            // Get incomes for the target date with detailed information
            $incomes = Income::where('user_id', $userId)
                ->where(function($query) use ($targetDateString, $targetDateStart, $targetDateEnd) {
                    $query->whereDate('received_date', $targetDateString)
                          ->orWhereBetween('created_at', [$targetDateStart, $targetDateEnd]);
                })
                ->with([
                    'account.bank:id,code_name,bank_name'
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Calculate total incomes
            $totalIncomes = $incomes->sum('amount');
            $incomeCount = $incomes->count();

            // Format detailed incomes list
            $detailIncomes = $incomes->map(function ($income) {
                $createdAt = Carbon::parse($income->created_at)->setTimezone('Asia/Jakarta');
                $receivedDate = Carbon::parse($income->received_date)->setTimezone('Asia/Jakarta');
                
                return [
                    'income_id' => $income->id,
                    'income_source' => $income->income_source ?? 'Lainnya',
                    'note' => $income->note ?? '',
                    'amount' => $income->amount,
                    'actual_amount' => $income->actual_amount,
                    'received_date' => $receivedDate->toDateString(),
                    'received_time' => $createdAt->format('H:i:s'),
                    'frequency' => $income->frequency ?? 'Sekali',
                    'confirmation_status' => $income->confirmation_status ?? 'Pending',
                    'is_manual' => $income->is_manual ?? false,
                    'to_bank' => [
                        'code_name' => $income->account->bank->code_name ?? 'Unknown Bank',
                        'bank_name' => $income->account->bank->bank_name ?? 'Unknown Bank',
                        'account_id' => $income->account_id
                    ],
                    'timestamps' => [
                        'created_at' => $createdAt->format('Y-m-d H:i:s'),
                        'received_date_full' => $receivedDate->format('Y-m-d H:i:s')
                    ],
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($income->amount, 0, ',', '.'),
                        'actual_amount' => $income->actual_amount ? 'Rp ' . number_format($income->actual_amount, 0, ',', '.') : null,
                        'received_date' => $receivedDate->format('d M Y'),
                        'received_time' => $createdAt->format('H:i'),
                        'received_datetime' => $receivedDate->format('d M Y') . ' ' . $createdAt->format('H:i'),
                        'confirmation_status' => ucfirst($income->confirmation_status ?? 'Pending'),
                        'frequency' => $income->frequency ?? 'Sekali',
                        'income_source' => $income->income_source ?? 'Lainnya'
                    ]
                ];
            });

            // Get date navigation info (previous/next dates with incomes)
            $previousIncomeDate = Income::where('user_id', $userId)
                ->whereDate('received_date', '<', $targetDateString)
                ->orderBy('received_date', 'desc')
                ->first();
                
            $nextIncomeDate = Income::where('user_id', $userId)
                ->whereDate('received_date', '>', $targetDateString)
                ->orderBy('received_date', 'asc')
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Detail receipt incomes retrieved successfully',
                'data' => [
                    'target_date' => $targetDateString,
                    'date_info' => [
                        'date' => $targetDateString,
                        'day_name' => $targetDate->format('l'),
                        'formatted_date' => $targetDate->format('d M Y'),
                        'is_today' => $targetDateString === $this->now()->toDateString(),
                        'is_past' => $targetDate->lt($this->now()->startOfDay()),
                        'is_future' => $targetDate->gt($this->now()->endOfDay())
                    ],
                    'summary' => [
                        'total_incomes' => $totalIncomes,
                        'income_count' => $incomeCount,
                        'average_per_income' => $incomeCount > 0 ? round($totalIncomes / $incomeCount, 0) : 0,
                        'confirmed_incomes' => $incomes->where('confirmation_status', 'Confirmed')->count(),
                        'pending_incomes' => $incomes->where('confirmation_status', 'Pending')->count(),
                        'manual_incomes' => $incomes->where('is_manual', true)->count(),
                        'formatted' => [
                            'total_incomes' => 'Rp ' . number_format($totalIncomes, 0, ',', '.'),
                            'income_count' => $incomeCount . ' transaksi',
                            'average_per_income' => 'Rp ' . number_format($incomeCount > 0 ? round($totalIncomes / $incomeCount, 0) : 0, 0, ',', '.'),
                            'confirmed_incomes' => $incomes->where('confirmation_status', 'Confirmed')->count() . ' terkonfirmasi',
                            'pending_incomes' => $incomes->where('confirmation_status', 'Pending')->count() . ' pending'
                        ]
                    ],
                    'incomes' => $detailIncomes,
                    'navigation' => [
                        'previous_date' => $previousIncomeDate ? Carbon::parse($previousIncomeDate->received_date)->toDateString() : null,
                        'next_date' => $nextIncomeDate ? Carbon::parse($nextIncomeDate->received_date)->toDateString() : null,
                        'has_previous' => $previousIncomeDate !== null,
                        'has_next' => $nextIncomeDate !== null,
                        'formatted' => [
                            'previous_date' => $previousIncomeDate ? Carbon::parse($previousIncomeDate->received_date)->format('d M Y') : null,
                            'next_date' => $nextIncomeDate ? Carbon::parse($nextIncomeDate->received_date)->format('d M Y') : null
                        ]
                    ],
                    'debug_info' => [
                        'requested_date' => $requestDate,
                        'target_date' => $targetDateString,
                        'query_date_start' => $targetDateStart->format('Y-m-d H:i:s'),
                        'query_date_end' => $targetDateEnd->format('Y-m-d H:i:s'),
                        'timezone' => 'Asia/Jakarta'
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving detail receipt incomes: ' . $e->getMessage(),
                'debug' => [
                    'requested_date' => $request->get('date', 'today'),
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }
}
