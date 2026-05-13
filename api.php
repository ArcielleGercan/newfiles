<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\FastestTimeController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\StarsController;
use App\Http\Controllers\AdminController;

// ==========================================
// AUTH & USER
// ==========================================
Route::post('/user/register',               [UserController::class, 'register']);
Route::post('/login',                      [UserController::class, 'login']);
Route::get('/user/profile/{id}',           [UserController::class, 'profile']);
Route::put('/user/update/{id}',            [UserController::class, 'update']);
Route::get('/homepage/{id}',               [UserController::class, 'homepage']);
Route::put('/user/change-password/{id}',   [UserController::class, 'changePassword']);
Route::post('/user/logout',                [UserController::class, 'logout']);

// ==========================================
// LOCATION
// ==========================================
Route::get('/region',                      [RegionController::class, 'index']);
Route::get('/province/{regionId}',         [ProvinceController::class, 'getByRegion']);
Route::get('/city/{provinceId}',           [CityController::class, 'getByProvince']);

// ==========================================
// TUTORIALS
// ==========================================
Route::get('/user/tutorial-status/{id}',    [UserController::class, 'getTutorialStatus']);
Route::post('/user/tutorial-complete/{id}', [UserController::class, 'markTutorialComplete']);
Route::get('/user/game-tutorial-status/{id}',   [UserController::class, 'getGameTutorialStatus']);
Route::post('/user/complete-game-tutorial',     [UserController::class, 'markGameTutorialComplete']);

// ==========================================
// QUIZ QUESTIONS
// ==========================================
Route::get('/quiz/questions/{category}/{difficulty}/{yearLevel}', [QuizController::class, 'getQuestions']);
Route::get('/quiz/questions/{category}/{difficulty}',             [QuizController::class, 'getQuestionsWithoutYearLevel']);
Route::get('/quiz/statistics',            [QuizController::class, 'getStatistics']);
Route::get('/quiz/debug',                 [QuizController::class, 'debug']);

// ==========================================
// GAME RESULTS
// ==========================================
Route::post('/game/save-challenge-result', [GameController::class, 'saveChallengeResult']);
Route::post('/game/save-battle-result',    [GameController::class, 'saveBattleResult']);

// ==========================================
// LEADERBOARD
// ==========================================
Route::get('/leaderboard',                   [LeaderboardController::class, 'getLeaderboard']);
Route::get('/leaderboard/player/{playerId}', [LeaderboardController::class, 'getPlayerRank']);
Route::get('/players/{playerId}/badges',     [LeaderboardController::class, 'getPlayerBadges']);

// ==========================================
// FASTEST TIME
// ==========================================
Route::prefix('game')->group(function () {
    Route::post('/fastest-time', [FastestTimeController::class, 'saveFastestTime']);
    Route::get('/fastest-time/{playerId}/{gameType}/{difficulty}', [FastestTimeController::class, 'getPlayerFastestTime']);
    Route::get('/fastest-time/{playerId}/all', [FastestTimeController::class, 'getPlayerAllRecords']);
    Route::get('/fastest-time/{playerId}/rank', [FastestTimeController::class, 'getPlayerRank']);
    Route::get('/fastest-time/{playerId}/puzzle/{difficulty}/all-categories', [FastestTimeController::class, 'getPlayerPuzzleRecordsByDifficulty']);
    Route::get('/fastest-times/leaderboard', [FastestTimeController::class, 'getGlobalLeaderboard']);
});

// ==========================================
// BADGES
// ==========================================
Route::prefix('badges')->group(function () {
    Route::get('/player/{playerId}/summary',    [BadgeController::class, 'getPlayerSummary']);
    Route::get('/player/{playerId}/rewards',    [BadgeController::class, 'getPlayerRewards']);
    Route::get('/player/{playerId}/unclaimed',  [BadgeController::class, 'getUnclaimedRewards']);
    Route::post('/player/{playerId}/claim',     [BadgeController::class, 'claimBadge']);
    Route::post('/player/{playerId}/claim-all', [BadgeController::class, 'claimAllByDifficulty']);
});

