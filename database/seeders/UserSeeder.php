<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\UserFinancePlan;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Goal;
use App\Models\ExpenseCategories;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. CREATE USERS WITH COMPLETE FORM DETAIL
        // Clear existing test data first
        User::whereIn('email', ['itsar@test.com', 'santa@test.com', 'ian@test.com'])->delete();

        $users = [
            [
                'name' => 'Itsar Ahmad',
                'username' => 'itsar123',
                'email' => 'itsar@test.com',
                'password' => bcrypt('12345678'),
                'age' => 22,
                'origin_id' => 14, // Jakarta, DKI Jakarta
                'status' => 'mahasiswa',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Santa Maria',
                'username' => 'santa456',
                'email' => 'santa@test.com',
                'password' => bcrypt('12345678'),
                'age' => 19,
                'origin_id' => 39, // Surabaya, Jawa Timur
                'status' => 'pelajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ian Pratama',
                'username' => 'ian789',
                'email' => 'ian@test.com',
                'password' => bcrypt('12345678'),
                'age' => 21,
                'origin_id' => 17, // Bandung, Jawa Barat
                'status' => 'mahasiswa',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($users as $userData) {
            User::create($userData);
        }

        // 2. CREATE FINANCE PLANS
        $financePlans = [
            [
                'user_id' => 1, // Itsar
                'monthly_income' => 2500000,
                'income_date' => 25,
                'saving_target_amount' => 400000, // 400k per bulan
                'saving_target_duration' => 2, // 2 tahun
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 2, // Santa
                'monthly_income' => 1500000,
                'income_date' => 1,
                'saving_target_amount' => 200000, // 200k per bulan
                'saving_target_duration' => 1, // 1 tahun
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 3, // Ian
                'monthly_income' => 3000000,
                'income_date' => 15,
                'saving_target_amount' => 600000, // 600k per bulan
                'saving_target_duration' => 3, // 3 tahun
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($financePlans as $planData) {
            UserFinancePlan::create($planData);
        }

        // 3. CREATE ACCOUNTS WITH PROPER ALLOCATION LOGIC

        // ITSAR - 1 Account (3 allocation types in 1 account)
        $itsarAccount = Account::create([
            'user_id' => 1,
            'bank_id' => 1, // Assuming BCA bank_id = 1
            'initial_balance' => 2300000,
            'current_balance' => 2300000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $itsarAccount->id,
            'type' => 'Kebutuhan',
            'balance_per_type' => 800000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $itsarAccount->id,
            'type' => 'Tabungan',
            'balance_per_type' => 1200000, // For Pendekar Hemat badge
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $itsarAccount->id,
            'type' => 'Darurat',
            'balance_per_type' => 300000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // SANTA - 2 Accounts (Kebutuhan + Tabungan+Darurat)
        $santaAccount1 = Account::create([
            'user_id' => 2,
            'bank_id' => 2, // Assuming Mandiri bank_id = 2
            'initial_balance' => 500000,
            'current_balance' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $santaAccount2 = Account::create([
            'user_id' => 2,
            'bank_id' => 3, // Assuming BRI bank_id = 3
            'initial_balance' => 1400000,
            'current_balance' => 1400000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $santaAccount1->id,
            'type' => 'Kebutuhan',
            'balance_per_type' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $santaAccount2->id,
            'type' => 'Tabungan',
            'balance_per_type' => 1000000, // For Pendekar Hemat badge
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $santaAccount2->id,
            'type' => 'Darurat',
            'balance_per_type' => 400000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // IAN - 3 Accounts (Each type separated)
        $ianAccount1 = Account::create([
            'user_id' => 3,
            'bank_id' => 4, // Assuming CIMB bank_id = 4
            'initial_balance' => 750000,
            'current_balance' => 750000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ianAccount2 = Account::create([
            'user_id' => 3,
            'bank_id' => 1, // BCA
            'initial_balance' => 900000,
            'current_balance' => 900000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ianAccount3 = Account::create([
            'user_id' => 3,
            'bank_id' => 2, // Mandiri
            'initial_balance' => 350000,
            'current_balance' => 350000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $ianAccount1->id,
            'type' => 'Kebutuhan',
            'balance_per_type' => 750000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $ianAccount2->id,
            'type' => 'Tabungan',
            'balance_per_type' => 900000, // Close to Pendekar Hemat
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountAllocation::create([
            'account_id' => $ianAccount3->id,
            'type' => 'Darurat',
            'balance_per_type' => 350000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. CREATE EXPENSES FOR STREAK BADGES
        $expenseCategories = ExpenseCategories::pluck('id')->toArray();

        // ITSAR - 60 days streak (Si Ahli Badge)
        for ($i = 59; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            Expense::create([
                'user_id' => 1,
                'account_id' => $itsarAccount->id,
                'expense_category_id' => $expenseCategories[array_rand($expenseCategories)],
                'is_manual' => true,
                'frequency' => 'Sekali',
                'amount' => rand(15000, 80000),
                'note' => 'Daily expense ' . $date->format('Y-m-d'),
                'expense_date' => $date->format('Y-m-d'),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }

        // SANTA - 35 days streak (Si Disiplin Badge)
        for ($i = 34; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $accountId = rand(0, 1) ? $santaAccount1->id : $santaAccount2->id;
            Expense::create([
                'user_id' => 2,
                'account_id' => $accountId,
                'expense_category_id' => $expenseCategories[array_rand($expenseCategories)],
                'is_manual' => true,
                'frequency' => 'Sekali',
                'amount' => rand(20000, 60000),
                'note' => 'Daily expense ' . $date->format('Y-m-d'),
                'expense_date' => $date->format('Y-m-d'),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }

        // IAN - 15 days streak (Si Rajin Badge)
        for ($i = 14; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $accounts = [$ianAccount1->id, $ianAccount2->id, $ianAccount3->id];
            $accountId = $accounts[array_rand($accounts)];
            Expense::create([
                'user_id' => 3,
                'account_id' => $accountId,
                'expense_category_id' => $expenseCategories[array_rand($expenseCategories)],
                'is_manual' => true,
                'frequency' => 'Sekali',
                'amount' => rand(10000, 50000),
                'note' => 'Daily expense ' . $date->format('Y-m-d'),
                'expense_date' => $date->format('Y-m-d'),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }

        // 5. CREATE INCOME DATA (3 months)
        $incomes = [
            // Itsar
            ['user_id' => 1, 'account_id' => $itsarAccount->id, 'income_amount' => 2500000, 'month' => 8, 'year' => 2025],
            ['user_id' => 1, 'account_id' => $itsarAccount->id, 'income_amount' => 2400000, 'month' => 9, 'year' => 2025],
            ['user_id' => 1, 'account_id' => $itsarAccount->id, 'income_amount' => 2600000, 'month' => 10, 'year' => 2025],
            
            // Santa
            ['user_id' => 2, 'account_id' => $santaAccount1->id, 'income_amount' => 1500000, 'month' => 8, 'year' => 2025],
            ['user_id' => 2, 'account_id' => $santaAccount1->id, 'income_amount' => 1450000, 'month' => 9, 'year' => 2025],
            ['user_id' => 2, 'account_id' => $santaAccount1->id, 'income_amount' => 1550000, 'month' => 10, 'year' => 2025],
            
            // Ian
            ['user_id' => 3, 'account_id' => $ianAccount1->id, 'income_amount' => 3000000, 'month' => 8, 'year' => 2025],
            ['user_id' => 3, 'account_id' => $ianAccount1->id, 'income_amount' => 2900000, 'month' => 9, 'year' => 2025],
            ['user_id' => 3, 'account_id' => $ianAccount1->id, 'income_amount' => 3100000, 'month' => 10, 'year' => 2025],
        ];

        foreach ($incomes as $incomeData) {
            Income::create([
                'user_id' => $incomeData['user_id'],
                'account_id' => $incomeData['account_id'],
                'is_manual' => true,
                'frequency' => 'Bulanan',
                'amount' => $incomeData['income_amount'],
                'confirmation_status' => 'Confirmed',
                'actual_amount' => $incomeData['income_amount'],
                'note' => 'Monthly income',
                'received_date' => Carbon::create($incomeData['year'], $incomeData['month'], 1)->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. CREATE GOALS (For Si Paling Self Reward Badge)
        $goals = [
            // Itsar - 1 completed goal
            [
                'user_id' => 1,
                'account_allocation_id' => 2, // Tabungan allocation
                'is_first' => true,
                'target_amount' => 15000000,
                'target_deadline' => Carbon::now()->addMonths(12)->format('Y-m-d'),
                'goal_name' => 'Beli Laptop Gaming',
                'is_goal_achieved' => true,
                'created_at' => Carbon::now()->subMonths(3),
                'updated_at' => Carbon::now()->subMonth(1),
            ],
            [
                'user_id' => 1,
                'account_allocation_id' => 3, // Darurat allocation
                'is_first' => false,
                'target_amount' => 8000000,
                'target_deadline' => Carbon::now()->addMonths(18)->format('Y-m-d'),
                'goal_name' => 'Emergency Fund',
                'is_goal_achieved' => false,
                'created_at' => Carbon::now()->subMonths(2),
                'updated_at' => now(),
            ],
            
            // Santa
            [
                'user_id' => 2,
                'account_allocation_id' => 4, // Tabungan allocation
                'is_first' => true,
                'target_amount' => 5000000,
                'target_deadline' => Carbon::now()->addMonths(8)->format('Y-m-d'),
                'goal_name' => 'Liburan Bali',
                'is_goal_achieved' => false,
                'created_at' => Carbon::now()->subMonth(),
                'updated_at' => now(),
            ],
            
            // Ian
            [
                'user_id' => 3,
                'account_allocation_id' => 5, // Tabungan allocation
                'is_first' => true,
                'target_amount' => 20000000,
                'target_deadline' => Carbon::now()->addMonths(24)->format('Y-m-d'),
                'goal_name' => 'Modal Bisnis',
                'is_goal_achieved' => false,
                'created_at' => Carbon::now()->subMonths(2),
                'updated_at' => now(),
            ],
        ];

        foreach ($goals as $goalData) {
            Goal::create($goalData);
        }

        echo "✅ UserSeeder completed successfully!\n";
        echo "Created:\n";
        echo "- 3 Users with complete form detail\n";
        echo "- 3 Finance plans\n";
        echo "- 6 Accounts with proper allocations\n";
        echo "- 110 Expense records for streak testing\n";
        echo "- 9 Income records\n";
        echo "- 4 Goals (1 completed for badge)\n\n";
        echo "Expected Badges:\n";
        echo "🏆 Itsar: Si Rajin, Si Disiplin, Si Ahli, Pendekar Hemat, Si Paling Self Reward\n";
        echo "🏆 Santa: Si Rajin, Si Disiplin, Calon Orang Sukses, Pendekar Hemat\n";
        echo "🏆 Ian: Si Rajin, Orang Sukses aamiin\n";
    }
}