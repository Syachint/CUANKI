<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UserFinancePlan;
use App\Models\AccountAllocation;
use App\Controllers\AchievementController;
use App\Controllers\UserController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log; // Tambahkan Log untuk debugging

class AdviceController extends Controller
{
    // Menggunakan model yang lebih stabil sesuai kode lu
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent"; 
    private $maxRetries = 3;
    private $backoffBase = 1000; // 1000ms (1 detik)

    public function __construct()
    {
        // PENTING: Hapus $this->apiKey karena lu pakai config() di fetchGeminiResponse()
        // Kita biarkan constructor kosong atau untuk logic lain
    }

    /**
     * Mengumpulkan data user dan meminta Gemini API untuk menganalisis dan memberikan 3 kartu saran finansial.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAdvices(Request $request)
    {
        $user = $request->user();
        
        // 1. Ambil Semua Data User dengan eager loading
        $plan = UserFinancePlan::where('user_id', $user->id)->first();
        
        // Load user dengan relasi origin
        $user->load('origin');
        
        $userData = [
            'username' => $user->username ?? $user->name ?? 'User', // Fallback ke name jika username kosong
            'age' => $user->age ?? 'tidak diketahui',
            'origin' => $user->origin ? $user->origin->city_province : 'Indonesia',
        ];

        // Debug log untuk memastikan data user benar
        if (config('app.debug')) {
            Log::info('User Data Debug:', [
                'user_id' => $user->id,
                'origin_id' => $user->origin_id,
                'username' => $userData['username'],
                'origin' => $userData['origin'],
                'age' => $userData['age'],
                'has_origin_relation' => !is_null($user->origin),
                'origin_data' => $user->origin ? [
                    'id' => $user->origin->id,
                    'city_province' => $user->origin->city_province
                ] : null
            ]);
        }

        if (!$plan) {
            return response()->json(['status' => 'error', 'message' => 'Rencana keuangan belum diisi, tidak dapat memberikan analisis.'], 400);
        }

        // Ambil Data Kebutuhan/Target dari Finance Plan
        $financeData = [
            'income' => (float) $plan->monthly_income,
            'saving_target' => (float) $plan->saving_target_amount,
            'emergency_target' => (float) $plan->emergency_target_amount ?? 0,
            'saving_duration' => $plan->saving_target_duration,
        ];
        
        // Hitung Alokasi Kebutuhan Bersih (Income - Saving - Emergency)
        $kebutuhanAmount = $financeData['income'] - $financeData['saving_target'] - $financeData['emergency_target'];
        $financeData['kebutuhan_amount'] = max(0, $kebutuhanAmount);

        // 2. Tentukan 3 Prompt Sesuai Kebutuhan Kartu
        $prompts = [
            'daily_budget' => [
                'title' => 'Analisis Anggaran Harian',
                'prompt' => $this->buildDailyBudgetPrompt($userData, $financeData),
            ],
            'saving_goal' => [
                'title' => 'Strategi Pencapaian Tabungan',
                'prompt' => $this->buildSavingGoalPrompt($financeData),
            ],
            'financial_security' => [
                'title' => 'Indeks Keamanan Finansial',
                'prompt' => $this->buildFinancialSecurityPrompt($userData, $financeData),
            ],
        ];

        $results = [];

        // 3. Panggil Gemini API untuk setiap prompt (Looping)
        foreach ($prompts as $key => $card) {
            // Debug log untuk melihat prompt yang dikirim
            if (config('app.debug')) {
                Log::info('Prompt Debug for ' . $key . ':', [
                    'origin' => $userData['origin'],
                    'username' => $userData['username'],
                    'prompt' => substr($card['prompt'], 0, 200) . '...'
                ]);
            }
            
            $geminiResponse = $this->fetchGeminiResponse($card['prompt'], $userData['username']);
            
            // Tambahkan hasil ke array
            $results[] = [
                'id' => $key,
                'title' => $card['title'],
                'content' => $geminiResponse['text'],
                'sources' => $geminiResponse['sources'],
            ];
        }

        // 4. Kirim Respon ke Frontend
        return response()->json([
            'status' => 'success',
            'message' => 'Analisis finansial berhasil dibuat oleh AI.',
            'cards' => $results
        ]);
    }

    /**
     * Get AI-powered contextual reminders for specific pages
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReminder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'required|in:transaction,asset,goals',
            'context' => 'nullable|array' // Additional context data per page
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid page parameter',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = $request->user();
        $page = $request->input('page');
        $context = $request->input('context', []);

        try {
            // Collect contextual data based on page
            $contextualData = $this->collectContextualData($user, $page, $context);
            
            // Generate AI prompt based on page and data
            $prompt = $this->buildReminderPrompt($page, $contextualData, $user);
            
            // Get AI response
            $aiResponse = $this->fetchGeminiResponse($prompt, $user->username ?? $user->name);
            
            return response()->json([
                'status' => 'success',
                'message' => 'AI reminder generated successfully',
                'data' => [
                    'page' => $page,
                    'reminder' => $aiResponse['text'],
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate reminder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Collect contextual data based on page type
     */
    private function collectContextualData($user, $page, $context)
    {
        $data = [
            'user_info' => [
                'username' => $user->username ?? $user->name,
                'age' => $user->age,
                'status' => $user->status // mahasiswa/pelajar
            ],
            'analysis' => [],
            'priority' => 'medium',
            'suggestions' => []
        ];

        switch ($page) {
            case 'transaction':
                $data = array_merge($data, $this->analyzeTransactionContext($user, $context));
                break;
                
            case 'asset':
                $data = array_merge($data, $this->analyzeAssetContext($user, $context));
                break;
                
            case 'goals':
                $data = array_merge($data, $this->analyzeGoalsContext($user, $context));
                break;
        }

        return $data;
    }

