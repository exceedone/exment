<?php

namespace Exceedone\Exment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

trait JobTrait
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Default try max count
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Job timeout seconds
     *
     * @var int
     */
    public $timeout = 120;
}
