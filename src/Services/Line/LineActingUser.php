<?php

namespace Exceedone\Exment\Services\Line;

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
        return is_nullorempty($userId) ? null : (int) $userId;
    }

    public static function loginUser(int $userId): ?LoginUser
    {
        return LoginUser::where('base_user_id', $userId)->first();
    }

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
