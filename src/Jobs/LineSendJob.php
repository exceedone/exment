<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Exceptions\LineSendFailedException;
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
    /**
     * @var bool Surface an API rejection to the inline caller as LineSendFailedException.
     * Only for callers that dispatch() synchronously AND catch it per recipient
     * (SafetyCheckSender). Never for dispatchAfterResponse() callers: Laravel runs
     * those inline in Application::terminate() with no try/catch, so one thrown
     * push would abort every later recipient's push in the same request/command.
     */
    protected $throwOnFailure;

    public function __construct(string $to, array $messages, array $context = [], bool $throwOnFailure = false)
    {
        $this->to = $to;
        $this->messages = $messages;
        $this->context = $context;
        $this->throwOnFailure = $throwOnFailure;
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

        if (!$res['ok'] && $this->throwOnFailure && $this->isSyncDriver()) {
            // Inline execution: the dispatching code is still on the stack, so tell
            // it. Without this, SafetyCheckSender counted a push LINE rejected (e.g.
            // expired channel token -> 401) as "sent" and the admin page showed N/N
            // while nobody received anything. The log row is already written above;
            // failed() skips this exception type so it is not written twice.
            throw new LineSendFailedException($this->to, $res);
        }
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
        if ($e instanceof LineSendFailedException) {
            return; // handle() logged the API result before throwing
        }
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
        if ($this->isSyncDriver()) {
            return false;
        }
        return $this->attempts() < $this->tries;
    }

    /** True when running inline (sync driver, or no queue job at all — e.g. handle() called directly). */
    protected function isSyncDriver(): bool
    {
        return !$this->job || $this->job->getConnectionName() === 'sync';
    }
}
