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

                // Recalculate all account balances
                foreach ($userAccounts as $account) {
                    $account->refresh();
                    $totalBalance = $account->allocations()->sum('balance_per_type');
                    $account->update([
                        'current_balance' => $totalBalance
                        // 'initial_balance' => $totalBalance
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