    /**
     * Analyze transaction page context
     */
    private function analyzeTransactionContext($user, $context)
    {
        // Import required models
        $todayExpenses = \App\Models\Expense::where('user_id', $user->id)
                            ->whereDate('expense_date', today())
                            ->sum('amount');
        
        $monthlyExpenses = \App\Models\Expense::where('user_id', $user->id)
                            ->whereMonth('expense_date', now()->month)
                            ->whereYear('expense_date', now()->year)
                            ->sum('amount');

        $lastExpense = \App\Models\Expense::where('user_id', $user->id)
                            ->latest('expense_date')
                            ->first();

        // Calculate streak for transaction context
        $streak = app(\App\Http\Controllers\AchievementController::class)->calculateCurrentStreak($user);

            // Priority 1: Cari budget daily dari tabel Budget
    $budget = \App\Models\Budget::where('user_id', $user->id)
                ->whereNotNull('daily_budget')
                ->first();
    
    if ($budget && $budget->initial_daily_budget > 0) {
        $dailyBudget = $budget->initial_daily_budget;
    } else {
        // Priority 2: Fallback ke AccountAllocation spending_limit
        $totalSpendingLimit = \App\Models\AccountAllocation::join('accounts', 'account_allocations.account_id', '=', 'accounts.id')
                                ->where('accounts.user_id', $user->id)
                                ->where('account_allocations.type', 'Kebutuhan')
                                ->sum('account_allocations.spending_limit');
        
        if ($totalSpendingLimit > 0) {
            $dailyBudget = $totalSpendingLimit / 30; // Convert monthly to daily
        }
    }

        $analysis = [
            'today_spending' => $todayExpenses,
            'monthly_spending' => $monthlyExpenses,
            'daily_budget' => $dailyBudget,
            'over_budget_today' => $todayExpenses > $dailyBudget,
            'streak_days' => $streak,
            'last_transaction' => $lastExpense ? $lastExpense->expense_date : null,
            'spending_pattern' => $this->analyzeSpendingPattern($user)
        ];

        // Determine priority based on spending behavior
        $priority = 'medium';
        if ($todayExpenses > $dailyBudget * 1.5) {
            $priority = 'high';
        } elseif ($streak >= 7) {
            $priority = 'low'; // Good habit
        }

        $suggestions = $this->generateTransactionSuggestions($analysis);

        return [
            'analysis' => $analysis,
            'priority' => $priority,
            'suggestions' => $suggestions
        ];
    }

