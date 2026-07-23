<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\System;

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
     * @return LineAccountLink|null the linked record, or null if it does not match or is already taken.
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

        $link = LineAccountLink::where('line_link_code', $code)->first();
        if (!$link) {
            return null;
        }

        // One LINE account maps to one user: reject if this line_user_id is already tied to another account
        $taken = LineAccountLink::where('line_user_id', $lineUserId)
            ->where('user_id', '!=', $link->user_id)
            ->exists();
        if ($taken) {
            return null;
        }

        $link->markLinked($lineUserId);
        return $link;
    }
}
