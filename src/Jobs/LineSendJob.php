<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Line\LineMessagingClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Job đẩy 1 tin LINE (push) qua Messaging API.
 */
class LineSendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var string */
    protected $to;
    /** @var array */
    protected $messages;

    public function __construct(string $to, array $messages)
    {
        $this->to = $to;
        $this->messages = $messages;
    }

    public function handle(): void
    {
        $res = (new LineMessagingClient())->push($this->to, $this->messages);
        if (!$res['ok']) {
            Log::warning('LINE push failed', [
                'status' => $res['status'],
                'body'   => $res['body'],
                'to'     => $this->to,
            ]);
        }
    }
}
