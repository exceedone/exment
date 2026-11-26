<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\LineWorks\LineWorks;
use Exceedone\Exment\Services\LineWorks\LineWorksException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A message of the bot to LINE WORKS (LineWorks, ISS00114).
 *
 * Tried 3 times, as a webhook is (JsonWebhookJob): by the queue worker a minute and five minutes
 * apart, or at once a second or two apart when there is no worker. Not sent at last: a bell to
 * the user who made the notification (LineWorks::failed()).
 */
class LineWorksSendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [60, 300];

    /** @var int */
    public $timeout = 60;

    /**
     * @param string $to "users/{id}" or "channels/{id}"
     * @param array<string, mixed> $content
     */
    public function __construct(public string $to, public array $content, public ?int $notifyId)
    {
        if (strval(config('queue.default')) !== 'sync') {
            $this->afterCommit();
        }
    }

    public function handle(): void
    {
        // a worker: it tries again itself (backoff), and failed() tells at last
        if (isset($this->job) && $this->job->getConnectionName() !== 'sync') {
            $error = $this->attempt();
            if ($error === null) {
                return;
            }
            if (!$error->retry) {
                $this->fail($error);
                return;
            }
            throw $error;
        }
        // no worker: here, a little later each time
        $waits = array_values((array)config('exment.notify_webhook_retry_seconds', [1, 2]));
        $message = '';
        for ($i = 0; $i < $this->tries; $i++) {
            $error = $this->attempt();
            if ($error === null) {
                return;
            }
            $message = $error->getMessage();
            if (!$error->retry || $i === $this->tries - 1) {
                break;
            }
            $wait = intval($waits[$i] ?? end($waits));
            if ($wait > 0) {
                sleep($wait);
            }
        }
        LineWorks::failed($this->notifyId, $this->to, $message);
    }

    /**
     * The last try by the worker failed.
     */
    public function failed(?\Throwable $ex): void
    {
        LineWorks::failed($this->notifyId, $this->to, isset($ex) ? $ex->getMessage() : '');
    }

    /**
     * Send once. Null: sent.
     */
    protected function attempt(): ?LineWorksException
    {
        $client = LineWorks::client();
        if (!isset($client)) {
            return new LineWorksException('The LINE WORKS app is not set.', false);
        }
        try {
            $client->send($this->to, $this->content);
            return null;
        } catch (LineWorksException $e) {
            return $e;
        }
    }
}
