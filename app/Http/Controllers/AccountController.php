<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\BankData;

class AccountController extends Controller
{
    public function listBanks()
    {
        $banks = BankData::all(['id', 'code_name', 'bank_name']);

        return response()->json([
            'status' => 'success',
            'data'   => $banks
        ]);
    }

    /**
     * Menyimpan data bank baru dan mengatur jenis alokasi di tabel account_allocation.
     * Logika: 1 Akun = Gabungan, 2 Akun = Pecah dua, 3 Akun = Pecah tiga.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function formDetailAccount(Request $request)
    {
        $user = $request->user();
        $accounts = $user->accounts()->with('bank')->oldest('created_at')->get();
        $currentAccountCount = $accounts->count();
        
        $validator = Validator::make($request->all(), [
            'bank_id'           => 'required|integer|exists:bank_data,id', 
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validasi data bank gagal.',
                'errors'  => $validator->errors()
            ], 401);
        }

        $account = $user->accounts()->create([
            'bank_id'           => $request->bank_id, 
        ]);
        
        // Load bank data untuk account baru
        $account->load('bank');
        
        $accounts = $user->accounts()->with('bank')->oldest('created_at')->get();

        $accountCount = $accounts->count();
        
        $accountIds = $accounts->pluck('id')->toArray();
        AccountAllocation::whereIn('account_id', $accountIds)->delete();

        $message = '';
        $allocationsToCreate = [];

        // Note: balance_per_type diset 0. Perhitungan balance dilakukan di FinanceController.

        if ($accountCount == 1) {
            $account = $accounts->first();
            $allocationsToCreate[] = ['account_id' => $account->id, 'type' => 'Kebutuhan', 'balance_per_type' => 0];
            $allocationsToCreate[] = ['account_id' => $account->id, 'type' => 'Tabungan', 'balance_per_type' => 0];
            $allocationsToCreate[] = ['account_id' => $account->id, 'type' => 'Darurat', 'balance_per_type' => 0];
            $message = 'Bagus, tapi saran dari aku sih kamu harus ada minimal 2 akun, untuk kebutuhan dan tabungan';

        } else if ($accountCount == 2) {
            $firstAccount = $accounts->get(0);
            $secondAccount = $accounts->get(1);
            
            $allocationsToCreate[] = ['account_id' => $firstAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 0];

            $allocationsToCreate[] = ['account_id' => $secondAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 0];
            $allocationsToCreate[] = ['account_id' => $secondAccount->id, 'type' => 'Darurat', 'balance_per_type' => 0];
            
            $message = 'Mantap, 2 akun cukup tapi 3 akun lebih baik, untuk kebutuhan, tabungan, dan dana darurat';

        } else if ($accountCount >= 3) {
            $kebutuhanAccount = $accounts->get(0);
            $tabunganAccount = $accounts->get(1);
            $daruratAccount = $accounts->get(2);

            $allocationsToCreate[] = ['account_id' => $kebutuhanAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 0];
            $allocationsToCreate[] = ['account_id' => $tabunganAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 0];
            $allocationsToCreate[] = ['account_id' => $daruratAccount->id, 'type' => 'Darurat', 'balance_per_type' => 0];

            $message = 'Wah, kamu keren! Dengan lebuf dari 3 akun, kamu pasti sudah sangat terorganisir dalam mengelola keuanganmu.';
        }
        
        foreach ($allocationsToCreate as $allocation) {
            AccountAllocation::create([
                'account_id' => $allocation['account_id'],
                'type' => $allocation['type'],
                'balance_per_type' => $allocation['balance_per_type'],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'account_count' => $accountCount,
                'new_account' => $account,
                'accounts' => $accounts,
                'allocations' => AccountAllocation::whereIn('account_id', $accountIds)->with(['account.bank'])->get()
            ]
        ], 201);
    }
}
