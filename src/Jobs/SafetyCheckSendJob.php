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
     * @var SafetyCheckSendLock|null the event's send lock, kept while sending and released when done
     */
    protected $eventLock;

    /**
     * @var string|null the admin's 送信 lock to release when done
     */
    protected $sendLockKey;

    public function __construct(CustomValue $event, bool $onlyUnanswered = false, ?SafetyCheckSendLock $eventLock = null, ?string $sendLockKey = null)
    {
        $this->event = $event;
        $this->onlyUnanswered = $onlyUnanswered;
        $this->eventLock = $eventLock;
        $this->sendLockKey = $sendLockKey;
    }

    public function handle(): void
    {
        // the admin has the response already; closing the page must not cut the send off
        ignore_user_abort(true);
        \Exment::setTimeLimitLong();

        try {
            SafetyCheckSender::send($this->event, $this->onlyUnanswered, $this->eventLock);
        } catch (\Throwable $e) {
            // no error page reaches the admin any more
            Log::error('safety check send failed', [
                'event_id' => $this->event->id,
                'exception' => $e,
            ]);
        } finally {
            if ($this->eventLock) {
                $this->eventLock->release();
            }
            if ($this->sendLockKey !== null) {
                \Cache::forget($this->sendLockKey);
            }
        }
    }
}