    /**
     * Analyze asset page context
     */
    private function analyzeAssetContext($user, $context)
    {
        $accounts = \App\Models\Account::where('user_id', $user->id)->count();
        $allocations = \App\Models\AccountAllocation::whereHas('account', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->get();

        $totalBalance = $allocations->sum('balance_per_type');
        $savingsBalance = $allocations->where('type', 'Tabungan')->sum('balance_per_type');
        $emergencyBalance = $allocations->where('type', 'Darurat')->sum('balance_per_type');

        $financePlan = \App\Models\UserFinancePlan::where('user_id', $user->id)->first();
        $monthlyIncome = $financePlan ? $financePlan->monthly_income : 0;

        $analysis = [
            'account_count' => $accounts,
            'total_balance' => $totalBalance,
            'savings_balance' => $savingsBalance,
            'emergency_balance' => $emergencyBalance,
            'monthly_income' => $monthlyIncome,
            'emergency_fund_months' => $monthlyIncome > 0 ? round($emergencyBalance / $monthlyIncome, 1) : 0,
            'savings_rate' => $monthlyIncome > 0 ? round(($savingsBalance / $monthlyIncome) * 100, 1) : 0,
            'account_diversity' => $this->analyzeAccountDiversity($user)
        ];

        $priority = 'medium';
        if ($analysis['emergency_fund_months'] < 3) {
            $priority = 'high';
        } elseif ($analysis['savings_rate'] > 20) {
            $priority = 'low'; // Good savings rate
        }

        $suggestions = $this->generateAssetSuggestions($analysis);

        return [
            'analysis' => $analysis,
            'priority' => $priority,
            'suggestions' => $suggestions
        ];
    }

    /**
     * Analyze goals page context
     */
    private function analyzeGoalsContext($user, $context)
    {
        $goals = \App\Models\Goal::where('user_id', $user->id)->get();
        $activeGoals = $goals->where('is_goal_achieved', false);
        $completedGoals = $goals->where('is_goal_achieved', true);

        $financePlan = \App\Models\UserFinancePlan::where('user_id', $user->id)->first();
        $monthlySavings = $financePlan ? $financePlan->saving_target_amount : 0;

        $analysis = [
            'total_goals' => $goals->count(),
            'active_goals' => $activeGoals->count(),
            'completed_goals' => $completedGoals->count(),
            'monthly_savings_target' => $monthlySavings,
            'goal_completion_rate' => $goals->count() > 0 ? round(($completedGoals->count() / $goals->count()) * 100, 1) : 0,
            'nearest_deadline' => $this->findNearestGoalDeadline($activeGoals),
            'goal_progress' => $this->analyzeGoalProgress($activeGoals)
        ];

        $priority = 'medium';
        if ($analysis['active_goals'] == 0) {
            $priority = 'high'; // No active goals
        } elseif ($analysis['goal_completion_rate'] > 50) {
            $priority = 'low'; // Good goal achievement
        }

        $suggestions = $this->generateGoalSuggestions($analysis);

        return [
            'analysis' => $analysis,
            'priority' => $priority,
            'suggestions' => $suggestions
        ];
    }

    /**
     * Build AI prompt based on page and contextual data
     * 
     * PLACEHOLDER - You'll implement the actual prompts here
     */
    private function buildReminderPrompt($page, $contextualData, $user)
    {
        $username = $user->username ?? $user->name ?? 'User';
        $basePrompt = "Kamu adalah penasihat keuangan pribadi. Berikan analisis yang ringkas, objektif, dan menggunakan bahasa gaul Indonesia yang sopan dan ramah (aku-kamu). Jangan berikan judul atau poin-poin, jangan memakai text style Bold atau yang lain, cukup text biasa saja. Cukup berikan paragraf analisis. Sapa user dengan nama: " . $username;

        switch ($page) {
            case 'transaction':
                $todaySpending = number_format($contextualData['analysis']['today_spending'], 0, ',', '.');
                $dailyBudget = number_format($contextualData['analysis']['daily_budget'], 0, ',', '.');
                $streak = $contextualData['analysis']['streak_days'];
                $overBudget = $contextualData['analysis']['over_budget_today'];
                $priority = $contextualData['priority'];
                
                return $basePrompt . "
                Kamu adalah penasihat keuangan pribadi, dengan pengeluaran sehari segini Rp {$todaySpending} dan anggaran harian Rp {$dailyBudget} apakah sudah efektif?(jika " . ($overBudget ? "OVER BUDGET!" : "okee amannn") . " anggaran hari ini), Ini adalah streak dia {$streak} hari, jadi berikan semangat untuk selalu menyalakan streaknya. Response harus motivatif dan asik(max 150 kata) dalam bahasa Indonesia.
                ";
                
            case 'asset':
                $accountCount = $contextualData['analysis']['account_count'];
                $emergencyMonths = $contextualData['analysis']['emergency_fund_months'];
                $savingsRate = $contextualData['analysis']['savings_rate'];
                $totalBalance = number_format($contextualData['analysis']['total_balance'], 0, ',', '.');
                $priority = $contextualData['priority'];
                
                return $basePrompt . " 
                Hai {$username}! Kamu lagi cek asset nih. Kondisi keuangan kamu: Jumlah akun {$accountCount} akun, Dana darurat Rp {$emergencyMonths} bulan dari penghasilan, Tingkat tabungan Rp {$savingsRate}% dari income, Total saldo Rp {$totalBalance}, tampilkan semua nominalnya. Berikan reminder tentang kesehatan finansial berdasarkan data di atas: Jika dana darurat kurang dari 3 bulan sarankan prioritas emergency fund, Jika cuma 1 akun motivasi diversifikasi account, Jika savings rate lebih dari 20% kasih pujian good job, Jika savings rate kurang dari 10% motivasi untuk tingkatkan tabungan. Berikan reminder dalam 1-2 paragraf yang actionable dan supportive maksimal 150 kata dalam bahasa Indonesia.
                ";
                
            case 'goals':
                $activeGoals = $contextualData['analysis']['active_goals'];
                $completionRate = $contextualData['analysis']['goal_completion_rate'];
                $monthlySavings = number_format($contextualData['analysis']['monthly_savings_target'], 0, ',', '.');
                $totalGoals = $contextualData['analysis']['total_goals'];
                $completedGoals = $contextualData['analysis']['completed_goals'];
                $priority = $contextualData['priority'];
                
                return $basePrompt . "
                Kamu adalah penasihat keuangan pribadi, dengan " . $activeGoals . " goal aktif dari total " . $totalGoals . " goals. Tingkat completion rate kamu " . $completionRate . "% (udah selesai " . $completedGoals . " goals). Target nabung bulanan untuk goals: Rp " . $monthlySavings . ". Prioritas: " . $priority . ". Berikan motivasi tentang pencapaian goals, evaluasi progress, dan strategi untuk boost goal achievement. Response harus inspiring dan actionable (max 150 kata) dalam bahasa Indonesia.
                ";
                
            default:
                return $basePrompt . "Berikan motivasi umum untuk mengelola keuangan dengan baik.";
        }
    }

    // Helper methods (implement these based on your needs)
    private function analyzeSpendingPattern($user) { return 'normal'; }
    private function generateTransactionSuggestions($analysis) { return []; }
    private function analyzeAccountDiversity($user) { return 'balanced'; }
    private function generateAssetSuggestions($analysis) { return []; }
    private function findNearestGoalDeadline($goals) { return null; }
    private function analyzeGoalProgress($goals) { return []; }
    private function generateGoalSuggestions($analysis) { return []; }

    /**
     * Membuat prompt untuk Kartu 1: Daily Budget
     */
    private function buildDailyBudgetPrompt(array $userData, array $financeData): string
    {
        // Gunakan origin yang tepat sesuai data user
        $location = $userData['origin'];
        return "Act as a professional financial advisor. Based on the user's location of " . $location . " and their monthly spending money of Rp " . number_format($financeData['kebutuhan_amount'], 0, ',', '.') . ", calculate the user's approximate daily budget and compare it to the typical cost of living in " . $location . ". (but in indonesia i think we can expenses 45-60k per day for eating)Provide advice on whether this monthly budget is sufficient for living in " . $location . ". The response must be a single, concise paragraph (max 100 words) in Indonesian.";
    }

    /**
     * Membuat prompt untuk Kartu 2: Saving Goal
     */
    private function buildSavingGoalPrompt(array $financeData): string
    {
        return "Act as a financial consultant. A user saves Rp " . number_format($financeData['saving_target'], 0, ',', '.') . " monthly with a target duration of " . $financeData['saving_duration'] . " years. Calculate the total expected amount saved (excluding interest) and provide a concise analysis of this saving discipline and suggestions for improving it. The response must be a single, concise paragraph (max 100 words) in Indonesian.";
    }

    /**
     * Membuat prompt untuk Kartu 3: Financial Security
     */
    private function buildFinancialSecurityPrompt(array $userData, array $financeData): string
    {
        return "Act as a financial planner. A user's monthly income is Rp " . number_format($financeData['income'], 0, ',', '.') . " and they have an emergency fund target of Rp " . number_format($financeData['emergency_target'], 0, ',', '.') . ". Analyze their financial security based on their age (" . $userData['age'] . ") and income. Suggest an ideal emergency fund multiple (e.g., 6x monthly expenses) and evaluate if their current target is adequate. The response must be a single, concise paragraph (max 100 words) in Indonesian.";
    }

    /**
     * Memanggil Gemini API dengan logic exponential backoff.
     */
    private function fetchGeminiResponse(string $prompt, string $username): array
    {
        // Level Up Fix: Ambil key dari config Laravel (asumsi lu sudah set .env dan config/services.php)
        // Jika config('services.gemini.api_key') tidak bekerja di env ini, ganti dengan API Key lu
        $apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
        
        if (!$apiKey) {
            return [
                'text' => 'Maaf ' . $username . ', konfigurasi API AI belum diatur. Hubungi admin!',
                'sources' => []
            ];
        }
        
        $url = $this->apiUrl . '?key=' . $apiKey;
        
        // Debug log URL (hanya di development)
        if (config('app.debug')) {
            Log::info('Gemini API URL:', ['url' => $this->apiUrl, 'has_key' => !empty($apiKey)]);
        }
        $systemPrompt = "Kamu adalah penasihat keuangan pribadi. Berikan analisis yang ringkas, objektif, dan menggunakan bahasa gaul Indonesia yang sopan dan ramah (aku-kamu). Jangan berikan judul atau poin-poin. Cukup berikan paragraf analisis. Sapa user dengan nama: " . $username;
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt . "\n\n" . $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 1000,
                'topP' => 0.8,
                'topK' => 40,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH', 
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];

        // Pesan Fallback menggunakan nama user yang dinamis
        $result = [
            'text' => 'Maaf, lagi ada gangguan di server AI. Status: UNKNOWN. Coba lagi ya, ' . $username . '!', 
            'sources' => []
        ];
        
        $lastStatusCode = 'UNKNOWN'; // Variable baru untuk debugging

        for ($i = 0; $i < $this->maxRetries; $i++) {
            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                                ->timeout(30)
                                ->post($url, $payload);
                                
                $lastStatusCode = $response->status(); // Capture status code
                
                if ($response->successful()) {
                    $data = $response->json();
                    
                    // Debug log response structure
                    if (config('app.debug')) {
                        Log::info('Gemini API Response:', ['data' => $data]);
                    }
                    
                    $candidate = $data['candidates'][0] ?? null;

                    if ($candidate && isset($candidate['content']['parts'][0]['text'])) {
                        $result['text'] = trim($candidate['content']['parts'][0]['text']);
                        $result['sources'] = []; // Simplified - tidak pakai grounding sources
                        return $result;
                    } else {
                        // Log response structure untuk debugging
                        if (config('app.debug')) {
                            Log::warning('Unexpected Gemini response structure:', ['candidate' => $candidate]);
                        }
                    }
                } else {
                    // Log error response for debugging
                    $errorBody = $response->body();
                    if (config('app.debug')) {
                        Log::error('Gemini API Error Response:', [
                            'status' => $lastStatusCode,
                            'body' => $errorBody,
                            'payload' => $payload
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $lastStatusCode = 'EXCEPTION';
                if (config('app.debug')) {
                    Log::error('Gemini API Exception: ' . $e->getMessage());
                }
            }

            // Exponential Backoff
            if ($i < $this->maxRetries - 1) {
                usleep($this->backoffBase * pow(2, $i) * 1000); // Wait time in microseconds
            }
        }
        
        // Update fallback message with status code
        $result['text'] = 'Maaf ' . $username . ', gagal menghubungi AI. Status error terakhir: ' . $lastStatusCode . '. Coba lagi ya!';

        return $result;
    }
}
