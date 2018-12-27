<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Illuminate\Database\Eloquent\Model;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Exporters\AbstractExporter;
use Exceedone\Exment\Model\CustomRelation;

abstract class DataExporterBase extends AbstractExporter
{
    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';

    protected $scope;
    protected $table;
    protected $search_enabled_columns;

    /**
     * Whether this output is as template
     */
    protected $template = false;

    /**
     * Create a new exporter instance.
     *
     * @param $grid
     */
    public function __construct(Grid $grid = null, $table = null, $search_enabled_columns = null)
    {
        set_time_limit(240);

        if ($grid) {
            $this->setGrid($grid);
        }
        if ($table) {
            $this->table = $table;
        }
        if ($search_enabled_columns) {
            $this->search_enabled_columns = $search_enabled_columns;
        }

        $this->template = app('request')->query('temp');
    }

    /**
     */
    //abstract public function export();

    /**
     * get export table. contains header and body.
     */
    protected function getDataTable()
    {
        // get header info
        list($firstColumns, $custom_columns, $lastColumns) = $this->getColumnDefines();
        // get header and body
        $headers = $this->getHeaders($firstColumns, $custom_columns, $lastColumns);

        // if only template, output only headers
        if ($this->template) {
            $bodies = [];
        } else {
            $bodies = $this->getBodies($this->getRecords(), $firstColumns, $custom_columns, $lastColumns);
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
        $custom_columns = $this->table->custom_columns()->get(['column_name', 'column_view_name'])->toArray();
        return [$firstColumns, $custom_columns, $lastColumns];
    }

    /**
     * get target chunk records
     */
    protected function getRecords()
    {
        // get target records
        //return target
        $this->chunk(function ($data) use (&$records) {
            $records = $data;
        }) ?? [];

        return $records;
    }

    /**
     * get export headers
     * contains custom column name, column view name
     */
    protected function getHeaders($firstColumns, $custom_columns, $lastColumns)
    {
        // create 2 rows.
        $rows = [];
        
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
     * get export bodies
     */
    protected function getBodies($records, $firstColumns, $custom_columns, $lastColumns)
    {
        // convert $custom_columns to pluck column_name array
        $custom_column_names = collect($custom_columns)->pluck('column_name')->toArray();
        
        $bodies = [];
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

    protected function getBodyItems($record, $columns, $array_header_key = null)
    {
        $body_items = [];
        foreach ($columns as $column) {
            // get key.
            $key = (isset($array_header_key) ? $array_header_key : "").$column;
            $body_items[] = array_get($record, $key);
        }
        return $body_items;
    }

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     */
    protected function isOutputAsZip(){
        // check relations
        return count(CustomRelation::getRelationsByChild($this->table)) > 0;
    }

    /**
     * get exporter model
     */
    public static function getModel(Grid $grid = null, $table = null, $search_enabled_columns = null)
    {
        $format = app('request')->input('format');
        switch ($format) {
            case 'excel':
                return new ExcelExporter($grid, $table, $search_enabled_columns);
            default:
                return new CsvExporter($grid, $table, $search_enabled_columns);
        }
    }
}
