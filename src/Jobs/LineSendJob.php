<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LineSendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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

        LineSendLogger::record(
            array_merge(['line_user_id' => $this->to], $this->context),
            $this->messages,
            $res
        );
    }
}
