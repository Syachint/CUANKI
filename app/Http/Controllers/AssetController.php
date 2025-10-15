<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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

    
}
