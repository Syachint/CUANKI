<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UserFinancePlan;
use App\Models\AccountAllocation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log; // Tambahkan Log untuk debugging

class AdviceController extends Controller
{
    // Menggunakan model yang lebih stabil sesuai kode lu
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent"; 
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
                        $result['text'] = $candidate['content']['parts'][0]['text'];
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
