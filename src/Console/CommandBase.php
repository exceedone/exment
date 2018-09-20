<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;


abstract class CommandBase extends Command
{   /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
    }
}
