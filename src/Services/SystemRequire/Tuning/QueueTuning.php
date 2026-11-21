<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * With the "sync" queue, mails and other jobs run inside the request, and the screen waits
 * for them (tens of seconds when the mail server does not answer).
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
        return "# .env\nQUEUE_CONNECTION=database\n# keep a worker running (supervisor, systemd):\nphp artisan queue:work --tries=3";
    }
}
