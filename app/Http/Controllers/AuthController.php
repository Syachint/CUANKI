<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Origin;
use App\Models\Account;
use App\Models\RefreshToken;
use App\Models\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;


class AuthController extends Controller
{
    /**
     * Get current datetime with proper timezone
     */
    private function now()
    {
        return Carbon::now(config('app.timezone', 'Asia/Jakarta'));
    }

    function getOriginsList() {
        $origins = Origin::all();
        return response()->json($origins);
    }

    function registerUser(Request $request) {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 401);
        }

        $data = $request->all();
        $data['name'] = $data['first_name'] . ' ' . $data['last_name'];
        $data['password'] = bcrypt($data['password']);
        $user = User::create($data);

        // Buat access token
        $accessTokenExpiresAt = $this->now()->addMinutes(15);
        $access_token = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
        
        // Buat refresh token
        $refreshTokenExpiresAt = $this->now()->addDays(7);
        $refresh_token = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

        // Set refresh token ke cookies untuk register juga
        $response = response()->json([
            'status' => 'success',
            'message' => 'Lanjut step 2',
            'data' => [
                'user' => $user,
                'access_token' => $access_token,
                'token_type' => 'Bearer',
                'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
            ]
        ], 201);

        // Set refresh token sebagai httpOnly cookie (fixed parameter count)
        setcookie(
            'refresh_token',                     // name
            $refresh_token,                      // value
            $refreshTokenExpiresAt->getTimestamp(), // expires
            '/',                                 // path
            config('session.domain'),            // domain  
            config('session.secure', false),     // secure
            true                                 // httponly (parameter ke-7)
        );

        return $response;
    }

    function loginUser(Request $request) {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        // Cek apakah email ada di database
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email tidak terdaftar, silahkan register terlebih dahulu',
                'code' => 'EMAIL_NOT_FOUND'
            ], 404);
        }

        if (auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $user = Auth::user();

            $user->load('origin');

            // Hapus semua token lama
            $user->tokens()->delete();
            RefreshToken::where('user_id', $user->id)->delete();

            // Buat access token
            $accessTokenExpiresAt = $this->now()->addMinutes(15);
            $access_token = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
            
            // Buat refresh token menggunakan Sanctum
            $refreshTokenExpiresAt = $this->now()->addDays(7);
            $refresh_token = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

            // Set refresh token ke cookies (httpOnly untuk keamanan)
            $response = response()->json([
                'status' => 'success',
                'message' => 'Login berhasil',
                'data' => [
                    'user' => $user,
                    'access_token' => $access_token,
                    'token_type' => 'Bearer',
                    'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
                ]
            ], 200);

            // Set refresh token sebagai cookie (fixed parameter count)
            setcookie(
                'refresh_token',                     // name
                $refresh_token,                      // value
                $refreshTokenExpiresAt->getTimestamp(), // expires
                '/',                                 // path
                config('session.domain'),            // domain
                config('session.secure', false),     // secure
                true                                 // httponly (parameter ke-7)
            );

            return $response;
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Password salah',
                'code' => 'INVALID_PASSWORD'
            ], 401);
        }
    }

    function refreshToken(Request $request) {
        // Ambil refresh token dari cookies
        $currentRefreshToken = $request->cookie('refresh_token');
        
        if (!$currentRefreshToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Refresh token not found in cookies'
            ], 401);
        }

        // Cari refresh token di personal access tokens
        $refreshToken = PersonalAccessToken::findToken($currentRefreshToken);

        if (!$refreshToken || !$refreshToken->can('refresh') || $refreshToken->expires_at->isPast()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired refresh token'
            ], 401);
        }

        $user = $refreshToken->tokenable;
        
        // Hapus refresh token lama
        $refreshToken->delete();

        // Buat token baru
        $accessTokenExpiresAt = $this->now()->addMinutes(15);
        $refreshTokenExpiresAt = $this->now()->addDays(7);

        $newAccessToken = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
        $newRefreshToken = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

        // Response dengan access token baru dan set refresh token ke cookies
        $response = response()->json([
            'status' => 'success',
            'message' => 'Token refreshed successfully',
            'data' => [
                'access_token' => $newAccessToken,
                'token_type' => 'Bearer',
                'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
            ]
        ]);

        // Set refresh token baru ke cookies (fixed parameter count)
        setcookie(
            'refresh_token',                     // name
            $newRefreshToken,                    // value
            $refreshTokenExpiresAt->getTimestamp(), // expires
            '/',                                 // path
            config('session.domain'),            // domain
            config('session.secure', false),     // secure
            true                                 // httponly (parameter ke-7)
        );

        return $response;
    }

    public function logout(Request $request)
    {
        // Hapus semua tokens user
        $request->user()->tokens()->delete();
        
        // Response dengan menghapus refresh token cookie
        $response = response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ], 200);

        // Hapus refresh token cookie dengan mengset expired
        $response->withCookie(cookie()->forget('refresh_token'));

        return $response;
    }
}
