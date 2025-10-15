<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Goal;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\Income;
use App\Models\Expense;
use App\Models\UserFinancePlan;
use Carbon\Carbon;

class GoalController extends Controller
{
    /**
     * Helper method to get current time in Asia/Jakarta timezone
     */
    private function now()
    {
        return Carbon::now('Asia/Jakarta');
    }

    /**
     * Get graphic rate for savings (Tabungan) balance history
     * Shows the ups and downs of balance_per_type for "Tabungan" type
     */
    public function getGoalGraphicRate(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get period parameter (default: last 12 months)
            $period = $request->get('period', '12months');
            $endDate = $this->now();
            
            // Calculate start date based on period
            switch ($period) {
                case '3months':
                    $startDate = $endDate->copy()->subMonths(3);
                    $dateFormat = 'M';
                    break;
                case '6months':
                    $startDate = $endDate->copy()->subMonths(6);
                    $dateFormat = 'M';
                    break;
                case '1year':
                case '12months':
                default:
                    $startDate = $endDate->copy()->subMonths(12);
                    $dateFormat = 'M';
                    break;
            }

            // Get user's accounts
            $accountIds = Account::where('user_id', $userId)->pluck('id');

            if ($accountIds->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No accounts found for user',
                    'data' => [
                        'period' => $period,
                        'chart_data' => [],
                        'summary' => [
                            'current_balance' => 0,
                            'highest_balance' => 0,
                            'lowest_balance' => 0,
                            'total_growth' => 0,
                            'growth_percentage' => 0
                        ]
                    ]
                ], 200);
            }

            // Get historical data for "Tabungan" type from account_allocation
            $savingsHistory = AccountAllocation::whereIn('account_id', $accountIds)
                ->where('type', 'Tabungan')
                ->whereBetween('allocation_date', [
                    $startDate->toDateString(), 
                    $endDate->toDateString()
                ])
                ->orderBy('allocation_date', 'asc')
                ->get()
                ->groupBy(function($item) {
                    return Carbon::parse($item->allocation_date)->format('Y-m');
                });

            // Prepare chart data
            $chartData = [];
            $monthlyData = [];
            
            // Generate all months in the period
            $currentMonth = $startDate->copy()->startOfMonth();
            while ($currentMonth <= $endDate) {
                $monthKey = $currentMonth->format('Y-m');
                $monthLabel = $currentMonth->format($dateFormat);
                
                // Get data for this month
                if ($savingsHistory->has($monthKey)) {
                    $monthAllocations = $savingsHistory[$monthKey];
                    
                    // Get the latest balance for this month
                    $latestAllocation = $monthAllocations->sortByDesc('allocation_date')->first();
                    $balance = $latestAllocation ? (float) $latestAllocation->balance_per_type : 0;
                } else {
                    // If no data for this month, use previous month's balance or 0
                    $balance = end($monthlyData)['balance'] ?? 0;
                }

                $monthlyData[] = [
                    'month' => $monthLabel,
                    'month_full' => $currentMonth->format('F Y'),
                    'date' => $currentMonth->format('Y-m-d'),
                    'balance' => $balance,
                    'formatted_balance' => 'Rp ' . number_format($balance, 0, ',', '.'),
                ];

                $chartData[] = [
                    'x' => $monthLabel,
                    'y' => $balance,
                    'date' => $currentMonth->format('Y-m-d'),
                    'month_name' => $currentMonth->format('F Y'),
                    'formatted_value' => 'Rp ' . number_format($balance, 0, ',', '.')
                ];

                $currentMonth->addMonth();
            }

            // Calculate summary statistics
            $balances = array_column($monthlyData, 'balance');
            $currentBalance = end($balances) ?: 0;
            $firstBalance = reset($balances) ?: 0;
            $highestBalance = !empty($balances) ? max($balances) : 0;
            $lowestBalance = !empty($balances) ? min($balances) : 0;
            $totalGrowth = $currentBalance - $firstBalance;
            $growthPercentage = $firstBalance > 0 ? (($totalGrowth / $firstBalance) * 100) : 0;

            // Get current month's detailed data for hover info
            $currentMonthKey = $endDate->format('Y-m');
            $currentMonthAllocations = $savingsHistory->get($currentMonthKey, collect());
            
            $hoverData = $currentMonthAllocations->map(function($allocation) {
                $date = Carbon::parse($allocation->allocation_date);
                return [
                    'date' => $date->format('Y-m-d'),
                    'formatted_date' => $date->format('d M Y'),
                    'balance' => (float) $allocation->balance_per_type,
                    'formatted_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.'),
                ];
            })->values();

            return response()->json([
                'status' => 'success',
                'message' => 'Goal graphic rate retrieved successfully',
                'data' => [
                    'period' => $period,
                    'period_info' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d'),
                        'formatted_period' => $startDate->format('M Y') . ' - ' . $endDate->format('M Y')
                    ],
                    'chart_data' => $chartData,
                    'monthly_data' => $monthlyData,
                    'hover_data' => $hoverData,
                    'summary' => [
                        'current_balance' => $currentBalance,
                        'first_balance' => $firstBalance,
                        'highest_balance' => $highestBalance,
                        'lowest_balance' => $lowestBalance,
                        'total_growth' => $totalGrowth,
                        'growth_percentage' => round($growthPercentage, 2),
                        'formatted' => [
                            'current_balance' => 'Rp ' . number_format($currentBalance, 0, ',', '.'),
                            'first_balance' => 'Rp ' . number_format($firstBalance, 0, ',', '.'),
                            'highest_balance' => 'Rp ' . number_format($highestBalance, 0, ',', '.'),
                            'lowest_balance' => 'Rp ' . number_format($lowestBalance, 0, ',', '.'),
                            'total_growth' => 'Rp ' . number_format($totalGrowth, 0, ',', '.'),
                            'growth_percentage' => ($totalGrowth >= 0 ? '+' : '') . round($growthPercentage, 2) . '%'
                        ]
                    ],
                    'metadata' => [
                        'total_accounts' => $accountIds->count(),
                        'data_points' => count($chartData),
                        'has_data' => !empty($chartData),
                        'timezone' => 'Asia/Jakarta'
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving goal graphic rate: ' . $e->getMessage(),
                'debug' => [
                    'period' => $request->get('period', '12months'),
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Get main goal progress showing current savings vs target
     * Current amount from balance_per_type "Tabungan", target from UserFinancePlan saving_target_amount
     */
    public function getMainGoalProgress(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get user's finance plan for saving target
            $userFinancePlan = UserFinancePlan::where('user_id', $userId)->first();

            if (!$userFinancePlan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User finance plan not found. Please complete your financial planning first.'
                ], 404);
            }

            // Get saving target from user finance plan
            $savingTargetAmount = $userFinancePlan->saving_target_amount * $userFinancePlan->saving_target_duration * 12;
            
            if (!$savingTargetAmount || $savingTargetAmount <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Saving target amount not set. Please update your financial plan.'
                ], 400);
            }

            // Get user's accounts
            $accountIds = Account::where('user_id', $userId)->pluck('id');

            if ($accountIds->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No accounts found for user',
                    'data' => [
                        'goal_name' => 'Tabungan Utama',
                        'current_amount' => 0,
                        'target_amount' => $savingTargetAmount,
                        'progress_percentage' => 0,
                        'remaining_amount' => $savingTargetAmount,
                        'is_achieved' => false,
                        'formatted' => [
                            'current_amount' => 'Rp 0',
                            'target_amount' => 'Rp ' . number_format($savingTargetAmount, 0, ',', '.'),
                            'remaining_amount' => 'Rp ' . number_format($savingTargetAmount, 0, ',', '.'),
                            'progress_text' => 'Rp 0/Rp ' . number_format($savingTargetAmount, 0, ',', '.')
                        ]
                    ]
                ], 200);
            }

            // Get current savings balance from AccountAllocation for type "Tabungan"
            $currentSavingsBalance = AccountAllocation::whereIn('account_id', $accountIds)
                ->where('type', 'Tabungan')
                ->orderBy('allocation_date', 'desc')
                ->orderBy('updated_at', 'desc')
                ->first();

            $currentAmount = $currentSavingsBalance ? (float) $currentSavingsBalance->balance_per_type : 0;

            // Calculate progress
            $progressPercentage = $savingTargetAmount > 0 ? ($currentAmount / $savingTargetAmount) * 100 : 0;
            $progressPercentage = min($progressPercentage, 100); // Cap at 100%
            
            $remainingAmount = max(0, $savingTargetAmount - $currentAmount);
            $isAchieved = $currentAmount >= $savingTargetAmount;

            // Get additional insights
            $overAchievement = $currentAmount > $savingTargetAmount ? $currentAmount - $savingTargetAmount : 0;
            
            // Calculate how much more needed per month if target duration is available
            $monthlyNeeded = 0;
            $monthsRemaining = 0;
            
            if (isset($userFinancePlan->saving_target_duration) && $userFinancePlan->saving_target_duration > 0) {
                $totalMonths = $userFinancePlan->saving_target_duration * 12; // Convert years to months
                $startDate = Carbon::parse($userFinancePlan->created_at);
                $targetDate = $startDate->copy()->addMonths($totalMonths);
                $now = $this->now();
                
                $monthsRemaining = max(0, $now->diffInMonths($targetDate, false));
                
                if ($monthsRemaining > 0 && !$isAchieved) {
                    $monthlyNeeded = (float) $userFinancePlan->saving_target_amount;
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Main goal retrieved successfully',
                'data' => [
                    'goal_name' => 'Goals utama kamu:',
                    'goal_title' => 'Tabunganmu',
                    'current_amount' => $currentAmount,
                    'target_amount' => (float) $savingTargetAmount,
                    'progress_percentage' => round($progressPercentage, 1),
                    'remaining_amount' => $remainingAmount,
                    'over_achievement' => $overAchievement,
                    'is_achieved' => $isAchieved,
                    'months_remaining' => $monthsRemaining,
                    'monthly_needed' => $monthlyNeeded,
                    'duration_info' => [
                        'target_duration_years' => $userFinancePlan->saving_target_duration ?? null,
                        'months_remaining' => $monthsRemaining,
                        'is_on_track' => $monthsRemaining > 0 ? ($currentAmount / ($userFinancePlan->saving_target_duration * 12 - $monthsRemaining + 1)) >= ($savingTargetAmount / ($userFinancePlan->saving_target_duration * 12)) : true
                    ],
                    'formatted' => [
                        'current_amount' => 'Rp' . number_format($currentAmount, 0, ',', '.'),
                        'target_amount' => 'Rp' . number_format($savingTargetAmount, 0, ',', '.'),
                        'remaining_amount' => 'Rp' . number_format($remainingAmount, 0, ',', '.'),
                        'over_achievement' => $overAchievement > 0 ? 'Rp' . number_format($overAchievement, 0, ',', '.') : null,
                        'progress_text' => 'Rp' . number_format($currentAmount, 0, ',', '.') . '/Rp' . number_format($savingTargetAmount, 0, ',', '.'),
                        'progress_percentage' => round($progressPercentage, 1) . '%',
                        'monthly_needed' => $monthlyNeeded > 0 ? 'Rp' . number_format($monthlyNeeded, 0, ',', '.') : null,
                        'status' => $isAchieved ? 'Target Tercapai! 🎉' : ($progressPercentage >= 80 ? 'Hampir Tercapai! 💪' : ($progressPercentage >= 50 ? 'Setengah Jalan! 📈' : 'Tetap Semangat! 🚀'))
                    ],
                    'insights' => [
                        'is_on_target' => $progressPercentage >= 80,
                        'needs_boost' => $progressPercentage < 50,
                        'achievement_level' => $progressPercentage >= 100 ? 'excellent' : ($progressPercentage >= 80 ? 'good' : ($progressPercentage >= 50 ? 'moderate' : 'needs_improvement')),
                        'recommendation' => $isAchieved ? 
                            'Selamat! Target sudah tercapai. Pertimbangkan untuk menaikkan target atau mulai goal baru.' :
                            ($monthlyNeeded > 0 ? 
                                "Untuk mencapai target, perlu menabung Rp" . number_format($monthlyNeeded, 0, ',', '.') . " per bulan." :
                                'Terus konsisten menabung untuk mencapai target Anda.'
                            )
                    ],
                    'metadata' => [
                        'last_updated' => $currentSavingsBalance ? $currentSavingsBalance->updated_at->format('Y-m-d H:i:s') : null,
                        'accounts_count' => $accountIds->count(),
                        'has_savings_data' => $currentSavingsBalance !== null,
                        'timezone' => 'Asia/Jakarta'
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving main goal: ' . $e->getMessage(),
                'debug' => [
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Create a new goal
     * POST /api/goals
     */
    public function createGoal(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Validate input
            $validated = $request->validate([
                'goal_name' => 'required|string|max:255',
                'target_amount' => 'required|numeric|min:1',
                'target_deadline' => 'nullable|date|after:today',
                'account_allocation_id' => 'required|integer|exists:accounts_allocation,id'
            ]);

            // Check if user owns the account allocation
            $accountAllocation = AccountAllocation::with('account')
                ->where('id', $validated['account_allocation_id'])
                ->first();

            if (!$accountAllocation || $accountAllocation->account->user_id !== $userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account allocation not found or access denied'
                ], 403);
            }

            // Check if allocation is type "Tabungan"
            if ($accountAllocation->type !== 'Tabungan') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Goals can only be created for "Tabungan" type allocations'
                ], 400);
            }

            // Create goal
            $goal = Goal::create([
                'user_id' => $userId,
                'account_allocation_id' => $validated['account_allocation_id'],
                'goal_name' => $validated['goal_name'],
                'target_amount' => $validated['target_amount'],
                'target_deadline' => $validated['target_deadline'] ?? null,
                'is_first' => false,
                'is_goal_achieved' => false,
            ]);

            // Load relationships
            $goal->load('accountAllocation.account.bank');

            $currentAmount = $goal->getCurrentAmount();
            $progressPercentage = $goal->getProgressPercentage();

            return response()->json([
                'status' => 'success',
                'message' => 'Goal created successfully',
                'data' => [
                    'goal' => [
                        'id' => $goal->id,
                        'goal_name' => $goal->goal_name,
                        'target_amount' => (float) $goal->target_amount,
                        'current_amount' => $currentAmount,
                        'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('Y-m-d') : null,
                        'progress_percentage' => round($progressPercentage, 1),
                        'is_achieved' => $goal->isAchieved(),
                        'remaining_amount' => max(0, $goal->target_amount - $currentAmount),
                        'account_info' => [
                            'allocation_id' => $goal->account_allocation_id,
                            'allocation_type' => $accountAllocation->type,
                            'account_name' => $accountAllocation->account->account_name,
                            'bank_name' => $accountAllocation->account->bank->bank_name ?? 'Unknown Bank'
                        ],
                        'formatted' => [
                            'target_amount' => 'Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                            'current_amount' => 'Rp ' . number_format($currentAmount, 0, ',', '.'),
                            'progress_text' => 'Rp ' . number_format($currentAmount, 0, ',', '.') . '/Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                            'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('d M Y') : 'Tanpa tenggat waktu',
                            'status' => $goal->isAchieved() ? 'Target Tercapai! 🎉' : 'Dalam Progress 📈'
                        ],
                        'created_at' => $goal->created_at->format('Y-m-d H:i:s')
                    ]
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error creating goal: ' . $e->getMessage(),
                'debug' => [
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Get all goals for user
     * GET /api/goals
     */
    public function getAllGoals(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get query parameters
            $status = $request->get('status'); // 'achieved', 'in_progress', 'all'
            $sortBy = $request->get('sort_by', 'created_at'); // 'created_at', 'target_deadline', 'progress'
            $sortOrder = $request->get('sort_order', 'desc'); // 'asc', 'desc'

            // Base query
            $query = Goal::with(['accountAllocation.account.bank'])
                ->where('user_id', $userId);

            // Apply status filter
            if ($status === 'achieved') {
                // We'll filter this after getting results since isAchieved is calculated
            } elseif ($status === 'in_progress') {
                // We'll filter this after getting results since isAchieved is calculated
            }

            // Apply sorting
            switch ($sortBy) {
                case 'target_deadline':
                    $query->orderByRaw('target_deadline IS NULL, target_deadline ' . $sortOrder);
                    break;
                case 'target_amount':
                    $query->orderBy('target_amount', $sortOrder);
                    break;
                case 'goal_name':
                    $query->orderBy('goal_name', $sortOrder);
                    break;
                case 'created_at':
                default:
                    $query->orderBy('created_at', $sortOrder);
                    break;
            }

            $goals = $query->get();

            // Apply status filter and calculate progress
            $formattedGoals = $goals->map(function ($goal) {
                $currentAmount = $goal->getCurrentAmount();
                $progressPercentage = $goal->getProgressPercentage();
                $isAchieved = $goal->isAchieved();

                return [
                    'id' => $goal->id,
                    'goal_name' => $goal->goal_name,
                    'target_amount' => (float) $goal->target_amount,
                    'current_amount' => $currentAmount,
                    'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('Y-m-d') : null,
                    'progress_percentage' => round($progressPercentage, 1),
                    'is_achieved' => $isAchieved,
                    'remaining_amount' => max(0, $goal->target_amount - $currentAmount),
                    'days_remaining' => $goal->target_deadline ? max(0, $this->now()->diffInDays($goal->target_deadline, false)) : null,
                    'account_info' => [
                        'allocation_id' => $goal->account_allocation_id,
                        'allocation_type' => $goal->accountAllocation->type,
                        'account_name' => $goal->accountAllocation->account->account_name,
                        'bank_name' => $goal->accountAllocation->account->bank->bank_name ?? 'Unknown Bank'
                    ],
                    'formatted' => [
                        'target_amount' => 'Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                        'current_amount' => 'Rp ' . number_format($currentAmount, 0, ',', '.'),
                        'progress_text' => 'Rp ' . number_format($currentAmount, 0, ',', '.') . '/Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                        'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('d M Y') : 'Tanpa tenggat waktu',
                        'status' => $isAchieved ? 'Target Tercapai! 🎉' : 'Dalam Progress 📈',
                        'progress_percentage' => round($progressPercentage, 1) . '%'
                    ],
                    'created_at' => $goal->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $goal->updated_at->format('Y-m-d H:i:s')
                ];
            });

            // Apply status filter after calculation
            if ($status === 'achieved') {
                $formattedGoals = $formattedGoals->filter(function ($goal) {
                    return $goal['is_achieved'];
                });
            } elseif ($status === 'in_progress') {
                $formattedGoals = $formattedGoals->filter(function ($goal) {
                    return !$goal['is_achieved'];
                });
            }

            // Apply progress sorting if requested
            if ($sortBy === 'progress') {
                $formattedGoals = $sortOrder === 'desc' 
                    ? $formattedGoals->sortByDesc('progress_percentage')
                    : $formattedGoals->sortBy('progress_percentage');
            }

            // Calculate summary
            $totalGoals = $formattedGoals->count();
            $achievedGoals = $formattedGoals->where('is_achieved', true)->count();
            $inProgressGoals = $totalGoals - $achievedGoals;

            return response()->json([
                'status' => 'success',
                'message' => 'Goals retrieved successfully',
                'data' => [
                    'goals' => $formattedGoals->values(),
                    'summary' => [
                        'total_goals' => $totalGoals,
                        'achieved_goals' => $achievedGoals,
                        'in_progress_goals' => $inProgressGoals,
                        'achievement_rate' => $totalGoals > 0 ? round(($achievedGoals / $totalGoals) * 100, 1) : 0
                    ],
                    'filters' => [
                        'status' => $status ?? 'all',
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving goals: ' . $e->getMessage(),
                'debug' => [
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Get specific goal by ID
     * GET /api/goals/{id}
     */
    public function getGoal(Request $request, $id)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Find goal with relationships
            $goal = Goal::with(['accountAllocation.account.bank'])
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$goal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Goal not found'
                ], 404);
            }

            $currentAmount = $goal->getCurrentAmount();
            $progressPercentage = $goal->getProgressPercentage();
            $isAchieved = $goal->isAchieved();

            return response()->json([
                'status' => 'success',
                'message' => 'Goal retrieved successfully',
                'data' => [
                    'goal' => [
                        'id' => $goal->id,
                        'goal_name' => $goal->goal_name,
                        'target_amount' => (float) $goal->target_amount,
                        'current_amount' => $currentAmount,
                        'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('Y-m-d') : null,
                        'progress_percentage' => round($progressPercentage, 1),
                        'is_achieved' => $isAchieved,
                        'remaining_amount' => max(0, $goal->target_amount - $currentAmount),
                        'over_achievement' => $currentAmount > $goal->target_amount ? $currentAmount - $goal->target_amount : 0,
                        'days_remaining' => $goal->target_deadline ? max(0, $this->now()->diffInDays($goal->target_deadline, false)) : null,
                        'is_overdue' => $goal->target_deadline ? $this->now()->gt($goal->target_deadline) && !$isAchieved : false,
                        'account_info' => [
                            'allocation_id' => $goal->account_allocation_id,
                            'allocation_type' => $goal->accountAllocation->type,
                            'account_name' => $goal->accountAllocation->account->account_name,
                            'bank_name' => $goal->accountAllocation->account->bank->bank_name ?? 'Unknown Bank',
                            'current_balance' => $goal->accountAllocation->balance_per_type
                        ],
                        'formatted' => [
                            'target_amount' => 'Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                            'current_amount' => 'Rp ' . number_format($currentAmount, 0, ',', '.'),
                            'remaining_amount' => 'Rp ' . number_format(max(0, $goal->target_amount - $currentAmount), 0, ',', '.'),
                            'progress_text' => 'Rp ' . number_format($currentAmount, 0, ',', '.') . '/Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                            'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('d M Y') : 'Tanpa tenggat waktu',
                            'status' => $isAchieved ? 'Target Tercapai! 🎉' : 'Dalam Progress 📈',
                            'progress_percentage' => round($progressPercentage, 1) . '%'
                        ],
                        'created_at' => $goal->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $goal->updated_at->format('Y-m-d H:i:s')
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving goal: ' . $e->getMessage(),
                'debug' => [
                    'goal_id' => $id,
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Update goal
     * PUT /api/goals/{id}
     */
    public function updateGoal(Request $request, $id)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Find goal
            $goal = Goal::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$goal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Goal not found'
                ], 404);
            }

            // Validate input
            $validated = $request->validate([
                'goal_name' => 'sometimes|required|string|max:255',
                'target_amount' => 'sometimes|required|numeric|min:1',
                'target_deadline' => 'sometimes|nullable|date|after:today',
            ]);

            // Update goal
            $goal->update($validated);

            // Reload with relationships
            $goal->load('accountAllocation.account.bank');

            $currentAmount = $goal->getCurrentAmount();
            $progressPercentage = $goal->getProgressPercentage();

            return response()->json([
                'status' => 'success',
                'message' => 'Goal updated successfully',
                'data' => [
                    'goal' => [
                        'id' => $goal->id,
                        'goal_name' => $goal->goal_name,
                        'target_amount' => (float) $goal->target_amount,
                        'current_amount' => $currentAmount,
                        'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('Y-m-d') : null,
                        'progress_percentage' => round($progressPercentage, 1),
                        'is_achieved' => $goal->isAchieved(),
                        'remaining_amount' => max(0, $goal->target_amount - $currentAmount),
                        'formatted' => [
                            'target_amount' => 'Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                            'current_amount' => 'Rp ' . number_format($currentAmount, 0, ',', '.'),
                            'progress_text' => 'Rp ' . number_format($currentAmount, 0, ',', '.') . '/Rp ' . number_format($goal->target_amount, 0, ',', '.'),
                            'target_deadline' => $goal->target_deadline ? $goal->target_deadline->format('d M Y') : 'Tanpa tenggat waktu',
                            'status' => $goal->isAchieved() ? 'Target Tercapai! 🎉' : 'Dalam Progress 📈'
                        ],
                        'updated_at' => $goal->updated_at->format('Y-m-d H:i:s')
                    ]
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating goal: ' . $e->getMessage(),
                'debug' => [
                    'goal_id' => $id,
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Delete goal
     * DELETE /api/goals/{id}
     */
    public function deleteGoal(Request $request, $id)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Find goal
            $goal = Goal::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$goal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Goal not found'
                ], 404);
            }

            $goalName = $goal->goal_name;
            $goal->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Goal deleted successfully',
                'data' => [
                    'deleted_goal' => [
                        'id' => $id,
                        'goal_name' => $goalName
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error deleting goal: ' . $e->getMessage(),
                'debug' => [
                    'goal_id' => $id,
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }

    /**
     * Get available account allocations for creating goals (only Tabungan type)
     * GET /api/goals/available-allocations
     */
    public function getAvailableAllocations(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Get user's accounts
            $accountIds = Account::where('user_id', $userId)->pluck('id');

            if ($accountIds->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No accounts found',
                    'data' => ['allocations' => []]
                ], 200);
            }

            // Get Tabungan allocations
            $allocations = AccountAllocation::with(['account.bank'])
                ->whereIn('account_id', $accountIds)
                ->where('type', 'Tabungan')
                ->get();

            $formattedAllocations = $allocations->map(function ($allocation) {
                return [
                    'allocation_id' => $allocation->id,
                    'account_id' => $allocation->account_id,
                    'account_name' => $allocation->account->account_name,
                    'bank_name' => $allocation->account->bank->bank_name ?? 'Unknown Bank',
                    'type' => $allocation->type,
                    'current_balance' => (float) $allocation->balance_per_type,
                    'formatted_balance' => 'Rp ' . number_format($allocation->balance_per_type, 0, ',', '.'),
                    'last_updated' => $allocation->updated_at->format('Y-m-d H:i:s')
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Available allocations retrieved successfully',
                'data' => [
                    'allocations' => $formattedAllocations
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving available allocations: ' . $e->getMessage(),
                'debug' => [
                    'user_id' => $user->id ?? 'unknown'
                ]
            ], 500);
        }
    }
}
