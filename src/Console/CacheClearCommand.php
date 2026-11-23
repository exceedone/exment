<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\System;

/**
 * Clears the cache of Exment (EXMENT_USE_CACHE) on every server of the site: the store of
 * this server, and a new generation of the cache for the others (System::cacheGeneration).
 * For after the database was changed from outside Exment (SQL, a restore, another tool).
 */
class CacheClearCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:cache-clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the cache of Exment (EXMENT_USE_CACHE) on every server of the site';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!boolval(config('exment.use_cache', false))) {
            // nothing is kept from one request to the next; a server of the site set
            // otherwise still sees the new generation
            System::clearRequestSession();
            $generation = System::renewCacheGeneration();
            $this->info('The cache of Exment is not used here (EXMENT_USE_CACHE). The generation of the cache is now ' . $generation . '.');
            return 0;
        }

        System::clearCache();
        $this->info('The cache of Exment was cleared. The generation of the cache is now ' . System::cacheGeneration() . '.');
        return 0;
    }
}
