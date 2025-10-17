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
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserController extends Controller
{
    /**
     * Get current user profile data
     */
    public function getUserProfile(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Load user with origin relationship
            $user->load('origin');

            return response()->json([
                'status' => 'success',
                'message' => 'User profile retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'full_name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'age' => $user->age,
                        'status' => $user->status,
                        'origin_id' => $user->origin_id,
                        'origin_name' => $user->origin ? $user->origin->city_province : null,
                        'profile_picture' => $user->profile_picture,
                        'email_verified_at' => $user->email_verified_at,
                        'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $user->updated_at->format('Y-m-d H:i:s')
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving user profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user profile data
     */
    public function updateUserProfile(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Validation rules
            $validator = Validator::make($request->all(), [
                'full_name' => 'sometimes|string|max:255|unique:users,full_name,' . $user->id,
                'username' => 'sometimes|string|max:50|unique:users,username,' . $user->id,
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'age' => 'sometimes|integer|min:1|max:150',
                'status' => 'sometimes|string|in:Pelajar,Mahasiswa,Pekerja,Pengangguran,Lainnya',
                'origin_id' => 'sometimes|exists:origins,id',
                'current_password' => 'sometimes|string|min:6',
                'new_password' => 'sometimes|string|min:6|confirmed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check current password if changing password
            if ($request->has('new_password')) {
                if (!$request->has('current_password')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Current password is required to change password'
                    ], 422);
                }

                if (!Hash::check($request->current_password, $user->password)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Current password is incorrect'
                    ], 422);
                }
            }

            // Store old data for comparison
            $oldData = [
                'full_name' => $user->full_name,
                'email' => $user->email,
                'age' => $user->age,
                'status' => $user->status,
                'origin_id' => $user->origin_id
            ];

            // Update fields that are provided
            $updateData = [];
            $changedFields = [];

            if ($request->has('full_name') && $request->full_name !== $user->full_name) {
                $updateData['full_name'] = $request->full_name;
                $changedFields['full_name'] = ['old' => $user->full_name, 'new' => $request->full_name];
            }

            if ($request->has('email') && $request->email !== $user->email) {
                $updateData['email'] = $request->email;
                $updateData['email_verified_at'] = null; // Reset email verification
                $changedFields['email'] = ['old' => $user->email, 'new' => $request->email];
            }

            if ($request->has('age') && $request->age != $user->age) {
                $updateData['age'] = $request->age;
                $changedFields['age'] = ['old' => $user->age, 'new' => $request->age];
            }

            if ($request->has('status') && $request->status !== $user->status) {
                $updateData['status'] = $request->status;
                $changedFields['status'] = ['old' => $user->status, 'new' => $request->status];
            }

            if ($request->has('origin_id') && $request->origin_id != $user->origin_id) {
                $updateData['origin_id'] = $request->origin_id;
                $changedFields['origin_id'] = ['old' => $user->origin_id, 'new' => $request->origin_id];
            }

            if ($request->has('new_password')) {
                $updateData['password'] = Hash::make($request->new_password);
                $changedFields['password'] = ['old' => 'Hidden', 'new' => 'Updated'];
            }

            // Update user if there are changes
            if (!empty($updateData)) {
                $user->update($updateData);
                $user->refresh();
                $user->load('origin');

                return response()->json([
                    'status' => 'success',
                    'message' => 'User profile updated successfully',
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'full_name' => $user->full_name,
                            'email' => $user->email,
                            'age' => $user->age,
                            'status' => $user->status,
                            'origin_id' => $user->origin_id,
                            'origin_name' => $user->origin ? $user->origin->name : null,
                            'profile_picture' => $user->profile_picture,
                            'email_verified_at' => $user->email_verified_at,
                            'updated_at' => $user->updated_at->format('Y-m-d H:i:s')
                        ],
                        'changes' => $changedFields,
                        'total_changes' => count($changedFields)
                    ]
                ], 200);
            } else {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No changes detected',
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'full_name' => $user->full_name,
                            'email' => $user->email,
                            'age' => $user->age,
                            'status' => $user->status,
                            'origin_id' => $user->origin_id,
                            'origin_name' => $user->origin ? $user->origin->name : null,
                            'profile_picture' => $user->profile_picture,
                            'email_verified_at' => $user->email_verified_at
                        ]
                    ]
                ], 200);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating user profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user account (soft delete with data cleanup)
     */
    public function deleteUserAccount(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Validate password before deletion
            $validator = Validator::make($request->all(), [
                'password' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Password is required to delete account',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Password is incorrect'
                ], 422);
            }

            // Store user data before deletion for response
            $userData = [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'deleted_at' => now()->format('Y-m-d H:i:s')
            ];

            // Revoke all tokens
            $user->tokens()->delete();
            
            // Delete refresh tokens
            RefreshToken::where('user_id', $user->id)->delete();

            // Soft delete user (if using SoftDeletes trait)
            // Or you can hard delete depending on your requirements
            $user->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'User account deleted successfully',
                'data' => [
                    'deleted_user' => $userData
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error deleting user account: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user profile picture
     */
    public function updateProfilePicture(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Validation for image upload
            $validator = Validator::make($request->all(), [
                'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048' // 2MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid image file',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Handle file upload
            if ($request->hasFile('profile_picture')) {
                $image = $request->file('profile_picture');
                $imageName = time() . '_' . $user->id . '.' . $image->getClientOriginalExtension();
                
                // Store in storage/app/public/profile_pictures
                $imagePath = $image->storeAs('profile_pictures', $imageName, 'public');
                
                // Delete old profile picture if exists
                if ($user->profile_picture && \Storage::disk('public')->exists($user->profile_picture)) {
                    \Storage::disk('public')->delete($user->profile_picture);
                }

                // Update user profile picture path
                $user->update([
                    'profile_picture' => $imagePath
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Profile picture updated successfully',
                    'data' => [
                        'profile_picture_path' => $imagePath,
                        'profile_picture_url' => asset('storage/' . $imagePath),
                        'updated_at' => $user->updated_at->format('Y-m-d H:i:s')
                    ]
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'No image file provided'
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating profile picture: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user data (existing method)
     */
    public function getUserData(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user' => $user
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving user data: ' . $e->getMessage()
            ], 500);
        }
    }
}
