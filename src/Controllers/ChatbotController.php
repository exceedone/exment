<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends BaseController
{
    // Response types
    public const RESPONSE_TYPE_FAQ_ID = 'faq_id';
    public const RESPONSE_TYPE_DIRECT_ANSWER = 'direct_answer';
    public const RESPONSE_TYPE_FAQ_ANSWER = 'faq_answer';
    public const RESPONSE_TYPE_DIRECT_ANSWER_SAVED = 'direct_answer_saved';

    // Error codes
    public const ERROR_CHATBOT_DISABLED = 'CHATBOT_DISABLED';
    public const ERROR_NO_ANSWER_FOUND = 'NO_ANSWER_FOUND';
    public const ERROR_INVALID_AI_RESPONSE = 'INVALID_AI_RESPONSE';
    public const ERROR_PROCESSING_ERROR = 'PROCESSING_ERROR';
    public const ERROR_FAQ_TABLE_NOT_FOUND = 'FAQ_TABLE_NOT_FOUND';
    public const ERROR_FAQ_SAVE_FAILED = 'FAQ_SAVE_FAILED';

    // HTTP status codes
    public const HTTP_OK = 200;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    // Validation rules
    public const MAX_MESSAGE_LENGTH = 1000;
    public const MAX_SESSION_ID_LENGTH = 255;

    // AI server endpoints
    public const AI_SERVER_EMBED_ENDPOINT = '/api/chatbot/embed';
    public const AI_SERVER_ASK_ENDPOINT = '/api/chatbot/ask-ai';
    public const AI_SERVER_HOST_CONFIG_KEY = 'exment.ai_server_host';

    /**
     * Returns chatbot configuration including UI texts and idle time.
     *
     * @return JsonResponse
     */
    public function config(): JsonResponse
    {
        $timeidle = System::chatbot_available();
        if (!$timeidle) {
            return response()->json([]);
        }
        $uiTextTable = CustomTable::getEloquent(SystemTableName::CHATBOT_UI_TEXT);
        $uiTexts = $uiTextTable ? $uiTextTable->getValueModel()->all() : [];
        $uiTexts = collect($uiTexts)->map(function ($item) {
            $value = is_array($item->value) ? $item->value : (array) $item->value;
            return array_merge(['id' => $item->id], $value);
        })->values();
        return response()->json([
            'ui_texts' => $uiTexts,
            'timeidle' => $timeidle,
        ]);
    }

    /**
     * Returns a list of featured FAQ entries filtered by workflow status.
     *
     * @return JsonResponse
     */
    public function faq(): JsonResponse
    {
        $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
        $records = $customTable->getValueModel()->query()->where('value->is_featured', 1)->orderBy('value->display_order')->get();

        if (System::chatbot_faq_wf()) {
            $filterArray = preg_split('/\r\n|\r|\n/', System::chatbot_faq_wf_status_filters());
            $filterArray = array_filter(array_map('trim', $filterArray));

            $filter = $records->filter(function ($record) use ($filterArray) {
                return in_array($record->workflow_status_name, $filterArray);
            });
        } else {
            $filter = $records;
        }
        $result = $filter->map(function ($record) {
            return [
                'question' => $record->value['question'] ?? null,
                'display_order' => $record->value['display_order'] ?? null,
            ];
        })->values()->all();

        return response()->json($result, self::HTTP_OK, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Handle user messages: get embedding, search FAQ, fallback to AI answer if needed.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => 'required|string|max:' . self::MAX_MESSAGE_LENGTH,
            'session_id' => 'nullable|string|max:' . self::MAX_SESSION_ID_LENGTH,
            'user_id' => 'nullable|integer',
        ]);

        $message = $request->input('question');
        $sessionId = $request->input('session_id');
        $userId = $request->input('user_id');
        $similarityThreshold = 0.85;

        if (!System::chatbot_available()) {
            return response()->json([
                'success' => false,
                'message' => 'Chatbot is currently unavailable',
                'error_code' => self::ERROR_CHATBOT_DISABLED
            ], self::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            // 1. Get embedding vector from AI server
            $embedding = $this->getEmbeddingFromAI($message);
            if (!$embedding || !is_array($embedding)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to get embedding from AI server',
                    'error_code' => self::ERROR_PROCESSING_ERROR
                ], self::HTTP_INTERNAL_SERVER_ERROR);
            }

            // 2. Search FAQ by vector similarity
            $faqMatch = $this->findMostSimilarFaq($embedding, $similarityThreshold);
            if ($faqMatch) {
                // Found similar FAQ
                return response()->json([
                    'success' => true,
                    'answer' => $faqMatch['answer'],
                    'question' => $faqMatch['question'],
                    'similarity' => $faqMatch['similarity'],
                    'message_id' => uniqid('msg_'),
                    'timestamp' => now()->toISOString(),
                ], self::HTTP_OK, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // 3. No match, get answer from AI server
            $aiAnswer = $this->getAnswerFromAI($message);
            if (!$aiAnswer || !is_string($aiAnswer)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to get answer from AI server',
                    'error_code' => self::ERROR_PROCESSING_ERROR
                ], self::HTTP_INTERNAL_SERVER_ERROR);
            }

            // 4. Save new FAQ with embedding
            $savedFaqId = $this->saveToFaqTableWithEmbedding($message, $aiAnswer, $embedding);

            return response()->json([
                'success' => true,
                'answer' => $aiAnswer,
                'question' => $message,
                'message_id' => uniqid('msg_'),
                'timestamp' => now()->toISOString(),
            ], self::HTTP_OK, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            \Log::error('Chatbot ask method error', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'session_id' => $sessionId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your request',
                'error_code' => self::ERROR_PROCESSING_ERROR
            ], self::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Call AI server to get embedding vector for a message.
     * @param string $message
     * @return array|null
     */
    private function getEmbeddingFromAI(string $message): ?array
    {
        $host = config(self::AI_SERVER_HOST_CONFIG_KEY);
        if (!$host) {
            \Log::error('AI server host not configured');
            return null;
        }
        try {
            $url = rtrim($host, '/') . self::AI_SERVER_EMBED_ENDPOINT;
            $response = \Http::timeout(15)->post($url, [
                'text' => [$message]
            ]);
            if ($response->successful()) {
                $data = $response->json();
                if (!is_array($data) || empty($data)) {
                    \Log::error('AI embedding: response is not array or empty', ['body' => $response->body()]);
                    return null;
                }
                // Tìm đúng object theo text (phòng trường hợp trả về nhiều kết quả)
                foreach ($data as $item) {
                    if (
                        isset($item['text'], $item['label'], $item['embedding']) &&
                        $item['text'] === $message &&
                        $item['label'] === 'question' &&
                        is_array($item['embedding']) && count($item['embedding']) > 0
                    ) {
                        return $item['embedding'];
                    }
                }
                \Log::error('AI embedding: no valid embedding found', ['data' => $data, 'message' => $message]);
            } else {
                \Log::error('AI embedding response error', ['body' => $response->body()]);
            }
        } catch (\Exception $e) {
            \Log::error('AI embedding request failed', ['error' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * Find the most similar FAQ by cosine similarity.
     * @param array $embedding
     * @param float $threshold
     * @return array|null
     */
    private function findMostSimilarFaq(array $embedding, float $threshold): ?array
    {
        $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
        if (!$customTable) return null;
        $faqs = $customTable->getValueModel()->all();
        $best = null;
        $bestSim = -1;
        foreach ($faqs as $faq) {
            $value = is_array($faq->value) ? $faq->value : (array) $faq->value;
            if (empty($value['embedding']) || !is_array($value['embedding'])) continue;
            $sim = $this->cosineSimilarity($embedding, $value['embedding']);
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $best = [
                    'id' => $faq->id,
                    'question' => $value['question'] ?? null,
                    'answer' => $value['answer'] ?? null,
                    'similarity' => $sim
                ];
            }
        }
        if ($best && $best['similarity'] >= $threshold) return $best;
        return null;
    }

    /**
     * Cosine similarity between two vectors.
     * @param array $a
     * @param array $b
     * @return float
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA == 0.0 || $normB == 0.0) return 0.0;
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Call AI server to get answer for a message.
     * @param string $message
     * @return string|null
     */
    private function getAnswerFromAI(string $message): ?string
    {
        $host = config(self::AI_SERVER_HOST_CONFIG_KEY);
        if (!$host) {
            \Log::error('AI server host not configured');
            return null;
        }
        try {
            $url = rtrim($host, '/') . self::AI_SERVER_ASK_ENDPOINT;
            $response = \Http::timeout(20)->post($url, [
                'question' => $message
            ]);
            if ($response->successful()) {
                $data = $response->json();
                // Expecting: { "answer": "..." }
                if (is_array($data) && isset($data['ai_response'])) {
                    return $data['ai_response'];
                }
            }
            \Log::error('AI answer response error', ['body' => $response->body()]);
        } catch (\Exception $e) {
            \Log::error('AI answer request failed', ['error' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * Save question, answer, and embedding to FAQ table.
     * @param string $question
     * @param string $answer
     * @param array $embedding
     * @return int|null
     */
    private function saveToFaqTableWithEmbedding(string $question, string $answer, array $embedding): ?int
    {
        try {
            $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
            if (!$customTable) {
                \Log::error('CHATBOT_FAQ table not found');
                return null;
            }
            $maxDisplayOrder = $customTable->getValueModel()->max('value->display_order') ?? 0;
            $nextDisplayOrder = $maxDisplayOrder + 1;
            $faqData = [
                'question' => $question,
                'answer' => $answer,
                'embedding' => $embedding,
                'display_order' => $nextDisplayOrder,
                'is_featured' => false,
            ];
            $newFaq = $customTable->getValueModel()->create([
                'value' => $faqData,
                'created_user_id' => auth()->id() ?? null,
                'updated_user_id' => auth()->id() ?? null,
            ]);
            \Log::info('New FAQ saved from AI answer', [
                'faq_id' => $newFaq->id,
                'question' => $question,
                'display_order' => $nextDisplayOrder
            ]);
            return $newFaq->id;
        } catch (\Exception $e) {
            \Log::error('Error saving FAQ to table', [
                'question' => $question,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
