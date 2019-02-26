<?php
namespace Exceedone\Exment\Console;

use Exceedone\Exment\Middleware;

trait CommandTrait
{   
    protected function initExmentCommand(){
        Middleware\Morph::defineMorphMap();
        Middleware\Initialize::initializeConfig(false);
    }
}
