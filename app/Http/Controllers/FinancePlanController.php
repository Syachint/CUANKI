<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Account;
use App\Models\UserFinancePlan;
use App\Models\AccountAllocation;
use Illuminate\Support\Facades\Validator;

class FinancePlanController extends Controller
{
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
