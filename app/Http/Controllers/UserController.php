<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\RefreshToken;
use App\Models\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UserController extends Controller
{
    function registerUser1(Request $request) {
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

        $user->load('origin');

        // Buat access token
        $accessTokenExpiresAt = Carbon::now()->addMinutes(15);
        $access_token = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
        
        // Buat refresh token
        $refreshTokenExpiresAt = Carbon::now()->addDays(7);
        $refresh_token = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Lanjut step 2',
            'data' => [
                'user' => $user,
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'token_type' => 'Bearer',
                'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
                'refresh_token_expires_at' => $refreshTokenExpiresAt->toDateTimeString()
            ]
        ], 201);
    }

    function registerUser2(Request $request) {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'username' => 'required|string|max:255',
            'age' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 401);
        }

        $user = User::find($request->user_id);
        if (!$user) {
            return response()->json([
                'st atus' => 'error',
                'message' => 'User tidak ditemukan',
            ], 404);
        }
        $user->username = $request->username;
        $user->age = $request->age;
        $user->save();

        $user->load('origin');

        return response()->json([
            'status' => 'success',
            'message' => 'Lanjut step 3',
            'data' => $user
        ], 201);
    }

    function registerUser3(Request $request) {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'origin_id' => 'nullable|integer',
            'status' => 'required|in:mahasiswa,pelajar',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 401);
        }

        $user = User::find($request->user_id);
        $user->origin_id = $request->origin_id;
        $user->status = $request->status;
        $user->save();

        $user->load('origin');

        return response()->json([
            'status' => 'success',
            'message' => 'Lanjut step 4',
            'data' => $user
        ], 201);
    }

    function loginUser(Request $request) {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $user = Auth::user();

            $user->load('origin');

            // Hapus semua token lama
            $user->tokens()->delete();
            RefreshToken::where('user_id', $user->id)->delete();

            // Buat access token
            $accessTokenExpiresAt = Carbon::now()->addMinutes(15);
            $access_token = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
            
            // Buat refresh token menggunakan Sanctum
            $refreshTokenExpiresAt = Carbon::now()->addDays(7);
            $refresh_token = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login berhasil',
                'data' => [
                    'user' => $user,
                    'access_token' => $access_token,
                    'refresh_token' => $refresh_token,
                    'token_type' => 'Bearer',
                    'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
                    'refresh_token_expires_at' => $refreshTokenExpiresAt->toDateTimeString()
                ]
            ], 200);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Email atau password salah'
            ], 401);
        }
    }

    function refreshToken(Request $request) {
        // Ambil refresh token dari header Authorization
        $currentRefreshToken = $request->bearerToken();
        
        if (!$currentRefreshToken) {
            return response()->json(['error' => 'Refresh token not provided'], 401);
        }

        // Cari refresh token di personal access tokens
        $refreshToken = PersonalAccessToken::findToken($currentRefreshToken);

        if (!$refreshToken || !$refreshToken->can('refresh') || $refreshToken->expires_at->isPast()) {
            return response()->json(['error' => 'Invalid or expired refresh token'], 401);
        }

        $user = $refreshToken->tokenable;
        
        // Hapus refresh token lama
        $refreshToken->delete();

        // Buat token baru
        $accessTokenExpiresAt = Carbon::now()->addMinutes(15);
        $refreshTokenExpiresAt = Carbon::now()->addDays(7);

        $newAccessToken = $user->createToken('access_token', ['access-api'], $accessTokenExpiresAt)->plainTextToken;
        $newRefreshToken = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Token refreshed successfully',
            'data' => [
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'token_type' => 'Bearer',
                'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
                'refresh_token_expires_at' => $refreshTokenExpiresAt->toDateTimeString()
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully'], 200);
    }
}
