<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginType;

class BatchCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:batch {id?} {--name=} {--uuid=} {--param1=} {--param2=} {--param3=} {--param4=} {--param5=} {--param6=} {--param7=} {--param8=} {--param9=} {--param10=}';

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
     * @return int
     */
    public function handle()
    {
        $this->pluginBatch();
        return 0;
    }

    /**
     * Execute Plugin Batch
     *
     * @return void
     */
    protected function pluginBatch()
    {
        $plugin = $this->findPlugin();

        if (!isset($plugin)) {
            $this->error('Plugin not found. Please select plugin.');
            return;
        }
        
        if (!$plugin->matchPluginType(PluginType::BATCH)) {
            $this->error('Plugin not not batch. Please select batch plugin.');
            return;
        }

        $batch = $plugin->getClass(PluginType::BATCH, [
            'command_options' => $this->options()
        ]);
        $batch->execute();
    }

    protected function findPlugin()
    {
        // Execute batch. *Batch can execute if active_flg is false, so get value directly.
        if (!is_null($key = $this->argument("id"))) {
            return Plugin::find($key);
        } elseif (!is_null($key = $this->option("name"))) {
            return Plugin::where('plugin_name', $key)->first();
        } elseif (!is_null($key = $this->option("uuid"))) {
            return Plugin::where('uuid', $key)->first();
        }

        return null;
    }
}
