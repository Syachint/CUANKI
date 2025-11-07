<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BankDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('bank_data')->insert([
            // --- Bank Konvensional Besar ---
            ['code_name' => 'BNI', 'bank_name' => 'PT. BANK NEGARA INDONESIA (PERSERO)'],
            ['code_name' => 'MANDIRI', 'bank_name' => 'PT. BANK MANDIRI (PERSERO) TBK.'],
            ['code_name' => 'BCA', 'bank_name' => 'PT. BANK CENTRAL ASIA TBK.'],
            ['code_name' => 'BRI', 'bank_name' => 'PT. BANK RAKYAT INDONESIA (PERSERO)'],
            ['code_name' => 'CIMB', 'bank_name' => 'PT. BANK CIMB NIAGA TBK.'],
            ['code_name' => 'PERMATA', 'bank_name' => 'PT. BANK PERMATA TBK.'],
            ['code_name' => 'DANAMON', 'bank_name' => 'PT. BANK DANAMON INDONESIA TBK.'],
            ['code_name' => 'MEGA', 'bank_name' => 'PT. BANK MEGA TBK.'],
            ['code_name' => 'PANIN', 'bank_name' => 'PT. BANK PANIN TBK.'],
            ['code_name' => 'BUKOPIN', 'bank_name' => 'PT. BANK BUKOPIN TBK.'],
            ['code_name' => 'MAYAPADA', 'bank_name' => 'PT. BANK MAYAPADA INTERNASIONAL TBK.'],
            ['code_name' => 'ICBC', 'bank_name' => 'PT. BANK ICBC INDONESIA'],
            ['code_name' => 'QNB', 'bank_name' => 'PT. BANK QNB INDONESIA TBK.'],
            ['code_name' => 'HSBC', 'bank_name' => 'PT. BANK HSBC INDONESIA'],
            ['code_name' => 'CAPITAL', 'bank_name' => 'PT. BANK CAPITAL INDONESIA TBK.'],
            ['code_name' => 'BUMIARTA', 'bank_name' => 'PT. BANK BUMI ARTA TBK.'],
            ['code_name' => 'MAYORA', 'bank_name' => 'PT. BANK MAYORA'],
            ['code_name' => 'INA', 'bank_name' => 'PT. BANK INA PERDANA TBK.'],
            ['code_name' => 'SAMPOERNA', 'bank_name' => 'PT. BANK SAHABAT SAMPOERNA'],
            ['code_name' => 'NOBU', 'bank_name' => 'PT. BANK NATIONAL NOBU'],
            ['code_name' => 'HANA', 'bank_name' => 'PT. BANK KEB HANA INDONESIA'],
            ['code_name' => 'WOORI', 'bank_name' => 'PT. BANK WOORI SAUDARA INDONESIA 1906 TBK.'],
            ['code_name' => 'MNC', 'bank_name' => 'PT. BANK MNC INTERNASIONAL TBK.'],
            ['code_name' => 'SINARMAS', 'bank_name' => 'PT. BANK SINARMAS TBK.'],

            // --- Bank Syariah ---
            ['code_name' => 'BSI', 'bank_name' => 'PT. BANK SYARIAH INDONESIA TBK.'],
            ['code_name' => 'MEGA SYARIAH', 'bank_name' => 'PT. BANK MEGA SYARIAH'],
            ['code_name' => 'PANIN SYARIAH', 'bank_name' => 'PT. BANK PANIN DUBAI SYARIAH TBK.'],
            ['code_name' => 'MUAMALAT', 'bank_name' => 'PT. BANK MUAMALAT INDONESIA TBK.'],
            ['code_name' => 'BJB SYARIAH', 'bank_name' => 'PT. BANK JABAR BANTEN SYARIAH'],
            ['code_name' => 'VICTORIA SYARIAH', 'bank_name' => 'PT. BANK VICTORIA SYARIAH'],
            ['code_name' => 'BUKOPIN SYARIAH', 'bank_name' => 'PT. BANK BUKOPIN SYARIAH'],
            ['code_name' => 'NTB SYARIAH', 'bank_name' => 'PT. BANK NTB SYARIAH'],
            ['code_name' => 'BTPN SYARIAH', 'bank_name' => 'PT. BANK BTPN SYARIAH TBK.'],
            ['code_name' => 'MAYBANK SYARIAH', 'bank_name' => 'PT. BANK MAYBANK SYARIAH INDONESIA'],
            ['code_name' => 'BCA SYARIAH', 'bank_name' => 'PT. BANK BCA SYARIAH'],

            // --- Bank Daerah (BPD) contoh beberapa ---
            ['code_name' => 'BANK ACEH', 'bank_name' => 'PT. BANK ACEH'],
            ['code_name' => 'BANK ACEH SYARIAH', 'bank_name' => 'PT. BANK ACEH SYARIAH'],
            ['code_name' => 'BANK PEMBANGUNAN DAERAH BALI', 'bank_name' => 'PT. BANK PEMBANGUNAN DAERAH BALI'],

            // --- Bank Digital ---
            ['code_name' => 'BANK JAGO', 'bank_name' => 'PT. BANK JAGO TBK.'],
            ['code_name' => 'BLU', 'bank_name' => 'blu by BCA (Bank Digital BCA)'],
            ['code_name' => 'SEABANK', 'bank_name' => 'SeaBank Indonesia'],
            ['code_name' => 'ALLO', 'bank_name' => 'Allo Bank Indonesia'],
            ['code_name' => 'NEO', 'bank_name' => 'Bank Neo Commerce'],
            ['code_name' => 'ALADIN', 'bank_name' => 'Bank Aladin Syariah'],
            ['code_name' => 'SUPERBANK', 'bank_name' => 'Superbank Indonesia'],
            ['code_name' => 'JENIUS', 'bank_name' => 'Jenius (BTPN Digital Banking)'],

            // --- E-Wallet ---
            ['code_name' => 'OVO', 'bank_name' => 'OVO'],
            ['code_name' => 'LINKAJA', 'bank_name' => 'Link Aja'],
            ['code_name' => 'DANA', 'bank_name' => 'Dana'],
            ['code_name' => 'GOPAY', 'bank_name' => 'GO-PAY'],
            ['code_name' => 'SHOPEEPAY', 'bank_name' => 'ShopeePay'],
            ['code_name' => 'ISAKU', 'bank_name' => 'iSaku'],
            ['code_name' => 'PAYTREN', 'bank_name' => 'Paytren'],
            ['code_name' => 'SAKUKU', 'bank_name' => 'Sakuku (BCA e-wallet)'],
            ['code_name' => 'JENIUSPAY', 'bank_name' => 'Jenius Pay'],
        ]);
    }
}
