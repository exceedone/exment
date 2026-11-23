<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * With the "sync" queue, mails and other jobs run inside the request, and the screen waits
 * for them (tens of seconds when the mail server does not answer).
 * A server where no process may keep running (a rental server) runs the worker from cron.
 */
class QueueTuning extends TuningBase
{
    protected function key(): string
    {
        return 'queue';
    }

    public function checkResult(): string
    {
        return config('queue.default') === 'sync' ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        return (string) config('queue.default');
    }

    public function getFixText(): ?string
    {
        // a server where nothing may keep running (a rental server): cron starts a worker
        // every minute, which ends when the queue is empty (and before the next minute)
        return implode("\n", [
            '# .env',
            'QUEUE_CONNECTION=database',
            '# keep a worker running (supervisor, systemd):',
            'php artisan queue:work --tries=3',
            '# a server where nothing may keep running (a rental server): cron every minute instead',
            '* * * * * cd ' . base_path() . ' && nice -n 15 ' . CliPhp::path() . ' artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1',
        ]);
    }
}
