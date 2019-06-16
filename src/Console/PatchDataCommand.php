<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
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

        switch($name){
            case 'rmcomma':
                $this->removeDecimalComma();       
                return;
            case 'use_label_flg':
                $this->modifyUseLabelFlg();       
                return;
        }
    }

    /**
     * Remove decimal comma
     *
     * @return void
     */
    protected function removeDecimalComma()
    {
        // get ColumnType is decimal or Currency
        $columns = CustomColumn::whereIn('column_type', ColumnType::COLUMN_TYPE_CALC())->get();

        foreach ($columns as $column) {
            $custom_table = $column->custom_table;

            // get value contains comma
            $dbTableName = \getDBTableName($custom_table);
            $custom_table->getValueModel()
                ->where("value->{$column->column_name}", 'LIKE', '%,%')
                ->withTrashed()
                ->chunk(1000, function ($commaValues) use ($column, $dbTableName) {
                    foreach ($commaValues as &$commaValue) {
                        // rmcomma
                        $v = array_get($commaValue, "value.{$column->column_name}");
                        $v = rmcomma($v);

                        \DB::table($dbTableName)->where('id', $commaValue->id)->update(["value->{$column->column_name}" => $v]);
                    }
                });
        }
    }
    
    /**
     * Modify Use Label Flg
     *
     * @return void
     */
    protected function modifyUseLabelFlg()
    {
        // move use_label_flg to custom_column_multi
        $use_label_flg_columns = CustomColumn::whereNotIn('options->use_label_flg', [0, "0"])->orderby('options->use_label_flg')->get();
        foreach($use_label_flg_columns as $use_label_flg_column){
            $custom_table = $use_label_flg_column->custom_table;

            $custom_table->table_labels()->save(
                new CustomColumnMulti([
                    'multisetting_type' => 2,
                    'table_label_column_id' => $use_label_flg_column->id,
                    'priority' => $use_label_flg_column->getOption('use_label_flg'),
                ])
            );

            $use_label_flg_column->setOption('use_label_flg', null);
            $use_label_flg_column->save();
        }

        // remove use_label_flg property
        $columns = CustomColumn::all();
        foreach($columns as $column){
            if(!array_has($column, 'options.use_label_flg')){
                continue;
            }
            $column->setOption('use_label_flg', null);

            $column->save();
        }
    }
}
