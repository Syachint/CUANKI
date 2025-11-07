<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\Income;
use App\Models\Expense;
use App\Models\ExpenseCategories;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\CalendarLogs;
use App\Models\Streak;

class FullUserSeeder extends Seeder
{
    /**
     * Run the database seeds for comprehensive user data
     */
    public function run(): void
    {
        // PostgreSQL doesn't use FOREIGN_KEY_CHECKS, we'll handle dependencies properly

        // Clear existing USER data only (tidak touch master data)
        $this->clearExistingUserData();
        
        // Create multiple users with different scenarios
        $this->createNewUserScenario();          // User baru dengan sedikit data
        $this->createActiveUserScenario();       // User aktif dengan banyak transaksi
        $this->createSavingsUserScenario();      // User yang fokus menabung
        $this->createBudgetingUserScenario();    // User yang rajin budgeting
        $this->createGoalOrientedUserScenario(); // User dengan banyak goals

        $this->command->info('✅ Full User Seeder completed successfully!');
        $this->command->info('📊 Created 5 users with comprehensive data including:');
        $this->command->info('   - Multiple bank accounts and allocations');
        $this->command->info('   - Historical transactions (income & expenses)');
        $this->command->info('   - Daily budgets and budget tracking');
        $this->command->info('   - Savings progression data');
        $this->command->info('   - Financial goals and achievements');
        $this->command->info('   - User badges and streaks');
        $this->command->info('   - Calendar logs for activity tracking');
        $this->command->info('💡 Master data (BankData, ExpenseCategories, Badges, Origin) tidak diubah');
    }

    private function clearExistingUserData(): void
    {
        // Clear HANYA user-related data (TIDAK touch master data seperti BankData, ExpenseCategories, Badges, Origin)
        UserBadge::truncate();
        CalendarLogs::truncate();
        Streak::truncate();
        Budget::truncate();
        Goal::truncate();
        Expense::truncate();
        Income::truncate();
        AccountAllocation::truncate();
        Account::truncate();
        User::truncate();
        
        $this->command->info('🧹 Cleared existing user data (master data tetap aman)');
    }



