<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Goal;
use App\Models\Account;
use App\Models\AccountAllocation;
use App\Models\Income;
use App\Models\Expense;
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
}
