<?php

namespace App\Http\Controllers;

use App\Models\PlayerStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

/**
 * LeaderboardController
 *
 * Handles simplified leaderboard with:
 * - Top 20 players by total badges
 * - Filtering by mode (challenge/battle)
 * - Filtering by category (Math/Science)
 * - Returns easy_count, average_count, difficult_count
 */
class LeaderboardController extends Controller
{
    /**
     * Get leaderboard with top 20 players by total badges
     * Shows cumulative totals across ALL categories (Math + Science)
     * Supports filtering by mode (challenge/battle)
     */
    public function getLeaderboard(Request $request)
    {
        try {
            $limit = $request->query('limit', 20); // Default 20 users
            $mode = $request->query('mode', 'challenge'); // 'challenge' or 'battle'

            \Log::info('Leaderboard request:', [
                'mode' => $mode,
                'limit' => $limit
            ]);

            // Get all player stats
            $allPlayers = PlayerStats::all();

            // Build leaderboard with cumulative badge counts across all categories
            $leaderboard = $allPlayers->map(function($player) use ($mode) {
                $statsField = $mode . '_stats'; // 'challenge_stats' or 'battle_stats'
                $stats = $player->$statsField ?? [];

                // Initialize counters
                $easyCount = 0;
                $averageCount = 0;
                $difficultCount = 0;

                // Sum up ALL categories (math, science, etc.)
                foreach ($stats as $categoryKey => $categoryStats) {
                    $easyCount += $categoryStats['easy'] ?? 0;
                    $averageCount += $categoryStats['average'] ?? 0;
                    $difficultCount += $categoryStats['difficult'] ?? 0;
                }

                $totalBadges = $easyCount + $averageCount + $difficultCount;

                return [
                    'player_id' => (string)$player->player_id,
                    'username' => $player->username,
                    'avatar' => $player->avatar,
                    'easy_count' => $easyCount,
                    'average_count' => $averageCount,
                    'difficult_count' => $difficultCount,
                    'total_badges' => $totalBadges,
                ];
            })
            ->filter(function($player) {
                // Only include players with at least 1 badge
                return $player['total_badges'] > 0;
            })
            ->sortByDesc('total_badges') // Sort by total badges descending
            ->take($limit) // Limit to top N players
            ->values()
            ->map(function($player, $index) {
                $player['rank'] = $index + 1;
                return $player;
            });

            return response()->json([
                'success' => true,
                'mode' => $mode,
                'users' => $leaderboard,
                'total_players' => $leaderboard->count(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching leaderboard: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching leaderboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get player's rank in leaderboard for a specific mode
     * Uses cumulative totals across all categories
     */
    public function getPlayerRank($playerId, Request $request)
    {
        try {
            $mode = $request->query('mode', 'challenge');

            $playerObjectId = new ObjectId($playerId);
            $playerStats = PlayerStats::where('player_id', $playerObjectId)->first();

            if (!$playerStats) {
                return response()->json([
                    'success' => false,
                    'message' => 'Player stats not found'
                ], 404);
            }

            $statsField = $mode . '_stats';
            $stats = $playerStats->$statsField ?? [];

            // Sum across all categories
            $playerEasy = 0;
            $playerAverage = 0;
            $playerDifficult = 0;

            foreach ($stats as $categoryKey => $categoryStats) {
                $playerEasy += $categoryStats['easy'] ?? 0;
                $playerAverage += $categoryStats['average'] ?? 0;
                $playerDifficult += $categoryStats['difficult'] ?? 0;
            }

            $playerTotal = $playerEasy + $playerAverage + $playerDifficult;

            // Count how many players have more badges
            $allPlayers = PlayerStats::all();
            $rank = 1;

            foreach ($allPlayers as $player) {
                if ((string)$player->player_id === $playerId) continue;

                $otherStats = $player->$statsField ?? [];
                $otherTotal = 0;

                foreach ($otherStats as $categoryKey => $categoryStats) {
                    $otherTotal += ($categoryStats['easy'] ?? 0) +
                                  ($categoryStats['average'] ?? 0) +
                                  ($categoryStats['difficult'] ?? 0);
                }

                if ($otherTotal > $playerTotal) {
                    $rank++;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'rank' => $rank,
                    'mode' => $mode,
                    'easy_count' => $playerEasy,
                    'average_count' => $playerAverage,
                    'difficult_count' => $playerDifficult,
                    'total_badges' => $playerTotal,
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching player rank: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching player rank'
            ], 500);
        }
    }

    /**
     * Get player badge counts for leaderboard display
     * This is for the badge section in the user stats panel
     */
    public function getPlayerBadges($playerId)
    {
        try {
            $playerObjectId = new ObjectId($playerId);

            // Get from player_stats collection
            $playerStats = PlayerStats::where('player_id', $playerObjectId)->first();

            if (!$playerStats) {
                return response()->json([
                    'success' => true,
                    'easy_count' => 0,
                    'average_count' => 0,
                    'difficult_count' => 0,
                ]);
            }

            // Aggregate all badge counts from both challenge and battle
            $challengeStats = $playerStats->challenge_stats ?? [];
            $battleStats = $playerStats->battle_stats ?? [];

            $easyCount = 0;
            $averageCount = 0;
            $difficultCount = 0;

            // Sum up from all categories in challenge stats
            foreach ($challengeStats as $categoryStats) {
                $easyCount += $categoryStats['easy'] ?? 0;
                $averageCount += $categoryStats['average'] ?? 0;
                $difficultCount += $categoryStats['difficult'] ?? 0;
            }

            // Sum up from all categories in battle stats
            foreach ($battleStats as $categoryStats) {
                $easyCount += $categoryStats['easy'] ?? 0;
                $averageCount += $categoryStats['average'] ?? 0;
                $difficultCount += $categoryStats['difficult'] ?? 0;
            }

            return response()->json([
                'success' => true,
                'easy_count' => $easyCount,
                'average_count' => $averageCount,
                'difficult_count' => $difficultCount,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching player badges: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching player badges'
            ], 500);
        }
    }
}
