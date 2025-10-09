<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Origin;
use App\Models\User;
use App\Models\BankData;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\UserFinancePlan;
use Illuminate\Support\Facades\Validator;

class FormDetailController extends Controller
{
    function getOrigins() {
        $origins = Origin::all();
        return response()->json([
            'status' => 'success',
            'data' => $origins
        ], 200);
    }

    function formDetailUser(Request $request)
        {
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'username'  => 'required|string|max:255',
                'age'       => 'nullable|integer|min:0',
                'origin_id' => 'nullable|integer',
                'status'    => 'required|in:mahasiswa,pelajar',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Validasi data profil gagal. Pastikan data di semua step lengkap.',
                    'errors'  => $validator->errors()
                ], 401);
            }

            $user->username = $request->username;

            if ($request->has('age')) {
                $user->age = $request->age;
            }

            if ($request->has('origin_id')) {
                $user->origin_id = $request->origin_id;
            }
            
            $user->status = $request->status;

            $user->save();

            $user->load('origin');

            return response()->json([
                'status'  => 'success',
                'message' => 'Semua data profil berhasil disimpan secara lengkap (Atomic Success).',
                'data'    => $user
            ], 201);
        }

    public function listBanks()
    {
        $banks = BankData::all(['id', 'code_name', 'bank_name']);

        return response()->json([
            'status' => 'success',
            'statu' => 'success',// hapus
            'data'   => $banks
        ]);
    }

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

    public function formDetailPlan(Request $request)
    {
        $user = $request->user();

        // 1. Validasi Input Pemasukan dan Tabungan
        $validator = Validator::make($request->all(), [
            'monthly_income'            => 'required|numeric|min:0',
            'income_date'               => 'required|integer|min:1|max:31',
            'saving_target_amount'      => 'required|numeric|min:0',
            // Field Dana Darurat: nullable karena opsional
            'emergency_target_amount'   => 'nullable|numeric|min:0', 
            'saving_target_duration'    => 'required|integer|min:1', // Dalam tahun
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validasi data pemasukan gagal.',
                'errors'  => $validator->errors()
            ], 401);
        }

        // 2. SIMPAN/UPDATE Rencana Keuangan
        // Kalo ada, update. Kalo belum ada, bikin baru.
        // Asumsi kolom emergency_target_amount sudah ada di tabel user_finance_plans
        $plan = UserFinancePlan::updateOrCreate(
            ['user_id' => $user->id],
            [
                'monthly_income'            => $request->monthly_income,
                'income_date'               => $request->income_date,
                'saving_target_amount'      => $request->saving_target_amount,
                'saving_target_duration'    => $request->saving_target_duration,
            ]
        );
        
        // 3. Response: Mengkonfirmasi Rencana telah tersimpan
        return response()->json([
            'status' => 'success',
            'message' => 'Rencana keuangan bulanan berhasil disimpan! Alokasi saldo akan dijalankan otomatis bulan depan sesuai tanggal gajian.',
            'data' => $plan
        ], 201);
    }
}
