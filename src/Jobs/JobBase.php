<?php

namespace Exceedone\Exment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

abstract class JobBase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Default try max count
     *
     * @var int
     */
    public $tries = 5;

    /**
     * Job timeout seconds
     *
     * @var int
     */
    public $timeout = 120;
}
