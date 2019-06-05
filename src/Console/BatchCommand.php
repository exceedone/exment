<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Services\Plugin\PluginBatchBase;
use Carbon\Carbon;

class BatchCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:batch {id?} {--name=} {--uuid=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Batch';

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
     * @return void
     */
    public function handle()
    {
        $this->pluginBatch();
    }

    /**
     * Execute Plugin Batch
     *
     * @return void
     */
    protected function pluginBatch(){
        $plugin = $this->findPlugin();

        if(!isset($plugin)){
            $this->error('Plugin not found. Please select plugin.');
            return;
        }
        
        if($plugin->plugin_type != PluginType::BATCH){
            $this->error('Plugin not not batch. Please select batch plugin.');
            return;
        }

        $batch = $plugin->getClass();
        $batch->execute();
    }

    protected function findPlugin(){
        if(!is_null($key = $this->argument("id"))){
            return Plugin::find($key);
        }elseif(!is_null($key = $this->option("name"))){
            return Plugin::getPluginByName($key);
        }elseif(!is_null($key = $this->option("uuid"))){
            return Plugin::getPluginByUUID($key);
        }

        return null;
    }
}
