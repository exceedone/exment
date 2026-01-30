<?php

namespace Exceedone\Exment\Observers;

use Exceedone\Exment\Enums\ErrorCode;
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

        if (! $this->updateEmbeddingIfNecessary($model, true)) {
            throw new \Exception(
                exmtrans('tenant.faq_validate'),
                ErrorCode::ERROR_CODE_VALIDATION_FAILED 
            );
        }
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
            $this->updateEmbeddingIfNecessary($model, false);
        }
    }

    private function updateEmbeddingIfNecessary(CustomValue $model, bool $isCreate): bool
    {

        $value = is_array($model->value) ? $model->value : (array) $model->value;
        $question = $value['question'] ?? null;
        if (!is_string($question) || $question === '') {
            return false;
        }

        /** @var ChatbotService $chatbotService */
        $chatbotService = app(ChatbotService::class);
        $embedding = $chatbotService->getEmbeddingFromAI($question);
        if (!$embedding || !is_array($embedding)) {
            return false;
        }

        $model->embedding_vector = json_encode($embedding);
        return true;
    }
}
