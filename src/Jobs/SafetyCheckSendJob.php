<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSendLock;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a safety check (安否確認) to every user once the admin's response has gone out.
 *
 * Dispatched with dispatchAfterResponse(), and deliberately not a ShouldQueue job: it runs in
 * the same process, so the send never waits for a queue worker that may not be running.
 */
class SafetyCheckSendJob
{
    use Dispatchable;

    /**
     * @var CustomValue
     */
    protected $event;

    /**
     * @var bool
     */
    protected $onlyUnanswered;

    /**
     * @var SafetyCheckSendLock|null the event's send lock (and the admin's 送信 lock riding along),
     *      kept while sending and released when done
     */
    protected $lock;

    public function __construct(CustomValue $event, bool $onlyUnanswered = false, ?SafetyCheckSendLock $lock = null)
    {
        $this->event = $event;
        $this->onlyUnanswered = $onlyUnanswered;
        $this->lock = $lock;
    }

    public function handle(): void
    {
        // the admin has the response already; closing the page must not cut the send off
        ignore_user_abort(true);
        \Exment::setTimeLimitLong();

        try {
            SafetyCheckSender::send($this->event, $this->onlyUnanswered, $this->lock);
        } catch (\Throwable $e) {
            // no error page reaches the admin any more
            Log::error('safety check send failed', [
                'event_id' => $this->event->id,
                'exception' => $e,
            ]);
        } finally {
            // never throws: an exception here would skip the after-response callbacks queued after this one
            if ($this->lock) {
                $this->lock->release();
            }
        }
    }
}