// ==========================================
// STARS
// ==========================================
Route::post('/players/{playerId}/stars',           [StarsController::class, 'awardStars']);
Route::get('/players/{playerId}/stars',            [StarsController::class, 'getPlayerStars']);
Route::get('/players/{playerId}/stars/milestones', [StarsController::class, 'getMilestoneHistory']);
Route::get('/stars/leaderboard',                  [StarsController::class, 'getStarsLeaderboard']);
Route::get('/players/{playerId}/stars/rank',      [StarsController::class, 'getPlayerStarsRank']);
Route::options('/quiz/questions/{any}', [QuizController::class, 'handleOptions'])->where('any', '.*');
// ==========================================
// CLEANUP
// ==========================================
Route::get('/badges/cleanup/{playerId}', function ($playerId) {
    $playerObjectId = new \MongoDB\BSON\ObjectId($playerId);
    $playerBadge = \App\Models\PlayerBadge::where('player_info_id', $playerObjectId)->first();

    if (!$playerBadge) {
        return response()->json(['message' => 'No player badge record found']);
    }

    foreach (['easy', 'average', 'difficult'] as $difficulty) {
        $badgeCountField = $difficulty . '_badge_count';
        $currentCount = $playerBadge->$badgeCountField ?? 0;
        $currentInSet = $currentCount % 3;

        if ($currentInSet != 0) {
            \Illuminate\Support\Facades\DB::connection('mongodb')
                ->table('player_rewards')
                ->where('player_id', $playerObjectId)
                ->where('difficulty', $difficulty)
                ->where('claimed', false)
                ->delete();
        }
    }

    return response()->json(['message' => 'Cleanup complete']);
});

// ==========================================
// ADMIN PUBLIC
// ==========================================
Route::post('/admin/login',              [AdminController::class, 'login']);
Route::get('/admin/difficulty-settings',[AdminController::class, 'getDifficultySettings']);

// ==========================================
// ADMIN PROTECTED
// ==========================================
Route::post('/admin/logout', [AdminController::class, 'logout']);
Route::get('/admin/profile', [AdminController::class, 'profile']);

// Questions
Route::get('/admin/questions',              [AdminController::class, 'getQuestions']);
Route::post('/admin/questions',             [AdminController::class, 'addQuestion']);
Route::put('/admin/questions/{id}',         [AdminController::class, 'updateQuestion']);
Route::delete('/admin/questions/{id}',      [AdminController::class, 'deleteQuestion']);
Route::patch('/admin/questions/{id}/restore',[AdminController::class, 'restoreQuestion']);
Route::delete('/admin/questions/{id}/permanent',[AdminController::class, 'permanentDeleteQuestion']);

// Difficulty
Route::put('/admin/difficulty-settings/{level}', [AdminController::class, 'updateDifficultySettings']);

// Players
Route::get('/admin/players',           [AdminController::class, 'getPlayers']);
Route::post('/admin/players',          [AdminController::class, 'addPlayer']);
Route::put('/admin/players/{id}',      [AdminController::class, 'updatePlayer']);
Route::delete('/admin/players/{id}',   [AdminController::class, 'deletePlayer']);   // soft-ban
Route::patch('/admin/players/{id}/restore', [AdminController::class, 'restorePlayer']); // unban

// Admins
Route::get('/admin/admins',                        [AdminController::class, 'getAdmins']);
Route::post('/admin/admins',                       [AdminController::class, 'addAdmin']);
Route::put('/admin/admins/{id}',                   [AdminController::class, 'updateAdmin']);
Route::delete('/admin/admins/{id}',                [AdminController::class, 'deleteAdmin']);
Route::post('/admin/admins/{id}/change-password',  [AdminController::class, 'changeAdminPassword']);

// Player Actions
Route::post('/admin/players/{id}/award-badge',     [AdminController::class, 'awardBadge']);
Route::post('/admin/players/{id}/change-password', [AdminController::class, 'changePlayerPassword']);

// Audit
Route::get('/admin/audit-logs', [AdminController::class, 'getAuditLogs']);

// Analytics
Route::get('/admin/analytics', [AdminController::class, 'getAnalytics']);

// Leaderboard (admin)
Route::get('/admin/leaderboard/challenge', [AdminController::class, 'getChallengeLeaderboard']);
Route::get('/admin/leaderboard/battle',    [AdminController::class, 'getBattleLeaderboard']);

// CSV + Image Upload
Route::post('/admin/questions/import-csv',   [AdminController::class, 'importQuestions']);
Route::post('/admin/questions/upload-image',[AdminController::class, 'uploadQuestionImage']);

// ==========================================
// IMAGE PROXY
// ==========================================
Route::get('/uploads/{folder}/{filename}', function ($folder, $filename) {
    $path = public_path("uploads/{$folder}/{$filename}");

    if (!file_exists($path)) {
        return response()->json(['error' => 'Not found'], 404);
    }

    return response()->file($path, [
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where([
    'folder' => '[a-zA-Z0-9_-]+',
    'filename' => '[a-zA-Z0-9_.\-]+'
]);
