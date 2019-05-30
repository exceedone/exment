<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ColumnType;

class PatchDataCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:patchdata {action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Patch data if has bug';

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
        $name = $this->argument("action");

        if($name == 'rmcomma'){
            $this->removeDecimalComma();
        }
        else{
            $this->error('patch name not found.');
        }
    }

    /**
     * Remove decimal comma
     *
     * @return void
     */
    protected function removeDecimalComma(){
        // get ColumnType is decimal or Currency
        $columns = CustomColumn::whereIn('column_type', ColumnType::COLUMN_TYPE_CALC())->get();

        foreach($columns as $column){
            $custom_table = $column->custom_table;

            // get value contains comma
            $dbTableName = \getDBTableName($custom_table);
            $custom_table->getValueModel()
                ->where("value->{$column->column_name}", 'LIKE', '%,%')
                ->withTrashed()
                ->chunk(1000, function($commaValues) use($column, $dbTableName){
                    foreach($commaValues as &$commaValue){
                        // rmcomma                 
                        $v = array_get($commaValue, "value.{$column->column_name}");
                        $v = rmcomma($v);

                        \DB::table($dbTableName)->where('id', $commaValue->id)->update(["value->{$column->column_name}" => $v]);
                    }
                });
        }
    }
}
