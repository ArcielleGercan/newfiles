<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QuizController extends Controller
{

    /**
     * Attach CORS headers so Flutter web (port 80) can reach the API (port 8000).
     */
    private function cors($response)
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With');
    }

    /** Handle browser pre-flight OPTIONS requests. */
    public function handleOptions()
    {
        return $this->cors(response()->json(['ok' => true], 200));
    }

    /**
     * Get questions — yearLevel is optional.
     * Route:  GET /api/quiz/questions/{category}/{difficulty}/{yearLevel?}
     *
     * Whiz Challenge omits yearLevel → returns questions from ALL year levels.
     * Other game modes pass yearLevel → filters to that level only.
     */
    public function getQuestionsWithoutYearLevel($category, $difficulty)
    {
        return $this->getQuestions($category, $difficulty, null);
    }

    public function getQuestions($category, $difficulty, $yearLevel = null)
    {
        try {
            $normalizedCategory   = ucfirst(strtolower($category));
            $normalizedDifficulty = ucfirst(strtolower($difficulty));
            // ✅ yearLevel is optional — null = all year levels (Whiz Challenge)
            $normalizedYearLevel  = $yearLevel ? strtoupper($yearLevel) : null;

            \Log::info("Fetching questions", [
                'category'   => $normalizedCategory,
                'difficulty' => $normalizedDifficulty,
                'year_level' => $normalizedYearLevel ?? 'ALL',
            ]);

            // ✅ Build query — only filter by year_level when provided
            $query = \DB::connection('mongodb')
                ->table('quiz_questions')
                ->where('category', $normalizedCategory)
                ->where('difficulty_level', $normalizedDifficulty)
                ->where('is_active', 1);

            if ($normalizedYearLevel) {
                $query->where('year_level', $normalizedYearLevel);
            }

            $rawQuestions = $query->get();

            $questionCount = $rawQuestions->count();
            \Log::info("Questions found", ['count' => $questionCount]);

            if ($rawQuestions->isEmpty()) {
                $levelLabel = $normalizedYearLevel ?? 'ALL year levels';
                // ✅ FIX: Added missing closing ))
                return $this->cors(response()->json([
                    'success' => false,
                    'message' => "No questions found for {$normalizedCategory} - {$normalizedDifficulty} ({$levelLabel})",
                    'questions' => []
                ], 404));
            }

            // Format questions with image support
            $formattedQuestions = $rawQuestions->map(function ($question) {
                // Convert stdClass to array
                $q = json_decode(json_encode($question), true);

                // Handle MongoDB ObjectId format
                $id = '';
                if (isset($q['id']['$oid'])) {
                    $id = $q['id']['$oid'];
                } elseif (isset($q['_id']['$oid'])) {
                    $id = $q['_id']['$oid'];
                } elseif (isset($q['id'])) {
                    $id = (string) $q['id'];
                } elseif (isset($q['_id'])) {
                    $id = (string) $q['_id'];
                } else {
                    $id = uniqid();
                }

                return [
                    'id' => $id,
                    'question' => $q['question'] ?? '',
                    'question_image' => $q['question_image'] ?? null,
                    'choice_a' => $q['choice_a'] ?? '',
                    'choice_a_image' => $q['choice_a_image'] ?? null,
                    'choice_b' => $q['choice_b'] ?? '',
                    'choice_b_image' => $q['choice_b_image'] ?? null,
                    'choice_c' => $q['choice_c'] ?? '',
                    'choice_c_image' => $q['choice_c_image'] ?? null,
                    'choice_d' => $q['choice_d'] ?? '',
                    'choice_d_image' => $q['choice_d_image'] ?? null,
                    'correct_answer' => $q['correct_answer'] ?? '',
                    'category' => $q['category'] ?? '',
                    'difficulty_level' => $q['difficulty_level'] ?? '',
                    'year_level' => $q['year_level'] ?? '',
                    'has_images' => $q['has_images'] ?? 0,
                ];
            })
            ->shuffle()
            ->values();

            // Look up num_questions from difficulty settings (admin-configurable)
            $diffSetting = \DB::connection('mongodb')
                ->table('quiz_difficulty_settings')
                ->where('difficulty_level', $normalizedDifficulty)
                ->first();
            $numQuestions = $diffSetting ? (int) ($diffSetting->num_questions ?? 10) : 10;

            \Log::info("Difficulty setting applied", [
                'difficulty' => $normalizedDifficulty,
                'num_questions' => $numQuestions,
            ]);

            $formattedQuestions = $formattedQuestions->take($numQuestions);

            $finalCount = $formattedQuestions->count();

            // Warn if fewer questions available than configured
            $warning = null;
            if ($finalCount < $numQuestions) {
                $warning = "Only {$finalCount} questions available for this combination (configured: {$numQuestions}).";
                \Log::warning($warning);
            }

            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => true,
                'count' => $finalCount,
                'warning' => $warning,
                'questions' => $formattedQuestions
            ]));

        } catch (\Exception $e) {
            \Log::error("Error in getQuestions (yearLevel=" . ($yearLevel ?? 'ALL') . ")", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => false,
                'message' => 'Error fetching questions',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Add or update a question with images
     */
    public function addQuestion(Request $request)
    {
        try {
            $validated = $request->validate([
                'question' => 'required|string',
                'question_image' => 'nullable|string',
                'choice_a' => 'required|string',
                'choice_a_image' => 'nullable|string',
                'choice_b' => 'required|string',
                'choice_b_image' => 'nullable|string',
                'choice_c' => 'required|string',
                'choice_c_image' => 'nullable|string',
                'choice_d' => 'required|string',
                'choice_d_image' => 'nullable|string',
                'correct_answer' => 'required|string',
                'category' => 'required|string',
                'difficulty_level' => 'required|string',
                'year_level' => 'required|string',
                'subcategory' => 'nullable|string',
            ]);

            // Determine if question has images
            $hasImages = !empty($validated['question_image']) ||
                        !empty($validated['choice_a_image']) ||
                        !empty($validated['choice_b_image']) ||
                        !empty($validated['choice_c_image']) ||
                        !empty($validated['choice_d_image']);

            $validated['has_images'] = $hasImages ? 1 : 0;
            $validated['is_active'] = 1;

            $result = \DB::connection('mongodb')
                ->table('quiz_questions')
                ->insertGetId($validated);

            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => true,
                'message' => 'Question added successfully',
                'question_id' => (string) $result
            ], 201));

        } catch (\Exception $e) {
            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => false,
                'message' => 'Error adding question',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    public function debug()
    {
        try {
            $total = \DB::connection('mongodb')->table('quiz_questions')->count();
            $active = \DB::connection('mongodb')->table('quiz_questions')->where('is_active', 1)->count();
            $inactive = \DB::connection('mongodb')->table('quiz_questions')->where('is_active', 0)->count();

            // Manual distinct by grouping
            $categoriesRaw = \DB::connection('mongodb')
                ->table('quiz_questions')
                ->select('category')
                ->groupBy('category')
                ->get();

            $categories = $categoriesRaw->pluck('category')->filter()->unique()->values()->toArray();

            $difficultiesRaw = \DB::connection('mongodb')
                ->table('quiz_questions')
                ->select('difficulty_level')
                ->groupBy('difficulty_level')
                ->get();

            $difficulties = $difficultiesRaw->pluck('difficulty_level')->filter()->unique()->values()->toArray();

            $yearLevelsRaw = \DB::connection('mongodb')
                ->table('quiz_questions')
                ->select('year_level')
                ->groupBy('year_level')
                ->get();

            $yearLevels = $yearLevelsRaw->pluck('year_level')->filter()->unique()->values()->toArray();

            // Count questions with images
            $withImages = \DB::connection('mongodb')
                ->table('quiz_questions')
                ->where('has_images', 1)
                ->count();

            // Detailed breakdown (ACTIVE questions only)
            $breakdown = [];
            foreach (['Math', 'Science'] as $cat) {
                foreach (['Easy', 'Average', 'Difficult'] as $diff) {
                    foreach (['ELEMENTARY', 'JUNIOR', 'SENIOR'] as $year) {
                        $count = \DB::connection('mongodb')
                            ->table('quiz_questions')
                            ->where('category', $cat)
                            ->where('difficulty_level', $diff)
                            ->where('year_level', $year)
                            ->where('is_active', 1)
                            ->count();
                        $breakdown["{$cat} - {$diff} - {$year}"] = $count;
                    }
                }
            }

            // Get sample from each category
            $samples = [];
            foreach (['Math', 'Science'] as $cat) {
                $sample = \DB::connection('mongodb')
                    ->table('quiz_questions')
                    ->where('category', $cat)
                    ->limit(2)
                    ->get()
                    ->map(function($q) {
                        return json_decode(json_encode($q), true);
                    });
                $samples[$cat] = $sample;
            }

            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => true,
                'total_questions' => $total,
                'active_questions' => $active,
                'inactive_questions' => $inactive,
                'questions_with_images' => $withImages,
                'questions_text_only' => $total - $withImages,
                'categories_found' => $categories,
                'difficulties_found' => $difficulties,
                'year_levels_found' => $yearLevels,
                'breakdown' => $breakdown,
                'sample_questions' => $samples,
            ]));

        } catch (\Exception $e) {
            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500));
        }
    }

    public function getStatistics()
    {
        try {
            $stats = [
                'total' => \DB::connection('mongodb')->table('quiz_questions')->count(),
                'active' => \DB::connection('mongodb')->table('quiz_questions')->where('is_active', 1)->count(),
                'inactive' => \DB::connection('mongodb')->table('quiz_questions')->where('is_active', 0)->count(),
                'with_images' => \DB::connection('mongodb')->table('quiz_questions')->where('has_images', 1)->count(),
                'by_category' => [
                    'Math' => \DB::connection('mongodb')->table('quiz_questions')->where('category', 'Math')->where('is_active', 1)->count(),
                    'Science' => \DB::connection('mongodb')->table('quiz_questions')->where('category', 'Science')->where('is_active', 1)->count(),
                ],
                'by_difficulty' => [
                    'Easy' => \DB::connection('mongodb')->table('quiz_questions')->where('difficulty_level', 'Easy')->where('is_active', 1)->count(),
                    'Average' => \DB::connection('mongodb')->table('quiz_questions')->where('difficulty_level', 'Average')->where('is_active', 1)->count(),
                    'Difficult' => \DB::connection('mongodb')->table('quiz_questions')->where('difficulty_level', 'Difficult')->where('is_active', 1)->count(),
                ],
                'by_year_level' => [
                    'ELEMENTARY' => \DB::connection('mongodb')->table('quiz_questions')->where('year_level', 'ELEMENTARY')->where('is_active', 1)->count(),
                    'JUNIOR' => \DB::connection('mongodb')->table('quiz_questions')->where('year_level', 'JUNIOR')->where('is_active', 1)->count(),
                    'SENIOR' => \DB::connection('mongodb')->table('quiz_questions')->where('year_level', 'SENIOR')->where('is_active', 1)->count(),
                ],
            ];

            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => true,
                'statistics' => $stats
            ]));

        } catch (\Exception $e) {
            // ✅ FIX: Added missing closing ))
            return $this->cors(response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
                'error' => $e->getMessage()
            ], 500));
        }
    }
}
