<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MonthlyExpense;
use App\Models\ExpenseCategories;
use App\Models\Budget;
use App\Models\AccountAllocation;
use App\Models\Account;
use App\Models\Expense;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MonthlyExpenseController extends Controller
{
    /**
     * Get current datetime with proper timezone
     */
    private function now()
    {
        return Carbon::now(config('app.timezone', 'Asia/Jakarta'));
    }

    /**
     * Round currency amounts to 2 decimal places for cleaner display
     */
    private function roundCurrency($amount)
    {
        return round((float)$amount, 2);
    }

    /**
     * Get all monthly expenses for current month
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $now = $this->now();

            $monthlyExpenses = MonthlyExpense::where('user_id', $user->id)
                ->currentMonth()
                ->active()
                ->with('category')
                ->orderBy('created_at', 'desc')
                ->get();

            // Format response
            $formattedExpenses = $monthlyExpenses->map(function ($expense) {
                return [
                    'id' => $expense->id,
                    'category' => [
                        'id' => $expense->category->id,
                        'name' => $expense->category->name,
                        'icon' => $expense->category->icon ?? null,
                    ],
                    'total_amount' => $expense->total_amount,
                    'current_amount' => $expense->current_amount,
                    'used_amount' => $expense->used_amount,
                    'usage_percentage' => $expense->usage_percentage,
                    'is_over_budget' => $expense->isOverBudget(),
                    'month' => $expense->month,
                    'year' => $expense->year,
                    'note' => $expense->note,
                    'formatted' => [
                        'total_amount' => 'Rp ' . number_format($expense->total_amount, 0, ',', '.'),
                        'current_amount' => 'Rp ' . number_format($expense->current_amount, 0, ',', '.'),
                        'used_amount' => 'Rp ' . number_format($expense->used_amount, 0, ',', '.'),
                        'usage_percentage' => number_format($expense->usage_percentage, 1) . '%',
                    ],
                    'created_at' => $expense->created_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'monthly_expenses' => $formattedExpenses,
                    'total_budget' => $monthlyExpenses->sum('total_amount'),
                    'total_used' => $monthlyExpenses->sum('used_amount'),
                    'total_remaining' => $monthlyExpenses->sum('current_amount'),
                    'month' => $now->month,
                    'year' => $now->year,
                    'month_name' => $now->format('F'),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving monthly expenses: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new monthly expense
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $now = $this->now();

            $validator = Validator::make($request->all(), [
                'expense_category_id' => 'required|exists:expense_categories,id',
                'total_amount' => 'required|numeric|min:0',
                'note' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if monthly expense already exists for this category in current month
            $existingExpense = MonthlyExpense::where('user_id', $user->id)
                ->where('expense_category_id', $request->expense_category_id)
                ->currentMonth()
                ->first();

            if ($existingExpense) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Monthly expense for this category already exists for current month'
                ], 409);
            }

            DB::beginTransaction();

            // Create monthly expense
            $monthlyExpense = MonthlyExpense::create([
                'user_id' => $user->id,
                'expense_category_id' => $request->expense_category_id,
                'total_amount' => $request->total_amount,
                'current_amount' => $request->total_amount,
                'used_amount' => 0,
                'month' => $now->month,
                'year' => $now->year,
                'note' => $request->note,
            ]);

            // Recalculate daily budget
            $this->recalculateDailyBudget($user->id);

            DB::commit();

            // Load category relationship
            $monthlyExpense->load('category');

            return response()->json([
                'status' => 'success',
                'message' => 'Monthly expense created successfully',
                'data' => [
                    'monthly_expense' => [
                        'id' => $monthlyExpense->id,
                        'category' => [
                            'id' => $monthlyExpense->category->id,
                            'name' => $monthlyExpense->category->name,
                        ],
                        'total_amount' => $monthlyExpense->total_amount,
                        'current_amount' => $monthlyExpense->current_amount,
                        'used_amount' => $monthlyExpense->used_amount,
                        'formatted' => [
                            'total_amount' => 'Rp ' . number_format($monthlyExpense->total_amount, 0, ',', '.'),
                        ],
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error creating monthly expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update monthly expense
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();

            $monthlyExpense = MonthlyExpense::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$monthlyExpense) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Monthly expense not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'expense_category_id' => 'sometimes|exists:expense_categories,id',
                'total_amount' => 'sometimes|numeric|min:0',
                'note' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $oldTotalAmount = $monthlyExpense->total_amount;

            // Update fields
            if ($request->has('total_amount')) {
                $newTotalAmount = $request->total_amount;
                $difference = $newTotalAmount - $oldTotalAmount;
                
                $monthlyExpense->total_amount = $newTotalAmount;
                $monthlyExpense->current_amount += $difference; // Adjust current amount
            }

            if ($request->has('expense_category_id')) {
                // Check if category is not already used in current month
                $existingExpense = MonthlyExpense::where('user_id', $user->id)
                    ->where('expense_category_id', $request->expense_category_id)
                    ->currentMonth()
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingExpense) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Category already used for another monthly expense'
                    ], 409);
                }

                $monthlyExpense->expense_category_id = $request->expense_category_id;
            }

            if ($request->has('note')) {
                $monthlyExpense->note = $request->note;
            }

            $monthlyExpense->save();

            // Recalculate daily budget if total amount changed
            if ($request->has('total_amount')) {
                $this->recalculateDailyBudget($user->id);
            }

            DB::commit();

            $monthlyExpense->load('category');

            return response()->json([
                'status' => 'success',
                'message' => 'Monthly expense updated successfully',
                'data' => [
                    'monthly_expense' => [
                        'id' => $monthlyExpense->id,
                        'category' => [
                            'id' => $monthlyExpense->category->id,
                            'name' => $monthlyExpense->category->name,
                        ],
                        'total_amount' => $monthlyExpense->total_amount,
                        'current_amount' => $monthlyExpense->current_amount,
                        'used_amount' => $monthlyExpense->used_amount,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating monthly expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete monthly expense
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();

            $monthlyExpense = MonthlyExpense::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$monthlyExpense) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Monthly expense not found'
                ], 404);
            }

            DB::beginTransaction();

            $monthlyExpense->delete();

            // Recalculate daily budget
            $this->recalculateDailyBudget($user->id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Monthly expense deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error deleting monthly expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add expense amount to monthly expense (when user uses the budget)
     */
    public function addExpenseAmount(Request $request, $id)
    {
        try {
            $user = $request->user();

            $monthlyExpense = MonthlyExpense::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$monthlyExpense) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Monthly expense not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'expense_amount' => 'required|numeric|min:0.01',
                'note' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $expenseAmount = $request->expense_amount;

            DB::beginTransaction();

            // 1. Update monthly expense amounts
            $monthlyExpense->addExpense($expenseAmount);

            // 2. Get account_id dari kebutuhan allocation untuk expense record
            $kebutuhanAllocations = AccountAllocation::whereHas('account', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('type', 'Kebutuhan')
            ->whereDate('allocation_date', $this->now()->toDateString())
            ->get();

            if ($kebutuhanAllocations->isEmpty()) {
                throw new \Exception('No Kebutuhan allocation found for today. Please setup your accounts first.');
            }

            $firstKebutuhanAllocation = $kebutuhanAllocations->first();

            // 3. Create expense record untuk tracking (masuk ke TodayReceipt & DetailReceiptExpense)
            $expenseRecord = Expense::create([
                'user_id' => $user->id,
                'account_id' => $firstKebutuhanAllocation->account_id,
                'expense_category_id' => $monthlyExpense->expense_category_id,
                'amount' => $expenseAmount,
                'note' => $request->note ?: 'Monthly budget usage: ' . $monthlyExpense->category->name,
                'expense_date' => $this->now()->toDateString(),
                'frequency' => 'Bulanan',
                'is_manual' => true
            ]);

            // 4. Update balance_per_type untuk tipe Kebutuhan
            $firstKebutuhanAllocation->balance_per_type -= $expenseAmount;
            $firstKebutuhanAllocation->save();

            // 5. Update current_balance di account yang bersangkutan
            $account = $firstKebutuhanAllocation->account;
            $account->current_balance -= $expenseAmount;
            $account->save();

            DB::commit();

            $monthlyExpense->load('category');

            return response()->json([
                'status' => 'success',
                'message' => 'Expense added to monthly budget successfully',
                'data' => [
                    'monthly_expense' => [
                        'id' => $monthlyExpense->id,
                        'category' => [
                            'id' => $monthlyExpense->category->id,
                            'name' => $monthlyExpense->category->name,
                        ],
                        'total_amount' => $monthlyExpense->total_amount,
                        'current_amount' => $monthlyExpense->current_amount,
                        'used_amount' => $monthlyExpense->used_amount,
                        'usage_percentage' => $monthlyExpense->usage_percentage,
                        'is_over_budget' => $monthlyExpense->isOverBudget(),
                        'formatted' => [
                            'current_amount' => 'Rp ' . number_format($monthlyExpense->current_amount, 0, ',', '.'),
                            'used_amount' => 'Rp ' . number_format($monthlyExpense->used_amount, 0, ',', '.'),
                        ],
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error adding expense amount: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate daily budget based on monthly expenses
     */
    private function recalculateDailyBudget($userId)
    {
        $now = $this->now();
        $daysInMonth = $now->daysInMonth;

        // Get all active monthly expenses for current month
        $totalMonthlyExpenses = MonthlyExpense::where('user_id', $userId)
            ->currentMonth()
            ->active()
            ->sum('total_amount');

        // Get user's account allocations for "Kebutuhan" (daily budget allocation)
        $kebutuhanAllocations = AccountAllocation::whereHas('account', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->where('type', 'Kebutuhan')
        ->whereDate('allocation_date', $now->toDateString())
        ->get();

        // Calculate total available balance for daily budget
        $totalKebutuhanBalance = $kebutuhanAllocations->sum('balance_per_type');

        // Subtract monthly expenses from total balance, then divide by days in month (dengan pembulatan)
        $availableForDaily = $totalKebutuhanBalance - $totalMonthlyExpenses;
        $newDailyBudget = $availableForDaily > 0 ? $this->roundCurrency($availableForDaily / $daysInMonth) : 0;

        // Update budget table - both daily_budget dan initial_daily_budget
        $budget = Budget::where('user_id', $userId)
            ->whereDate('updated_at', $now->toDateString())
            ->first();

        if ($budget) {
            $budget->daily_budget = $newDailyBudget;
            $budget->initial_daily_budget = $newDailyBudget; // Update initial juga
            $budget->save();
        } else {
            // Create budget record if not exists
            Budget::create([
                'user_id' => $userId,
                'daily_budget' => $newDailyBudget,
                'initial_daily_budget' => $newDailyBudget,
                'daily_saving' => 0,
                'date' => $now->toDateString()
            ]);
        }

        return $newDailyBudget;
    }

    /**
     * Get available expense categories
     */
    public function getCategories(Request $request)
    {
        try {
            $categories = ExpenseCategories::all();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'categories' => $categories
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving categories: ' . $e->getMessage()
            ], 500);
        }
    }
}