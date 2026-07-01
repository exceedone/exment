<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;

/**
 * Xử lý postback từ Flex Message: thực thi action Workflow tương ứng.
 */
class LineWorkflowAction
{
    /** Tách query-string postback data thành mảng key=>value. */
    public static function parsePostback(string $data): array
    {
        parse_str($data, $out);
        return $out;
    }

    /**
     * Thực thi action workflow theo postback. Trả message để reply về LINE.
     */
    public static function handle(array $data, ?string $lineUserId): string
    {
        if (empty($lineUserId)) {
            return 'Không xác định được người dùng LINE.';
        }
        $tableKey = array_get($data, 'table');
        $valueId  = array_get($data, 'id');
        $actionId = array_get($data, 'action');
        if (is_nullorempty($tableKey) || is_nullorempty($valueId) || is_nullorempty($actionId)) {
            return 'Dữ liệu thao tác không hợp lệ.';
        }

        // map line_user_id -> base_user_id
        $userId = LineAccountLink::where('line_user_id', $lineUserId)->value('user_id');
        if (is_nullorempty($userId)) {
            return 'Tài khoản LINE chưa liên kết. Vui lòng liên kết trước.';
        }

        // resolve the login user for that base_user_id and authenticate (for authority checks)
        $loginUser = LoginUser::where('base_user_id', $userId)->first();
        if (!$loginUser) {
            return 'Tài khoản chưa kích hoạt đăng nhập.';
        }
        $guard = \Auth::guard(config('admin.auth.guard', 'admin'));
        $guard->login($loginUser);
        try {
            $custom_table = CustomTable::getEloquent($tableKey);
            if (!$custom_table) {
                return 'Không tìm thấy bảng dữ liệu.';
            }
            $custom_value = $custom_table->getValueModel($valueId);
            if (!$custom_value) {
                return 'Không tìm thấy bản ghi.';
            }

            $wfAction = $custom_value->getWorkflowActions(true, false)->first(function ($a) use ($actionId) {
                return (string) $a->id === (string) $actionId;
            });
            if (!$wfAction) {
                return 'Thao tác không khả dụng hoặc đã được xử lý.';
            }

            // FIX 3 (tap side): comment-required actions must be handled on web
            if ($wfAction->comment_type === \Exceedone\Exment\Enums\WorkflowCommentType::REQUIRED) {
                return 'Thao tác này cần nhập ý kiến, vui lòng xử lý trên web.';
            }

            try {
                $wfAction->executeAction($custom_value, []);
            } catch (\Exception $e) {
                \Log::warning('LINE workflow executeAction failed', ['error' => $e->getMessage()]);
                return 'Có lỗi khi xử lý, vui lòng thử lại trên web.';
            }

            return '✅ Đã xử lý: ' . $wfAction->action_name;
        } finally {
            $guard->logout();
        }
    }
}
