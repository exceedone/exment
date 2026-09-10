<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Links a LINE account via a one-time code, stored in the dedicated line_account_links table.
 * - generateCodeForUser : generate a code for an Exment user_id.
 * - deepLink            : URL that pre-fills "LINK <code>".
 * - handleMessage       : match "LINK <code>" -> store line_user_id on the matching record.
 */
class LineAccountLinker
{
    public const PREFIX = 'LINK';

    /** Generate a one-time code for an Exment user_id and return it. */
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
     * Match a "LINK <code>" message and store the line_user_id.
     *
     * Brute-force guard: the code space is small (6 hex chars) and the webhook is
     * public, so (a) a code is only valid for link_code_ttl_minutes after it was
     * generated and (b) each LINE user gets link_max_attempts wrong codes per
     * link_attempt_decay_minutes before LINK messages are ignored — both return
     * null, which the webhook answers with the generic "invalid or expired" text.
     *
     * @return LineAccountLink|null the linked record, or null if it does not match, is
     *                              expired/rate-limited, or the LINE account is already taken.
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

        // One LINE account maps to one user: reject if this line_user_id is already tied to another account
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

    /** RateLimiter key for wrong-code attempts from one LINE user. */
    public static function attemptKey(string $lineUserId): string
    {
        return 'line_link_attempt:' . $lineUserId;
    }
}
