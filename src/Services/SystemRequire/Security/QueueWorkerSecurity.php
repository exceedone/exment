<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\QueueWorker;

/**
 * A worker of the queue runs (ISS02908): with QUEUE_CONNECTION other than sync, the notices,
 * the webhooks and the search index wait in the queue until a worker takes them. The heartbeat
 * of QueueWorker tells whether one does. Without a queue (sync, the default): nothing to check.
 */
class QueueWorkerSecurity extends SecurityBase
{
    protected function key(): string
    {
        return 'queue_worker';
    }

    public function checkResult(): string
    {
        $state = QueueWorker::state();
        if ($state === 'stale') {
            return SystemRequireResult::NG;
        }

        return $state === 'unknown' ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('security_check.item.queue_worker.unknown_state');
    }

    protected function getMessageNg(): ?string
    {
        $last = QueueWorker::lastDone();

        return exmtrans('security_check.item.queue_worker.ng', ['last' => isset($last) ? $last->format('Y-m-d H:i') : '—', 'minutes' => QueueWorker::STALE_MINUTES]);
    }

    public function getFixText(): ?string
    {
        return QueueWorker::used() ? 'php artisan queue:work --tries=3 --max-time=3600' : null;
    }
}
