<?php

namespace Exceedone\Exment\Services\ScheduledJobs;

/**
 * A cron expression in words, for the page "Scheduled jobs" (ISS02832): every fifth minute is
 * "Every 5 minutes", "30 3 * * *" is "Every day at 3:30". What is not one of the usual
 * forms is given as it is ("Custom (0 9-17 * * *)").
 */
class CronWords
{
    public static function describe(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return static::custom($expression);
        }
        [$minute, $hour, $day, $month, $weekday] = $parts;
        $everyDay = $day === '*' && $month === '*' && $weekday === '*';

        if ($everyDay && $hour === '*') {
            if ($minute === '*' || $minute === '*/1') {
                return static::trans('every_minute');
            }
            if (preg_match('#^\*/(\d+)$#D', $minute, $m) && intval($m[1]) >= 2 && intval($m[1]) <= 59) {
                return static::trans('every_minutes', ['n' => intval($m[1])]);
            }
            if (static::number($minute, 0, 59) !== null) {
                $at = intval($minute);
                return $at === 0 ? static::trans('hourly') : static::trans('hourly_at', ['minute' => $at]);
            }
            return static::custom($expression);
        }

        if ($everyDay && preg_match('#^\*/(\d+)$#D', $hour, $m) && static::number($minute, 0, 59) !== null) {
            $n = intval($m[1]);
            if ($n >= 2 && $n <= 23) {
                return static::trans('every_hours', ['n' => $n, 'minute' => intval($minute)]);
            }
            return static::custom($expression);
        }

        $times = static::times($minute, $hour);
        if (is_null($times)) {
            return static::custom($expression);
        }

        if ($everyDay) {
            return static::trans('daily', ['time' => $times]);
        }
        if ($day === '*' && $month === '*') {
            $days = static::weekdays($weekday);
            if (is_null($days)) {
                return static::custom($expression);
            }
            return static::trans('weekly', ['days' => $days, 'time' => $times]);
        }
        if ($weekday === '*' && $month === '*') {
            $days = static::list($day, 1, 31);
            if (is_null($days)) {
                return static::custom($expression);
            }
            return static::trans('monthly', ['day' => implode(static::separator(), $days), 'time' => $times]);
        }
        if ($weekday === '*' && static::number($day, 1, 31) !== null && static::number($month, 1, 12) !== null) {
            return static::trans('yearly', ['month' => intval($month), 'day' => intval($day), 'time' => $times]);
        }
        return static::custom($expression);
    }

    /**
     * "3:30", or "9:00, 18:00" for a list of hours: null when it is not one minute and fixed hours.
     */
    protected static function times(string $minute, string $hour): ?string
    {
        $at = static::number($minute, 0, 59);
        $hours = static::list($hour, 0, 23);
        if (is_null($at) || is_null($hours)) {
            return null;
        }
        return implode(static::separator(), array_map(function ($h) use ($at) {
            return $h . ':' . str_pad(strval($at), 2, '0', STR_PAD_LEFT);
        }, $hours));
    }

    /**
     * The days of the week ("1-5" is Monday to Friday; 0 and 7 are Sunday), null when not numbers.
     */
    protected static function weekdays(string $field): ?string
    {
        $days = [];
        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d)-(\d)$/D', $part, $m)) {
                $from = intval($m[1]);
                $to = intval($m[2]);
                if ($from > $to || $to > 7) {
                    return null;
                }
                for ($d = $from; $d <= $to; $d++) {
                    $days[] = $d % 7;
                }
                continue;
            }
            $d = static::number($part, 0, 7);
            if (is_null($d)) {
                return null;
            }
            $days[] = $d % 7;
        }
        $days = array_values(array_unique($days));
        if (count($days) === 0) {
            return null;
        }
        // Monday first, Sunday last
        usort($days, function ($a, $b) {
            return (($a + 6) % 7) <=> (($b + 6) % 7);
        });
        return implode(static::separator(), array_map(function ($d) {
            return static::trans('weekday.' . $d);
        }, $days));
    }

    /**
     * A list of numbers ("9" or "9,18"), sorted; null when it is anything else.
     *
     * @return int[]|null
     */
    protected static function list(string $field, int $min, int $max): ?array
    {
        $numbers = [];
        foreach (explode(',', $field) as $part) {
            $n = static::number($part, $min, $max);
            if (is_null($n)) {
                return null;
            }
            $numbers[] = $n;
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers);
        return $numbers;
    }

    protected static function number(string $value, int $min, int $max): ?int
    {
        if (!preg_match('/^\d{1,2}$/D', $value)) {
            return null;
        }
        $n = intval($value);
        return $n >= $min && $n <= $max ? $n : null;
    }

    protected static function separator(): string
    {
        return static::trans('separator');
    }

    protected static function custom(string $expression): string
    {
        return static::trans('custom', ['expression' => trim($expression)]);
    }

    /**
     * @param array<string, mixed> $replace
     */
    protected static function trans(string $key, array $replace = []): string
    {
        return strval(exmtrans('scheduled_jobs.cron.' . $key, $replace));
    }
}
