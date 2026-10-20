<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

class LineActingUser
{
    public static function userId(?string $lineUserId): ?int
    {
        if (is_nullorempty($lineUserId)) {
            return null;
        }
        $userId = LineAccountLink::where('line_user_id', $lineUserId)->value('user_id');
        // a soft-deleted user keeps both its link and its login_users row, so it could still act from LINE
        if (is_nullorempty($userId) || !static::userExists((int) $userId)) {
            return null;
        }
        return (int) $userId;
    }

    /**
     * Whether the user is still there, not deleted. Not filtered by the current viewer's permission.
     */
    public static function userExists(int $userId): bool
    {
        return getModelName(SystemTableName::USER)::withoutGlobalScope(CustomValueModelScope::class)
            ->whereKey($userId)
            ->exists();
    }

    public static function loginUser(int $userId): ?LoginUser
    {
        return LoginUser::where('base_user_id', $userId)->first();
    }

    /**
     * @return mixed the callback's return value
     */
    public static function runAs(LoginUser $loginUser, \Closure $callback)
    {
        $guard = \Auth::guard(config('admin.auth.guard', 'admin'));
        System::clearRequestSession();
        $guard->login($loginUser);
        try {
            return $callback();
        } finally {
            $guard->logoutCurrentDevice();
            System::clearRequestSession();
        }
    }
}
