<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

/**
 * Definitions for the safety-check (安否確認) feature: the single owner of the
 * feature's table names and status/trigger vocabulary. The installer's
 * select_items, the watcher/controller/sender queries and the action's
 * validation must all derive from these constants — a status string spelled
 * inline in a where() clause fails silently when it drifts.
 */
class SafetyCheckDefine
{
    public const TABLE_EVENT  = 'safety_check_event';
    public const TABLE_ANSWER = 'safety_check_answer';

    public const EVENT_OPEN   = 'open';
    public const EVENT_CLOSED = 'closed';

    public const TRIGGER_MANUAL   = 'manual';
    public const TRIGGER_DRILL    = 'drill';
    public const TRIGGER_JMA_AUTO = 'jma_auto';

    /** Initial status of every answer row: no answer given yet. */
    public const ANSWER_NOT_ANSWERED = 'not_answered';

    /** Statuses a user can answer with — the three Flex buttons. */
    public const ANSWER_STATUSES = ['safe', 'minor_injury', 'need_help'];

    /**
     * Read an int system setting, falling back to its Define default when the stored
     * value is not a positive number: a numeric field emptied in the admin UI is
     * stored as 0, and for these settings 0 is meaningless-to-dangerous (e.g.
     * max_bulletin_age=0 marks every bulletin stale and silently kills the watcher).
     *
     * NOT for safety_check_resend_throttle_minutes: there 0 is a documented value
     * ("no throttle") and must be kept as-is.
     */
    public static function intSetting(string $name): int
    {
        $value = (int) System::{$name}();
        if ($value > 0) {
            return $value;
        }
        return (int) array_get(Define::SYSTEM_SETTING_NAME_VALUE, $name . '.default', 0);
    }

    /**
     * JMA seismic intensity (震度) options used as the "minimum scale to
     * auto-trigger a safety check" select field. Keys are JMA's numeric
     * scale codes (x10); labels are localized (safety.scale_options.*).
     */
    public static function scaleOptions(): array
    {
        return [
            // 10 => exmtrans('safety.scale_options.10'), // 震度1 — enable temporarily for live testing
            40 => exmtrans('safety.scale_options.40'),
            45 => exmtrans('safety.scale_options.45'),
            50 => exmtrans('safety.scale_options.50'),
            55 => exmtrans('safety.scale_options.55'),
            60 => exmtrans('safety.scale_options.60'),
            70 => exmtrans('safety.scale_options.70'),
        ];
    }
}
