<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\CustomValue;
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
     * @var string|null the send lock to release when done
     */
    protected $lockKey;

    public function __construct(CustomValue $event, bool $onlyUnanswered = false, ?string $lockKey = null)
    {
        $this->event = $event;
        $this->onlyUnanswered = $onlyUnanswered;
        $this->lockKey = $lockKey;
    }

    public function handle(): void
    {
        // the admin has the response already; closing the page must not cut the send off
        ignore_user_abort(true);
        \Exment::setTimeLimitLong();

        try {
            SafetyCheckSender::send($this->event, $this->onlyUnanswered);
        } catch (\Throwable $e) {
            // no error page reaches the admin any more
            Log::error('safety check send failed', [
                'event_id' => $this->event->id,
                'exception' => $e,
            ]);
        } finally {
            if ($this->lockKey !== null) {
                \Cache::forget($this->lockKey);
            }
        }
    }
}
