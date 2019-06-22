<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Illuminate\Support\Collection;

class DefaultTableProvider extends ProviderBase
{
    protected $grid;

    public function __construct($args = [])
    {
        parent::__construct();
        $this->custom_table = array_get($args, 'custom_table');

        $this->grid = array_get($args, 'grid');
    }

    /**
     * get data name
     */
    public function name()
    {
        return $this->custom_table->table_name;
    }

    /**
     * get data
     */
    public function data()
    {
        // get header info
        $columnDefines = $this->getColumnDefines();
        // get header and body
        $headers = $this->getHeaders($columnDefines);

        // if only template, output only headers
        if ($this->template) {
            $bodies = [];
        } else {
            $bodies = $this->getBodies($this->getRecords(), $columnDefines);
        }
        // get output items
        $outputs = array_merge($headers, $bodies);

        return $outputs;
    }

    /**
     * get column info
     * @return mixed list. first:fixed column id, suuid, parent_id, parent_type. second: custom columns: third: created_at, updated_at, deleted_at
     */
    protected function getColumnDefines()
    {
        $firstColumns = ['id','suuid','parent_id','parent_type'];
        $lastColumns = ['created_at','updated_at','deleted_at'];

        // get custom columns
        $custom_columns = $this->custom_table->custom_columns()->get(['column_name', 'column_view_name'])->toArray();
        return [$firstColumns, $custom_columns, $lastColumns];
    }

    /**
     * get export headers
     * contains custom column name, column view name
     */
    protected function getHeaders($columnDefines)
    {
        // create 2 rows.
        $rows = [];

        list($firstColumns, $custom_columns, $lastColumns) = $columnDefines;
        
        // 1st row, column name
        $rows[] = array_merge(
            $firstColumns,
            collect($custom_columns)->map(function ($value) {
                return "value.".array_get($value, 'column_name');
            })->toArray(),
            $lastColumns
        );

        // 2st row, column view name
        $rows[] = array_merge(
            collect($firstColumns)->map(function ($value) {
                return exmtrans("common.$value");
            })->toArray(),
            collect($custom_columns)->map(function ($value) {
                return array_get($value, 'column_view_name');
            })->toArray(),
            collect($lastColumns)->map(function ($value) {
                return exmtrans("common.$value");
            })->toArray()
        );
        return $rows;
    }

    /**
     * get target chunk records
     */
    protected function getRecords()
    {
        $this->grid->getFilter()->chunk(function ($data) use (&$records) {
            if (!isset($records)) {
                $records = new Collection;
            }
            $records = $records->merge($data);
        }) ?? [];

        return $records;
    }

    /**
     * get export bodies
     */
    protected function getBodies($records, $columnDefines)
    {
        if (!isset($records)) {
            return [];
        }
        
        $bodies = [];

        list($firstColumns, $custom_columns, $lastColumns) = $columnDefines;
        // convert $custom_columns to pluck column_name array
        $custom_column_names = collect($custom_columns)->pluck('column_name')->toArray();
        foreach ($records as $record) {
            $body_items = [];
            // add items
            $body_items = array_merge($body_items, $this->getBodyItems($record, $firstColumns));
            $body_items = array_merge($body_items, $this->getBodyItems($record, $custom_column_names, "value."));
            $body_items = array_merge($body_items, $this->getBodyItems($record, $lastColumns));

            $bodies[] = $body_items;
        }

        return $bodies;
    }

    /**
     * get export body items
     */
    protected function getBodyItems($record, $columns, $array_header_key = null)
    {
        $body_items = [];
        foreach ($columns as $column) {
            // get key.
            $key = (isset($array_header_key) ? $array_header_key : "").$column;
            $value = array_get($record, $key);
            if (is_array($value)) {
                $value = implode(",", $value);
            }
            $body_items[] = $value;
        }
        return $body_items;
    }
}
