<?php

namespace Exceedone\Exment\Services\Line;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\Log;


class LineSendLogger
{
    public const TABLE_NAME = SystemTableName::LINE_SEND_LOG;

    public const TYPE_TEXT = 'text';
    public const TYPE_FLEX = 'flex';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    /**
     *
     * @param array $context  
     * @param array $messages
     * @param array $result 
     */
    public static function record(array $context, array $messages, array $result): void
    {
        $table = CustomTable::getEloquent(static::TABLE_NAME);
        if (!$table) {
            return; 
        }

        try {
            $modelname = getModelName(static::TABLE_NAME);
            $model = new $modelname();

            $model->setValue([
                'line_user_id'  => array_get($context, 'line_user_id'),
                'message_type'  => array_get($context, 'message_type', static::TYPE_TEXT),
                'flex_template' => array_get($context, 'flex_template_id'),
                'subject'       => array_get($context, 'subject'),
                'body'          => static::buildBody($messages, boolval(array_get($context, 'save_body', true))),
                'user'          => array_get($context, 'user_id'),
                'send_datetime' => Carbon::now()->format('Y-m-d H:i:s'),
                'status'        => static::resolveStatus($result),
                'error_message' => static::formatError($result),
            ]);

           
            $model->parent_id   = array_get($context, 'parent_id');
            $model->parent_type = array_get($context, 'parent_type');

            $model->save();
        } catch (\Exception $e) {
            Log::warning('LINE send log failed', ['error' => $e->getMessage()]);
        }
    }

    public static function recentlySent(int $userId, $parentId, ?string $parentType, int $minutes): bool
    {
        if ($minutes <= 0 || is_nullorempty($parentId) || is_nullorempty($parentType)) {
            return false;
        }
        $table = CustomTable::getEloquent(static::TABLE_NAME);
        if (!$table) {
            return false;
        }
        $index_user = CustomColumn::getEloquent('user', $table)->getIndexColumnName();
        $index_send = CustomColumn::getEloquent('send_datetime', $table)->getIndexColumnName();

        return getModelName(static::TABLE_NAME)::where($index_user, $userId)
            ->where('parent_id', $parentId)
            ->where('parent_type', $parentType)
            ->where($index_send, '>=', Carbon::now()->subMinutes($minutes)->format('Y-m-d H:i:s'))
            ->exists();
    }

    public static function buildBody(array $messages, bool $saveBody): string
    {
        if (!$saveBody) {
            return exmtrans('mail_template.disable_body');
        }
        return json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public static function resolveStatus(array $result): string
    {
        return !empty($result['ok']) ? static::STATUS_SUCCESS : static::STATUS_FAILED;
    }

    public static function formatError(array $result): ?string
    {
        if (!empty($result['ok'])) {
            return null;
        }
        $status = $result['status'] ?? '';
        $raw    = (string) ($result['raw'] ?? '');
        return trim("HTTP {$status} {$raw}");
    }
}
