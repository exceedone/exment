<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class LineSendJob implements ShouldQueue
{
    // JobTrait: $tries = 3 like every other send job (Mail/Slack/Teams). Retryable
    // LINE failures (429 / 5xx / network exceptions) are re-attempted with backoff()
    // delays; the sync driver cannot retry, so there it is effectively 1.
    use JobTrait;

    /** @var string */
    protected $to;
    /** @var array */
    protected $messages;
    /** @var array Context for the line_send_log entry (see LineSendLogger::record) */
    protected $context;

    public function __construct(string $to, array $messages, array $context = [])
    {
        $this->to = $to;
        $this->messages = $messages;
        $this->context = $context;
    }

    /** Exposes the (otherwise protected) log context, mainly so tests can assert on it. */
    public function getContext(): array
    {
        return $this->context;
    }

    /** Seconds to wait before each retry (attempt 1 -> [0], attempt 2 -> [1], ...). */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        // Container-resolved (bound in ExmentServiceProvider); tests re-bind it
        // with a mocked transport.
        $client = app(LineMessagingClient::class);

        // A thrown Guzzle exception (network error) is NOT caught here on purpose:
        // a real queue retries it via $tries, the sync driver surfaces it to the caller.
        $res = $client->push($this->to, $this->messages);

        if (!$res['ok']) {
            if ($this->shouldRetry((int) $res['status'])) {
                Log::warning('LINE push failed, will retry', [
                    'status'  => $res['status'],
                    'attempt' => $this->attempts(),
                    'to'      => $this->to,
                ]);
                $backoff = $this->backoff();
                $this->release($backoff[min($this->attempts(), count($backoff)) - 1]);
                return; // no log row yet: the retry will produce the final result
            }

            Log::warning('LINE push failed', [
                'status' => $res['status'],
                'body'   => $res['body'],
                'to'     => $this->to,
            ]);
        }

        LineSendLogger::record(
            array_merge(['line_user_id' => $this->to], $this->context),
            $this->messages,
            $res
        );
    }

    /**
     * Called by the queue when the job dies for good (e.g. a Guzzle network
     * exception thrown from handle() on every attempt — those never reach the
     * LineSendLogger::record call there). Without this, an unreachable LINE API
     * leaves failed_jobs entries but no line_send_log row, so the admin screen
     * has no per-user failure to follow up on.
     */
    public function failed(\Throwable $e): void
    {
        LineSendLogger::record(
            array_merge(['line_user_id' => $this->to], $this->context),
            $this->messages,
            ['ok' => false, 'status' => 0, 'raw' => $e->getMessage()]
        );
    }

    /**
     * Retry only failures that can heal on their own (rate limit / LINE outage),
     * and only when a later attempt is actually possible: on a real queue with
     * attempts left. The sync driver runs in the caller's process — releasing there
     * would silently drop the job instead of retrying it.
     */
    protected function shouldRetry(int $status): bool
    {
        if ($status !== 429 && $status < 500) {
            return false;
        }
        if (!$this->job || $this->job->getConnectionName() === 'sync') {
            return false;
        }
        return $this->attempts() < $this->tries;
    }
}
