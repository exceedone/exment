<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Exceptions\LineSendFailedException;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class LineSendJob implements ShouldQueue
{
    use JobTrait;

    /** @var string */
    protected $to;
    /** @var array */
    protected $messages;
    /** @var array */
    protected $context;
    /**
     * @var bool
     */
    protected $throwOnFailure;

    public function __construct(string $to, array $messages, array $context = [], bool $throwOnFailure = false)
    {
        $this->to = $to;
        $this->messages = $messages;
        $this->context = $context;
        $this->throwOnFailure = $throwOnFailure;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        $client = app(LineMessagingClient::class);

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
                return;
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
            throw new LineSendFailedException($this->to, $res);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($e instanceof LineSendFailedException) {
            return;
        }
        LineSendLogger::record(
            array_merge(['line_user_id' => $this->to], $this->context),
            $this->messages,
            ['ok' => false, 'status' => 0, 'raw' => $e->getMessage()]
        );
    }

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

    protected function isSyncDriver(): bool
    {
        return !$this->job || $this->job->getConnectionName() === 'sync';
    }
}
