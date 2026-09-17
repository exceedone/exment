<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\RateLimiter;

class LineAccountLinker
{
    public const PREFIX = 'LINK';

    public function generateCodeForUser(int $userId): string
    {
        return LineAccountLink::forUser($userId)->generateCode();
    }

    public function deepLink(string $code): string
    {
        $oa   = ltrim((string) System::system_line_oa_basic_id(), '@');
        $text = rawurlencode(self::PREFIX . ' ' . $code);
        return "https://line.me/R/oaMessage/@{$oa}/?{$text}";
    }

    /**
     * @return LineAccountLink|null
     */
    public function handleMessage(string $text, ?string $lineUserId): ?LineAccountLink
    {
        if (empty($lineUserId)) {
            return null;
        }
        if (!preg_match('/^\s*' . self::PREFIX . '\s+([A-Za-z0-9]{4,12})\s*$/i', $text, $m)) {
            return null;
        }
        $code = strtoupper($m[1]);

        $attemptKey = static::attemptKey($lineUserId);
        if (RateLimiter::tooManyAttempts($attemptKey, (int) config('exment.line.link_max_attempts', 5))) {
            return null;
        }

        $link = LineAccountLink::where('line_link_code', $code)->first();
        if (!$link || !$link->hasActiveCode()) {
            RateLimiter::hit($attemptKey, 60 * (int) config('exment.line.link_attempt_decay_minutes', 10));
            return null;
        }

        $taken = LineAccountLink::where('line_user_id', $lineUserId)
            ->where('user_id', '!=', $link->user_id)
            ->exists();
        if ($taken) {
            return null;
        }

        RateLimiter::clear($attemptKey);
        $link->markLinked($lineUserId);
        return $link;
    }

    public static function attemptKey(string $lineUserId): string
    {
        return 'line_link_attempt:' . $lineUserId;
    }
}
