<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomRelation;
use Illuminate\Support\Collection;

class DefaultTableSettingProvider extends ProviderBase
{
    protected $custom_table;
    protected $custom_columns;
    protected $outputs = [];

    public function __construct($args = [])
    {
        parent::__construct();
        $this->custom_table = array_get($args, 'custom_table');

        $this->setOutputData();
    }

    /**
     * get data name
     */
    public function name()
    {
        return Define::SETTING_SHEET_NAME;
    }

    /**
     * get data
     */
    public function data()
    {
        return $this->outputs;
    }

    protected function setOutputData()
    {
        // get header and body
        $headers = $this->getHeaders();

        $bodies = [];

        // get this column table's columns
        $custom_columns = static::getTargetColumns($this->custom_table);
        foreach ($custom_columns as $custom_column) {
            $bodies[] = [$custom_column->custom_table->table_name, 'value.' . $custom_column->column_name, $custom_column->select_import_column->column_name ?? ''];
        }

        // get relation
        $relation = CustomRelation::getRelationByChild($this->custom_table);
        if (isset($relation)) {
            $bodies[] = [$relation->child_custom_table->table_name, 'parent_id', $relation->parent_import_column->column_name ?? ''];
        }

        $relations = CustomRelation::getRelationsByParent($this->custom_table, RelationType::ONE_TO_MANY);
        if (isset($relations)) {
            foreach ($relations as $relation) {
                // get child column table's columns
                $custom_columns = static::getTargetColumns($relation->child_custom_table);
                foreach ($custom_columns as $custom_column) {
                    $bodies[] = [$custom_column->custom_table->table_name, 'value.' . $custom_column->column_name, $custom_column->select_import_column->column_name ?? ''];
                }

                $bodies[] = [$relation->child_custom_table->table_name, 'parent_id', $relation->parent_import_column->column_name ?? ''];
            }
        }

        // get output items
        if (count($bodies) > 0) {
            $this->outputs = array_merge($headers, $bodies);
        }
    }

    /**
     * is output this sheet
     *
     * @return boolean
     */
    public function isOutput()
    {
        return count($this->outputs) > 0;
    }

    /**
     * get export headers
     * contains custom column name, column view name
     */
    protected function getHeaders()
    {
        // create 2 rows.
        $rows = [];

        // 1st row, column name
        $rows[] = ['table_name', 'column_name', 'target_column_name'];

        $rows[] = [exmtrans('custom_table.table_name'), exmtrans('custom_column.column_name'), exmtrans('custom_value.import.target_column_name')];

        return $rows;
    }

    /**
     * get setting target columns
     *
     * @return \Illuminate\Support\Collection
     */
    protected static function getTargetColumns($custom_table)
    {
        // get custom columns. only select_valtext, select_table
        return $custom_table->custom_columns()
            ->whereIn('column_type', ColumnType::COLUMN_TYPE_IMPORT_REPLACE())
            ->get();
    }


    public function getRecords(): Collection
    {
        return new Collection();
    }
}
