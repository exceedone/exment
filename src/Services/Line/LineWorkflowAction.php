<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\CustomTable;

class LineWorkflowAction
{
    public static function parsePostback(string $data): array
    {
        parse_str($data, $out);
        return $out;
    }

    public static function handle(array $data, ?string $lineUserId): string
    {
        if (empty($lineUserId)) {
            return exmtrans('line.user_unidentified');
        }
        $tableKey = array_get($data, 'table');
        $valueId  = array_get($data, 'id');
        $actionId = array_get($data, 'action');
        if (is_nullorempty($tableKey) || is_nullorempty($valueId) || is_nullorempty($actionId)) {
            return exmtrans('line.invalid_action_data');
        }

        $userId = LineActingUser::userId($lineUserId);
        if ($userId === null) {
            return exmtrans('line.account_not_linked');
        }

        $loginUser = LineActingUser::loginUser($userId);
        if (!$loginUser) {
            return exmtrans('line.login_not_activated');
        }
        return LineActingUser::runAs($loginUser, function () use ($tableKey, $valueId, $actionId) {
            $custom_table = CustomTable::getEloquent($tableKey);
            if (!$custom_table) {
                return exmtrans('line.table_not_found');
            }
            $custom_value = $custom_table->getValueModel($valueId);
            if (!$custom_value) {
                return exmtrans('line.record_not_found');
            }

            $wfAction = $custom_value->getWorkflowActions(true, false)->first(function ($a) use ($actionId) {
                return (string) $a->id === (string) $actionId;
            });
            if (!$wfAction) {
                return exmtrans('line.action_unavailable');
            }

            if ($wfAction->comment_type === \Exceedone\Exment\Enums\WorkflowCommentType::REQUIRED) {
                return exmtrans('line.action_need_comment');
            }

            try {
                $wfAction->executeAction($custom_value, []);
            } catch (\Exception $e) {
                \Log::warning('LINE workflow executeAction failed', ['error' => $e->getMessage()]);
                return exmtrans('line.action_error');
            }

            return exmtrans('line.action_done', $wfAction->action_name);
        });
    }
}
