<?php

namespace Exceedone\Exment\Observers;

use Exceedone\Exment\Services\AI\ChatbotService;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomValue;

class ChatbotFaqObserver
{
    public function creating(CustomValue $model): void
    {
        $customTable = $model->custom_table ?? null;
        if (!$customTable || $customTable->table_name !== SystemTableName::CHATBOT_FAQ) {
            return;
        }
        $this->setEmbeddingIfNeeded($model, true);
    }

    public function updating(CustomValue $model): void
    {
        $customTable = $model->custom_table ?? null;
        if (!$customTable || $customTable->table_name !== SystemTableName::CHATBOT_FAQ) {
            return;
        }

        $oldRaw = $model->getRawOriginal('value');
        if (is_string($oldRaw)) {
            $oldValue = json_decode($oldRaw, true) ?: [];
        } elseif (is_array($oldRaw)) {
            $oldValue = $oldRaw;
        } else {
            $oldValue = [];
        }

        $newValue = is_array($model->value) ? $model->value : (array) $model->value;

        $questionOld = $oldValue['question'] ?? null;
        $questionNew = $newValue['question'] ?? null;
        if ($questionNew !== $questionOld) {
            $this->setEmbeddingIfNeeded($model, false);
        }
    }

    private function setEmbeddingIfNeeded(CustomValue $model, bool $isCreate): void
    {

        $value = is_array($model->value) ? $model->value : (array) $model->value;
        $question = $value['question'] ?? null;
        if (!is_string($question) || $question === '') {
            return;
        }

        /** @var ChatbotService $chatbotService */
        $chatbotService = app(ChatbotService::class);
        $embedding = $chatbotService->getEmbeddingFromAI($question);
        if (!$embedding || !is_array($embedding)) {
            return;
        }

        $model->embedding_vector = json_encode($embedding);
    }
}
