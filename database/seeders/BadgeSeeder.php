<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BadgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('badges')->insert([
            [
                'name' => 'Si Rajin',
                'description' => 'Mencapai 10 hari berturut-turut mencatat pengeluaran.',
            ],
            [
                'name' => 'Si Disiplin',
                'description' => 'Mencapai 30 hari berturut-turut mencatat pengeluaran.',
            ],
            [
                'name' => 'Si Ahli',
                'description' => 'Mencapai 50 hari berturut-turut mencatat pengeluaran.',
            ],
            [
                'name' => 'Master Pengelola',
                'description' => 'Mencapai 100 hari berturut-turut mencatat pengeluaran.',
            ],
            [
                'name' => 'Legenda Cuanki',
                'description' => 'Mencapai 1000 hari berturut-turut mencatat pengeluaran.',
            ],
            [
                'name' => 'Calon Orang Sukses',
                'description' => 'Memiliki 2 rekening.',
            ],
            [
                'name' => 'Orang Sukses, aamiin',
                'description' => 'Memiliki 3 rekening.',
            ],
            [
                'name' => 'Pendekar Hemat',
                'description' => 'Berhasil menghemat 1 juta dalam sebulan.',
            ],
            [
                'name' => 'Si Paling Self Reward',
                'description' => 'Berhasil menyelesaikan 10 goals yang dibuat.',
            ],
        ]);
    }
}
