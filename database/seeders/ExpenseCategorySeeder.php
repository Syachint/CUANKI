<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = [
            ['name' => 'Belanja'],
            ['name' => 'Bensin'],
            ['name' => 'Makan'],
            ['name' => 'Laundry'],
            ['name' => 'Ngopi'],
            ['name' => 'Rokok / Vape'],
            ['name' => 'Jajan'],
            ['name' => 'Transportasi Umum'],
            ['name' => 'Parkir'],
            ['name' => 'Pulsa & Kuota'],
            ['name' => 'Kos / Kontrakan'],
            ['name' => 'Kebutuhan Rumah Tangga'],
            ['name' => 'Streaming / Musik'],
            ['name' => 'Hobi'],
            ['name' => 'Obat / Vitamin / Perawatan'],
            ['name' => 'Periksa Dokter'],
            ['name' => 'Buku / Alat Tulis'],
            ['name' => 'Kursus'],
            ['name' => 'Cicilan'],
            ['name' => 'Lain-lain'],
        ];
        DB::table('expense_categories')->insert($name);
    }
}
