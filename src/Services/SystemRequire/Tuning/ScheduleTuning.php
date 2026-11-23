<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The scheduler (php artisan schedule:run, from cron every minute) (ISS02823).
 * - Is cron alive? A task of the scheduler finishes every minute, so nothing for
 *   STALE_SECONDS means that cron does not run it (notifications, schedules, batches stop).
 * - Is it heavy? Every minute it starts PHP and Exment; when its tasks take long, they take
 *   the CPU from the screens. The heavy tasks are listed, and cron can run it with a low priority (nice).
 * What it did is kept by ScheduleStatus.
 */
class ScheduleTuning extends TuningBase
{
    /** No task finished for this long: cron is not running the scheduler. */
    public const STALE_SECONDS = 600;

    /** The tasks of one minute together took longer than this. */
    public const SLOW_MINUTE_SECONDS = 10.0;

    /** A task that takes this long on average is heavy. */
    public const HEAVY_TASK_SECONDS = 3.0;

    /** @var array<string, mixed>|null */
    protected $status;

    /** @var int|null the time now (a test gives it) */
    public $now;

    protected function key(): string
    {
        return 'schedule';
    }

    /**
     * @return array<string, mixed>
     */
    protected function status(): array
    {
        if (!isset($this->status)) {
            $this->status = ScheduleStatus::read();
        }
        return $this->status;
    }

    protected function now(): int
    {
        return $this->now ?? time();
    }

    public function alive(): bool
    {
        $last = $this->status()['last_finished'];
        return !is_null($last) && ($this->now() - $last) <= static::STALE_SECONDS;
    }

    /**
     * The longest total of one minute in the last hour, in seconds.
     */
    public function slowestMinute(): float
    {
        $minutes = $this->status()['minutes'];
        return count($minutes) > 0 ? round(floatval(max($minutes)), 1) : 0.0;
    }

    /**
     * The heavy tasks: name => average seconds, the heaviest first.
     *
     * @return array<string, float>
     */
    public function heavyTasks(): array
    {
        $heavy = [];
        foreach ($this->status()['tasks'] as $name => $task) {
            $count = max(1, intval($task['count'] ?? 1));
            $average = floatval($task['total'] ?? 0) / $count;
            if ($average >= static::HEAVY_TASK_SECONDS) {
                $heavy[(string) $name] = round($average, 1);
            }
        }
        arsort($heavy);
        return $heavy;
    }

    public function checkResult(): string
    {
        if (!$this->alive()) {
            return SystemRequireResult::WARNING;
        }
        if ($this->slowestMinute() > static::SLOW_MINUTE_SECONDS || count($this->heavyTasks()) > 0) {
            return SystemRequireResult::WARNING;
        }
        return SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $last = $this->status()['last_finished'];
        if (is_null($last)) {
            return exmtrans('tuning.item.schedule.never');
        }
        $text = exmtrans('tuning.item.schedule.result', [
            'last' => date('Y-m-d H:i', $last),
            'seconds' => $this->slowestMinute(),
        ]);
        $heavy = $this->heavyTasks();
        if (count($heavy) > 0) {
            $text .= ' / ' . exmtrans('tuning.item.schedule.heavy', ['tasks' => collect($heavy)->map(function ($seconds, $name) {
                return "{$name} ({$seconds}s)";
            })->implode(', ')]);
        }
        return $text;
    }

    protected function getMessageWarning(): ?string
    {
        return $this->alive()
            ? exmtrans('tuning.item.schedule.warning_heavy')
            : exmtrans('tuning.item.schedule.warning');
    }

    public function getFixText(): ?string
    {
        // a low priority (nice), so that the screens get the CPU first
        return implode("\n", [
            '# crontab -e (the user of the web server)',
            '* * * * * cd ' . base_path() . ' && nice -n 15 php artisan schedule:run >> /dev/null 2>&1',
        ]);
    }
}
