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
            ['code_name' => 'bni', 'bank_name' => 'PT. BANK NEGARA INDONESIA (PERSERO)'],
            ['code_name' => 'mandiri', 'bank_name' => 'PT. BANK MANDIRI (PERSERO) TBK.'],
            ['code_name' => 'bca', 'bank_name' => 'PT. BANK CENTRAL ASIA TBK.'],
            ['code_name' => 'bri', 'bank_name' => 'PT. BANK RAKYAT INDONESIA (PERSERO)'],
            ['code_name' => 'cimb', 'bank_name' => 'PT. BANK CIMB NIAGA TBK.'],
            ['code_name' => 'permata', 'bank_name' => 'PT. BANK PERMATA TBK.'],
            ['code_name' => 'danamon', 'bank_name' => 'PT. BANK DANAMON INDONESIA TBK.'],
            ['code_name' => 'mega', 'bank_name' => 'PT. BANK MEGA TBK.'],
            ['code_name' => 'panin', 'bank_name' => 'PT. BANK PANIN TBK.'],
            ['code_name' => 'bukopin', 'bank_name' => 'PT. BANK BUKOPIN TBK.'],
            ['code_name' => 'mayapada', 'bank_name' => 'PT. BANK MAYAPADA INTERNASIONAL TBK.'],
            ['code_name' => 'icbc', 'bank_name' => 'PT. BANK ICBC INDONESIA'],
            ['code_name' => 'qnb', 'bank_name' => 'PT. BANK QNB INDONESIA TBK.'],
            ['code_name' => 'hsbc', 'bank_name' => 'PT. BANK HSBC INDONESIA'],
            ['code_name' => 'capital', 'bank_name' => 'PT. BANK CAPITAL INDONESIA TBK.'],
            ['code_name' => 'bumiarta', 'bank_name' => 'PT. BANK BUMI ARTA TBK.'],
            ['code_name' => 'mayora', 'bank_name' => 'PT. BANK MAYORA'],
            ['code_name' => 'ina', 'bank_name' => 'PT. BANK INA PERDANA TBK.'],
            ['code_name' => 'sampoerna', 'bank_name' => 'PT. BANK SAHABAT SAMPOERNA'],
            ['code_name' => 'nobu', 'bank_name' => 'PT. BANK NATIONAL NOBU'],
            ['code_name' => 'hana', 'bank_name' => 'PT. BANK KEB HANA INDONESIA'],
            ['code_name' => 'woori', 'bank_name' => 'PT. BANK WOORI SAUDARA INDONESIA 1906 TBK.'],
            ['code_name' => 'mnc', 'bank_name' => 'PT. BANK MNC INTERNASIONAL TBK.'],
            ['code_name' => 'sinarmas', 'bank_name' => 'PT. BANK SINARMAS TBK.'],

            // --- Bank Syariah ---
            ['code_name' => 'bsi', 'bank_name' => 'PT. BANK SYARIAH INDONESIA TBK.'],
            ['code_name' => 'mega_syariah', 'bank_name' => 'PT. BANK MEGA SYARIAH'],
            ['code_name' => 'panin_syariah', 'bank_name' => 'PT. BANK PANIN DUBAI SYARIAH TBK.'],
            ['code_name' => 'muamalat', 'bank_name' => 'PT. BANK MUAMALAT INDONESIA TBK.'],
            ['code_name' => 'bjb_syariah', 'bank_name' => 'PT. BANK JABAR BANTEN SYARIAH'],
            ['code_name' => 'victoria_syariah', 'bank_name' => 'PT. BANK VICTORIA SYARIAH'],
            ['code_name' => 'bukopin_syariah', 'bank_name' => 'PT. BANK BUKOPIN SYARIAH'],
            ['code_name' => 'ntb_syariah', 'bank_name' => 'PT. BANK NTB SYARIAH'],
            ['code_name' => 'btpn_syariah', 'bank_name' => 'PT. BANK BTPN SYARIAH TBK.'],
            ['code_name' => 'maybank_syariah', 'bank_name' => 'PT. BANK MAYBANK SYARIAH INDONESIA'],
            ['code_name' => 'bca_syariah', 'bank_name' => 'PT. BANK BCA SYARIAH'],

            // --- Bank Daerah (BPD) contoh beberapa ---
            ['code_name' => 'aceh', 'bank_name' => 'PT. BANK ACEH'],
            ['code_name' => 'aceh_syariah', 'bank_name' => 'PT. BANK ACEH SYARIAH'],
            ['code_name' => 'bali', 'bank_name' => 'PT. BANK PEMBANGUNAN DAERAH BALI'],

            // --- Bank Digital ---
            ['code_name' => 'jago', 'bank_name' => 'PT. BANK JAGO TBK.'],
            ['code_name' => 'blu', 'bank_name' => 'blu by BCA (Bank Digital BCA)'],
            ['code_name' => 'seabank', 'bank_name' => 'SeaBank Indonesia'],
            ['code_name' => 'allo', 'bank_name' => 'Allo Bank Indonesia'],
            ['code_name' => 'neo', 'bank_name' => 'Bank Neo Commerce'],
            ['code_name' => 'aladin', 'bank_name' => 'Bank Aladin Syariah'],
            ['code_name' => 'superbank', 'bank_name' => 'Superbank Indonesia'],
            ['code_name' => 'jenius', 'bank_name' => 'Jenius (BTPN Digital Banking)'],

            // --- E-Wallet ---
            ['code_name' => 'ovo', 'bank_name' => 'OVO'],
            ['code_name' => 'linkaja', 'bank_name' => 'Link Aja'],
            ['code_name' => 'dana', 'bank_name' => 'Dana'],
            ['code_name' => 'gopay', 'bank_name' => 'GO-PAY'],
            ['code_name' => 'shopeepay', 'bank_name' => 'ShopeePay'],
            ['code_name' => 'isaku', 'bank_name' => 'iSaku'],
            ['code_name' => 'paytren', 'bank_name' => 'Paytren'],
            ['code_name' => 'sakuku', 'bank_name' => 'Sakuku (BCA e-wallet)'],
            ['code_name' => 'jeniuspay', 'bank_name' => 'Jenius Pay'],

            // --- Virtual Account ---
            ['code_name' => 'va_mandiri', 'bank_name' => 'Virtual Account Bank Mandiri'],
            ['code_name' => 'va_bri', 'bank_name' => 'Virtual Account Bank BRI'],
            ['code_name' => 'va_cimb', 'bank_name' => 'Virtual Account Bank CIMB'],
            ['code_name' => 'va_permata', 'bank_name' => 'Virtual Account Bank Permata'],
            ['code_name' => 'va_bca', 'bank_name' => 'Virtual Account Bank BCA'],
            ['code_name' => 'va_bni', 'bank_name' => 'Virtual Account Bank BNI'],
        ]);
    }
}
