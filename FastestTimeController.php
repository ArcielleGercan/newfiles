<?php

namespace App\Http\Controllers;

use App\Models\FastestTime;
use App\Models\User;
use App\Models\PlayerStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;

class FastestTimeController extends Controller
{
    /**
     * Save or update fastest time record
     */
    public function saveFastestTime(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'player_id' => 'required|string',
            'game_type' => 'required|in:memory_match,puzzle',
            'difficulty' => 'required|in:EASY,AVERAGE,DIFFICULT',
            'category' => 'nullable|string', // For puzzle only
            'time_seconds' => 'required|integer|min:1',
            'moves' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();
            $playerObjectId = new ObjectId($data['player_id']);

            // Get player username
            $player = User::find($playerObjectId);
            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Player not found'
                ], 404);
            }

            // Build query to find existing record
            $query = [
                'player_id' => $playerObjectId,
                'game_type' => $data['game_type'],
                'difficulty' => $data['difficulty'],
            ];

            // Add category for puzzle (IMPORTANT: Category is part of the unique record)
            if ($data['game_type'] === 'puzzle' && isset($data['category'])) {
                $query['category'] = $data['category'];
            }

            // Find existing record
            $existing = FastestTime::where($query)->first();

            $isNewRecord = false;
            $isFasterTime = false;

            if ($existing) {
                // Check if new time is faster
                if ($data['time_seconds'] < $existing->time_seconds) {
                    $existing->update([
                        'time_seconds' => $data['time_seconds'],
                        'moves' => $data['moves'],
                        'achieved_at' => now(),
                    ]);
                    $isNewRecord = true;
                    $isFasterTime = true;
                    $record = $existing;
                } else {
                    $record = $existing;
                }
            } else {
                // Create new record
                $record = FastestTime::create([
                    'player_id' => $playerObjectId,
                    'player_username' => $player->username,
                    'game_type' => $data['game_type'],
                    'difficulty' => $data['difficulty'],
                    'category' => $data['category'] ?? null,
                    'time_seconds' => $data['time_seconds'],
                    'moves' => $data['moves'],
                    'achieved_at' => now(),
                ]);
                $isNewRecord = true;
            }

            // Update Player Stats
            PlayerStats::updateStats(
                (string)$playerObjectId,
                $data['game_type'],
                $data['category'] ?? null,
                $data['difficulty'],
                'won', // Assuming completion is a win
                0
            );

            return response()->json([
                'success' => true,
                'is_new_record' => $isNewRecord,
                'is_faster_time' => $isFasterTime,
                'data' => $record,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving fastest time',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get player's fastest time for specific game/difficulty/category
     * URL: /api/game/fastest-time/{playerId}/{gameType}/{difficulty}?category=Solar%20System
     */
    public function getPlayerFastestTime(Request $request, $playerId, $gameType, $difficulty)
    {
        try {
            $playerObjectId = new ObjectId($playerId);
            $category = $request->query('category');

            $query = FastestTime::where('player_id', $playerObjectId)
                ->where('game_type', $gameType)
                ->where('difficulty', $difficulty);

            // For puzzle, category is REQUIRED to get the correct record
            if ($gameType === 'puzzle') {
                if ($category) {
                    $query = $query->where('category', $category);
                } else {
                    // If no category provided for puzzle, return error
                    return response()->json([
                        'success' => false,
                        'message' => 'Category is required for puzzle game type'
                    ], 400);
                }
            }

            $record = $query->first();

            return response()->json([
                'success' => true,
                'data' => $record,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching fastest time',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get global leaderboard (top 10 fastest times) per category per difficulty
     * URL: /api/game/fastest-times/leaderboard?game_type=puzzle&difficulty=EASY&category=Solar%20System&limit=10
     */
    public function getGlobalLeaderboard(Request $request)
    {
        try {
            $gameType = $request->query('game_type', 'memory_match');
            $difficulty = $request->query('difficulty', 'EASY');
            $category = $request->query('category');
            $limit = $request->query('limit', 10);

            $query = FastestTime::where('game_type', $gameType)
                ->where('difficulty', $difficulty);

            // For puzzle, category is REQUIRED for accurate leaderboard
            if ($gameType === 'puzzle') {
                if ($category) {
                    $query = $query->where('category', $category);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Category is required for puzzle leaderboard'
                    ], 400);
                }
            }

            $leaderboard = $query->orderBy('time_seconds', 'asc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'game_type' => $gameType,
                'difficulty' => $difficulty,
                'category' => $category,
                'data' => $leaderboard,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching leaderboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all fastest times for a player (grouped by game type, difficulty, and category)
     * URL: /api/game/fastest-time/{playerId}/all
     */
    public function getPlayerAllRecords($playerId)
    {
        try {
            $playerObjectId = new ObjectId($playerId);

            $records = FastestTime::where('player_id', $playerObjectId)
                ->orderBy('achieved_at', 'desc')
                ->get();

            // Group by game_type -> difficulty -> category
            $grouped = $records->groupBy('game_type')->map(function ($gameRecords) {
                return $gameRecords->groupBy('difficulty')->map(function ($difficultyRecords) {
                    // Further group by category for puzzles
                    return $difficultyRecords->groupBy('category');
                });
            });

            return response()->json([
                'success' => true,
                'data' => $grouped,
                'raw_records' => $records, // Also include flat list for easier access
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching player records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get player's rank in global leaderboard for specific category/difficulty
     * URL: /api/game/fastest-time/{playerId}/rank?game_type=puzzle&difficulty=EASY&category=Solar%20System
     */
    public function getPlayerRank(Request $request, $playerId)
    {
        try {
            $gameType = $request->query('game_type', 'memory_match');
            $difficulty = $request->query('difficulty', 'EASY');
            $category = $request->query('category');

            $playerObjectId = new ObjectId($playerId);

            // Build query
            $query = FastestTime::where('game_type', $gameType)
                ->where('difficulty', $difficulty);

            if ($gameType === 'puzzle' && $category) {
                $query = $query->where('category', $category);
            }

            $allTimes = $query->orderBy('time_seconds', 'asc')->get();

            // Find player's rank
            $rank = null;
            $playerRecord = null;

            foreach ($allTimes as $index => $record) {
                if ((string)$record->player_id === (string)$playerObjectId) {
                    $rank = $index + 1;
                    $playerRecord = $record;
                    break;
                }
            }

            return response()->json([
                'success' => true,
                'rank' => $rank,
                'total_players' => $allTimes->count(),
                'player_record' => $playerRecord,
                'game_type' => $gameType,
                'difficulty' => $difficulty,
                'category' => $category,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching player rank',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all categories with player's fastest times for a specific difficulty
     * Useful for showing all category records at once
     * URL: /api/game/fastest-time/{playerId}/puzzle/{difficulty}/all-categories
     */
    public function getPlayerPuzzleRecordsByDifficulty($playerId, $difficulty)
    {
        try {
            $playerObjectId = new ObjectId($playerId);

            $records = FastestTime::where('player_id', $playerObjectId)
                ->where('game_type', 'puzzle')
                ->where('difficulty', $difficulty)
                ->orderBy('time_seconds', 'asc')
                ->get()
                ->keyBy('category'); // Key by category for easy access

            return response()->json([
                'success' => true,
                'difficulty' => $difficulty,
                'data' => $records,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching puzzle records',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
