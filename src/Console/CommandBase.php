<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Carbon\Carbon;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;
use Exceedone\Exment\Model;
use Exceedone\Exment\Services\MailSender;

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
