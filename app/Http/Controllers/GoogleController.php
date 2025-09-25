<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use App\Models\RefreshToken;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Str;
use Carbon\Carbon;

class GoogleController extends Controller
{
    public function googleLogin()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function googleAuthentication()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // dd($googleUser); // Ini kode lu, bisa lu hapus
            
            // Cari user berdasarkan Google ID
            $user = User::where('google_id', $googleUser->getId())->first();

            // Kalo user udah ada, langsung login
            if ($user) {
                return $this->generateTokensForUser($user);
            }
            
            // Kalo belum ada, cari user berdasarkan email
            $existingUser = User::where('email', $googleUser->getEmail())->first();

            if ($existingUser) {
                // Update user lama dengan Google ID
                $existingUser->update([
                    'google_id' => $googleUser->getId(),
                ]);
                return $this->generateTokensForUser($existingUser);
            }
            
            // Kalo bener-bener user baru, bikin akun baru
            $newUser = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'password' => bcrypt(Str::random(16)), // password acak
                //'status' => 'mahasiswa', // Pastikan kolom ini di `fillable`
            ]);
            
            return $this->generateTokensForUser($newUser);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate access and refresh tokens for user
     */
    private function generateTokensForUser($user)
    {
        // Hapus token Sanctum lama
        $user->tokens()->delete();

        $user->load('origin');
        
        // Bikin access token Sanctum baru
        $access_token = $user->createToken('access_token')->plainTextToken;

        // Hapus refresh token lama, lalu bikin baru
        RefreshToken::where('user_id', $user->id)->delete();
        $refresh_token = Str::random(64);
        RefreshToken::create([
            'user_id' => $user->id,
            'token' => $refresh_token,
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Google authentication successful',
            'data' => [
                'user' => $user,
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'token_type' => 'Bearer',
            ]
        ], 200);
    }
    /**
     * Redirect user to Google OAuth
     */
    // public function redirectToGoogle()
    // {
    //     return Socialite::driver('google')->redirect();
    // }

    /**
     * Handle Google OAuth callback
     */
    // public function handleGoogleCallback()
    // {
    //     try {
    //         // Get user info from Google
    //         $googleUser = Socialite::driver('google')->user();
            
    //         // Check if user already exists with this Google ID
    //         $user = User::where('google_id', $googleUser->getId())->first();
            
    //         if ($user) {
    //             // User exists, login
    //             return $this->generateTokensForUser($user);
    //         }
            
    //         // Check if user exists with same email
    //         $existingUser = User::where('email', $googleUser->getEmail())->first();
            
    //         if ($existingUser) {
    //             // Update existing user with Google ID
    //             $existingUser->update([
    //                 'google_id' => $googleUser->getId(),
    //             ]);
                
    //             return $this->generateTokensForUser($existingUser);
    //         }
            
    //         // Create new user
    //         $newUser = User::create([
    //             'name' => $googleUser->getName(),
    //             'email' => $googleUser->getEmail(),
    //             'google_id' => $googleUser->getId(),
    //             'password' => bcrypt(Str::random(16)), // Random password for Google users
    //             'status' => 'mahasiswa', // Default status
    //         ]);
            
    //         return $this->generateTokensForUser($newUser);
            
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Google authentication failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Generate access and refresh tokens for user
     */
    // private function generateTokensForUser($user)
    // {
    //     // Delete all existing tokens for this user
    //     $user->tokens()->delete();
        
    //     // Create new tokens
    //     $accessTokenExpiresAt = Carbon::now()->addMinutes(15);
    //     $refreshTokenExpiresAt = Carbon::now()->addDays(7);
        
    //     $accessToken = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
    //     $refreshToken = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;
        
    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Google authentication successful',
    //         'data' => [
    //             'user' => $user,
    //             'access_token' => $accessToken,
    //             'refresh_token' => $refreshToken,
    //             'token_type' => 'Bearer',
    //             'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
    //             'refresh_token_expires_at' => $refreshTokenExpiresAt->toDateTimeString()
    //         ]
    //     ], 200);
    // }

    /**
     * Login with Google (for mobile/API usage)
     * Expects Google ID token in request
     */
    // public function loginWithGoogle(Request $request)
    // {
    //     $request->validate([
    //         'id_token' => 'required|string',
    //     ]);

    //     try {
    //         // Verify Google ID token
    //         $client = new \Google_Client(['client_id' => config('services.google.client_id')]);
    //         $payload = $client->verifyIdToken($request->id_token);
            
    //         if (!$payload) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'Invalid Google ID token'
    //             ], 401);
    //         }
            
    //         $googleId = $payload['sub'];
    //         $email = $payload['email'];
    //         $name = $payload['name'];
            
    //         // Find or create user
    //         $user = User::where('google_id', $googleId)->first();
            
    //         if (!$user) {
    //             $existingUser = User::where('email', $email)->first();
                
    //             if ($existingUser) {
    //                 $existingUser->update(['google_id' => $googleId]);
    //                 $user = $existingUser;
    //             } else {
    //                 $user = User::create([
    //                     'name' => $name,
    //                     'email' => $email,
    //                     'google_id' => $googleId,
    //                     'password' => bcrypt(Str::random(16)),
    //                     'status' => 'mahasiswa',
    //                 ]);
    //             }
    //         }
            
    //         return $this->generateTokensForUser($user);
            
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Google authentication failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
}
