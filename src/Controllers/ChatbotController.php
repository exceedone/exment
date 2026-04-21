<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\AI\ChatbotService;
use Exceedone\Exment\ColumnItems\CustomColumns\Editor;

class ChatbotController extends BaseController
{
    // Error codes
    public const ERROR_CHATBOT_DISABLED = 'CHATBOT_DISABLED';
    public const ERROR_NO_ANSWER_FOUND = 'NO_ANSWER_FOUND';
    public const ERROR_INVALID_AI_RESPONSE = 'INVALID_AI_RESPONSE';
    public const ERROR_PROCESSING_ERROR = 'PROCESSING_ERROR';
    public const ERROR_FAQ_TABLE_NOT_FOUND = 'FAQ_TABLE_NOT_FOUND';
    public const ERROR_FAQ_SAVE_FAILED = 'FAQ_SAVE_FAILED';

    // Chat modes
    public const MODE_FAQ = 'faq';
    public const MODE_EXTENDED = 'extended';

    // HTTP status codes
    public const HTTP_OK = 200;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    // Validation rules
    public const MAX_MESSAGE_LENGTH = 1000;
    public const MAX_SESSION_ID_LENGTH = 255;

    protected $chatbotService;

    public function __construct(ChatbotService $chatbotService)
    {
        $this->chatbotService = $chatbotService;
    }

    /**
     * Returns chatbot configuration including UI texts and idle time.
     *
     * @return JsonResponse
     */
    public function config(): JsonResponse
    {
        if (!System::chatbot_available()) {
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
            'timeidle' => System::chatbot_timeidle(),
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
        ]);

        $message = $request->input('question');
        $sessionId = $request->input('session_id');
        $userId = $request->input('user_id');
        $history = $request->input('history', []);
        $mode = $request->input('mode', self::MODE_FAQ);

        if (!in_array($mode, [self::MODE_FAQ, self::MODE_EXTENDED], true)) {
            $mode = self::MODE_FAQ;
        }

        $similarityThreshold = config('exment.chatbot_similarity_threshold', 0.85);
        $lowSimilarityThreshold = config('exment.chatbot_low_similarity_threshold', 0.6);

        if (!System::chatbot_available()) {
            return response()->json([
                'success' => false,
                'message' => exmtrans('chatbot.error_message'),
            ], 503);
        }

        try {
            // Extended mode (non-FAQ-based)
            if ($mode === self::MODE_EXTENDED) {
                $result = $this->chatbotService->getAnswerFromAI($message, $history, []);

                if (!$result['ok']) {
                    $status = $result['status'] ?? 500;

                    return response()->json([
                        'success' => false,
                        'message' => $this->mapChatbotErrorResponse(
                            $status,
                            $result['message'] ?? null
                        ),
                    ], $status);
                }

                return response()->json([
                    'success' => true,
                    'answer' => Editor::replaceImgUrl($result['answer']),
                    'question' => $message,
                    'mode' => self::MODE_EXTENDED,
                    'message_id' => uniqid('msg_'),
                    'timestamp' => now()->toISOString(),
                ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // 1. Get embedding vector from AI server
            $embedding = $this->chatbotService->getEmbeddingFromAI($message);
            \Log::debug('Chatbot embedding result', [
                'is_array' => is_array($embedding),
                'count' => is_array($embedding) ? count($embedding) : null,
                'sample' => is_array($embedding) ? array_slice($embedding, 0, 5) : null,
            ]);
            if (!$embedding || !is_array($embedding)) {
                return response()->json([
                    'success' => false,
                    'message' => exmtrans('chatbot.error_message'),
                ], 500);
            }

            // 2. Search FAQ by vector similarity
            $faqMatch = $this->chatbotService->findMostSimilarFaq($embedding, $similarityThreshold);
            if ($faqMatch) {
                return response()->json([
                    'success' => true,
                    'answer' => Editor::replaceImgUrl($faqMatch['answer']),
                    'question' => $faqMatch['question'],
                    'similarity' => $faqMatch['similarity'],
                    'message_id' => uniqid('msg_'),
                    'timestamp' => now()->toISOString(),
                ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $answerChoices = $this->chatbotService->getAnswerChoices($embedding, $lowSimilarityThreshold, 3);

            // 3. No match, get answer from AI server
            $result = $this->chatbotService->getAnswerFromAI($message, $history, $answerChoices);

            if (!$result['ok']) {
                $status = $result['status'] ?? 500;

                return response()->json([
                    'success' => false,
                    'message' => $this->mapChatbotErrorResponse(
                        $status,
                        $result['message'] ?? null
                    ),
                ], $status);
            }

            // 4. Save new FAQ with embedding
            $this->chatbotService->saveToFaqTableWithEmbedding($message, $result['answer'], $embedding);

            return response()->json([
                'success' => true,
                'answer' => Editor::replaceImgUrl($result['answer']),
                'question' => $message,
                'mode' => self::MODE_FAQ,
                'message_id' => uniqid('msg_'),
                'timestamp' => now()->toISOString(),
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            \Log::error('Chatbot ask method error', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'session_id' => $sessionId,
            ]);

            return response()->json([
                'success' => false,
                'message' => exmtrans('chatbot.error_message'),
            ], 500);
        }
    }

    protected function mapChatbotErrorResponse(int $status, ?string $upstreamMessage = null): string
    {
        \Log::warning('Chatbot upstream error', [
            'status' => $status,
            'message' => $upstreamMessage,
        ]);

        return match ($status) {
            401 => exmtrans('chatbot.error_unauthorized'),
            403 => exmtrans('chatbot.error_subscription_required'),
            404 => exmtrans('chatbot.error_service_not_found'),
            429 => exmtrans('chatbot.error_api_limit_exceeded'),
            default => exmtrans('chatbot.error_message'),
        };
    }
}
