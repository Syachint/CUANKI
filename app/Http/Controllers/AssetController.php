<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
}
