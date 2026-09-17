<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class SafetyCheckDefine
{
    public const TABLE_EVENT  = 'safety_check_event';
    public const TABLE_ANSWER = 'safety_check_answer';

    public const EVENT_OPEN   = 'open';
    public const EVENT_CLOSED = 'closed';

    public const TRIGGER_MANUAL   = 'manual';
    public const TRIGGER_DRILL    = 'drill';
    public const TRIGGER_JMA_AUTO = 'jma_auto';

    public const ANSWER_NOT_ANSWERED = 'not_answered';

    public const ANSWER_STATUSES = ['safe', 'minor_injury', 'need_help'];

    public static function intSetting(string $name): int
    {
        $value = (int) System::{$name}();
        if ($value > 0) {
            return $value;
        }
        return (int) array_get(Define::SYSTEM_SETTING_NAME_VALUE, $name . '.default', 0);
    }

    public static function scaleOptions(): array
    {
        return [
            40 => exmtrans('safety.scale_options.40'),
            45 => exmtrans('safety.scale_options.45'),
            50 => exmtrans('safety.scale_options.50'),
            55 => exmtrans('safety.scale_options.55'),
            60 => exmtrans('safety.scale_options.60'),
            70 => exmtrans('safety.scale_options.70'),
        ];
    }

    /**
     * @param int $scale
     * @return string
     */
    public static function scaleLabel(int $scale): string
    {
        $labels = exmtrans('safety.scale_labels');
        $label = (is_array($labels) && array_key_exists($scale, $labels)) ? $labels[$scale] : (string) $scale;

        return exmtrans('safety.quake_max_scale', ['scale' => $label]);
    }
}
