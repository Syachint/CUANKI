<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Income;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\ExpenseCategories;
use Carbon\Carbon;

class TransactionController extends Controller
{
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

            // Update daily budget if allocation type is "Kebutuhan"
            $budgetData = null;
            if ($allocationType === 'Kebutuhan') {
                // Get total kebutuhan balance from all accounts
                $totalKebutuhanBalance = $this->getTotalKebutuhanBalance($user->id);
                $budgetData = $this->updateDailyBudgetFromIncome($user->id, $request->total, $totalKebutuhanBalance);
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

            // Update daily budget if allocation type is "Kebutuhan"
            $budgetData = null;
            if ($allocationType === 'Kebutuhan') {
                // Get total kebutuhan balance from all accounts after expense
                $totalKebutuhanBalance = $this->getTotalKebutuhanBalance($user->id);
                $budgetData = $this->updateDailyBudgetFromExpense($user->id, $request->total, $totalKebutuhanBalance);
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

    /**
     * Get total kebutuhan balance from all user accounts
     */
    private function getTotalKebutuhanBalance($userId)
    {
        return AccountAllocation::whereHas('account', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->where('type', 'Kebutuhan')
        ->sum('balance_per_type');
    }

    /**
     * Update daily budget after income added to kebutuhan
     */
    private function updateDailyBudgetFromIncome($userId, $incomeAmount, $totalKebutuhanBalance)
    {
        try {
            // Get current month details - total days in month
            $daysInMonth = $this->now()->daysInMonth;
            
            // Calculate new daily budget from total kebutuhan balance
            $newDailyBudget = $daysInMonth > 0 ? round($totalKebutuhanBalance / $daysInMonth, 0) : 0;

            // Get today's date
            $today = $this->now()->toDateString();
            
            // Update all today's budgets for all user accounts with kebutuhan
            $accountIds = Account::where('user_id', $userId)
                ->whereHas('allocations', function($query) {
                    $query->where('type', 'Kebutuhan');
                })
                ->pluck('id');

            $budgetsUpdated = 0;
            $totalOldDailyBudget = 0;

            foreach ($accountIds as $accountId) {
                $existingBudget = Budget::where('user_id', $userId)
                    ->where('account_id', $accountId)
                    ->whereDate('created_at', $today)
                    ->first();

                if ($existingBudget) {
                    $totalOldDailyBudget += $existingBudget->daily_budget;
                    $existingBudget->daily_budget += round($incomeAmount / $daysInMonth, 0);
                    $existingBudget->save();
                    $budgetsUpdated++;
                }
            }

            return [
                'action' => 'income_added_to_kebutuhan',
                'income_amount' => $incomeAmount,
                'total_kebutuhan_balance' => $totalKebutuhanBalance,
                'daily_budget_increase_per_account' => round($incomeAmount / $daysInMonth, 0),
                'accounts_updated' => $budgetsUpdated,
                'days_in_month' => $daysInMonth,
                'formatted' => [
                    'income_amount' => 'Rp ' . number_format($incomeAmount, 0, ',', '.'),
                    'total_kebutuhan_balance' => 'Rp ' . number_format($totalKebutuhanBalance, 0, ',', '.'),
                    'daily_budget_increase' => 'Rp ' . number_format(round($incomeAmount / $daysInMonth, 0), 0, ',', '.'),
                    'accounts_updated' => $budgetsUpdated . ' account(s)'
                ]
            ];

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to update daily budget from income: ' . $e->getMessage(),
                'action' => 'failed'
            ];
        }
    }

    /**
     * Update daily budget after expense from kebutuhan
     */
    private function updateDailyBudgetFromExpense($userId, $expenseAmount, $totalKebutuhanBalance)
    {
        try {
            // Get current month details - total days in month
            $daysInMonth = $this->now()->daysInMonth;

            // Get today's date
            $today = $this->now()->toDateString();
            
            // Find the account that had the expense (should have budget for today)
            $todayBudgets = Budget::where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->get();

            $budgetsUpdated = 0;
            $totalBudgetDecrease = 0;

            foreach ($todayBudgets as $budget) {
                if ($budget->daily_budget >= $expenseAmount) {
                    // Deduct from this budget
                    $budget->daily_budget -= $expenseAmount;
                    $budget->save();
                    $budgetsUpdated++;
                    $totalBudgetDecrease += $expenseAmount;
                    break; // Only deduct from one budget
                } else {
                    // Deduct what we can and continue to next budget
                    $deductAmount = $budget->daily_budget;
                    $budget->daily_budget = 0;
                    $budget->save();
                    $expenseAmount -= $deductAmount;
                    $totalBudgetDecrease += $deductAmount;
                    $budgetsUpdated++;
                    
                    if ($expenseAmount <= 0) break;
                }
            }

            return [
                'action' => 'expense_deducted_from_kebutuhan',
                'expense_amount' => $totalBudgetDecrease,
                'total_kebutuhan_balance' => $totalKebutuhanBalance,
                'daily_budget_decrease' => $totalBudgetDecrease,
                'accounts_updated' => $budgetsUpdated,
                'days_in_month' => $daysInMonth,
                'formatted' => [
                    'expense_amount' => 'Rp ' . number_format($totalBudgetDecrease, 0, ',', '.'),
                    'total_kebutuhan_balance' => 'Rp ' . number_format($totalKebutuhanBalance, 0, ',', '.'),
                    'daily_budget_decrease' => 'Rp ' . number_format($totalBudgetDecrease, 0, ',', '.'),
                    'accounts_updated' => $budgetsUpdated . ' account(s)'
                ]
            ];

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to update daily budget from expense: ' . $e->getMessage(),
                'action' => 'failed'
            ];
        }
    }

    private function updateBudgetFromIncome($userId, $accountId, $newKebutuhanBalance)
    {
        try {
            // Get current month details - total days in month (30 or 31)
            $daysInMonth = $this->now()->daysInMonth;
            
            // Calculate new daily budget from updated kebutuhan balance
            $newDailyBudget = $daysInMonth > 0 ? round($newKebutuhanBalance / $daysInMonth, 0) : 0;

            // Get today's date
            $today = $this->now()->toDateString();
            
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
                    'days_in_month' => $daysInMonth,
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
                    'days_in_month' => $daysInMonth,
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
            // Get current month details - total days in month (30 or 31)
            $daysInMonth = $this->now()->daysInMonth;

            // Get today's date
            $today = $this->now()->toDateString();
            
            // Check if budget exists for today
            $existingBudget = Budget::where('user_id', $userId)
                ->where('account_id', $accountId)
                ->whereDate('created_at', $today)
                ->first();

            if ($existingBudget) {
                // Directly subtract expense from daily_budget for today
                $oldDailyBudget = $existingBudget->daily_budget;
                $newDailyBudget = $oldDailyBudget - $expenseAmount;
                $existingBudget->daily_budget = $newDailyBudget;
                $existingBudget->save();
                
                return [
                    'budget_id' => $existingBudget->id,
                    'action' => 'updated_after_expense',
                    'old_daily_budget' => $oldDailyBudget,
                    'new_daily_budget' => $newDailyBudget,
                    'daily_budget_decrease' => $expenseAmount,
                    'expense_amount' => $expenseAmount,
                    'daily_saving' => $existingBudget->daily_saving,
                    'kebutuhan_balance' => $newKebutuhanBalance,
                    'days_in_month' => $daysInMonth,
                    'formatted' => [
                        'old_daily_budget' => 'Rp ' . number_format($oldDailyBudget, 0, ',', '.'),
                        'new_daily_budget' => 'Rp ' . number_format($newDailyBudget, 0, ',', '.'),
                        'daily_budget_decrease' => 'Rp ' . number_format($expenseAmount, 0, ',', '.'),
                        'expense_amount' => 'Rp ' . number_format($expenseAmount, 0, ',', '.'),
                        'daily_saving' => 'Rp ' . number_format($existingBudget->daily_saving, 0, ',', '.'),
                        'kebutuhan_balance' => 'Rp ' . number_format($newKebutuhanBalance, 0, ',', '.')
                    ]
                ];
            } else {
                // This shouldn't happen for expenses, but handle it just in case
                return [
                    'action' => 'no_budget_found',
                    'message' => 'No budget found for today. Please update account balance first.',
                    'new_daily_budget' => $newDailyBudget,
                    'kebutuhan_balance' => $newKebutuhanBalance
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

            // Get expenses for the target date with detailed information
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
                        'expense_datetime' => $expenseDate->format('d M Y') . ' ' . $createdAt->format('H:i')
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
