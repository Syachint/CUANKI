<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Income;
use App\Models\Account;
use App\Models\AccountAllocation;
use Carbon\Carbon;

class TransactionController extends Controller
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
                    $bankName = $account->bank ? $account->bank->bank_name : 'Unknown Bank';
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
                'aset' => 'required|string|max:255', // Account format: "BCA - Kebutuhan"
            ]);

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Parse account name to find the account
            // Format expected: "BCA - Kebutuhan" or similar
            $asetParts = explode(' - ', $request->aset);
            if (count($asetParts) !== 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid account format. Expected format: "Bank - Type"'
                ], 400);
            }

            $bankName = trim($asetParts[0]);
            $allocationType = trim($asetParts[1]);

            // Find the account based on bank_name and verify allocation type exists
            $account = Account::where('user_id', $user->id)
                ->whereHas('bank', function($query) use ($bankName) {
                    $query->where('code_name', $bankName);
                })
                ->whereHas('allocations', function($query) use ($allocationType) {
                    $query->where('type', $allocationType);
                })
                ->with(['bank', 'allocations'])
                ->first();

            if (!$account) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account not found: ' . $bankName
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

            return response()->json([
                'status' => 'success',
                'message' => 'Income added successfully',
                'data' => [
                    'income_id' => $income->id,
                    'user_id' => $income->user_id,
                    'account_id' => $income->account_id,
                    'account_name' => $account->bank ? $account->bank->bank_name : 'Unknown Bank',
                    'amount' => $income->amount,
                    'note' => $income->note,
                    'received_date' => $income->received_date,
                    'confirmation_status' => $income->confirmation_status,
                    'created_at' => $income->created_at->format('Y-m-d H:i:s'),
                    'formatted' => [
                        'amount' => 'Rp ' . number_format($income->amount, 0, ',', '.'),
                        'received_date' => Carbon::parse($income->received_date)->format('d M Y'),
                        'aset' => $request->aset
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
}
