<?php
namespace Exceedone\Exment\Console;

use Exceedone\Exment\Middleware;

trait CommandTrait
{   /**
     * Execute the console command.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        Middleware\Morph::defineMorphMap();
        Middleware\Initialize::initializeConfig(false);
    }
}
