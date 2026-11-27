<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs;
use Illuminate\Console\Command;

/**
 * The end of a "Run now" of the page "Scheduled jobs" (ISS02832): the command line that ran
 * the job in the background calls it with the exit code. Not for a person.
 */
class ScheduledJobFinishCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:scheduled-job-finish {run : the id of the run} {code=0 : the exit code}';

    /**
     * @var string
     */
    protected $description = 'Keep the end of a scheduled job run from the page (used by the page itself)';

    /**
     * @var bool
     */
    protected $hidden = true;

    /**
     * @return int
     */
    public function handle()
    {
        $this->initExmentCommand();
        return ScheduledJobs::finishRun(intval($this->argument('run')), intval($this->argument('code'))) ? 0 : 1;
    }
}
