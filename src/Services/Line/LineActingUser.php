<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;

/**
 * Resolves the Exment identity behind a LINE user id and runs code with that
 * user's authority. This is the ONE place for the auth-sensitive
 * "lineUserId -> LineAccountLink -> LoginUser -> guard login/logout" sequence
 * every LINE postback/message handler needs — a security fix here reaches all
 * of them (LineWorkflowAction, SafetyCheckAction, future handlers) at once.
 */
class LineActingUser
{
    /** The linked Exment user id for a LINE user id, or null when not linked. */
    public static function userId(?string $lineUserId): ?int
    {
        if (is_nullorempty($lineUserId)) {
            return null;
        }
        $userId = LineAccountLink::where('line_user_id', $lineUserId)->value('user_id');
        return is_nullorempty($userId) ? null : (int) $userId;
    }

    /** The LoginUser for an Exment user id, or null when login is not activated. */
    public static function loginUser(int $userId): ?LoginUser
    {
        return LoginUser::where('base_user_id', $userId)->first();
    }

    /**
     * Runs $callback while authenticated as $loginUser (for authority checks on
     * saves), logging out again afterwards even if $callback throws.
     */
    public static function runAs(LoginUser $loginUser, \Closure $callback)
    {
        $guard = \Auth::guard(config('admin.auth.guard', 'admin'));
        $guard->login($loginUser);
        try {
            return $callback();
        } finally {
            $guard->logout();
        }
    }
}
