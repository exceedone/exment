<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowValue;

class WorkflowClearCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:workflow-clear {table_name} {id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exment clear workflow value';

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
     * @return mixed
     */
    public function handle()
    {
        $table_name = $this->argument("table_name");
        $id = $this->argument("id");

        if (is_nullorempty($table_name) || is_nullorempty($id)) {
            return;
        }

        $custom_table = CustomTable::getEloquent($table_name);
        if (is_nullorempty($custom_table)) {
            $this->error('table is not found.');
            return;
        }

        $custom_value = $custom_table->getValueModel($id);
        if (is_nullorempty($custom_value)) {
            $this->error('id ' . $id . ' is not found.');
            return;
        }

        // delete workflow value
        WorkflowValue::where('morph_type', $custom_value->custom_table->table_name)
            ->where('morph_id', $custom_value->id)
            ->delete();

        $this->info('Executed.');
    }
}
