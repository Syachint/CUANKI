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

    public function getUserAccounts(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Get user accounts with their allocations and bank data
            $accounts = Account::where('user_id', $user->id)
                ->with(['allocations', 'bank'])
                ->get();

            $accountOptions = [];
            
            foreach ($accounts as $account) {
                foreach ($account->allocations as $allocation) {
                    $bankName = $account->bank ? $account->bank->code_name : 'Unknown Bank';
                    $accountOptions[] = [
                        'value' => $bankName . ' - ' . $allocation->type,
                        'label' => $bankName . ' - ' . $allocation->type,
                        'account_id' => $account->id,
                        'account_name' => $bankName,
                        'type' => $allocation->type,
                        'balance' => $allocation->balance_per_type,
                        'formatted_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.')
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'accounts' => $accountOptions,
                    'total_options' => count($accountOptions)
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving user accounts: ' . $e->getMessage()
            ], 500);
        }
    }

    public function addIncome(Request $request)
    {
        try {
            $request->validate([
                'tanggal' => 'required|date',
                'total' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:255',
                'aset' => 'required|string|max:255', // Account format: "bank_id - type"
            ]);

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Parse account name to find the account
            // Format expected: "bank_id - type" (e.g., "1 - Kebutuhan")
            $asetParts = explode(' - ', $request->aset);
            if (count($asetParts) !== 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid account format. Expected format: "bank_id - type"'
                ], 400);
            }

            $bankId = trim($asetParts[0]);
            $allocationType = trim($asetParts[1]);

            // Find the account based on bank_id and verify allocation type exists
            $account = Account::where('user_id', $user->id)
                ->where('bank_id', $bankId)
                ->whereHas('allocations', function($query) use ($allocationType) {
                    $query->where('type', $allocationType);
                })
                ->with(['bank', 'allocations'])
                ->first();

            if (!$account) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account not found with bank_id: ' . $bankId
                ], 404);
            }

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

            // Update account allocation balance (add income to the specific allocation type)
            $allocation = $account->allocations->where('type', $allocationType)->first();
            if ($allocation) {
                $oldAllocationBalance = $allocation->balance_per_type;
                $allocation->balance_per_type += $request->total;
                $allocation->save();
            }

            // Update account current_balance
            $oldCurrentBalance = $account->current_balance;
            $account->current_balance += $request->total;
            $account->save();

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
                        'allocation_type' => $allocationType,
                        'old_balance' => $oldAllocationBalance ?? 0,
                        'new_balance' => $allocation ? $allocation->balance_per_type : 0,
                        'balance_increase' => $request->total
                    ],
                    'account_update' => [
                        'old_current_balance' => $oldCurrentBalance,
                        'new_current_balance' => $account->current_balance,
                        'balance_increase' => $request->total
                    ],
                    'budget_update' => $budgetData,
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($income->amount, 0, ',', '.'),
                        'received_date' => Carbon::parse($income->received_date)->format('d M Y'),
                        'aset' => $request->aset,
                        'old_allocation_balance' => 'Rp ' . number_format($oldAllocationBalance ?? 0, 0, ',', '.'),
                        'new_allocation_balance' => 'Rp ' . number_format($allocation ? $allocation->balance_per_type : 0, 0, ',', '.'),
                        'old_current_balance' => 'Rp ' . number_format($oldCurrentBalance, 0, ',', '.'),
                        'new_current_balance' => 'Rp ' . number_format($account->current_balance, 0, ',', '.')
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
                'aset' => 'required|string|max:255', // Account format: "bank_id - type"
            ]);

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Parse account name to find the account
            // Format expected: "bank_id - type" (e.g., "1 - Kebutuhan")
            $asetParts = explode(' - ', $request->aset);
            if (count($asetParts) !== 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid account format. Expected format: "bank_id - type"'
                ], 400);
            }

            $bankId = trim($asetParts[0]);
            $allocationType = trim($asetParts[1]);

            // Find the account based on bank_id and verify allocation type exists
            $account = Account::where('user_id', $user->id)
                ->where('bank_id', $bankId)
                ->whereHas('allocations', function($query) use ($allocationType) {
                    $query->where('type', $allocationType);
                })
                ->with(['bank', 'allocations'])
                ->first();

            if (!$account) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account not found with bank_id: ' . $bankId
                ], 404);
            }

            // Verify the category belongs to the user
            $category = ExpenseCategories::where('id', $request->kategori)->first();

            if (!$category) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Category not found or not accessible'
                ], 404);
            }

            // Check if user has enough balance in the allocation
            $allocation = $account->allocations->where('type', $allocationType)->first();
            if (!$allocation || $allocation->balance_per_type < $request->total) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient balance in ' . $allocationType . ' allocation'
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

            // Update account allocation balance (subtract expense from the specific allocation type)
            $oldAllocationBalance = $allocation->balance_per_type;
            $allocation->balance_per_type -= $request->total;
            $allocation->save();

            // Update account current_balance
            $oldCurrentBalance = $account->current_balance;
            $account->current_balance -= $request->total;
            $account->save();

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
                        'allocation_type' => $allocationType,
                        'old_balance' => $oldAllocationBalance,
                        'new_balance' => $allocation->balance_per_type,
                        'balance_decrease' => $request->total
                    ],
                    'account_update' => [
                        'old_current_balance' => $oldCurrentBalance,
                        'new_current_balance' => $account->current_balance,
                        'balance_decrease' => $request->total
                    ],
                    'budget_update' => $budgetData,
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($expense->amount, 0, ',', '.'),
                        'expense_date' => Carbon::parse($expense->expense_date)->format('d M Y'),
                        'aset' => $request->aset,
                        'kategori' => $category->name,
                        'old_allocation_balance' => 'Rp ' . number_format($oldAllocationBalance, 0, ',', '.'),
                        'new_allocation_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.'),
                        'old_current_balance' => 'Rp ' . number_format($oldCurrentBalance, 0, ',', '.'),
                        'new_current_balance' => 'Rp ' . number_format($account->current_balance, 0, ',', '.')
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
}
