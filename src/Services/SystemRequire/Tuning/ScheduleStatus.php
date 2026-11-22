<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Illuminate\Console\Scheduling\Event;

/**
 * What the scheduler (php artisan schedule:run, started by cron every minute) did lately,
 * kept in a small file of the site so that the tuning page can tell (ISS02823):
 * - whether cron is alive: when a task of the scheduler last finished;
 * - how long the scheduler takes each minute (the total of the tasks that finished in it);
 * - which tasks are heavy (their last, longest and average seconds).
 *
 * It is written once per finished task (ScheduledTaskFinished, see ExmentServiceProvider),
 * with a lock, and only read by the tuning page and "php artisan exment:tuning".
 */
class ScheduleStatus
{
    /** How many past minutes are kept for the total per minute. */
    public const KEEP_MINUTES = 60;

    /** @var string|null where the file is (a test sets it) */
    public static $path;

    public static function path(): string
    {
        return static::$path ?? storage_path('framework/exment_schedule_status.json');
    }

    /**
     * The short name of a task: "exment:safetywatch" for "'/usr/bin/php' 'artisan' exment:safetywatch",
     * the description (or "closure") for a task that is not a command.
     */
    public static function taskName(Event $event): string
    {
        $command = (string) $event->command;
        if ($command !== '' && preg_match("/artisan'?\\s+(.+)$/", $command, $m)) {
            return trim(str_replace(["'", '"'], '', $m[1]));
        }
        if ($command !== '') {
            return $command;
        }
        return (string) ($event->description ?: 'closure');
    }

    /**
     * Keep a finished task.
     *
     * @param string $task the short name (taskName)
     * @param float $seconds how long it ran
     * @param int|null $now the time it finished (a test gives it)
     */
    public static function record(string $task, float $seconds, ?int $now = null): void
    {
        $now = $now ?? time();
        $path = static::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            $data = json_decode((string) stream_get_contents($fp), true);
            $data = is_array($data) ? $data : [];

            $data['last_finished'] = $now;

            $item = $data['tasks'][$task] ?? ['count' => 0, 'total' => 0.0, 'max' => 0.0];
            $item['count'] = intval($item['count']) + 1;
            $item['total'] = floatval($item['total']) + $seconds;
            $item['max'] = max(floatval($item['max']), $seconds);
            $item['last'] = $seconds;
            $item['last_at'] = $now;
            $data['tasks'][$task] = $item;

            // the total of each minute, for the last KEEP_MINUTES minutes
            $minute = (string) intdiv($now, 60);
            $minutes = $data['minutes'] ?? [];
            $minutes[$minute] = floatval($minutes[$minute] ?? 0) + $seconds;
            $oldest = intdiv($now, 60) - static::KEEP_MINUTES;
            $minutes = array_filter($minutes, function ($key) use ($oldest) {
                return intval($key) > $oldest;
            }, ARRAY_FILTER_USE_KEY);
            $data['minutes'] = $minutes;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * What was kept: ['last_finished' => int|null, 'tasks' => [name => [...]], 'minutes' => [minute => seconds]].
     *
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $path = static::path();
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        $data = is_array($data) ? $data : [];
        return [
            'last_finished' => isset($data['last_finished']) ? intval($data['last_finished']) : null,
            'tasks' => is_array($data['tasks'] ?? null) ? $data['tasks'] : [],
            'minutes' => is_array($data['minutes'] ?? null) ? $data['minutes'] : [],
        ];
    }
}
