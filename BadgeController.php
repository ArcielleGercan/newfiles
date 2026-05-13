<?php

namespace App\Http\Controllers;

use App\Models\PlayerBadge;
use App\Models\PlayerReward;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;

class BadgeController extends Controller
{
    /**
     * Get player badge summary.
     * Returns progress, official badges (admin-confirmed), and requested (player-claimed, pending admin).
     */
    public function getPlayerSummary($playerId)
    {
        try {
            $playerObjectId = new ObjectId($playerId);
            $playerBadge = PlayerBadge::where('player_info_id', $playerObjectId)->first();

            if (!$playerBadge) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'progress' => [
                            'easy'      => ['current_count' => 0, 'remaining' => 3, 'total_earned' => 0],
                            'average'   => ['current_count' => 0, 'remaining' => 3, 'total_earned' => 0],
                            'difficult' => ['current_count' => 0, 'remaining' => 3, 'total_earned' => 0],
                        ],
                        'official_badges' => ['easy' => 0, 'average' => 0, 'difficult' => 0],
                        'requested'       => ['easy' => 0, 'average' => 0, 'difficult' => 0],
                    ]
                ]);
            }

            // Count unclaimed rewards per difficulty (regardless of requested flag)
            // This handles both old records (no requested field) and new ones (requested=true)
            $allUnclaimed = \Illuminate\Support\Facades\DB::connection('mongodb')
                ->table('player_rewards')
                ->where('player_id', $playerObjectId)
                ->where('claimed', '!=', true)
                ->get();
            $requestedCounts = [
                'easy'      => $allUnclaimed->where('difficulty', 'easy')->count(),
                'average'   => $allUnclaimed->where('difficulty', 'average')->count(),
                'difficult' => $allUnclaimed->where('difficulty', 'difficult')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'progress' => [
                        'easy'      => $this->calculateProgress($playerBadge->easy_badge_count      ?? 0),
                        'average'   => $this->calculateProgress($playerBadge->average_badge_count   ?? 0),
                        'difficult' => $this->calculateProgress($playerBadge->difficult_badge_count ?? 0),
                    ],
                    'official_badges' => [
                        'easy'      => $playerBadge->easy_official_badge      ?? 0,
                        'average'   => $playerBadge->average_official_badge   ?? 0,
                        'difficult' => $playerBadge->difficult_official_badge ?? 0,
                    ],
                    'requested' => $requestedCounts,
                    'total_official_badges' => ($playerBadge->easy_official_badge ?? 0) +
                                              ($playerBadge->average_official_badge ?? 0) +
                                              ($playerBadge->difficult_official_badge ?? 0),
                    'total_requested' => array_sum($requestedCounts),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching badge summary: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error fetching badge summary'], 500);
        }
    }

    private function calculateProgress($totalCount)
    {
        $currentInSet = $totalCount % 3;
        // When exactly on a milestone (currentInSet=0) and totalCount>0,
        // show full set (3/3) not empty set (0/3)
        $displayCount = ($currentInSet === 0 && $totalCount > 0) ? 3 : $currentInSet;
        $remaining    = ($currentInSet === 0 && $totalCount > 0) ? 0 : (3 - $currentInSet);

        return [
            'current_count' => $displayCount,
            'remaining'     => $remaining,
            'total_earned'  => $totalCount,
        ];
    }

    /**
     * Player taps "Claim Reward" — marks reward as REQUESTED (pending admin).
     * Does NOT increment official badge — admin does that after giving physical prize.
     */
    public function claimBadge(Request $request, $playerId)
    {
        try {
            $validated = $request->validate(['reward_id' => 'required|string']);

            try {
                $rewardId = new ObjectId($validated['reward_id']);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Invalid badge ID format'], 400);
            }

            $playerObjectId = new ObjectId($playerId);

            $reward = PlayerReward::where('_id', $rewardId)
                ->where('player_id', $playerObjectId)
                ->first();

            if (!$reward) {
                return response()->json(['success' => false, 'message' => 'Reward not found'], 404);
            }

            if ($reward->claimed) {
                return response()->json(['success' => false, 'message' => 'Reward already given by admin.'], 400);
            }

            if ($reward->requested) {
                return response()->json(['success' => false, 'message' => 'Already requested — waiting for admin to confirm.'], 400);
            }

            $playerBadge = PlayerBadge::where('player_info_id', $playerObjectId)->first();

            if (!$playerBadge) {
                $playerBadge = PlayerBadge::create([
                    'player_info_id'          => $playerObjectId,
                    'easy_badge_count'         => 0,
                    'average_badge_count'      => 0,
                    'difficult_badge_count'    => 0,
                    'easy_official_badge'      => 0,
                    'average_official_badge'   => 0,
                    'difficult_official_badge' => 0,
                ]);
            }

            $difficulty        = $reward->difficulty;
            $badgeCountField   = strtolower($difficulty) . '_badge_count';
            $currentBadgeCount = $playerBadge->$badgeCountField ?? 0;

            // Must have at least 3 badges AND be on a milestone boundary
            if ($currentBadgeCount < 3 || $currentBadgeCount % 3 !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not eligible to claim reward. You need 3 badges first.'
                ], 400);
            }

            // Double-check: make sure this specific reward record belongs to a valid milestone
            $alreadyRequestedCount = PlayerReward::where('player_id', $playerObjectId)
                ->where('difficulty', $difficulty)
                ->where('requested', true)
                ->count();
            $alreadyClaimedCount = PlayerReward::where('player_id', $playerObjectId)
                ->where('difficulty', $difficulty)
                ->where('claimed', true)
                ->count();
            $totalProcessed = $alreadyRequestedCount + $alreadyClaimedCount;
            $milestoneNumber = (int) floor($currentBadgeCount / 3);

            if ($totalProcessed >= $milestoneNumber) {
                return response()->json([
                    'success' => false,
                    'message' => 'This milestone reward has already been requested or claimed.'
                ], 400);
            }

            // Mark as REQUESTED — admin will confirm and grant the official badge
            $reward->requested      = true;
            $reward->requested_date = now();
            $reward->save();

            \Log::info("Reward requested by player {$playerId}, difficulty: {$difficulty}");

            return response()->json([
                'success' => true,
                'message' => 'Reward requested! Waiting for admin to confirm your physical prize.',
                'data' => [
                    'difficulty'   => $reward->difficulty,
                    'badge_number' => $reward->badge_number,
                    'requested_at' => $reward->requested_date,
                    'status'       => 'pending_admin',
                ]
            ]);

        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => 'Invalid badge ID format'], 400);
        } catch (\Exception $e) {
            \Log::error('Error claiming badge: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get all rewards for a player grouped by difficulty.
     */
    public function getPlayerRewards($playerId)
    {
        try {
            $playerObjectId = new ObjectId($playerId);
            $playerBadge = PlayerBadge::where('player_info_id', $playerObjectId)->first();

            if (!$playerBadge) {
                return response()->json([
                    'success' => true,
                    'data'    => ['easy' => [], 'average' => [], 'difficult' => []],
                    'summary' => ['easy_total' => 0, 'average_total' => 0, 'difficult_total' => 0],
                ]);
            }

            $allRewards = PlayerReward::byPlayer($playerId)->orderBy('earned_date', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'easy'      => $allRewards->where('difficulty', 'easy')->values(),
                    'average'   => $allRewards->where('difficulty', 'average')->values(),
                    'difficult' => $allRewards->where('difficulty', 'difficult')->values(),
                ],
                'summary' => [
                    'easy_total'      => $playerBadge->easy_official_badge      ?? 0,
                    'average_total'   => $playerBadge->average_official_badge   ?? 0,
                    'difficult_total' => $playerBadge->difficult_official_badge ?? 0,
                ],
                'requested' => PlayerReward::getRequestedCountByDifficulty($playerId),
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching player rewards: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error fetching player rewards'], 500);
        }
    }

    /**
     * Get rewards where player hasn't yet tapped Claim Reward.
     */
    public function getUnclaimedRewards($playerId)
    {
        try {
            $unclaimedRewards = PlayerReward::where('player_id', new ObjectId($playerId))
                ->where('claimed', '!=', true)
                ->where(function ($query) {
                    // Match both: requested=false OR requested field doesn't exist (old records)
                    $query->where('requested', '!=', true)
                          ->orWhereNull('requested');
                })
                ->get();

            $format = fn($r) => [
                '_id'          => (string) $r->_id,
                'player_id'    => (string) $r->player_id,
                'difficulty'   => $r->difficulty,
                'badge_number' => $r->badge_number,
                'earned_date'  => $r->earned_date?->toIso8601String(),
                'requested'    => $r->requested ?? false,
                'claimed'      => $r->claimed   ?? false,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'easy'      => $unclaimedRewards->where('difficulty', 'easy')->map($format)->values()->toArray(),
                    'average'   => $unclaimedRewards->where('difficulty', 'average')->map($format)->values()->toArray(),
                    'difficult' => $unclaimedRewards->where('difficulty', 'difficult')->map($format)->values()->toArray(),
                ],
                'counts'          => PlayerReward::getRequestedCountByDifficulty($playerId),
                'total_unclaimed' => $unclaimedRewards->count(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching unclaimed rewards: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error fetching unclaimed rewards'], 500);
        }
    }

    /**
     * Batch claim for a difficulty — sets requested=true for all eligible rewards.
     */
    public function claimAllByDifficulty(Request $request, $playerId)
    {
        try {
            $validated   = $request->validate(['difficulty' => 'required|in:easy,average,difficult']);
            $difficulty  = $validated['difficulty'];
            $playerObjId = new ObjectId($playerId);

            $eligible = PlayerReward::where('player_id', $playerObjId)
                ->where('difficulty', $difficulty)
                ->where('requested', '!=', true)
                ->where('claimed', '!=', true)
                ->get();

            if ($eligible->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No rewards to request for this difficulty'], 404);
            }

            $count = 0;
            foreach ($eligible as $reward) {
                $reward->requested      = true;
                $reward->requested_date = now();
                $reward->save();
                $count++;
            }

            return response()->json([
                'success' => true,
                'message' => "Requested {$count} {$difficulty} reward(s)! Waiting for admin to confirm.",
                'data'    => ['difficulty' => $difficulty, 'requested_count' => $count, 'status' => 'pending_admin'],
            ]);

        } catch (\Exception $e) {
            \Log::error('Error claiming all badges: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error claiming badges'], 500);
        }
    }
}
