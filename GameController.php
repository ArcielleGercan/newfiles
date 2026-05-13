<?php

namespace App\Http\Controllers;

use App\Models\PlayerBadge;
use App\Models\PlayerReward;
use App\Models\PlayerStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    /**
     * Record badge progress for a player
     * Awards official badge every 3 wins/perfect scores
     * Creates claimable reward in player_rewards collection
     *
     * @param string $playerId
     * @param string $difficulty (Easy, Average, Difficult)
     * @param string $source ('challenge' or 'battle')
     * @return array|null
     */
    private function recordBadgeProgress($playerId, $difficulty, $source = 'challenge')
    {
        try {
            $playerObjectId = new ObjectId($playerId);
            $difficultyLower = strtolower($difficulty);

            Log::info("🎯 Recording badge progress from {$source}", [
                'player_id' => $playerId,
                'difficulty' => $difficultyLower
            ]);

            // Get or create player badge record in player_badges collection
            $playerBadge = PlayerBadge::firstOrCreate(
                ['player_info_id' => $playerObjectId],
                [
                    'easy_badge_count' => 0,
                    'average_badge_count' => 0,
                    'difficult_badge_count' => 0,
                    'easy_official_badge' => 0,
                    'average_official_badge' => 0,
                    'difficult_official_badge' => 0,
                ]
            );

            // Increment the badge count for this difficulty
            $badgeCountField = $difficultyLower . '_badge_count';
            $playerBadge->increment($badgeCountField);
            $playerBadge->refresh();
<<<<<<< HEAD
            // Track that we incremented so we can roll back if reward insert fails
=======
>>>>>>> b3ab9214a0cd365bb2eeee80809765bf8f132ad4

            $currentCount = $playerBadge->$badgeCountField;
            $currentInSet = $currentCount % 3;

            Log::info('📊 Badge progress updated', [
                'total_count' => $currentCount,
                'current_in_set' => $currentInSet,
                'milestone_reached' => ($currentInSet === 0)
            ]);

            // Check if milestone reached (every 3rd win)
            if ($currentInSet === 0 && $currentCount > 0) {
                // Calculate which badge number this is (1st, 2nd, 3rd, etc.)
                $badgeNumber = intdiv($currentCount, 3);

                Log::info('🎊 MILESTONE REACHED!', [
                    'difficulty' => $difficultyLower,
                    'badge_number' => $badgeNumber,
                    'total_badges_earned' => $currentCount
                ]);

                // ✅ CHECK: Don't create duplicate rewards
                $existingReward = DB::connection('mongodb')
                    ->table('player_rewards')
                    ->where('player_id', $playerObjectId)
                    ->where('difficulty', $difficultyLower)
                    ->where('badge_number', $badgeNumber)
                    ->first();

                if ($existingReward) {
                    Log::warning('⚠️ Reward already exists, skipping creation', [
                        'difficulty' => $difficultyLower,
                        'badge_number' => $badgeNumber,
                    ]);
                } else {
<<<<<<< HEAD
                    try {
                        DB::connection('mongodb')->table('player_rewards')->insert([
                            'player_id'      => $playerObjectId,
                            'difficulty'     => $difficultyLower,
                            'badge_number'   => $badgeNumber,
                            'earned_date'    => now(),
                            'claimed'        => false,
                            'claimed_date'   => null,
                            'requested'      => false,   // player must tap Claim themselves
                            'requested_date' => null,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);

                        Log::info('Claimable reward created — awaiting player claim', [
                            'difficulty'   => $difficultyLower,
                            'badge_number' => $badgeNumber,
                        ]);
                    } catch (\Exception $insertEx) {
                        // Roll back badge count so player doesn't lose their milestone
                        Log::error('Reward insert failed — rolling back badge count', [
                            'error' => $insertEx->getMessage(),
                        ]);
                        $playerBadge->decrement($badgeCountField);
                        throw $insertEx;
                    }
=======
                    // Create claimable reward in player_rewards collection
                    // requested=true immediately so admin can see and award it
                    DB::connection('mongodb')->table('player_rewards')->insert([
                        'player_id'      => $playerObjectId,
                        'difficulty'     => $difficultyLower,
                        'badge_number'   => $badgeNumber,
                        'earned_date'    => now(),
                        'claimed'        => false,
                        'claimed_date'   => null,
                        'requested'      => true,
                        'requested_date' => now(),
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);

                    Log::info('✅ Claimable reward created in player_rewards', [
                        'difficulty' => $difficultyLower,
                        'badge_number' => $badgeNumber,
                        'must_claim' => true
                    ]);
>>>>>>> b3ab9214a0cd365bb2eeee80809765bf8f132ad4
                }

                return [
                    'difficulty' => $difficulty,
                    'badge_unlocked' => true,
                    'badge_number' => $badgeNumber,
                    'can_claim' => true,
                    'message' => "Congratulations! You've earned badge #{$badgeNumber} for {$difficulty} difficulty! Visit the badge screen to claim it.",
                ];
            }

            // No milestone reached yet
            $remaining = 3 - $currentInSet;
            $progressMessage = $source === 'battle'
                ? sprintf('%d more battle win%s needed for next badge', $remaining, $remaining === 1 ? '' : 's')
                : sprintf('%d more perfect score%s needed for next badge', $remaining, $remaining === 1 ? '' : 's');

            return [
                'difficulty' => $difficulty,
                'progress' => $currentInSet,
                'remaining' => $remaining,
                'badge_unlocked' => false,
                'message' => $progressMessage,
            ];

        } catch (\Exception $e) {
            Log::error('❌ Error recording badge progress', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Save challenge result (for Challenge mode)
     * Awards badge progress ONLY on perfect scores
     */
    public function saveChallengeResult(Request $request)
    {
<<<<<<< HEAD
        try {
            $validated = $request->validate([
                'player_id'        => 'required|string',
                'category'         => 'required|string',
                'difficulty_level' => 'required|string',
                'total_questions'  => 'required|integer',
                'correct_answers'  => 'required|integer',
                'time_taken'       => 'required|integer',
                'score'            => 'nullable|integer',
            ]);

            $playerObjectId = new ObjectId($validated['player_id']);
            $isPerfect      = $validated['correct_answers'] === $validated['total_questions'];
            $badgeAwarded   = null;

            // Always save game result record regardless of score
            DB::connection('mongodb')->table('game_results')->insert([
                'player_id'        => $playerObjectId,
                'category'         => $validated['category'],
                'difficulty_level' => $validated['difficulty_level'],
                'total_questions'  => $validated['total_questions'],
                'correct_answers'  => $validated['correct_answers'],
                'time_taken'       => $validated['time_taken'],
                'is_perfect'       => $isPerfect,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            Log::info('Challenge result saved', [
                'player_id'       => $validated['player_id'],
                'correct_answers' => $validated['correct_answers'],
                'total_questions' => $validated['total_questions'],
                'is_perfect'      => $isPerfect,
            ]);

            if ($isPerfect) {
                Log::info('Perfect score detected', [
                    'player_id'  => $validated['player_id'],
                    'difficulty' => $validated['difficulty_level'],
                ]);

                // Update player stats — non-fatal if it fails
                try {
                    PlayerStats::updateStats(
                        $validated['player_id'],
                        'challenge',
                        $validated['category'],
                        $validated['difficulty_level'],
                        'won',
                        $validated['score'] ?? $validated['correct_answers'],
                    );
                    Log::info('Player stats updated');
                } catch (\Exception $e) {
                    Log::error('Stats update failed (non-fatal): ' . $e->getMessage());
                }

                // Record badge progress
                $badgeAwarded = $this->recordBadgeProgress(
                    $validated['player_id'],
                    $validated['difficulty_level'],
                    'challenge'
                );

                Log::info('Badge progress recorded', ['badge_awarded' => $badgeAwarded]);
            } else {
                Log::info('Not a perfect score — no badge awarded', [
                    'correct' => $validated['correct_answers'],
                    'total'   => $validated['total_questions'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Game result saved successfully',
                'data'    => ['badge_awarded' => $badgeAwarded],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in saveChallengeResult', $e->errors());
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Exception in saveChallengeResult', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
=======
        $validated = $request->validate([
            'player_id' => 'required|string',
            'category' => 'required|string',
            'difficulty_level' => 'required|string',
            'score' => 'required|integer',
            'total_questions' => 'required|integer',
            'correct_answers' => 'required|integer',
            'time_taken' => 'required|integer',
        ]);

        $playerObjectId = new ObjectId($validated['player_id']);

        $badgeAwarded = null;

        // Check if perfect score
        if ($validated['correct_answers'] === $validated['total_questions']) {
            Log::info('🎯 Perfect score detected!', [
                'player_id' => $validated['player_id'],
                'difficulty' => $validated['difficulty_level'],
                'correct' => $validated['correct_answers'],
                'total' => $validated['total_questions'],
            ]);

            // ✅ UPDATE PLAYER_STATS - THIS WAS MISSING!
            PlayerStats::updateStats(
                $validated['player_id'],
                'challenge',
                $validated['category'],
                $validated['difficulty_level'],
                'won',  // Perfect score = won
                $validated['score']
            );

            Log::info('✅ Player stats updated in player_stats collection');

            // Record badge progress (creates claimable reward if milestone reached)
            $badgeAwarded = $this->recordBadgeProgress(
                $validated['player_id'],
                $validated['difficulty_level'],
                'challenge'
            );

            Log::info('✅ Badge progress recorded', [
                'badge_awarded' => $badgeAwarded
            ]);
        } else {
            Log::info('ℹ️ Not a perfect score - no stats or badges awarded', [
                'correct' => $validated['correct_answers'],
                'total' => $validated['total_questions'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Game result saved successfully',
            'data' => [
                'badge_awarded' => $badgeAwarded,
            ]
        ], 201);
>>>>>>> b3ab9214a0cd365bb2eeee80809765bf8f132ad4
    }

    /**
     * Save battle result (for Battle mode)
     * Records to battle collection AND awards badge progress on wins
     */
    public function saveBattleResult(Request $request)
    {
        Log::info('=== BATTLE RESULT REQUEST RECEIVED ===');
        Log::info('Request Data:', $request->all());

        try {
<<<<<<< HEAD
            // Support both 'difficulty' and 'difficulty_level' field names from the Flutter client
            if (!$request->has('difficulty_level') && $request->has('difficulty')) {
                $request->merge(['difficulty_level' => $request->input('difficulty')]);
            }

=======
>>>>>>> b3ab9214a0cd365bb2eeee80809765bf8f132ad4
            $validated = $request->validate([
                'player_id' => 'required|string',
                'opponent_id' => 'nullable|string',
                'opponent_username' => 'nullable|string',
                'opponent_score' => 'nullable|integer|min:0',
                'category' => 'required|string',
                'difficulty_level' => 'required|string',
                'player_score' => 'required|integer|min:0',
                'result' => 'required|in:won,lost',
                'battle_id' => 'required|string',
                'questions_answered' => 'required|integer|min:0',
                'correct_answers' => 'required|integer|min:0',
            ]);

            Log::info('✅ Validation passed', $validated);

            $playerId = new ObjectId($validated['player_id']);
            $opponentId = isset($validated['opponent_id']) ? new ObjectId($validated['opponent_id']) : null;

            // 1. Save to battle collection (for history)
            DB::connection('mongodb')->table('battle')->insert([
                'player_id' => $playerId,
                'battle_id' => $validated['battle_id'],
                'opponent_id' => $opponentId,
                'opponent_username' => $validated['opponent_username'] ?? null,
                'opponent_score' => $validated['opponent_score'] ?? 0,
                'category' => $validated['category'],
                'difficulty_level' => $validated['difficulty_level'],
                'player_score' => $validated['player_score'],
                'result' => $validated['result'],
                'questions_answered' => $validated['questions_answered'],
                'correct_answers' => $validated['correct_answers'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('✅ Battle result saved to battle collection');

            // 2. Update player_stats collection
            PlayerStats::updateStats(
                (string)$playerId,
                'battle',
                $validated['category'],
                $validated['difficulty_level'],
                $validated['result'],
                $validated['player_score']
            );

            // 3. ONLY IF WON - Award badge progress and create claimable reward
            $badgeAwarded = null;

            if ($validated['result'] === 'won') {
                Log::info('🏆 Player WON - awarding badge progress');

                $badgeAwarded = $this->recordBadgeProgress(
                    (string)$playerId,
                    $validated['difficulty_level'],
                    'battle'
                );

                Log::info('Badge awarded result', ['badge_awarded' => $badgeAwarded]);
            } else {
                Log::info('ℹ️ Player LOST - no badges awarded');
            }

            Log::info('=== BATTLE RESULT SAVED SUCCESSFULLY ===');

            return response()->json([
                'success' => true,
                'message' => 'Battle result saved successfully',
                'data' => $validated,
                'badge_awarded' => $badgeAwarded,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('=== VALIDATION ERROR ===');
            Log::error('Validation errors:', $e->errors());

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('=== EXCEPTION IN saveBattleResult ===');
            Log::error('Error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
