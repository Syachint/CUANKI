<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Expense;
use App\Models\AccountAllocation;
use Carbon\Carbon;

class AchievementController extends Controller
{
    /**
     * Get user badges with progress information
     */
    public function getUserBadges(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Get all badges with user progress
            $badges = Badge::all();
            $userBadges = UserBadge::where('user_id', $user->id)->get();
            
            $badgeData = $badges->map(function($badge) use ($userBadges, $user) {
                $userBadge = $userBadges->firstWhere('badge_id', $badge->id);
                
                return [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'description' => $badge->description,
                    'icon' => $badge->icon,
                    'earned' => $userBadge ? true : false,
                    'earned_at' => $userBadge ? $userBadge->awarded_at->format('Y-m-d H:i:s') : null,
                    'progress' => $this->calculateBadgeProgress($badge, $user)
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'User badges retrieved successfully',
                'data' => [
                    'badges' => $badgeData,
                    'total_badges' => $badges->count(),
                    'earned_badges' => $userBadges->count(),
                    'completion_percentage' => $badges->count() > 0 ? round(($userBadges->count() / $badges->count()) * 100, 2) : 0
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
     * Check and award badges based on user activities
     */
    public function checkAndAwardBadges(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return [
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ];
            }

            $newBadges = [];

            // Check streak badges
            $streakBadges = $this->checkStreakBadges($user);
            $newBadges = array_merge($newBadges, $streakBadges);

            // Check account badges
            $accountBadges = $this->checkAccountBadges($user);
            $newBadges = array_merge($newBadges, $accountBadges);

            // Check saving badges
            $savingBadges = $this->checkSavingBadges($user);
            $newBadges = array_merge($newBadges, $savingBadges);

            // Check goal badges
            $goalBadges = $this->checkGoalBadges($user);
            $newBadges = array_merge($newBadges, $goalBadges);

            return [
                'status' => 'success',
                'message' => 'Badge check completed',
                'new_badges' => $newBadges,
                'badges_earned' => count($newBadges)
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error checking badges: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check streak badges (Si Rajin, Si Disiplin, Master Pengelola, Legenda Cuanki)
     */
    private function checkStreakBadges($user)
    {
        $newBadges = [];
        $now = Carbon::now('Asia/Jakarta');
        
        // Calculate current streak
        $currentStreak = $this->calculateCurrentStreak($user);
        
        // Define streak badges with their requirements
        $streakBadges = [
            'Si Rajin' => 10,
            'Si Disiplin' => 30,
            'Si Ahli' => 50,
            'Master Pengelola' => 100,
            'Legenda Cuanki' => 1000
        ];

        foreach ($streakBadges as $badgeName => $requiredDays) {
            if ($currentStreak >= $requiredDays) {
                $badge = Badge::where('name', $badgeName)->first();
                if ($badge) {
                    $existingUserBadge = UserBadge::where('user_id', $user->id)
                        ->where('badge_id', $badge->id)
                        ->first();
                    
                    if (!$existingUserBadge) {
                        UserBadge::create([
                            'user_id' => $user->id,
                            'badge_id' => $badge->id,
                            'awarded_at' => $now
                        ]);
                        
                        $newBadges[] = [
                            'badge_id' => $badge->id,
                            'name' => $badge->name,
                            'description' => $badge->description,
                            'earned_at' => $now->format('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }

        return $newBadges;
    }

    /**
     * Check account badges (Calon Orang Sukses for 2 accounts, Orang Sukses aamiin for 3 accounts)
     */
    private function checkAccountBadges($user)
    {
        $newBadges = [];
        $now = Carbon::now('Asia/Jakarta');
        
        $accountCount = Account::where('user_id', $user->id)->count();
        
        // Define account badges with their requirements
        $accountBadges = [
            'Calon Orang Sukses' => 2,
            'Orang Sukses, aamiin' => 3
        ];

        foreach ($accountBadges as $badgeName => $requiredAccounts) {
            if ($accountCount >= $requiredAccounts) {
                $badge = Badge::where('name', $badgeName)->first();
                if ($badge) {
                    $existingUserBadge = UserBadge::where('user_id', $user->id)
                        ->where('badge_id', $badge->id)
                        ->first();
                    
                    if (!$existingUserBadge) {
                        UserBadge::create([
                            'user_id' => $user->id,
                            'badge_id' => $badge->id,
                            'awarded_at' => $now
                        ]);
                        
                        $newBadges[] = [
                            'badge_id' => $badge->id,
                            'name' => $badge->name,
                            'description' => $badge->description,
                            'earned_at' => $now->format('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }

        return $newBadges;
    }

    /**
     * Check saving badges (Si Hemat, Si Irit, Si Cermat)
     */
    private function checkSavingBadges($user)
    {
        $newBadges = [];
        $now = Carbon::now('Asia/Jakarta');
        
        // Get current month tabungan balance
        $tabunganBalance = AccountAllocation::whereHas('account', function($query) use ($user) {
            $query->where('user_id', $user->id);
        })->where('type', 'Tabungan')->sum('balance_per_type');
        
        // Define saving badges with their requirements
        $savingBadges = [
            'Pendekar Hemat' => 1000000 // 1 juta - badge untuk tabungan
        ];

        foreach ($savingBadges as $badgeName => $requiredAmount) {
            if ($tabunganBalance >= $requiredAmount) {
                $badge = Badge::where('name', $badgeName)->first();
                if ($badge) {
                    $existingUserBadge = UserBadge::where('user_id', $user->id)
                        ->where('badge_id', $badge->id)
                        ->first();
                    
                    if (!$existingUserBadge) {
                        UserBadge::create([
                            'user_id' => $user->id,
                            'badge_id' => $badge->id,
                            'awarded_at' => $now
                        ]);
                        
                        $newBadges[] = [
                            'badge_id' => $badge->id,
                            'name' => $badge->name,
                            'description' => $badge->description,
                            'earned_at' => $now->format('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }

        return $newBadges;
    }

    /**
     * Check goal badges (Si Pejuang Mimpi)
     */
    private function checkGoalBadges($user)
    {
        $newBadges = [];
        $now = Carbon::now('Asia/Jakarta');
        
        $completedGoals = Goal::where('user_id', $user->id)
            ->where('is_goal_achieved', true)
            ->count();
        
        if ($completedGoals >= 10) {
            $badge = Badge::where('name', 'Si Paling Self Reward')->first(); // Update dengan nama yang ada di DB
            if ($badge) {
                $existingUserBadge = UserBadge::where('user_id', $user->id)
                    ->where('badge_id', $badge->id)
                    ->first();
                
                if (!$existingUserBadge) {
                    UserBadge::create([
                        'user_id' => $user->id,
                        'badge_id' => $badge->id,
                        'awarded_at' => $now
                    ]);
                    
                    $newBadges[] = [
                        'badge_id' => $badge->id,
                        'name' => $badge->name,
                        'description' => $badge->description,
                        'earned_at' => $now->format('Y-m-d H:i:s')
                    ];
                }
            }
        }

        return $newBadges;
    }

    /**
     * Calculate current expense streak
     */
    private function calculateCurrentStreak($user)
    {
        $expenses = Expense::where('user_id', $user->id)
            ->orderBy('expense_date', 'desc')
            ->get()
            ->groupBy(function($expense) {
                return Carbon::parse($expense->expense_date)->format('Y-m-d');
            });

        $streak = 0;
        $currentDate = Carbon::now('Asia/Jakarta');
        
        // Check if user recorded expense today
        $todayKey = $currentDate->format('Y-m-d');
        if (!$expenses->has($todayKey)) {
            // If no expense today, check yesterday
            $currentDate->subDay();
        }

        // Count consecutive days with expenses
        while (true) {
            $dateKey = $currentDate->format('Y-m-d');
            if ($expenses->has($dateKey)) {
                $streak++;
                $currentDate->subDay();
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Calculate badge progress for display
     */
    private function calculateBadgeProgress($badge, $user)
    {
        switch ($badge->name) {
            case 'Si Rajin':
                $current = $this->calculateCurrentStreak($user);
                return ['current' => $current, 'required' => 10, 'percentage' => min(100, ($current / 10) * 100)];
            
            case 'Si Disiplin':
                $current = $this->calculateCurrentStreak($user);
                return ['current' => $current, 'required' => 30, 'percentage' => min(100, ($current / 30) * 100)];
            
            case 'Si Ahli':
                $current = $this->calculateCurrentStreak($user);
                return ['current' => $current, 'required' => 50, 'percentage' => min(100, ($current / 50) * 100)];
            
            case 'Master Pengelola':
                $current = $this->calculateCurrentStreak($user);
                return ['current' => $current, 'required' => 100, 'percentage' => min(100, ($current / 100) * 100)];
            
            case 'Legenda Cuanki':
                $current = $this->calculateCurrentStreak($user);
                return ['current' => $current, 'required' => 1000, 'percentage' => min(100, ($current / 1000) * 100)];
            
            case 'Calon Orang Sukses':
                $current = Account::where('user_id', $user->id)->count();
                return ['current' => $current, 'required' => 2, 'percentage' => min(100, ($current / 2) * 100)];
            
            case 'Orang Sukses, aamiin':
                $current = Account::where('user_id', $user->id)->count();
                return ['current' => $current, 'required' => 3, 'percentage' => min(100, ($current / 3) * 100)];
            
            case 'Pendekar Hemat':
                $current = AccountAllocation::whereHas('account', function($query) use ($user) {
                    $query->where('user_id', $user->id);
                })->where('type', 'Tabungan')->sum('balance_per_type');
                return ['current' => $current, 'required' => 1000000, 'percentage' => min(100, ($current / 1000000) * 100)];
            
            case 'Si Paling Self Reward':
                $current = Goal::where('user_id', $user->id)->where('is_goal_achieved', true)->count();
                return ['current' => $current, 'required' => 1, 'percentage' => min(100, ($current / 1) * 100)];
            
            default:
                return ['current' => 0, 'required' => 1, 'percentage' => 0];
        }
    }

    /**
     * Trigger badge check manually (for testing or manual triggering)
     */
    public function triggerBadgeCheck(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            $result = $this->checkAndAwardBadges($request);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Badge check completed successfully',
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error triggering badge check: ' . $e->getMessage()
            ], 500);
        }
    }
}