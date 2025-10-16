<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\Expense;
use App\Models\Account;
use App\Models\Goal;
use App\Models\CalendarLogs;
use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AchievmentController extends Controller
{
    /**
     * Get current datetime with proper timezone
     */
    private function now()
    {
        return Carbon::now(config('app.timezone', 'Asia/Jakarta'));
    }

    /**
     * Get all badges dan status untuk user
     */
    public function getUserBadges(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            // Get all badges
            $allBadges = Badge::all();

            // Get user's earned badges
            $userBadges = UserBadge::where('user_id', $userId)
                ->with('badge')
                ->get()
                ->keyBy('badge_id');

            // Format response dengan status earned/not earned
            $badgesList = $allBadges->map(function ($badge) use ($userBadges, $userId) {
                $isEarned = $userBadges->has($badge->id);
                $progress = $this->getBadgeProgress($badge, $userId);

                return [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'description' => $badge->description,
                    'icon' => $badge->icon,
                    'is_earned' => $isEarned,
                    'earned_at' => $isEarned ? $userBadges[$badge->id]->awarded_at : null,
                    'progress' => $progress,
                    'formatted' => [
                        'earned_date' => $isEarned ? $userBadges[$badge->id]->awarded_at->format('d M Y') : null,
                        'progress_text' => $this->getProgressText($badge, $progress)
                    ]
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'badges' => $badgesList,
                    'total_badges' => $allBadges->count(),
                    'earned_badges' => $userBadges->count(),
                    'completion_percentage' => $allBadges->count() > 0 ? 
                        round(($userBadges->count() / $allBadges->count()) * 100, 1) : 0
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving user badges: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check and award badges untuk user (dipanggil setelah aktivitas tertentu)
     */
    public function checkAndAwardBadges($userId, $activityType = null)
    {
        try {
            $user = User::find($userId);
            if (!$user) return [];

            $newBadges = [];

            // Check all badge types
            $newBadges = array_merge($newBadges, $this->checkStreakBadges($userId));
            $newBadges = array_merge($newBadges, $this->checkAccountBadges($userId));
            $newBadges = array_merge($newBadges, $this->checkSavingBadges($userId));
            $newBadges = array_merge($newBadges, $this->checkGoalBadges($userId));

            return $newBadges;

        } catch (\Exception $e) {
            \Log::error('Error checking badges: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check streak badges (consecutive days recording expenses)
     */
    private function checkStreakBadges($userId)
    {
        $newBadges = [];

        try {
            // Get current streak from calendar_logs or calculate from expenses
            $currentStreak = $this->calculateCurrentStreak($userId);

            // Badge requirements: 10, 30, 50, 100, 1000 days
            $streakBadges = [
                1 => 10,   // Si Rajin
                2 => 30,   // Si Disiplin
                3 => 50,   // Si Ahli
                4 => 100,  // Master Pengelola
                5 => 1000, // Legenda Cuanki
            ];

            foreach ($streakBadges as $badgeId => $requiredDays) {
                if ($currentStreak >= $requiredDays) {
                    $awarded = $this->awardBadgeIfNotExists($userId, $badgeId);
                    if ($awarded) {
                        $newBadges[] = Badge::find($badgeId);
                    }
                }
            }

        } catch (\Exception $e) {
            \Log::error('Error checking streak badges: ' . $e->getMessage());
        }

        return $newBadges;
    }

    /**
     * Check account badges (number of accounts)
     */
    private function checkAccountBadges($userId)
    {
        $newBadges = [];

        try {
            $accountCount = Account::where('user_id', $userId)->count();

            // Badge requirements: 2, 3 accounts
            $accountBadges = [
                6 => 2, // Calon Orang Sukses
                7 => 3, // Orang Sukses, aamiin
            ];

            foreach ($accountBadges as $badgeId => $requiredAccounts) {
                if ($accountCount >= $requiredAccounts) {
                    $awarded = $this->awardBadgeIfNotExists($userId, $badgeId);
                    if ($awarded) {
                        $newBadges[] = Badge::find($badgeId);
                    }
                }
            }

        } catch (\Exception $e) {
            \Log::error('Error checking account badges: ' . $e->getMessage());
        }

        return $newBadges;
    }

    /**
     * Check saving badges (monthly savings)
     */
    private function checkSavingBadges($userId)
    {
        $newBadges = [];

        try {
            // Calculate this month's savings
            $thisMonth = $this->now()->startOfMonth();
            $thisMonthSavings = $this->calculateMonthlySavings($userId, $thisMonth);

            // Badge requirement: 1 million savings in a month
            if ($thisMonthSavings >= 1000000) {
                $awarded = $this->awardBadgeIfNotExists($userId, 8); // Pendekar Hemat
                if ($awarded) {
                    $newBadges[] = Badge::find(8);
                }
            }

        } catch (\Exception $e) {
            \Log::error('Error checking saving badges: ' . $e->getMessage());
        }

        return $newBadges;
    }

    /**
     * Check goal badges (completed goals)
     */
    private function checkGoalBadges($userId)
    {
        $newBadges = [];

        try {
            $completedGoals = Goal::where('user_id', $userId)
                ->where('current_amount', '>=', DB::raw('target_amount'))
                ->count();

            // Badge requirement: 10 completed goals
            if ($completedGoals >= 10) {
                $awarded = $this->awardBadgeIfNotExists($userId, 9); // Si Paling Self Reward
                if ($awarded) {
                    $newBadges[] = Badge::find(9);
                }
            }

        } catch (\Exception $e) {
            \Log::error('Error checking goal badges: ' . $e->getMessage());
        }

        return $newBadges;
    }

    /**
     * Award badge if user doesn't have it yet
     */
    private function awardBadgeIfNotExists($userId, $badgeId)
    {
        $exists = UserBadge::where('user_id', $userId)
            ->where('badge_id', $badgeId)
            ->exists();

        if (!$exists) {
            UserBadge::create([
                'user_id' => $userId,
                'badge_id' => $badgeId,
                'awarded_at' => $this->now()
            ]);
            return true;
        }

        return false;
    }

    /**
     * Calculate current consecutive streak
     */
    private function calculateCurrentStreak($userId)
    {
        try {
            $today = $this->now()->toDateString();
            $streak = 0;
            $currentDate = $this->now();

            // Check backwards from today to find consecutive days with expenses
            for ($i = 0; $i < 1000; $i++) { // Max check 1000 days
                $checkDate = $currentDate->copy()->subDays($i)->toDateString();
                
                $hasExpense = Expense::where('user_id', $userId)
                    ->whereDate('expense_date', $checkDate)
                    ->exists();

                if ($hasExpense) {
                    $streak++;
                } else {
                    break;
                }
            }

            return $streak;

        } catch (\Exception $e) {
            \Log::error('Error calculating streak: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Calculate monthly savings
     */
    private function calculateMonthlySavings($userId, $monthStart)
    {
        try {
            $monthEnd = $monthStart->copy()->endOfMonth();

            // Get total income for the month
            $monthlyIncome = \App\Models\Income::where('user_id', $userId)
                ->whereBetween('received_date', [$monthStart, $monthEnd])
                ->sum('amount');

            // Get total expenses for the month
            $monthlyExpenses = Expense::where('user_id', $userId)
                ->whereBetween('expense_date', [$monthStart, $monthEnd])
                ->sum('amount');

            return $monthlyIncome - $monthlyExpenses;

        } catch (\Exception $e) {
            \Log::error('Error calculating monthly savings: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get badge progress for display
     */
    private function getBadgeProgress($badge, $userId)
    {
        switch ($badge->id) {
            case 1: // Si Rajin (10 days)
                $current = $this->calculateCurrentStreak($userId);
                return ['current' => $current, 'target' => 10];
            
            case 2: // Si Disiplin (30 days)
                $current = $this->calculateCurrentStreak($userId);
                return ['current' => $current, 'target' => 30];
            
            case 3: // Si Ahli (50 days)
                $current = $this->calculateCurrentStreak($userId);
                return ['current' => $current, 'target' => 50];
            
            case 4: // Master Pengelola (100 days)
                $current = $this->calculateCurrentStreak($userId);
                return ['current' => $current, 'target' => 100];
            
            case 5: // Legenda Cuanki (1000 days)
                $current = $this->calculateCurrentStreak($userId);
                return ['current' => $current, 'target' => 1000];
            
            case 6: // Calon Orang Sukses (2 accounts)
                $current = Account::where('user_id', $userId)->count();
                return ['current' => $current, 'target' => 2];
            
            case 7: // Orang Sukses (3 accounts)
                $current = Account::where('user_id', $userId)->count();
                return ['current' => $current, 'target' => 3];
            
            case 8: // Pendekar Hemat (1M savings)
                $current = $this->calculateMonthlySavings($userId, $this->now()->startOfMonth());
                return ['current' => $current, 'target' => 1000000];
            
            case 9: // Si Paling Self Reward (10 goals)
                $current = Goal::where('user_id', $userId)
                    ->where('current_amount', '>=', DB::raw('target_amount'))
                    ->count();
                return ['current' => $current, 'target' => 10];
            
            default:
                return ['current' => 0, 'target' => 1];
        }
    }

    /**
     * Get progress text for UI
     */
    private function getProgressText($badge, $progress)
    {
        $current = $progress['current'];
        $target = $progress['target'];

        if ($current >= $target) {
            return 'Completed!';
        }

        switch ($badge->id) {
            case 1:
            case 2:
            case 3:
            case 4:
            case 5:
                return $current . '/' . $target . ' hari berturut-turut';
            
            case 6:
            case 7:
                return $current . '/' . $target . ' rekening';
            
            case 8:
                return 'Rp ' . number_format($current, 0, ',', '.') . ' / Rp ' . number_format($target, 0, ',', '.');
            
            case 9:
                return $current . '/' . $target . ' goals selesai';
            
            default:
                return $current . '/' . $target;
        }
    }

    /**
     * Manual trigger untuk check badges (untuk testing)
     */
    public function triggerBadgeCheck(Request $request)
    {
        try {
            $user = $request->user();
            $newBadges = $this->checkAndAwardBadges($user->id);

            return response()->json([
                'status' => 'success',
                'message' => 'Badge check completed',
                'data' => [
                    'new_badges_awarded' => count($newBadges),
                    'new_badges' => $newBadges
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error checking badges: ' . $e->getMessage()
            ], 500);
        }
    }
}
