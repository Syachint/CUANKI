<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\BankData;
use App\Models\Account;
use App\Models\AccountAllocation;
use Illuminate\Support\Facades\DB;

class AssetController extends Controller
{
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
                    
                    // Update Bank A current_balance (sum of all BTP = only Kebutuhan now)
                    $existingAccount->refresh();
                    $bankACurrentBalance = $existingAccount->allocations()->sum('balance_per_type');
                    $existingAccount->current_balance = $bankACurrentBalance;
                    $existingAccount->initial_balance = max($existingAccount->initial_balance, $bankACurrentBalance);
                    $existingAccount->save();
                    
                    // Update Bank B current_balance (sum of all BTP = only requested type has balance)
                    $newAccount->refresh();
                    $bankBCurrentBalance = $newAccount->allocations()->sum('balance_per_type');
                    $newAccount->current_balance = $bankBCurrentBalance;
                    $newAccount->initial_balance = max($newAccount->initial_balance, $bankBCurrentBalance);
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
                    
                    // Update Bank B current_balance (sum of all BTP = only Tabungan now)
                    $secondAccount->refresh();
                    $bankBCurrentBalance = $secondAccount->allocations()->sum('balance_per_type');
                    $secondAccount->current_balance = $bankBCurrentBalance;
                    $secondAccount->initial_balance = max($secondAccount->initial_balance, $bankBCurrentBalance);
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
                    'current_balance' => 0, // Will be calculated after allocation created
                    'initial_balance' => 0
                ]);
                
                // Add allocation for the requested type
                AccountAllocation::create([
                    'account_id' => $newAccount->id,
                    'type' => $type,
                    'balance_per_type' => $balancePerType
                ]);
                
                // Update account balance after allocation created
                $newAccount->refresh();
                $calculatedBalance = $newAccount->allocations()->sum('balance_per_type');
                $newAccount->current_balance = $calculatedBalance;
                $newAccount->initial_balance = max($newAccount->initial_balance, $calculatedBalance);
                $newAccount->save();
                
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

    /**
     * Update account allocation (type and/or balance) with intelligent swapping
     * Can update type only, balance only, or both type and balance
     */
    public function updateAccountAllocation(Request $request)
    {
        try {
            $request->validate([
                'account_allocation_id' => 'required|exists:accounts_allocation,id',
                'new_type' => 'sometimes|string|in:Kebutuhan,Tabungan,Darurat',
                'new_balance' => 'sometimes|numeric|min:0'
            ]);

            $user = $request->user();
            $allocationId = $request->account_allocation_id;
            $newType = $request->new_type ?? null;
            $newBalance = $request->new_balance ?? null;

            // At least one parameter must be provided
            if ($newType === null && $newBalance === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Either new_type or new_balance must be provided'
                ], 422);
            }

            // Get the allocation to be updated
            $targetAllocation = AccountAllocation::with(['account'])
                ->where('id', $allocationId)
                ->first();

            if (!$targetAllocation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Allocation not found'
                ], 404);
            }

            // Verify user owns the account
            if ($targetAllocation->account->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Access denied'
                ], 403);
            }

            $currentType = $targetAllocation->type;
            $currentBalance = $targetAllocation->balance_per_type;
            
            // Determine what needs to be updated
            $updateType = $newType !== null && $currentType !== $newType;
            $updateBalance = $newBalance !== null && $currentBalance != $newBalance;

            if (!$updateType && !$updateBalance) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No changes needed',
                    'data' => [
                        'no_change' => true,
                        'current_type' => $currentType,
                        'current_balance' => $currentBalance
                    ]
                ], 200);
            }

            // Get all user accounts with allocations
            $userAccounts = Account::where('user_id', $user->id)
                ->with(['allocations'])
                ->orderBy('id')
                ->get();

            $totalBanks = $userAccounts->count();
            $changeLog = [];

            DB::beginTransaction();

            try {
                // Handle type change with intelligent swapping
                if ($updateType) {
                    if ($totalBanks == 1) {
                        // 1 Bank: Simple swap between types within same account
                        $account = $userAccounts->first();
                        
                        // Find allocation with the new type (to swap)
                        $swapAllocation = $account->allocations()
                            ->where('type', $newType)
                            ->first();

                        if ($swapAllocation) {
                            $swapBalance = $swapAllocation->balance_per_type;
                            
                            // Swap the balances
                            $targetAllocation->update([
                                'type' => $newType,
                                'balance_per_type' => $swapBalance
                            ]);

                            $swapAllocation->update([
                                'type' => $currentType,
                                'balance_per_type' => $currentBalance
                            ]);

                            $changeLog['type_change'] = [
                                'scenario' => '1_bank_swap',
                                'swapped_with' => [
                                    'old_type' => $newType,
                                    'old_balance' => $swapBalance,
                                    'new_type' => $currentType,
                                    'new_balance' => $currentBalance
                                ]
                            ];
                        } else {
                            // Just change type if no existing allocation
                            $targetAllocation->update(['type' => $newType]);
                            $changeLog['type_change'] = [
                                'scenario' => '1_bank_simple_change',
                                'no_swap_needed' => true
                            ];
                        }

                    } elseif ($totalBanks == 2) {
                        // 2 Banks: Bank A (Kebutuhan) + Bank B (Tabungan, Darurat)
                        $bankA = $userAccounts->first();
                        $bankB = $userAccounts->last();

                        // Find allocation with the new type to swap
                        $swapAllocation = AccountAllocation::whereHas('account', function($query) use ($user) {
                                $query->where('user_id', $user->id);
                            })
                            ->where('type', $newType)
                            ->first();

                        if ($swapAllocation) {
                            $swapBalance = $swapAllocation->balance_per_type;
                            $swapAccountId = $swapAllocation->account_id;
                            
                            // Swap types and balances, accounting for cross-bank movement
                            $targetAllocation->update([
                                'account_id' => $swapAccountId,
                                'type' => $newType,
                                'balance_per_type' => $swapBalance
                            ]);

                            $swapAllocation->update([
                                'account_id' => $targetAllocation->account_id,
                                'type' => $currentType,
                                'balance_per_type' => $currentBalance
                            ]);

                            $changeLog['type_change'] = [
                                'scenario' => '2_banks_cross_swap',
                                'swapped_accounts' => [$targetAllocation->account_id, $swapAccountId]
                            ];
                        }

                    } else {
                        // 3+ Banks: Simple swap
                        $swapAllocation = AccountAllocation::whereHas('account', function($query) use ($user) {
                                $query->where('user_id', $user->id);
                            })
                            ->where('type', $newType)
                            ->first();

                        if ($swapAllocation) {
                            $swapBalance = $swapAllocation->balance_per_type;
                            
                            // Swap types and balances
                            $targetAllocation->update([
                                'type' => $newType,
                                'balance_per_type' => $swapBalance
                            ]);

                            $swapAllocation->update([
                                'type' => $currentType,
                                'balance_per_type' => $currentBalance
                            ]);

                            $changeLog['type_change'] = [
                                'scenario' => '3plus_banks_swap',
                                'swapped_accounts' => [$targetAllocation->account_id, $swapAllocation->account_id]
                            ];
                        } else {
                            // Just change type if no existing allocation
                            $targetAllocation->update(['type' => $newType]);
                            $changeLog['type_change'] = [
                                'scenario' => '3plus_banks_simple_change',
                                'no_swap_needed' => true
                            ];
                        }
                    }
                }

                // Handle balance change (independent of type change)
                if ($updateBalance) {
                    // Refresh allocation in case type was changed
                    $targetAllocation->refresh();
                    
                    $oldBalance = $targetAllocation->balance_per_type;
                    $targetAllocation->update(['balance_per_type' => $newBalance]);

                    $changeLog['balance_change'] = [
                        'old_balance' => $oldBalance,
                        'new_balance' => $newBalance,
                        'balance_change' => $newBalance - $oldBalance
                    ];
                }

                // Recalculate all account balances with smart initial_balance logic
                foreach ($userAccounts as $account) {
                    $account->refresh();
                    $totalBalance = $account->allocations()->sum('balance_per_type');
                    
                    // Update current_balance (always = sum of all BTP)
                    $newCurrentBalance = $totalBalance;
                    
                    // Update initial_balance only if current_balance > initial_balance
                    // initial_balance = highest ever current_balance (peak balance)
                    $newInitialBalance = max($account->initial_balance, $newCurrentBalance);
                    
                    $account->update([
                        'current_balance' => $newCurrentBalance,
                        'initial_balance' => $newInitialBalance
                    ]);
                }

                // Handle budget tracking for "Kebutuhan" type
                $budgetData = null;
                $finalAllocation = $targetAllocation->fresh();
                if ($finalAllocation->type === 'Kebutuhan') {
                    $this->resetMonthlyInitialBudget($user->id);
                    $this->updateMonthlyDailyBudget($user->id);
                    $budgetData = $this->handleBudgetTracking($user->id, $finalAllocation->account_id, $finalAllocation->balance_per_type);
                }

                DB::commit();

                // Get updated data
                $updatedAccounts = Account::where('user_id', $user->id)
                    ->with(['allocations', 'bank'])
                    ->orderBy('id')
                    ->get();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Account allocation updated successfully',
                    'data' => [
                        'change_summary' => [
                            'allocation_id' => $allocationId,
                            'changes_made' => [
                                'type_changed' => $updateType,
                                'balance_changed' => $updateBalance
                            ],
                            'original_values' => [
                                'type' => $currentType,
                                'balance' => $currentBalance
                            ],
                            'new_values' => [
                                'type' => $finalAllocation->type,
                                'balance' => $finalAllocation->balance_per_type
                            ],
                            'total_banks' => $totalBanks,
                            'change_log' => $changeLog
                        ],
                        'updated_accounts' => $updatedAccounts->map(function($account) {
                            return [
                                'account_id' => $account->id,
                                'bank_name' => $account->bank->code_name ?? 'Unknown Bank',
                                'current_balance' => $account->current_balance,
                                'allocations' => $account->allocations->map(function($allocation) {
                                    return [
                                        'allocation_id' => $allocation->id,
                                        'type' => $allocation->type,
                                        'balance_per_type' => $allocation->balance_per_type,
                                        'formatted_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.')
                                    ];
                                }),
                                'formatted_balance' => 'Rp ' . number_format($account->current_balance, 0, ',', '.')
                            ];
                        }),
                        'budget_tracking' => $budgetData
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating account allocation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get usage bar for all allocations showing current vs initial amounts
     * Tabungan: Always full (savings only grow)
     * Kebutuhan: Shows usage based on expenses 
     * Darurat: Always full (emergency funds not used)
     */
    public function getUsageBarAllocation(Request $request)
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

            // Get user accounts with allocations and bank info
            $accounts = Account::where('user_id', $userId)
                ->with(['allocations', 'bank'])
                ->orderBy('id')
                ->get();

            if ($accounts->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No accounts found',
                    'data' => [
                        'allocations' => [],
                        'total_accounts' => 0
                    ]
                ], 200);
            }

            // Get all allocations with usage calculations
            $allocationBars = collect();

            foreach ($accounts as $account) {
                foreach ($account->allocations as $allocation) {
                    $currentBalance = (float) $allocation->balance_per_type;
                    $bankName = $account->bank->code_name ?? 'Unknown Bank';
                    
                    // Calculate usage percentage based on type
                    if ($allocation->type === 'Tabungan') {
                        // Tabungan: Calculate based on current vs previous balance
                        // Get the previous balance_per_type (last record before today)
                        $today = now()->toDateString();
                        
                        $previousAllocation = AccountAllocation::where('account_id', $account->id)
                            ->where('type', 'Tabungan')
                            ->where('allocation_date', '<', $today)
                            ->orderBy('allocation_date', 'desc')
                            ->orderBy('updated_at', 'desc')
                            ->first();
                        
                        $maxBalance = $previousAllocation ? $previousAllocation->balance_per_type : $currentBalance;
                        
                        // If current balance is higher than previous, use current as max (savings increased)
                        if ($currentBalance > $maxBalance) {
                            $maxBalance = $currentBalance;
                        }
                        
                        // Calculate usage percentage: current/max * 100
                        if ($maxBalance > 0) {
                            $usagePercentage = ($currentBalance / $maxBalance) * 100;
                            $usagePercentage = max(0, min(100, $usagePercentage));
                            
                            if ($usagePercentage >= 90) {
                                $status = 'full';
                                $statusText = 'Tabungan Optimal';
                                $description = $currentBalance >= $maxBalance ? 
                                    'Dana tabungan bertambah' : 
                                    'Tabungan masih sangat aman';
                            } elseif ($usagePercentage >= 70) {
                                $status = 'high';
                                $statusText = 'Tabungan Baik';
                                $usedAmount = $maxBalance - $currentBalance;
                                $description = $usedAmount > 0 ? 
                                    "Tabungan berkurang Rp" . number_format($usedAmount, 0, ',', '.') :
                                    'Tabungan stabil';
                            } elseif ($usagePercentage >= 40) {
                                $status = 'medium';
                                $statusText = 'Tabungan Sedang';
                                $usedAmount = $maxBalance - $currentBalance;
                                $description = "Tabungan berkurang Rp" . number_format($usedAmount, 0, ',', '.');
                            } elseif ($usagePercentage >= 20) {
                                $status = 'low';
                                $statusText = 'Tabungan Sedikit';
                                $usedAmount = $maxBalance - $currentBalance;
                                $description = "Tabungan berkurang Rp" . number_format($usedAmount, 0, ',', '.');
                            } else {
                                $status = 'critical';
                                $statusText = 'Tabungan Hampir Habis';
                                $usedAmount = $maxBalance - $currentBalance;
                                $description = "Tabungan berkurang Rp" . number_format($usedAmount, 0, ',', '.');
                            }
                        } else {
                            $usagePercentage = 0;
                            $status = 'empty';
                            $statusText = 'Tidak Ada Tabungan';
                            $description = 'Tabungan belum ada';
                        }
                        
                    } elseif ($allocation->type === 'Kebutuhan') {
                        // Kebutuhan: Calculate usage based on expenses
                        // Get initial budget vs current balance
                        $today = now()->toDateString();
                        
                        // Get today's budget for this account
                        $todayBudget = \App\Models\Budget::where('user_id', $userId)
                            ->where('account_id', $account->id)
                            ->whereDate('created_at', $today)
                            ->first();
                        
                        $initialBudget = $todayBudget ? $todayBudget->initial_daily_budget : $currentBalance;
                        
                        if ($initialBudget > 0) {
                            $usagePercentage = ($currentBalance / $initialBudget) * 100;
                            $usagePercentage = max(0, min(100, $usagePercentage)); // Clamp between 0-100
                            
                            if ($usagePercentage >= 80) {
                                $status = 'high';
                                $statusText = 'Sisa Banyak';
                            } elseif ($usagePercentage >= 50) {
                                $status = 'medium';
                                $statusText = 'Sisa Sedang';
                            } elseif ($usagePercentage >= 20) {
                                $status = 'low';
                                $statusText = 'Sisa Sedikit';
                            } else {
                                $status = 'critical';
                                $statusText = 'Hampir Habis';
                            }
                            
                            $usedAmount = $initialBudget - $currentBalance;
                            $description = $usedAmount > 0 ? 
                                "Telah digunakan Rp" . number_format($usedAmount, 0, ',', '.') :
                                "Belum ada pengeluaran hari ini";
                        } else {
                            $usagePercentage = 0;
                            $status = 'empty';
                            $statusText = 'Tidak Ada Dana';
                            $description = 'Budget belum diatur';
                        }
                        
                    } elseif ($allocation->type === 'Darurat') {
                        // Darurat: Always 100% (emergency funds preserved)
                        $usagePercentage = 100;
                        $status = 'full';
                        $statusText = 'Dana Darurat Aman';
                        $description = 'Dana darurat terjaga dengan baik';
                        
                    } else {
                        // Default case
                        $usagePercentage = 100;
                        $status = 'full';
                        $statusText = 'Normal';
                        $description = 'Status normal';
                    }

                    $allocationBars->push([
                        'allocation_id' => $allocation->id,
                        'account_id' => $account->id,
                        'bank_name' => $bankName,
                        'bank_code' => $account->bank->code_name ?? 'UNK',
                        'type' => $allocation->type,
                        'current_balance' => $currentBalance,
                        'max_balance' => $allocation->type === 'Tabungan' && isset($maxBalance) ? $maxBalance : 
                                        ($allocation->type === 'Kebutuhan' && isset($initialBudget) ? $initialBudget : $currentBalance),
                        'usage_percentage' => round($usagePercentage, 1),
                        'status' => $status,
                        'status_text' => $statusText,
                        'description' => $description,
                        'color_scheme' => $this->getColorScheme($allocation->type, $status),
                        'formatted' => [
                            'current_balance' => 'Rp' . number_format($currentBalance, 0, ',', '.'),
                            'max_balance' => 'Rp' . number_format(
                                $allocation->type === 'Tabungan' && isset($maxBalance) ? $maxBalance : 
                                ($allocation->type === 'Kebutuhan' && isset($initialBudget) ? $initialBudget : $currentBalance), 
                                0, ',', '.'
                            ),
                            'usage_text' => round($usagePercentage, 0) . '%',
                            'display_text' => $bankName . ' - ' . $allocation->type,
                            'balance_display' => 'Rp' . number_format($currentBalance, 0, ',', '.') . '/Rp' . 
                                number_format(
                                    $allocation->type === 'Tabungan' && isset($maxBalance) ? $maxBalance : 
                                    ($allocation->type === 'Kebutuhan' && isset($initialBudget) ? $initialBudget : $currentBalance), 
                                    0, ',', '.'
                                )
                        ],
                        'last_updated' => $allocation->updated_at->format('Y-m-d H:i:s')
                    ]);
                }
            }

            // Sort by type priority: Kebutuhan, Tabungan, Darurat
            $sortedAllocations = $allocationBars->sortBy(function($item) {
                $typePriority = [
                    'Kebutuhan' => 1,
                    'Tabungan' => 2, 
                    'Darurat' => 3
                ];
                return $typePriority[$item['type']] ?? 4;
            })->values();

            // Calculate summary statistics
            $totalBalance = $allocationBars->sum('current_balance');
            $averageUsage = $allocationBars->avg('usage_percentage');
            
            $statusCounts = $allocationBars->groupBy('status')->map->count();

            return response()->json([
                'status' => 'success',
                'message' => 'Usage bar allocations retrieved successfully',
                'data' => [
                    'allocations' => $sortedAllocations,
                    'summary' => [
                        'total_allocations' => $allocationBars->count(),
                        'total_balance' => $totalBalance,
                        'average_usage_percentage' => round($averageUsage, 1),
                        'status_distribution' => [
                            'full' => $statusCounts['full'] ?? 0,
                            'high' => $statusCounts['high'] ?? 0,
                            'medium' => $statusCounts['medium'] ?? 0,
                            'low' => $statusCounts['low'] ?? 0,
                            'critical' => $statusCounts['critical'] ?? 0,
                            'empty' => $statusCounts['empty'] ?? 0
                        ],
                        'formatted' => [
                            'total_balance' => 'Rp ' . number_format($totalBalance, 0, ',', '.'),
                            'average_usage' => round($averageUsage, 1) . '%'
                        ]
                    ],
                    'metadata' => [
                        'generated_at' => now()->format('Y-m-d H:i:s'),
                        'timezone' => 'Asia/Jakarta',
                        'total_accounts' => $accounts->count()
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving usage bar allocations: ' . $e->getMessage(),
                'debug' => [
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Get color scheme for allocation based on type and status
     */
    private function getColorScheme($type, $status)
    {
        $colorSchemes = [
            'Tabungan' => [
                'full' => ['primary' => '#10B981', 'secondary' => '#D1FAE5', 'text' => '#047857'],
                'high' => ['primary' => '#059669', 'secondary' => '#CCFBF1', 'text' => '#065F46'],
                'medium' => ['primary' => '#F59E0B', 'secondary' => '#FEF3C7', 'text' => '#92400E'],
                'low' => ['primary' => '#EF4444', 'secondary' => '#FEE2E2', 'text' => '#DC2626'],
                'critical' => ['primary' => '#DC2626', 'secondary' => '#FEE2E2', 'text' => '#991B1B'],
                'empty' => ['primary' => '#6B7280', 'secondary' => '#F3F4F6', 'text' => '#374151'],
                'default' => ['primary' => '#10B981', 'secondary' => '#D1FAE5', 'text' => '#047857']
            ],
            'Kebutuhan' => [
                'high' => ['primary' => '#10B981', 'secondary' => '#D1FAE5', 'text' => '#047857'],
                'medium' => ['primary' => '#F59E0B', 'secondary' => '#FEF3C7', 'text' => '#92400E'],
                'low' => ['primary' => '#EF4444', 'secondary' => '#FEE2E2', 'text' => '#DC2626'],
                'critical' => ['primary' => '#DC2626', 'secondary' => '#FEE2E2', 'text' => '#991B1B'],
                'empty' => ['primary' => '#6B7280', 'secondary' => '#F3F4F6', 'text' => '#374151'],
                'default' => ['primary' => '#6366F1', 'secondary' => '#E0E7FF', 'text' => '#4338CA']
            ],
            'Darurat' => [
                'full' => ['primary' => '#8B5CF6', 'secondary' => '#EDE9FE', 'text' => '#6D28D9'],
                'default' => ['primary' => '#8B5CF6', 'secondary' => '#EDE9FE', 'text' => '#6D28D9']
            ]
        ];

        return $colorSchemes[$type][$status] ?? $colorSchemes[$type]['default'] ?? 
               ['primary' => '#6B7280', 'secondary' => '#F3F4F6', 'text' => '#374151'];
    }

    // Helper methods (add these if not exists)
    private function getCalculationMethod($totalBanks, $currentAccountId, $allAccounts)
    {
        if ($totalBanks == 1) {
            return "1 Bank: current_balance = kebutuhan + tabungan";
        } elseif ($totalBanks == 2) {
            $firstAccountId = $allAccounts->first()->id;
            if ($currentAccountId == $firstAccountId) {
                return "2 Banks - Bank A: current_balance = kebutuhan only";
            } else {
                return "2 Banks - Bank B: current_balance = tabungan + darurat";
            }
        } else {
            return "3+ Banks: current_balance = sum of all allocations";
        }
    }

    private function resetMonthlyInitialBudget($userId)
    {
        // Implementation for resetting monthly initial budget
        // This should be implemented based on your budget logic
    }

    private function updateMonthlyDailyBudget($userId)
    {
        // Implementation for updating monthly daily budget
        // This should be implemented based on your budget logic
    }

    private function handleBudgetTracking($userId, $accountId, $amount)
    {
        // Implementation for handling budget tracking
        // This should return budget tracking data
        return [
            'budget_updated' => true,
            'amount' => $amount
        ];
    }
}
