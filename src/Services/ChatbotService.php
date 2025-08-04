<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class ChatbotService
{
    public const AI_SERVER_EMBED_ENDPOINT = '/api/chatbot/embed';
    public const AI_SERVER_ASK_ENDPOINT = '/api/chatbot/ask-ai';

    /**
     * Get the embedding vector for a message from the AI server.
     *
     * @param string $message The message to embed.
     * @return array|null The embedding vector, or null on failure.
     */
    public function getEmbeddingFromAI(string $message): ?array
    {
        $host = config('exment.ai_server_host');
        if (!$host) {
            \Log::error('AI server host not configured');
            return null;
        }
        try {
            $url = rtrim($host, '/') . self::AI_SERVER_EMBED_ENDPOINT;
            $response = \Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => env('AI_SERVER_API_KEY')
                ])
                ->post($url, [
                    'text' => [$message]
                ]);
            if ($response->successful()) {
                $data = $response->json();
                if (!is_array($data) || empty($data)) {
                    \Log::error('AI embedding: response is not array or empty', ['body' => $response->body()]);
                    return null;
                }
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
     * Find the most similar FAQ entry to the given embedding, above a similarity threshold.
     *
     * @param array $embedding The embedding vector to compare.
     * @param float $threshold The minimum similarity threshold.
     * @return array|null The most similar FAQ entry (id, question, answer, similarity), or null if none found.
     */
    public function findMostSimilarFaq(array $embedding, float $threshold): ?array
    {
        $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
        if (!$customTable) return null;
        $faqs = $customTable->getValueModel()->all();
        $best = null;
        $bestSim = -1;
        foreach ($faqs as $faq) {
            $array_em = json_decode($faq->embedding_vector);
            if (empty($array_em) || !is_array($array_em)) continue;
            $value = $faq->value ?? [];
            $sim = $this->cosineSimilarity($embedding, $array_em);
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
     * Calculate the cosine similarity between two embedding vectors.
     *
     * @param array $a First embedding vector.
     * @param array $b Second embedding vector.
     * @return float Cosine similarity value between 0 and 1.
     */
    public function cosineSimilarity(array $a, array $b): float
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
     * Call the AI API to get an answer for a question, optionally with history and answer choices.
     *
     * @param string $message The question to ask.
     * @param array $history Conversation history (optional).
     * @param array $answerChoices List of answer choices (optional).
     * @return string|null The AI's answer, or null on failure.
     */
    public function getAnswerFromAI(string $message, array $history = [], array $answerChoices = []): ?string
    {
        $host = config('exment.ai_server_host');
        if (!$host) {
            \Log::error('AI server host not configured');
            return null;
        }
        try {
            $url = rtrim($host, '/') . self::AI_SERVER_ASK_ENDPOINT;
            $payload = [
                'history' => $history,
                'question' => $message,
                'answer_choices' => $answerChoices,
            ];
            $response = \Http::timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => env('AI_SERVER_API_KEY')
                ])
                ->post($url, $payload);
            if ($response->successful()) {
                $data = $response->json();
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
     * Save a new FAQ entry with its embedding to the FAQ table.
     *
     * @param string $question The FAQ question.
     * @param string $answer The FAQ answer.
     * @param array $embedding The embedding vector for the question.
     * @return int|null The ID of the new FAQ entry, or null on failure.
     */
    public function saveToFaqTableWithEmbedding(string $question, string $answer, array $embedding): ?int
    {
        try {
            $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
            if (!$customTable) {
                \Log::error('CHATBOT_FAQ table not found');
                return null;
            }
            // $maxDisplayOrder = $customTable->getValueModel()->max('value->display_order') ?? 0;
            $nextDisplayOrder = 0;
            $faqData = [
                'question' => $question,
                'answer' => $answer,
                'display_order' => $nextDisplayOrder,
                'is_featured' => false,
            ];
            $newFaq = $customTable->getValueModel()->create([
                'value' => $faqData,
                'embedding_vector' => json_encode($embedding),
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

    /**
     * Return up to $limit answers (strings) from FAQs with similarity above the threshold, sorted descending.
     *
     * @param array $embedding The embedding vector to compare.
     * @param float $threshold The minimum similarity threshold.
     * @param int $limit The maximum number of answers to return.
     * @return array List of answer strings.
     */
    public function getAnswerChoices(array $embedding, float $threshold, int $limit = 3): array
    {
        $customTable = CustomTable::getEloquent(SystemTableName::CHATBOT_FAQ);
        if (!$customTable) return [];
        $faqs = $customTable->getValueModel()->all();
        $similarFaqs = [];
        foreach ($faqs as $faq) {
            $array_em = json_decode($faq->embedding_vector);
            if (empty($array_em) || !is_array($array_em)) continue;
            $sim = $this->cosineSimilarity($embedding, $array_em);
            if ($sim >= $threshold) {
                $similarFaqs[] = [
                    'answer' => $value['answer'] ?? null,
                    'similarity' => $sim
                ];
            }
        }
        usort($similarFaqs, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        return array_values(array_filter(array_map(function($item) { return $item['answer']; }, array_slice($similarFaqs, 0, $limit))));
    }
} 