    /**
     * Scenario 1: User baru yang baru mulai menggunakan aplikasi
     */
    private function createNewUserScenario(): void
    {
        $user = User::create([
            'name' => 'Sinta Dewi',
            'email' => 'sinta.dewi@example.com',
            'password' => Hash::make('password'),
            'username' => 'sinta_dewi',
            'age' => 20,
            'status' => 'mahasiswa',
            'created_at' => now()->subDays(3),
            'updated_at' => now()
        ]);

        // 1 Bank Account dengan 3 allocations
        $bcaBank = \App\Models\BankData::where('code_name', 'BCA')->first();
        if (!$bcaBank) {
            $this->command->error('❌ BankData untuk BCA tidak ditemukan! Pastikan master data sudah ada.');
            return;
        }
        
        $bcaAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $bcaBank->id,
            'current_balance' => 1500000,
            'initial_balance' => 1500000,
            'created_at' => now()->subDays(3),
            'updated_at' => now()
        ]);

        // Allocations
        AccountAllocation::create(['account_id' => $bcaAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 800000, 'allocation_date' => now()->subDays(3)]);
        AccountAllocation::create(['account_id' => $bcaAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 500000, 'allocation_date' => now()->subDays(3)]);
        AccountAllocation::create(['account_id' => $bcaAccount->id, 'type' => 'Darurat', 'balance_per_type' => 200000, 'allocation_date' => now()->subDays(3)]);

        // Beberapa transaksi sederhana
        $this->createSimpleTransactions($user, $bcaAccount, 3);
        
        // Basic budget
        $this->createDailyBudgets($user, $bcaAccount, 3);

        // First goal - menggunakan allocation Tabungan
        $tabunganAllocation = AccountAllocation::where('account_id', $bcaAccount->id)
            ->where('type', 'Tabungan')
            ->first();
            
        if ($tabunganAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Beli Laptop Baru',
                'target_amount' => 8000000,
                'target_deadline' => now()->addMonths(6)->format('Y-m-d'),
                'is_first' => true,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(2),
                'updated_at' => now()
            ]);
        }

        // Award first badge
        $firstBadge = Badge::where('name', 'Si Rajin')->first();
        if ($firstBadge) {
            UserBadge::create([
                'user_id' => $user->id,
                'badge_id' => $firstBadge->id,
                'awarded_at' => now()->subDays(2),
                'created_at' => now()->subDays(2),
                'updated_at' => now()
            ]);
        }

        $this->command->info('👶 Created new user scenario: Sinta Dewi');
    }

    /**
     * Scenario 2: User aktif dengan banyak transaksi
     */
    private function createActiveUserScenario(): void
    {
        $user = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi.santoso@example.com',
            'password' => Hash::make('password'),
            'username' => 'budi_santoso',
            'age' => 25,
            'status' => 'mahasiswa',
            'created_at' => now()->subDays(45),
            'updated_at' => now()
        ]);

        // Multiple bank accounts
        $briBank = \App\Models\BankData::where('code_name', 'BRI')->first();
        $danaBank = \App\Models\BankData::where('code_name', 'DANA')->first();
        
        if (!$briBank || !$danaBank) {
            $this->command->error('❌ BankData untuk BRI atau DANA tidak ditemukan! Pastikan master data sudah ada.');
            return;
        }
        
        $briAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $briBank->id,
            'current_balance' => 2500000,
            'initial_balance' => 3000000,
            'created_at' => now()->subDays(45),
            'updated_at' => now()
        ]);

        $danaAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $danaBank->id,
            'current_balance' => 1200000,
            'initial_balance' => 1500000,
            'created_at' => now()->subDays(30),
            'updated_at' => now()
        ]);

        // BRI Allocations (Bank A scenario - only Kebutuhan)
        AccountAllocation::create(['account_id' => $briAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 2500000, 'allocation_date' => now()->subDays(45)]);

        // DANA Allocations (Bank B scenario - Tabungan + Darurat)
        AccountAllocation::create(['account_id' => $danaAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 800000, 'allocation_date' => now()->subDays(30)]);
        AccountAllocation::create(['account_id' => $danaAccount->id, 'type' => 'Darurat', 'balance_per_type' => 400000, 'allocation_date' => now()->subDays(30)]);

        // Banyak transaksi historical
        $this->createExtensiveTransactions($user, $briAccount, $danaAccount, 45);
        
        // Daily budgets untuk banyak hari
        $this->createDailyBudgets($user, $briAccount, 45);

        // Multiple goals
        $tabunganAllocation = AccountAllocation::where('account_id', $danaAccount->id)
            ->where('type', 'Tabungan')
            ->first();
        $daruratAllocation = AccountAllocation::where('account_id', $danaAccount->id)
            ->where('type', 'Darurat')
            ->first();
            
        if ($tabunganAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Dana Nikah',
                'target_amount' => 50000000,
                'target_deadline' => now()->addYear()->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(5, 40)),
                'updated_at' => now()
            ]);
            
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Liburan Bali',
                'target_amount' => 5000000,
                'target_deadline' => now()->subDays(10)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => true,
                'created_at' => now()->subDays(rand(5, 40)),
                'updated_at' => now()
            ]);
        }
        
        if ($daruratAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $daruratAllocation->id,
                'goal_name' => 'Emergency Fund',
                'target_amount' => 15000000,
                'target_deadline' => now()->addMonths(8)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(5, 40)),
                'updated_at' => now()
            ]);
        }

        // Award multiple badges
        $badges = Badge::whereIn('name', ['Si Rajin', 'Si Disiplin', 'Calon Orang Sukses'])->get();
        foreach ($badges as $badge) {
            UserBadge::create([
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'awarded_at' => now()->subDays(rand(5, 35)),
                'created_at' => now()->subDays(rand(5, 35)),
                'updated_at' => now()
            ]);
        }

        // Calendar logs untuk tracking aktivitas
        $this->createCalendarLogs($user, 45);

        // Streak data
        Streak::create([
            'user_id' => $user->id,
            'current_streak' => 12,
            'longest_streak' => 25,
            'last_submit_date' => now()->format('Y-m-d'),
            'streak_status' => true,
            'created_at' => now()->subDays(25),
            'updated_at' => now()
        ]);

        $this->command->info('🔥 Created active user scenario: Budi Santoso');
    }

    /**
     * Scenario 3: User yang fokus menabung
     */
    private function createSavingsUserScenario(): void
    {
        $user = User::create([
            'name' => 'Maya Sari',
            'email' => 'maya.sari@example.com',
            'password' => Hash::make('password'),
            'username' => 'maya_sari',
            'age' => 22,
            'status' => 'mahasiswa',
            'created_at' => now()->subDays(60),
            'updated_at' => now()
        ]);

        // 3 Bank accounts (3+ banks scenario)
        $bniBank = \App\Models\BankData::where('code_name', 'BNI')->first();
        $mandiriBank = \App\Models\BankData::where('code_name', 'MANDIRI')->first();
        $cimbBank = \App\Models\BankData::where('code_name', 'CIMB')->first();
        
        if (!$bniBank || !$mandiriBank || !$cimbBank) {
            $this->command->error('❌ BankData untuk BNI, MANDIRI, atau CIMB tidak ditemukan! Pastikan master data sudah ada.');
            return;
        }
        
        $bniAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $bniBank->id,
            'current_balance' => 3200000,
            'initial_balance' => 3500000,
            'created_at' => now()->subDays(60),
            'updated_at' => now()
        ]);

        $mandiriAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $mandiriBank->id,
            'current_balance' => 5800000,
            'initial_balance' => 6000000,
            'created_at' => now()->subDays(45),
            'updated_at' => now()
        ]);

        $cimbAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $cimbBank->id,
            'current_balance' => 2100000,
            'initial_balance' => 2100000,
            'created_at' => now()->subDays(20),
            'updated_at' => now()
        ]);

        // Allocations spread across banks
        AccountAllocation::create(['account_id' => $bniAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 3200000, 'allocation_date' => now()->subDays(60)]);
        AccountAllocation::create(['account_id' => $mandiriAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 5800000, 'allocation_date' => now()->subDays(45)]);
        AccountAllocation::create(['account_id' => $cimbAccount->id, 'type' => 'Darurat', 'balance_per_type' => 2100000, 'allocation_date' => now()->subDays(20)]);

        // Historical savings progression
        $this->createSavingsProgression($user, $mandiriAccount, 60);
        
        // Regular transactions focused on saving
        $this->createSavingsFocusedTransactions($user, $bniAccount, $mandiriAccount, 60);
        
        // Daily budgets
        $this->createDailyBudgets($user, $bniAccount, 60);

        // Savings-oriented goals
        $tabunganAllocation = AccountAllocation::where('account_id', $mandiriAccount->id)
            ->where('type', 'Tabungan')
            ->first();
        $daruratAllocation = AccountAllocation::where('account_id', $cimbAccount->id)
            ->where('type', 'Darurat')
            ->first();
            
        if ($tabunganAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Dana Pensiun',
                'target_amount' => 100000000,
                'target_deadline' => now()->addYears(5)->format('Y-m-d'),
                'is_first' => true,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(10, 55)),
                'updated_at' => now()
            ]);
            
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Beli Rumah',
                'target_amount' => 200000000,
                'target_deadline' => now()->addYears(3)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(10, 55)),
                'updated_at' => now()
            ]);
        }
        
        if ($daruratAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $daruratAllocation->id,
                'goal_name' => 'Dana Pendidikan Anak',
                'target_amount' => 80000000,
                'target_deadline' => now()->addYears(10)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(10, 55)),
                'updated_at' => now()
            ]);
        }

        // Award saver badges
        $badges = Badge::whereIn('name', ['Si Disiplin', 'Pendekar Hemat', 'Orang Sukses, aamiin'])->get();
        foreach ($badges as $badge) {
            UserBadge::create([
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'awarded_at' => now()->subDays(rand(10, 45)),
                'created_at' => now()->subDays(rand(10, 45)),
                'updated_at' => now()
            ]);
        }

        $this->command->info('💰 Created savings-focused user scenario: Maya Sari');
    }

    /**
     * Scenario 4: User yang rajin budgeting
     */
    private function createBudgetingUserScenario(): void
    {
        $user = User::create([
            'name' => 'Andi Wijaya',
            'email' => 'andi.wijaya@example.com',
            'password' => Hash::make('password'),
            'username' => 'andi_wijaya',
            'age' => 24,
            'status' => 'mahasiswa',
            'created_at' => now()->subDays(90),
            'updated_at' => now()
        ]);

        // Single account with detailed budgeting
        $permataBank = \App\Models\BankData::where('code_name', 'PERMATA')->first();
        if (!$permataBank) {
            $this->command->error('❌ BankData untuk PERMATA tidak ditemukan! Pastikan master data sudah ada.');
            return;
        }
        
        $permataAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $permataBank->id,
            'current_balance' => 4200000,
            'initial_balance' => 5000000,
            'created_at' => now()->subDays(90),
            'updated_at' => now()
        ]);

        // Single bank allocations
        AccountAllocation::create(['account_id' => $permataAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 2800000, 'allocation_date' => now()->subDays(90)]);
        AccountAllocation::create(['account_id' => $permataAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 1000000, 'allocation_date' => now()->subDays(90)]);
        AccountAllocation::create(['account_id' => $permataAccount->id, 'type' => 'Darurat', 'balance_per_type' => 400000, 'allocation_date' => now()->subDays(90)]);

        // Detailed budgeting with very consistent patterns
        $this->createDetailedBudgetTracking($user, $permataAccount, 90);
        
        // Very organized transactions
        $this->createOrganizedTransactions($user, $permataAccount, 90);

        // Budget-oriented goals
        $tabunganAllocation = AccountAllocation::where('account_id', $permataAccount->id)
            ->where('type', 'Tabungan')
            ->first();
            
        if ($tabunganAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Budget Bulanan 3 Juta',
                'target_amount' => 3000000,
                'target_deadline' => now()->endOfMonth()->format('Y-m-d'),
                'is_first' => true,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(15, 80)),
                'updated_at' => now()
            ]);
            
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Hemat Transportasi',
                'target_amount' => 500000,
                'target_deadline' => now()->endOfMonth()->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(15, 80)),
                'updated_at' => now()
            ]);
        }

        // Budget master badges
        $badges = Badge::whereIn('name', ['Si Rajin', 'Si Disiplin', 'Pendekar Hemat'])->get();
        foreach ($badges as $badge) {
            UserBadge::create([
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'awarded_at' => now()->subDays(rand(15, 70)),
                'created_at' => now()->subDays(rand(15, 70)),
                'updated_at' => now()
            ]);
        }

        $this->command->info('📊 Created budgeting-focused user scenario: Andi Wijaya');
    }

    /**
     * Scenario 5: User dengan banyak goals dan achievements
     */
    private function createGoalOrientedUserScenario(): void
    {
        $user = User::create([
            'name' => 'Lisa Kartika',
            'email' => 'lisa.kartika@example.com',
            'password' => Hash::make('password'),
            'username' => 'lisa_kartika',
            'age' => 23,
            'status' => 'mahasiswa',
            'created_at' => now()->subDays(120),
            'updated_at' => now()
        ]);

        // Multiple accounts dengan goals yang jelas
        $gopayBank = \App\Models\BankData::where('code_name', 'GOPAY')->first();
        $bcaBank = \App\Models\BankData::where('code_name', 'BCA')->first();
        
        if (!$gopayBank || !$bcaBank) {
            $this->command->error('❌ BankData untuk GOPAY atau BCA tidak ditemukan! Pastikan master data sudah ada.');
            return;
        }
        
        $gopayAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $gopayBank->id,
            'current_balance' => 2800000,
            'initial_balance' => 3200000,
            'created_at' => now()->subDays(120),
            'updated_at' => now()
        ]);

        $bcaAccount = Account::create([
            'user_id' => $user->id,
            'bank_id' => $bcaBank->id,
            'current_balance' => 8500000,
            'initial_balance' => 9000000,
            'created_at' => now()->subDays(100),
            'updated_at' => now()
        ]);

        // Allocations
        AccountAllocation::create(['account_id' => $gopayAccount->id, 'type' => 'Kebutuhan', 'balance_per_type' => 2800000, 'allocation_date' => now()->subDays(120)]);
        AccountAllocation::create(['account_id' => $bcaAccount->id, 'type' => 'Tabungan', 'balance_per_type' => 6000000, 'allocation_date' => now()->subDays(100)]);
        AccountAllocation::create(['account_id' => $bcaAccount->id, 'type' => 'Darurat', 'balance_per_type' => 2500000, 'allocation_date' => now()->subDays(100)]);

        // Multiple diverse goals
        $tabunganAllocation = AccountAllocation::where('account_id', $bcaAccount->id)
            ->where('type', 'Tabungan')
            ->first();
        $daruratAllocation = AccountAllocation::where('account_id', $bcaAccount->id)
            ->where('type', 'Darurat')
            ->first();
            
        if ($tabunganAllocation) {
            // Completed goals
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'iPhone 15 Pro Max',
                'target_amount' => 20000000,
                'target_deadline' => now()->subDays(30)->format('Y-m-d'),
                'is_first' => true,
                'is_goal_achieved' => true,
                'created_at' => now()->subDays(rand(30, 110)),
                'updated_at' => now()
            ]);
            
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Kursus Programming',
                'target_amount' => 5000000,
                'target_deadline' => now()->subDays(60)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => true,
                'created_at' => now()->subDays(rand(30, 110)),
                'updated_at' => now()
            ]);
            
            // Active goals
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Motor Yamaha NMAX',
                'target_amount' => 35000000,
                'target_deadline' => now()->addMonths(3)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(30, 110)),
                'updated_at' => now()
            ]);
            
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Investasi Saham',
                'target_amount' => 10000000,
                'target_deadline' => now()->addMonths(4)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(30, 110)),
                'updated_at' => now()
            ]);
            
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $tabunganAllocation->id,
                'goal_name' => 'Liburan Jepang',
                'target_amount' => 25000000,
                'target_deadline' => now()->addMonths(8)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(30, 110)),
                'updated_at' => now()
            ]);
        }
        
        if ($daruratAllocation) {
            Goal::create([
                'user_id' => $user->id,
                'account_allocation_id' => $daruratAllocation->id,
                'goal_name' => 'Laptop Gaming',
                'target_amount' => 15000000,
                'target_deadline' => now()->addMonths(6)->format('Y-m-d'),
                'is_first' => false,
                'is_goal_achieved' => false,
                'created_at' => now()->subDays(rand(30, 110)),
                'updated_at' => now()
            ]);
        }

        // Goal-oriented transactions
        $this->createGoalOrientedTransactions($user, $gopayAccount, $bcaAccount, 120);
        
        // Daily budgets
        $this->createDailyBudgets($user, $gopayAccount, 120);

        // Award all badges
        $allBadges = Badge::all();
        foreach ($allBadges as $badge) {
            UserBadge::create([
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'awarded_at' => now()->subDays(rand(20, 100)),
                'created_at' => now()->subDays(rand(20, 100)),
                'updated_at' => now()
            ]);
        }

        // Strong streaks
        Streak::create([
            'user_id' => $user->id,
            'current_streak' => 45,
            'longest_streak' => 67,
            'last_submit_date' => now()->format('Y-m-d'),
            'streak_status' => true,
            'created_at' => now()->subDays(67),
            'updated_at' => now()
        ]);

        // Extensive calendar logs
        $this->createCalendarLogs($user, 120);

        $this->command->info('🎯 Created goal-oriented user scenario: Lisa Kartika');
    }

    // Helper methods untuk create transactions dan data detail

    private function createSimpleTransactions($user, $account, $days): void
    {
        $categories = ExpenseCategories::all();
        
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Random income (setiap beberapa hari)
            if ($i % 7 == 0) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'amount' => rand(500000, 2000000),
                    'actual_amount' => rand(500000, 2000000),
                    'note' => 'Gaji bulanan',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Bulanan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Daily expenses
            if (rand(1, 100) <= 70) { // 70% chance of expense
                Expense::create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'expense_category_id' => $categories->random()->id,
                    'amount' => rand(15000, 150000),
                    'note' => 'Pengeluaran harian',
                    'expense_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }

    private function createExtensiveTransactions($user, $account1, $account2, $days): void
    {
        $categories = ExpenseCategories::all();
        
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Weekly income
            if ($i % 7 == 0) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $account1->id,
                    'amount' => rand(2000000, 5000000),
                    'actual_amount' => rand(2000000, 5000000),
                    'note' => 'Gaji dan bonus',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Bulanan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Freelance income (random)
            if (rand(1, 100) <= 20) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $account2->id,
                    'amount' => rand(500000, 1500000),
                    'actual_amount' => rand(500000, 1500000),
                    'note' => 'Proyek freelance',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Multiple daily expenses
            $expenseCount = rand(1, 4);
            for ($j = 0; $j < $expenseCount; $j++) {
                $expenseAccount = rand(1, 2) == 1 ? $account1 : $account2;
                Expense::create([
                    'user_id' => $user->id,
                    'account_id' => $expenseAccount->id,
                    'expense_category_id' => $categories->random()->id,
                    'amount' => rand(20000, 300000),
                    'note' => 'Berbagai pengeluaran',
                    'expense_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }

    private function createSavingsProgression($user, $savingsAccount, $days): void
    {
        // Create progressive savings transactions
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i);
            
            // Regular savings transfer setiap minggu
            if ($i % 7 == 0) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $savingsAccount->id,
                    'amount' => rand(200000, 500000),
                    'actual_amount' => rand(200000, 500000),
                    'note' => 'Transfer rutin ke tabungan',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Mingguan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Bonus savings (random)
            if (rand(1, 100) <= 15) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $savingsAccount->id,
                    'amount' => rand(100000, 1000000),
                    'actual_amount' => rand(100000, 1000000),
                    'note' => 'Bonus tabungan',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }

    private function createSavingsFocusedTransactions($user, $kebutuhanAccount, $tabunganAccount, $days): void
    {
        $categories = ExpenseCategories::all();
        
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Monthly salary
            if ($i % 30 == 0) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $kebutuhanAccount->id,
                    'amount' => 8000000,
                    'actual_amount' => 8000000,
                    'note' => 'Gaji bulanan',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Bulanan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);

                // Auto transfer to savings
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $tabunganAccount->id,
                    'amount' => 2000000,
                    'actual_amount' => 2000000,
                    'note' => 'Transfer otomatis ke tabungan',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Bulanan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Controlled daily expenses (karena fokus saving)
            if (rand(1, 100) <= 60) {
                Expense::create([
                    'user_id' => $user->id,
                    'account_id' => $kebutuhanAccount->id,
                    'expense_category_id' => $categories->random()->id,
                    'amount' => rand(25000, 120000),
                    'note' => 'Pengeluaran terkontrol',
                    'expense_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }

    private function createDetailedBudgetTracking($user, $account, $days): void
    {
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Detailed budget tracking setiap hari
            $remainingDays = $date->diffInDays($date->copy()->endOfMonth()) + 1;
            $dailyBudget = round(2800000 / $remainingDays, 0);
            $usedBudget = rand(0, $dailyBudget);
            
            Budget::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'daily_budget' => max(0, $dailyBudget - $usedBudget),
                'initial_daily_budget' => $dailyBudget,
                'daily_saving' => rand(0, 50000),
                'created_at' => $date,
                'updated_at' => $date
            ]);
        }

        // Organized transactions
        $categories = ExpenseCategories::all();
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Weekly income
            if ($i % 7 == 0) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'amount' => 1000000,
                    'actual_amount' => 1000000,
                    'note' => 'Gaji mingguan',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Mingguan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Very controlled expenses
            if (rand(1, 100) <= 80) {
                Expense::create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'expense_category_id' => $categories->random()->id,
                    'amount' => rand(15000, 80000), // Smaller, controlled amounts
                    'note' => 'Pengeluaran terencana',
                    'expense_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }

    private function createOrganizedTransactions($user, $account, $days): void
    {
        // Already handled in createDetailedBudgetTracking
    }

    private function createGoalOrientedTransactions($user, $account1, $account2, $days): void
    {
        $categories = ExpenseCategories::all();
        
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Bi-weekly income
            if ($i % 14 == 0) {
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $account1->id,
                    'amount' => rand(3000000, 6000000),
                    'actual_amount' => rand(3000000, 6000000),
                    'note' => 'Gaji bi-weekly',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Mingguan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);

                // Regular goal-oriented savings
                Income::create([
                    'user_id' => $user->id,
                    'account_id' => $account2->id,
                    'amount' => rand(500000, 1500000),
                    'actual_amount' => rand(500000, 1500000),
                    'note' => 'Saving untuk goals',
                    'received_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Mingguan',
                    'confirmation_status' => 'Confirmed',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }

            // Strategic expenses
            if (rand(1, 100) <= 75) {
                Expense::create([
                    'user_id' => $user->id,
                    'account_id' => $account1->id,
                    'expense_category_id' => $categories->random()->id,
                    'amount' => rand(30000, 200000),
                    'note' => 'Pengeluaran strategis',
                    'expense_date' => $date->format('Y-m-d'),
                    'is_manual' => true,
                    'frequency' => 'Sekali',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }

    private function createDailyBudgets($user, $account, $days): void
    {
        for ($i = 0; $i < min($days, 30); $i++) { // Max 30 days budget tracking
            $date = now()->subDays($i);
            
            // Calculate remaining days in month for that date
            $remainingDays = $date->diffInDays($date->copy()->endOfMonth()) + 1;
            $kebutuhanBalance = 800000; // Base amount
            $dailyBudget = $remainingDays > 0 ? round($kebutuhanBalance / $remainingDays, 0) : 0;
            
            // Random usage
            $usedAmount = rand(0, $dailyBudget);
            $remaining = max(0, $dailyBudget - $usedAmount);
            
            Budget::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'daily_budget' => $remaining,
                'initial_daily_budget' => $dailyBudget,
                'daily_saving' => rand(0, 20000),
                'created_at' => $date,
                'updated_at' => $date
            ]);
        }
    }

    private function createCalendarLogs($user, $days): void
    {
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i);
            
            // Budget tracking logs
            if (rand(1, 100) <= 80) { // 80% chance of log
                $plannedBudget = rand(50000, 200000);
                $actualExpense = rand(30000, $plannedBudget + 20000);
                $carryoverSaving = max(0, $plannedBudget - $actualExpense);
                
                CalendarLogs::create([
                    'user_id' => $user->id,
                    'date' => $date->format('Y-m-d'),
                    'planned_budget' => $plannedBudget,
                    'actual_expense' => $actualExpense,
                    'carryover_saving' => $carryoverSaving,
                    'is_ontrack' => $actualExpense <= $plannedBudget,
                    'note' => $actualExpense <= $plannedBudget ? 'Sesuai budget' : 'Over budget',
                    'created_at' => $date,
                    'updated_at' => $date
                ]);
            }
        }
    }
}