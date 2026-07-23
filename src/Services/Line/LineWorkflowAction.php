<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;

/**
 * Handles Flex Message postbacks: runs the matching workflow action.
 */
class LineWorkflowAction
{
    /** Parses the postback query string into a key => value array. */
    public static function parsePostback(string $data): array
    {
        parse_str($data, $out);
        return $out;
    }

    /**
     * Executes the workflow action from a postback. Returns the reply message for LINE.
     */
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

        $userId = LineAccountLink::where('line_user_id', $lineUserId)->value('user_id');
        if (is_nullorempty($userId)) {
            return exmtrans('line.account_not_linked');
        }

        // Resolve the login user for that base_user_id and authenticate (for authority checks)
        $loginUser = LoginUser::where('base_user_id', $userId)->first();
        if (!$loginUser) {
            return exmtrans('line.login_not_activated');
        }
        $guard = \Auth::guard(config('admin.auth.guard', 'admin'));
        $guard->login($loginUser);
        try {
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

            // Comment-required actions must be handled on the web
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
        } finally {
            $guard->logout();
        }
    }
}
