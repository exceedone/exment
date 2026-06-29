<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\System;

/**
 * Liên kết tài khoản LINE bằng mã 1 lần, lưu vào bảng riêng line_account_links.
 * - generateCodeForUser : sinh mã cho 1 user_id (Exment user).
 * - deepLink            : URL bấm là điền sẵn "LINK <mã>".
 * - handleMessage       : khớp "LINK <mã>" -> lưu line_user_id vào đúng bản ghi.
 */
class LineAccountLinker
{
    public const PREFIX = 'LINK';

    /** Sinh mã 1 lần cho 1 user_id (Exment user), trả về mã. */
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
     * Khớp tin "LINK <mã>" -> lưu line_user_id.
     *
     * @return LineAccountLink|null bản ghi vừa liên kết, hoặc null nếu không khớp/đã bị chiếm.
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

        // 1 LINE ↔ 1 tài khoản: từ chối nếu line_user_id đã gắn account khác
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
