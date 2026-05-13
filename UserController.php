<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use MongoDB\BSON\ObjectId;

class UserController extends Controller
{
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'username' => [
                    'required',
                    'unique:player_info,username',
                    'min:3',
                    'max:20',
                    'regex:/^[a-zA-Z0-9_]+$/',  // This already prevents spaces, but let's add custom validation
                    function ($attribute, $value, $fail) {
                        if (preg_match('/\s/', $value)) {
                            $fail('Username cannot contain spaces');
                        }
                    },
                ],
                'password' => [
                    'required',
                    'min:8',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>])[A-Za-z\d!@#$%^&*(),.?":{}|<>]+$/'
                ],
                'school' => 'required|min:2',
                'age' => 'required|string',
                'avatar' => 'required|string',
                'category' => 'required|string',
                'student_category' => 'nullable|string',  // ← ADD THIS LINE
                'sex' => 'required|in:Male,Female,Prefer not to say',
                'region' => 'required|numeric',
                'province' => 'required|numeric',
                'city' => 'required|numeric',
            ], [
                // Custom error messages matching Flutter app expectations
                'username.required' => 'Username is required',
                'username.unique' => 'Username is already taken',
                'username.min' => 'Username must be at least 3 characters',
                'username.max' => 'Username must not exceed 20 characters',
                'username.regex' => 'Username can only contain letters, numbers, and underscores',

                'password.required' => 'Password is required',
                'password.min' => 'Password must be at least 8 characters',
                'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character',

                'school.required' => 'School is required',
                'school.min' => 'School name must be at least 2 characters',

                'age.required' => 'Please select an age range',
                'avatar.required' => 'Avatar is required',
                'category.required' => 'Category is required',
                'sex.required' => 'Sex is required',
                'sex.in' => 'Sex must be Male, Female, or Prefer not to say',

                'region.required' => 'Region is required',
                'region.numeric' => 'Invalid region selected',
                'province.required' => 'Province is required',
                'province.numeric' => 'Invalid province selected',
                'city.required' => 'City is required',
                'city.numeric' => 'Invalid city selected',
            ]);

        } catch (ValidationException $e) {
            // Return the first validation error message
            $errors = $e->errors();
            $firstError = reset($errors);
            $message = is_array($firstError) ? $firstError[0] : $firstError;

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors
            ], 422);
        }

        try {
            $user = User::create([
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'school' => $validated['school'],
                'age' => $validated['age'],
                'avatar' => $validated['avatar'],
                'category' => $validated['category'],
                'student_category' => $validated['student_category'] ?? null,  // ← ADD THIS LINE
                'sex' => $validated['sex'],
                'region' => (int) $validated['region'],
                'province' => (int) $validated['province'],
                'city' => (int) $validated['city'],
                'stars' => 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'user' => $user,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username    = $request->username;
        $attemptKey  = 'login_attempts:' . strtolower($username);
        $lockoutKey  = 'login_lockout:'  . strtolower($username);

        $maxAttempts   = 5;
        $lockoutMinutes = 5;

        // ── Check if currently locked out ──────────────────────────────────
        if (Cache::has($lockoutKey)) {
            $secondsLeft = Cache::get($lockoutKey) - now()->timestamp;
            $minutesLeft = (int) ceil($secondsLeft / 60);
            $minutesLeft = max(1, $minutesLeft);

            return response()->json([
                'success'      => false,
                'message'      => "Account temporarily locked due to too many failed attempts. Please try again in {$minutesLeft} minute(s).",
                'locked_out'   => true,
                'minutes_left' => $minutesLeft,
            ], 429);
        }

        // ── Find user ──────────────────────────────────────────────────────
        $user = User::where('username', $username)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // ── Banned / suspended check ───────────────────────────────────────
        if (($user->status ?? 'active') === 'banned') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact your teacher.',
                'banned'  => true,
            ], 403);
        }

        // ── Wrong password ─────────────────────────────────────────────────
        if (!Hash::check($request->password, $user->password)) {
            $attempts = Cache::get($attemptKey, 0) + 1;
            $remaining = $maxAttempts - $attempts;

            if ($attempts >= $maxAttempts) {
                // Lock the account for 30 minutes
                $unlockAt = now()->addMinutes($lockoutMinutes)->timestamp;
                Cache::put($lockoutKey, $unlockAt, now()->addMinutes($lockoutMinutes));
                Cache::forget($attemptKey);

                return response()->json([
                    'success'      => false,
                    'message'      => 'Too many failed attempts. Your account has been locked for 5 minutes.',
                    'locked_out'   => true,
                    'minutes_left' => $lockoutMinutes,
                ], 429);
            }

            // Store attempt count — expires after lockout window so it self-cleans
            Cache::put($attemptKey, $attempts, now()->addMinutes($lockoutMinutes));

            return response()->json([
                'success'           => false,
                'message'           => "Invalid password. {$remaining} attempt(s) remaining before lockout.",
                'attempts_remaining'=> $remaining,
            ], 401);
        }

        // ── Success — clear any recorded attempts ──────────────────────────
        Cache::forget($attemptKey);
        Cache::forget($lockoutKey);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user'    => $user,
        ]);
    }

    public function profile($id)
    {
        try {
            $user = User::find(new ObjectId($id));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user ID format',
            ], 400);
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'user'    => $user,
            'status'  => $user->status ?? 'active',
        ]);
    }

    private function getLocationName($collection, $id, $provinceId = null)
    {
        $items = \DB::connection('mongodb')->table($collection)->get();

        if ($collection === 'city' && $provinceId !== null) {
            $item = $items->first(function ($c) use ($id, $provinceId) {
                return (string)($c->id ?? '') === (string)$id
                    && (string)($c->province_id ?? '') === (string)$provinceId;
            });
        } else {
            $item = $items->first(function ($c) use ($id) {
                return (string)($c->id ?? '') === (string)$id;
            });
        }

        return $item ? ($collection === 'region' ? $item->region_name : ($collection === 'province' ? $item->province_name : $item->city_name)) : 'Unknown';
    }

    public function homepage($id)
    {
        try {
            $playerObjectId = new \MongoDB\BSON\ObjectId($id);

            $user = \DB::connection('mongodb')
                ->table('player_info')
                ->where('_id', $playerObjectId)
                ->first();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $regionName   = $this->getLocationName('region', $user->region);
            $provinceName = $this->getLocationName('province', $user->province);
            $cityName     = $this->getLocationName('city', $user->city, $user->province);

            // ✅ FIX: Get real star count from player_stars collection
            $playerStars = \DB::connection('mongodb')
                ->table('player_stars')
                ->where('player_id', $playerObjectId)
                ->first();
            $totalStars = $playerStars ? ($playerStars->total_stars ?? 0) : 0;

            // ✅ FIX: Get real badge count from player_badges collection
            $playerBadge = \DB::connection('mongodb')
                ->table('player_badges')
                ->where('player_info_id', $playerObjectId)
                ->first();
            $badgeCount = 0;
            if ($playerBadge) {
                $badgeCount = ($playerBadge->easy_badge_count ?? 0)
                            + ($playerBadge->average_badge_count ?? 0)
                            + ($playerBadge->difficult_badge_count ?? 0);
            }

            return response()->json([
                'success' => true,
                'user' => [
                    'username'         => $user->username ?? '',
                    'region'           => $regionName,
                    'province'         => $provinceName,
                    'city'             => $cityName,
                    'stars'            => $totalStars,   // ✅ from player_stars
                    'total_stars'      => $totalStars,   // ✅ from player_stars
                    'badge_count'      => $badgeCount,   // ✅ from player_badges
                    'category'         => $user->category ?? '',
                    'student_category' => $user->student_category ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error fetching homepage data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = User::find(new ObjectId($id));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid ID format'
            ], 400);
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        try {
            $validated = $request->validate([
                'username' => [
                    'required',
                    'min:3',
                    'max:20',
                    'regex:/^[a-zA-Z0-9_]+$/',
                    function ($attribute, $value, $fail) use ($user) {
                        // Check for spaces
                        if (preg_match('/\s/', $value)) {
                            $fail('Username cannot contain spaces');
                            return;
                        }
                        // Check if username is taken by another user
                        $existing = User::where('username', $value)
                            ->where('_id', '!=', $user->_id)
                            ->first();
                        if ($existing) {
                            $fail('The username is already taken.');
                        }
                    },
                ],
                'school' => 'required|min:2',
                'age' => 'required|string',
                'avatar' => 'required|string',
                'category' => 'required|string',
                'student_category' => 'nullable|string',
                'sex' => 'required|in:Male,Female,Prefer not to say',
                'region' => 'required|numeric',
                'province' => 'required|numeric',
                'city' => 'required|numeric',
            ], [
                'username.required' => 'Username is required',
                'username.min' => 'Username must be at least 3 characters',
                'username.max' => 'Username must not exceed 20 characters',
                'username.regex' => 'Username can only contain letters, numbers, and underscores',
                'school.required' => 'School is required',
                'school.min' => 'School name must be at least 2 characters',
                'age.required' => 'Please select an age range',
                'avatar.required' => 'Avatar is required',
                'category.required' => 'Category is required',
                'sex.required' => 'Sex is required',
                'sex.in' => 'Sex must be Male, Female, or Prefer not to say',
                'region.required' => 'Region is required',
                'region.numeric' => 'Invalid region selected',
                'province.required' => 'Province is required',
                'province.numeric' => 'Invalid province selected',
                'city.required' => 'City is required',
                'city.numeric' => 'Invalid city selected',
            ]);

        } catch (ValidationException $e) {
            $errors = $e->errors();
            $firstError = reset($errors);
            $message = is_array($firstError) ? $firstError[0] : $firstError;

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors
            ], 422);
        }

        // Check if anything actually changed
        $hasChanges = false;

        if ($user->username !== $validated['username'] ||
            $user->school !== $validated['school'] ||
            $user->age !== $validated['age'] ||
            $user->avatar !== $validated['avatar'] ||
            $user->category !== $validated['category'] ||
            ($user->student_category ?? null) !== ($validated['student_category'] ?? null) ||  // ← ADD THIS LINE
            $user->sex !== $validated['sex'] ||
            (int)$user->region !== (int)$validated['region'] ||
            (int)$user->province !== (int)$validated['province'] ||
            (int)$user->city !== (int)$validated['city']) {
            $hasChanges = true;
        }

        if (!$hasChanges) {
            return response()->json([
                'success' => true,
                'message' => 'No changes were made',
                'user' => $user,
                'no_changes' => true
            ], 200);
        }

        $user->update([
            'username' => $validated['username'],
            'school' => $validated['school'],
            'age' => $validated['age'],
            'avatar' => $validated['avatar'],
            'category' => $validated['category'],
            'student_category' => $validated['student_category'] ?? null,  // ← ADD THIS LINE
            'sex' => $validated['sex'],
            'region' => (int) $validated['region'],
            'province' => (int) $validated['province'],
            'city' => (int) $validated['city'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
        ], 200);
    }

    public function fixUserLocationIds()
    {
        try {
            $users = \DB::connection('mongodb')->table('player_info')->get();
            $fixedUsers = [];

            foreach ($users as $user) {
                $mongoId = isset($user->_id) ? (string)$user->_id : (isset($user->id) ? (string)$user->id : null);
                if (!$mongoId) continue;

                $regionId = $user->region ?? 0;
                $provinceId = $user->province ?? 0;
                $cityId = $user->city ?? 0;

                $regionName = $this->getLocationName('region', $regionId);
                $provinceName = $this->getLocationName('province', $provinceId);
                $cityName = $this->getLocationName('city', $cityId, $provinceId);

                \DB::connection('mongodb')
                    ->table('player_info')
                    ->where('_id', new \MongoDB\BSON\ObjectId($mongoId))
                    ->update([
                        'region' => $regionId,
                        'province' => $provinceId,
                        'city' => $cityId,
                    ]);

                $fixedUsers[] = [
                    'username' => $user->username ?? 'Unknown',
                    'region' => $regionName,
                    'province' => $provinceName,
                    'city' => $cityName,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'All user location IDs synced successfully.',
                'fixed_users' => $fixedUsers,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function changePassword(Request $request, $id)
    {
        try {
            $request->validate([
                'old_password' => 'required',
                'new_password' => [
                    'required',
                    'min:8',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>])[A-Za-z\d!@#$%^&*(),.?":{}|<>]+$/'
                ],
                'new_password_confirmation' => 'required|same:new_password',
            ], [
                'old_password.required' => 'Please enter your current password.',
                'new_password.required' => 'Please enter a new password.',
                'new_password.min' => 'New password must be at least 8 characters.',
                'new_password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
                'new_password_confirmation.required' => 'Please confirm your new password.',
                'new_password_confirmation.same' => 'Password confirmation does not match.',
            ]);

        } catch (ValidationException $e) {
            $errors = $e->errors();
            $firstError = reset($errors);
            $message = is_array($firstError) ? $firstError[0] : $firstError;

            return response()->json([
                'success' => false,
                'message' => $message
            ], 422);
        }

        try {
            $user = User::find(new \MongoDB\BSON\ObjectId($id));

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if (!Hash::check($request->old_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Old password is incorrect'
                ], 400);
            }

            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'New password must be different from the old password'
                ], 400);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating password'
            ], 500);
        }
    }

    public function getTutorialStatus($id)
    {
        try {
            $user = User::find(new ObjectId($id));

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'has_seen_tutorial' => $user->has_seen_tutorial ?? false
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching tutorial status'
            ], 500);
        }
    }

    public function getGameTutorialStatus(Request $request, $id)
    {
        try {
            $gameType = $request->query('game_type');

            if (!$gameType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Game type is required'
                ], 400);
            }

            $user = User::find(new ObjectId($id));

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $gameTutorials = $user->game_tutorials_seen ?? [];

            return response()->json([
                'success' => true,
                'has_seen_tutorial' => in_array($gameType, $gameTutorials)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching game tutorial status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function markGameTutorialComplete(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|string',
                'game_type' => 'required|string|in:memory_match,challenge,battle,puzzle'
            ]);

            $user = User::find(new ObjectId($validated['user_id']));

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get current game tutorials seen array or create empty array
            $gameTutorials = $user->game_tutorials_seen ?? [];

            // Add game type if not already in array
            if (!in_array($validated['game_type'], $gameTutorials)) {
                $gameTutorials[] = $validated['game_type'];
                $user->game_tutorials_seen = $gameTutorials;
                $user->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Game tutorial marked as complete'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error marking game tutorial complete: ' . $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string',
        ]);

        try {
            $user = User::find(new \MongoDB\BSON\ObjectId($request->user_id));

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'You have been logged out successfully.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error during logout'
            ], 500);
        }
    }
}
